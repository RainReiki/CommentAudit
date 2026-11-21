<?php

namespace TypechoPlugin\CommentAudit\Ai;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * DeepSeek 返回 JSON 解析与规范化。
 */
class ResultParser
{
    /**
     * @param string $content
     * @return array|null
     */
    public static function parse($content)
    {
        $json = self::extractJson((string) $content);
        if ($json === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $decision = strtolower((string) ($data['decision'] ?? ''));
        if (!in_array($decision, ['pass', 'review', 'block'], true)) {
            return null;
        }

        $categories = [
            'normal', 'ad', 'fraud', 'porn', 'illegal',
            'abuse_mild', 'abuse_moderate', 'abuse_severe',
            'threat', 'hate', 'politics', 'irrelevant', 'self-promo',
        ];
        $category = strtolower((string) ($data['category'] ?? 'normal'));
        if (!in_array($category, $categories, true)) {
            $category = 'normal';
        }

        $severities = ['low', 'medium', 'high'];
        $severity = strtolower((string) ($data['severity'] ?? 'low'));
        if (!in_array($severity, $severities, true)) {
            $severity = 'low';
        }

        $threatTypes = ['none', 'joke', 'genuine', 'ambiguous'];
        $threatType = strtolower((string) ($data['threat_type'] ?? 'none'));
        if (!in_array($threatType, $threatTypes, true)) {
            $threatType = 'none';
        }

        $confidence = (float) ($data['confidence'] ?? 0);
        $confidence = max(0, min(1, $confidence));

        return [
            'decision' => $decision,
            'category' => $category,
            'severity' => $severity,
            'threat_type' => $threatType,
            'on_topic' => self::boolValue($data['on_topic'] ?? true),
            'faithful' => self::boolValue($data['faithful'] ?? true),
            'confidence' => $confidence,
            'reason' => trim((string) ($data['reason'] ?? '')),
        ];
    }

    /**
     * 兼容 bool 和 "true"/"false" 字符串。
     *
     * @param mixed $value
     * @return bool
     */
    private static function boolValue($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * 从文本中提取第一个 JSON 对象。
     *
     * @param string $content
     * @return string
     */
    public static function extractJson($content)
    {
        $content = trim((string) $content);

        // 去掉 ```json ... ``` 代码块。
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return '';
        }

        return substr($content, $start, $end - $start + 1);
    }
}
