<?php

namespace TypechoPlugin\CommentAudit\Ai;

use TypechoPlugin\CommentAudit\Repository;
use TypechoPlugin\CommentAudit\Rule\IdentityContext;
use TypechoPlugin\CommentAudit\Rule\ConversationContext;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * DeepSeek 审核客户端。
 */
class DeepSeekClient
{
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
        $baseUrl = trim((string) ($settings['deepseekBaseUrl'] ?? 'https://api.deepseek.com'));
        if ($baseUrl === '') {
            $baseUrl = 'https://api.deepseek.com';
        }
        $baseUrl = rtrim($baseUrl, '/');
        $endpoint = substr($baseUrl, -17) === '/chat/completions'
            ? $baseUrl
            : $baseUrl . '/chat/completions';

        $timeout = max(3, (int) ($settings['deepseekTimeout'] ?? 8));
        $maxTokens = max(300, (int) ($settings['deepseekMaxTokens'] ?? 1200));
        $sslVerify = !empty($settings['deepseekSslVerify']);
        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0,
            'max_tokens' => $maxTokens,
            'stream' => false,
        ];
        if (parse_url($baseUrl, PHP_URL_HOST) === 'api.deepseek.com'
            && in_array($model, ['deepseek-flash', 'deepseek-v4-flash', 'deepseek-v4-pro'], true)) {
            $body['thinking'] = ['type' => 'disabled'];
        }

        $start = microtime(true);
        $response = self::request($endpoint, $apiKey, $body, $timeout, $sslVerify);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($response['error'] !== '') {
            self::debug($settings, 'http error status=' . (int) $response['status']);
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency, self::requestDiagnostic($response['status'], $response['error']));
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            self::debug($settings, 'invalid response JSON');
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency, _t('服务没有返回有效的 JSON，请检查 DeepSeek 服务地址。'));
        }

        if (isset($data['error'])) {
            self::debug($settings, 'API returned an error');
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency, _t('服务拒绝了审核请求，请检查模型和账户设置。'));
        }

        $content = '';
        if (isset($data['choices'][0]['message']['content'])
            && is_string($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
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
     * HTTP 请求。
     *
     * @param string $endpoint
     * @param string $apiKey
     * @param array $body
     * @param int $timeout
     * @param bool $sslVerify
     * @return array{body:string,error:string,status:int}
     */
    private static function request($endpoint, $apiKey, array $body, $timeout, $sslVerify = true)
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
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
                'Authorization: Bearer ' . $apiKey,
            ]);

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
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
                'content' => $payload,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
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
            return _t('DeepSeek 服务暂时不可用（HTTP %d），请稍后重试。', $status);
        }

        return match ($status) {
            400, 422 => _t('DeepSeek 无法接受此请求（HTTP %d），请检查模型和服务地址。', $status),
            401 => _t('DeepSeek 未接受当前密钥（HTTP 401），请核对密钥和服务地址。'),
            402 => _t('DeepSeek 账户余额不足（HTTP 402），请充值后继续扫描。'),
            403 => _t('DeepSeek 拒绝访问（HTTP 403），请检查账号权限。'),
            404 => _t('DeepSeek 找不到接口或模型（HTTP 404），请检查服务地址和模型名称。'),
            429 => _t('DeepSeek 请求过多（HTTP 429），请稍后重试。'),
            default => match ($error) {
                'https_unavailable' => _t('本机 PHP 未启用 HTTPS 请求支持，请启用 cURL 或 OpenSSL 扩展。'),
                'curl_28' => _t('连接 DeepSeek 超时，请检查网络或增加「请求超时（秒）」。'),
                'curl_51', 'curl_60', 'curl_77' => _t('服务器无法验证 DeepSeek 的 SSL 证书，请检查本机信任的证书。'),
                default => _t('无法连接 DeepSeek，请检查网络和服务地址。'),
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
