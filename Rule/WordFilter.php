<?php

namespace TypechoPlugin\CommentAudit\Rule;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 明确违禁词与疑似词检测。
 */
class WordFilter
{
    /**
     * 每行一个词，去空行、去重。
     *
     * @param string $raw
     * @return array
     */
    public static function lines($raw)
    {
        $items = preg_split('/\r\n|\r|\n/', (string) $raw);
        $result = [];

        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $result[$item] = $item;
            }
        }

        return array_values($result);
    }

    /**
     * 不区分大小写的子字符串匹配。
     *
     * @param array $fields
     * @param array $words
     * @return string|null
     */
    public static function find(array $fields, array $words)
    {
        foreach ($fields as $field) {
            $haystack = self::lower((string) $field);
            if ($haystack === '') {
                continue;
            }

            foreach ($words as $word) {
                $needle = self::lower(trim((string) $word));
                if ($needle === '') {
                    continue;
                }

                $position = function_exists('mb_strpos')
                    ? mb_strpos($haystack, $needle)
                    : strpos($haystack, $needle);

                if ($position !== false) {
                    return (string) $word;
                }
            }
        }

        return null;
    }

    /**
     * 根据作用域收集字段。
     *
     * @param array $comment
     * @param array $settings
     * @return array
     */
    public static function scopedFields(array $comment, array $settings)
    {
        $scopes = is_array($settings['wordScopes'] ?? null)
            ? $settings['wordScopes']
            : ['text', 'author', 'mail'];

        $fields = [];
        if (in_array('text', $scopes, true)) {
            $fields[] = self::normalize($comment['text'] ?? '');
        }
        if (in_array('author', $scopes, true)) {
            $fields[] = (string) ($comment['author'] ?? '');
        }
        if (in_array('mail', $scopes, true)) {
            $fields[] = (string) ($comment['mail'] ?? '');
        }
        if (in_array('url', $scopes, true)) {
            $fields[] = (string) ($comment['url'] ?? '');
        }

        return $fields;
    }

    /**
     * 明确违禁词。
     *
     * @param array $comment
     * @param array $settings
     * @return string|null
     */
    public static function matchBlock(array $comment, array $settings)
    {
        $words = self::lines($settings['blockWords'] ?? '');
        if (empty($words)) {
            return null;
        }

        return self::find(self::scopedFields($comment, $settings), $words);
    }

    /**
     * 疑似敏感词。
     *
     * @param array $comment
     * @param array $settings
     * @return string|null
     */
    public static function matchSuspect(array $comment, array $settings)
    {
        $words = self::lines($settings['suspectWords'] ?? '');
        if (empty($words)) {
            return null;
        }

        return self::find(self::scopedFields($comment, $settings), $words);
    }

    /**
     * 清理评论正文用于匹配和 AI 输入。
     *
     * @param string $text
     * @return string
     */
    public static function normalize($text)
    {
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\r\n]+/u', ' ', $text);

        return trim((string) $text);
    }

    /**
     * @param string $value
     * @return string
     */
    private static function lower($value)
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower((string) $value, 'UTF-8')
            : strtolower((string) $value);
    }
}
