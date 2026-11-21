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
