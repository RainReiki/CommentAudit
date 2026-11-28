<?php

namespace TypechoPlugin\CommentAudit\Rule;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 本地软标记：链接数量、新用户带链接等。
 */
class LocalPrecheck
{
    /** Unicode code points, independent of mbstring. Markup is not visible text. */
    public static function englishRule(string $text, array $settings): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\p{Z}]+/u', '', $text);
        $action = $settings['englishRuleAction'] ?? 'off';
        $threshold = (float) ($settings['englishRatioThreshold'] ?? 80);
        if (!is_finite($threshold) || $threshold < 1 || $threshold > 100) {
            $threshold = 80;
        }
        $total = is_string($text) ? preg_match_all('/./us', $text) : 0;
        $english = $total ? preg_match_all('/[a-z]/i', $text) : 0;
        $ratio = $total ? $english / $total * 100 : 0.0;
        $matched = $total > 0 && $english > 0 && !preg_match('/\p{Han}/u', $text)
            && $ratio >= $threshold && in_array($action, ['reject', 'ai'], true);
        return ['matched' => $matched, 'action' => $action, 'ratio' => $ratio];
    }

    /**
     * 统计正文中的链接数量。
     *
     * @param string $text
     * @return int
     */
    public static function linkCount($text)
    {
        return count(Blacklist::extractUrls(WordFilter::normalize($text)));
    }

    /**
     * 构建软标记。
     *
     * @param array $comment
     * @param array $settings
     * @param string $trustLevel
     * @return array
     */
    public static function flags(array $comment, array $settings, $trustLevel)
    {
        $flags = [];
        $rawText = (string) ($comment['text'] ?? '');
        $linkCount = self::linkCount($rawText);
        $linkLimit = max(0, (int) ($settings['linkLimit'] ?? 3));

        if ($linkCount > $linkLimit && $linkLimit >= 0) {
            $flags[] = 'too_many_links';
        }

        if ($trustLevel === 'new' && $linkCount > 0) {
            $flags[] = 'new_user_with_link';
        }

        if (WordFilter::matchSuspect($comment, $settings) !== null) {
            $flags[] = 'suspect_word';
        }

        return $flags;
    }
}
