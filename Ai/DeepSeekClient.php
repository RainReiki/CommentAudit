<?php

namespace TypechoPlugin\CommentAudit\Ai;

use TypechoPlugin\CommentAudit\Repository;
use TypechoPlugin\CommentAudit\Rule\IdentityContext;
use TypechoPlugin\CommentAudit\Rule\ConversationContext;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * AI 审核客户端，兼容 DeepSeek 官方和 OpenAI 兼容的第三方中转站。
 */
class DeepSeekClient
{
    private const USER_AGENT = 'CommentAudit/1.0.26 (+https://lizihao.com/)';

    /**
     * 审核一条评论。
     *
     * @param array $comment
     * @param array $article
     * @param array $features
     * @param array $settings
     * @return array
     */
    public static function audit(array $comment, array $article, array $features, array $settings, bool $preview = false)
    {
        $model = trim((string) ($settings['deepseekModel'] ?? 'deepseek-flash'));
        if ($model === '') {
            $model = 'deepseek-flash';
        }

        $apiKey = trim((string) ($settings['deepseekApiKey'] ?? ''));
        if ($apiKey === '') {
            return self::failure('ai_missing_key');
        }

        try {
            $features['conversation_context'] = ConversationContext::build($comment);
        } catch (\Throwable $e) {
            return self::failure('ai_error', 0, _t('暂时无法读取回复的对话记录，请稍后重试。'));
        }
        $identityEnabled = !empty($settings['identityProtection'] ?? 1);
        if ($identityEnabled) {
            try {
                $features['identity_context'] = IdentityContext::build($comment);
            } catch (\Throwable $e) {
                return self::failure('ai_error', 0, _t('暂时无法读取身份检查所需的站点记录，请稍后重试。'));
            }
        }

        $cacheKey = PromptBuilder::cacheKey($model, $comment, $article, $features);
        $cached = Repository::getCache($cacheKey);
        if (is_array($cached)) {
            $cached['cached'] = true;
            $cached['latency_ms'] = 0;
            return $cached;
        }

        $circuit = Repository::getState('ai_circuit');
        if (!empty($circuit['open_until']) && (int) $circuit['open_until'] > time()) {
            return self::failure('ai_circuit_open');
        }

        $messages = PromptBuilder::build($comment, $article, $features);
        $baseUrl = self::normalizeBaseUrl((string) ($settings['deepseekBaseUrl'] ?? 'https://api.deepseek.com'));

        $timeout = max(3, (int) ($settings['deepseekTimeout'] ?? 8));
        $sslVerify = !empty($settings['deepseekSslVerify']);
        $body = self::buildBody($model, $messages, $settings, $baseUrl);

        $start = microtime(true);
        $response = ['body' => '', 'error' => 'http_error', 'status' => 0];
        $data = null;
        $endpoint = '';
        $attempts = self::endpoints($baseUrl);
        foreach ($attempts as $index => $candidate) {
            $endpoint = $candidate;
            $response = self::request($candidate, $apiKey, $body, $timeout, $sslVerify);

            if ($response['error'] === '') {
                $data = self::decodeResponseBody($response['body']);
                if (is_array($data)) {
                    break;
                }
            }

            // 中转站常见两种情况：根地址少了 /v1，或返回了 HTML 而不是 JSON。
            // 只在 404/405 或 2xx 非 JSON 时尝试备用路径；鉴权失败不重试。
            $retryable = ($response['error'] === '' && $response['status'] < 400)
                || in_array((int) $response['status'], [404, 405], true);
            if (!$retryable || $index === count($attempts) - 1) {
                break;
            }
            self::debug($settings, 'endpoint unavailable, trying fallback: ' . $candidate);
        }
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($response['error'] !== '') {
            self::debug($settings, 'http error status=' . (int) $response['status'] . ' endpoint=' . $endpoint);
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency, self::requestDiagnostic($response['status'], $response['error']));
        }

