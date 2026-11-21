<?php

namespace TypechoPlugin\CommentAudit\Ai;

use TypechoPlugin\CommentAudit\Repository;

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
    public static function audit(array $comment, array $article, array $features, array $settings)
    {
        $model = trim((string) ($settings['deepseekModel'] ?? 'deepseek-flash'));
        if ($model === '') {
            $model = 'deepseek-flash';
        }

        $apiKey = trim((string) ($settings['deepseekApiKey'] ?? ''));
        if ($apiKey === '') {
            return self::failure('ai_missing_key');
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

        $start = microtime(true);
        $response = self::request($endpoint, $apiKey, $body, $timeout, $sslVerify);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($response['error'] !== '') {
            self::debug($settings, 'http error status=' . $response['status'] . ' body=' . substr($response['body'], 0, 2000));
            self::recordFailure();
            return self::failure('ai_error', $latency);
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            self::debug($settings, 'invalid json body=' . substr($response['body'], 0, 2000));
            self::recordFailure();
            return self::failure('ai_error', $latency);
        }

        if (isset($data['error'])) {
            self::debug($settings, 'api error body=' . substr($response['body'], 0, 2000));
            self::recordFailure();
            return self::failure('ai_error', $latency);
        }

        $content = '';
        if (isset($data['choices'][0]['message']['content'])
            && is_string($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
        }

        $parsed = ResultParser::parse($content);
        if (!$parsed) {
            self::debug($settings, 'parse error content=' . substr($content, 0, 2000));
            self::recordFailure();
            return self::failure('ai_error', $latency);
        }

        $parsed['latency_ms'] = $latency;
        $parsed['tokens'] = (int) ($data['usage']['total_tokens'] ?? 0);
        $parsed['cached'] = false;

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
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]);

            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($result === false) {
                return ['body' => '', 'error' => $error ?: 'curl_error', 'status' => $status];
            }
            if ($status >= 400) {
                return ['body' => (string) $result, 'error' => 'http_' . $status, 'status' => $status];
            }

            return ['body' => (string) $result, 'error' => '', 'status' => $status];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
                'content' => $payload,
                'timeout' => $timeout,
                'ignore_errors' => true,
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
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        if ($status >= 400) {
            return ['body' => (string) $result, 'error' => 'http_' . $status, 'status' => $status];
        }

        return ['body' => (string) $result, 'error' => '', 'status' => $status];
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

        $file = __TYPECHO_ROOT_DIR__ . '/usr/plugins/CommentAudit/debug.log';
        @file_put_contents(
            $file,
            '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', (string) $message) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * 失败结果。
     *
     * @param string $reason
     * @param int $latency
     * @return array
     */
    private static function failure($reason, $latency = 0)
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