        if (!is_array($data)) {
            self::debug($settings, 'invalid response body: ' . self::short((string) $response['body'], 300));
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency, self::invalidResponseDiagnostic($response, $endpoint));
        }

        if (isset($data['error'])) {
            self::debug($settings, 'API returned an error: ' . self::short(self::errorMessage($data['error']), 300));
            if (!$preview) {
                self::recordFailure();
            }
            $message = self::errorMessage($data['error']);
            return self::failure('ai_error', $latency, $message !== ''
                ? _t('审核服务返回错误：%s', self::short($message, 240))
                : _t('审核服务拒绝了请求，请检查模型、密钥和账户设置。'));
        }

        $content = self::extractContent($data);
        if (trim($content) === '') {
            self::debug($settings, 'no choices[0].message.content in response');
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency,
                _t('审核服务返回了 JSON，但没有找到模型输出内容。请确认中转站兼容 OpenAI 的 /v1/chat/completions 格式。'));
        }
        $parsed = ResultParser::parse($content, $identityEnabled);
        if (!$parsed) {
            self::debug($settings, 'could not parse the audit decision');
            if (!$preview) {
                self::recordFailure();
            }
            $truncated = ($data['choices'][0]['finish_reason'] ?? '') === 'length';
            return self::failure('ai_error', $latency, $truncated
                ? _t('模型输出被截断，请在插件设置中增加「最大 token 数」。')
                : _t('模型没有返回可用的审核结果，请检查模型设置后重试。'));
        }

        if ($identityEnabled && is_array($parsed['identity'] ?? null)) {
            $context = $features['identity_context'];
            $allowed = [
                'site_name_similarity', 'placeholder_email', 'public_mailbox', 'platform_homepage',
                'search_engine_homepage', 'shortened_website', 'brand_impersonation',
                'profile_mismatch', 'unverified_website',
            ];
            if ($context['related_profiles']) {
                $allowed[] = 'historical_name_similarity';
                $allowed[] = 'history_profile_conflict';
                if (array_sum(array_column($context['related_profiles'], 'spam')) > 0) {
                    $allowed[] = 'history_spam_pattern';
                }
            }
            $parsed['identity']['sources'] = array_values(array_intersect($allowed, $parsed['identity']['sources']));
            if ($context['current']['authenticated_site_owner']
                || $parsed['identity']['category'] === 'none'
                || empty($parsed['identity']['evidence'])) {
                $parsed['identity'] = [
                    'risk' => 'low',
                    'score' => 0,
                    'category' => 'none',
                    'sources' => [],
                    'evidence' => [],
                ];
            }
        }

        $parsed['latency_ms'] = $latency;
        $parsed['tokens'] = (int) ($data['usage']['total_tokens'] ?? 0);
        $parsed['cached'] = false;
        $parsed['conversation'] = $features['conversation_context'];

        if ($preview) {
            return $parsed;
        }

        try {
            Repository::setCache(
                $cacheKey,
                $model,
                PromptBuilder::PROMPT_VERSION,
                $parsed,
                (int) ($settings['cacheTtl'] ?? 7)
            );
        } catch (\Throwable $e) {
            // 缓存失败不影响审核结果。
        }

        self::recordSuccess();

        return $parsed;
    }

    /**
     * 读取当前服务商支持的模型列表。
     *
     * @param array $settings
     * @return array{success:int,models?:array,message?:string,endpoint?:string}
     */
    public static function listModels(array $settings): array
    {
        $apiKey = trim((string) ($settings['deepseekApiKey'] ?? ''));
        if ($apiKey === '') {
            return ['success' => 0, 'message' => _t('请先填写 API Key。')];
        }

        $baseUrl = self::normalizeBaseUrl((string) ($settings['deepseekBaseUrl'] ?? 'https://api.deepseek.com'));
        $timeout = max(3, (int) ($settings['deepseekTimeout'] ?? 8));
        $sslVerify = !empty($settings['deepseekSslVerify']);
        $attempts = self::modelEndpoints($baseUrl);

        $response = ['body' => '', 'error' => 'http_error', 'status' => 0];
        $data = null;
        $endpoint = '';
        foreach ($attempts as $index => $candidate) {
            $endpoint = $candidate;
            $response = self::request($candidate, $apiKey, null, $timeout, $sslVerify);
            if ($response['error'] === '') {
                $data = self::decodeResponseBody($response['body']);
                if (is_array($data)) {
                    break;
                }
            }

            $retryable = ($response['error'] === '' && $response['status'] < 400)
                || in_array((int) $response['status'], [404, 405], true);
            if (!$retryable || $index === count($attempts) - 1) {
                break;
            }
        }

        if ($response['error'] !== '') {
            return ['success' => 0, 'message' => self::requestDiagnostic($response['status'], $response['error'])];
        }

        if (!is_array($data)) {
            return ['success' => 0, 'message' => self::invalidResponseDiagnostic($response, $endpoint)];
        }

        if (isset($data['error'])) {
            $message = self::errorMessage($data['error']);
            return ['success' => 0, 'message' => $message !== ''
                ? _t('审核服务返回错误：%s', self::short($message, 240))
                : _t('审核服务拒绝了请求，请检查密钥和服务地址。')];
        }

        $models = self::extractModels($data);
        if (!$models) {
            return ['success' => 0, 'message' => _t('服务返回了 JSON，但没有找到模型列表。')];
        }

        return ['success' => 1, 'models' => $models, 'endpoint' => $endpoint];
    }

    /**
     * @param string $baseUrl
     * @return string[]
     */
    private static function modelEndpoints(string $baseUrl): array
    {
        $baseUrl = self::normalizeBaseUrl($baseUrl);
        if (preg_match('#/chat/completions$#i', $baseUrl)) {
            $baseUrl = preg_replace('#/chat/completions$#i', '', $baseUrl) ?? $baseUrl;
        }
        if (preg_match('#/v\d+(?:beta)?$#i', $baseUrl)) {
            return [$baseUrl . '/models'];
        }

        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($host === 'api.deepseek.com') {
            return [$baseUrl . '/models', $baseUrl . '/v1/models'];
        }

        return [$baseUrl . '/v1/models', $baseUrl . '/models'];
    }

    /**
     * @param array $data
     * @return string[]
     */
    private static function extractModels(array $data): array
    {
        $rows = $data['data'] ?? $data['models'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $models = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $models[] = trim($row);
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            foreach (['id', 'name', 'model'] as $key) {
                if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                    $models[] = trim($row[$key]);
                    break;
                }
            }
        }

        $models = array_values(array_unique(array_filter($models, static fn ($model) => $model !== '')));
        sort($models, SORT_NATURAL | SORT_FLAG_CASE);

        return $models;
    }

    /**
     * 构建请求体。中转站开启“关闭思考”时统一带上 thinking 参数。
     *
     * @param string $model
     * @param array $messages
     * @param array $settings
     * @param string $baseUrl
     * @return array
     */
    private static function buildBody(string $model, array $messages, array $settings, string $baseUrl): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0,
            'max_tokens' => max(300, (int) ($settings['deepseekMaxTokens'] ?? 1200)),
            'stream' => false,
        ];

        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if (!empty($settings['deepseekDisableThinking'])) {
            $body['thinking'] = ['type' => 'disabled'];
        } elseif ($host === 'api.deepseek.com'
            && in_array($model, ['deepseek-flash', 'deepseek-v4-flash', 'deepseek-v4-pro'], true)) {
            $body['thinking'] = ['type' => 'disabled'];
        }

        return $body;
    }

    /**
     * 规范化服务地址。
     *
     * @param string $url
     * @return string
     */
    private static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            $url = 'https://api.deepseek.com';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $url = preg_replace('/[?#].*$/', '', $url) ?? $url;

        return rtrim($url, '/');
    }

    /**
     * 候选接口地址。中转站多数使用 /v1，官方 DeepSeek 根地址不需要 /v1。
     *
     * @param string $baseUrl
     * @return string[]
     */
    private static function endpoints(string $baseUrl): array
    {
        $baseUrl = self::normalizeBaseUrl($baseUrl);
        if (preg_match('#/chat/completions$#i', $baseUrl)) {
            return [$baseUrl];
        }
        if (preg_match('#/v\d+(?:beta)?$#i', $baseUrl)) {
            return [$baseUrl . '/chat/completions'];
        }

        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($host === 'api.deepseek.com') {
            return [$baseUrl . '/chat/completions', $baseUrl . '/v1/chat/completions'];
        }

        return [$baseUrl . '/v1/chat/completions', $baseUrl . '/chat/completions'];
    }

    /**
     * 解析响应体：普通 JSON、SSE 或带前后缀的 JSON。
     *
     * @param string $body
     * @return array|null
     */
    private static function decodeResponseBody(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
            $body = substr($body, 3);
        }

        $data = json_decode($body, true);
        if (is_array($data)) {
            return $data;
        }

        if (stripos($body, 'data:') !== false) {
            $sse = self::decodeSse($body);
            if ($sse !== null) {
                return $sse;
            }
        }

        // 某些中转站会在 JSON 前后输出空白、BOM 或 HTML 提示。
        $start = strpos($body, '{');
        $end = strrpos($body, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($body, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * 兼容中转站忽略 stream=false、直接返回 SSE 的情况。
     *
     * @param string $body
     * @return array|null
     */
    private static function decodeSse(string $body): ?array
    {
        $last = null;
        $text = '';
        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === 0 || stripos($line, 'data:') !== 0) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                continue;
            }
            $last = $data;
            $delta = $data['choices'][0]['delta']['content'] ?? null;
            if (is_string($delta)) {
                $text .= $delta;
            }
            $message = $data['choices'][0]['message']['content'] ?? null;
            if (is_string($message) && $message !== '') {
                $text = $message;
            }
        }

        if ($last === null) {
            return null;
        }
        if ($text !== '') {
            $last['choices'][0]['message']['content'] = $text;
            unset($last['choices'][0]['delta']);
        }

        return $last;
    }

    /**
     * 从不同兼容格式中提取模型文本。
     *
     * @param array $data
     * @return string
     */
    private static function extractContent(array $data): string
    {
        $choice = $data['choices'][0] ?? null;
        if (is_array($choice)) {
            $message = $choice['message'] ?? null;
            if (is_array($message)) {
                $content = $message['content'] ?? '';
                if (is_string($content)) {
                    return $content;
                }
                if (is_array($content)) {
                    return self::joinTextParts($content);
                }
            }
            if (isset($choice['text']) && is_string($choice['text'])) {
                return $choice['text'];
            }
        }

        if (isset($data['output_text']) && is_string($data['output_text'])) {
            return $data['output_text'];
        }

        if (isset($data['output']) && is_array($data['output'])) {
            $text = self::joinTextParts($data['output']);
            if ($text !== '') {
                return $text;
            }
        }

        foreach (['response', 'content'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        return '';
    }

    /**
     * @param array $parts
     * @return string
     */
    private static function joinTextParts(array $parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            if (is_string($part)) {
                $text .= $part;
            } elseif (is_array($part)) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                } elseif (isset($part['content'])) {
                    if (is_string($part['content'])) {
                        $text .= $part['content'];
                    } elseif (is_array($part['content'])) {
                        $text .= self::joinTextParts($part['content']);
                    }
                }
            }
        }

        return $text;
    }

    /**
     * 非 JSON 响应的具体诊断，避免只提示“服务地址错误”。
     *
     * @param array $response
     * @param string $endpoint
     * @return string
     */
    private static function invalidResponseDiagnostic(array $response, string $endpoint): string
    {
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        $trimmed = ltrim($body);
        if ($trimmed !== '' && $trimmed[0] === '<') {
            $hint = _t('返回的是 HTML 页面，可能是中转站拦截、鉴权失败或服务地址填错。');
        } elseif (stripos($body, 'data:') !== false) {
            $hint = _t('返回的是流式数据，但无法解析出审核结果。');
        } else {
            $hint = _t('请确认服务地址兼容 OpenAI 的 /v1/chat/completions 接口。');
        }
        $snippet = self::short(trim((string) preg_replace('/\s+/', ' ', strip_tags($body))), 120);

        return _t(
            '审核服务返回的不是有效 JSON（HTTP %d，地址 %s）。%s%s',
            $status,
            $endpoint,
            $hint,
            $snippet !== '' ? _t(' 返回内容：%s', $snippet) : ''
        );
    }

    /**
     * @param mixed $error
     * @return string
     */
    private static function errorMessage($error): string
    {
        if (is_string($error)) {
            return trim($error);
        }
        if (is_array($error)) {
            foreach (['message', 'msg', 'error', 'detail'] as $key) {
                if (isset($error[$key])) {
                    $message = self::errorMessage($error[$key]);
                    if ($message !== '') {
                        return $message;
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param string $text
     * @param int $limit
     * @return string
     */
    private static function short(string $text, int $limit): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    }

    /**
     * HTTP 请求。
     *
     * @param string $endpoint
     * @param string $apiKey
     * @param array $body
     * @param int $timeout
     * @param bool $sslVerify
     * @return array{body:string,error:string,status:int}
     */
    private static function request($endpoint, $apiKey, ?array $body, $timeout, $sslVerify = true)
    {
        $payload = $body === null
            ? ''
            : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            if ($body === null) {
                curl_setopt($ch, CURLOPT_HTTPGET, true);
            } else {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
            if ($sslVerify && PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
                curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
                'User-Agent: ' . self::USER_AGENT,
            ]);
            if (defined('CURLOPT_ENCODING')) {
                curl_setopt($ch, CURLOPT_ENCODING, '');
            }
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            if (defined('CURL_REDIR_POST_ALL')) {
                curl_setopt($ch, CURLOPT_POSTREDIR, CURL_REDIR_POST_ALL);
            }

            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($result === false) {
                return ['body' => '', 'error' => 'curl_' . $errno, 'status' => $status];
            }
            if ($status >= 400) {
                return ['body' => (string) $result, 'error' => 'http_' . $status, 'status' => $status];
            }

            return ['body' => (string) $result, 'error' => '', 'status' => $status];
        }

        if (parse_url($endpoint, PHP_URL_SCHEME) === 'https' && !in_array('https', stream_get_wrappers(), true)) {
            return ['body' => '', 'error' => 'https_unavailable', 'status' => 0];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $body === null ? 'GET' : 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n"
                    . "Authorization: Bearer {$apiKey}\r\nUser-Agent: " . self::USER_AGENT . "\r\n",
                'content' => $payload,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer' => $sslVerify,
                'verify_peer_name' => $sslVerify,
            ],
        ]);

        $result = @file_get_contents($endpoint, false, $context);
        if ($result === false) {
            return ['body' => '', 'error' => 'stream_error', 'status' => 0];
        }

        $status = 0;
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        if (!empty($headers[0]) && preg_match('/\s(\d{3})\b/', $headers[0], $m)) {
            $status = (int) $m[1];
        }

        if ($status >= 400) {
            return ['body' => (string) $result, 'error' => 'http_' . $status, 'status' => $status];
        }

        return ['body' => (string) $result, 'error' => '', 'status' => $status];
    }

    private static function requestDiagnostic(int $status, string $error): string
    {
        if ($status >= 500) {
            return _t('审核服务暂时不可用（HTTP %d），请稍后重试。', $status);
        }

        return match ($status) {
            400, 422 => _t('审核服务无法接受此请求（HTTP %d），请检查模型、服务地址和请求格式。', $status),
            401 => _t('审核服务未接受当前密钥（HTTP 401），请核对密钥和服务地址。'),
            402 => _t('审核服务账户余额不足（HTTP 402），请充值后继续扫描。'),
            403 => _t('审核服务拒绝访问（HTTP 403），请检查账号权限或中转站白名单。'),
            404 => _t('审核服务找不到接口或模型（HTTP 404），请检查服务地址和模型名称；中转站通常需要 /v1/chat/completions。'),
            429 => _t('审核服务请求过多（HTTP 429），请稍后重试。'),
            default => match ($error) {
                'https_unavailable' => _t('本机 PHP 未启用 HTTPS 请求支持，请启用 cURL 或 OpenSSL 扩展。'),
                'curl_28' => _t('连接审核服务超时，请检查网络或增加「请求超时（秒）」。'),
                'curl_51', 'curl_60', 'curl_77' => _t('服务器无法验证审核服务的 SSL 证书，请检查本机信任的证书。'),
                default => _t('无法连接审核服务，请检查网络和服务地址。'),
            },
        };
    }

    /**
     * 调试日志。
     *
     * @param array $settings
     * @param string $message
     */
    private static function debug(array $settings, $message)
    {
        if (empty($settings['deepseekDebug'])) {
            return;
        }

        error_log('[CommentAudit] ' . $message);
    }

    /**
     * 失败结果。
     *
     * @param string $reason
     * @param int $latency
     * @return array
     */
    private static function failure($reason, $latency = 0, ?string $diagnostic = null)
    {
        return [
            'decision' => 'review',
            'category' => 'normal',
            'severity' => 'low',
            'threat_type' => 'none',
            'on_topic' => true,
            'faithful' => true,
            'confidence' => 0,
            'reason' => $reason,
            'latency_ms' => (int) $latency,
            'tokens' => 0,
            'cached' => false,
            'error' => true,
            'diagnostic' => $diagnostic,
        ];
    }

    /**
     * 记录一次失败，连续 3 次熔断 5 分钟。
     */
    private static function recordFailure()
    {
        try {
            $state = Repository::getState('ai_circuit');
            $failures = (int) ($state['failures'] ?? 0) + 1;
            $openUntil = (int) ($state['open_until'] ?? 0);

            if ($failures >= 3) {
                $openUntil = time() + 300;
                $failures = 0;
            }

            Repository::setState('ai_circuit', [
                'failures' => $failures,
                'open_until' => $openUntil,
                'updated' => time(),
            ]);
        } catch (\Throwable $e) {
            // 熔断状态写入失败时忽略。
        }
    }

    /**
     * 记录成功，重置熔断。
     */
    private static function recordSuccess()
    {
        try {
            Repository::setState('ai_circuit', [
                'failures' => 0,
                'open_until' => 0,
                'updated' => time(),
            ]);
        } catch (\Throwable $e) {
            // 忽略。
        }
    }
}
