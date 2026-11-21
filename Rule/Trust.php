<?php

namespace TypechoPlugin\CommentAudit\Rule;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 信誉等级与类别风险。
 */
class Trust
{
    /**
     * 当前等级对应的 block 阈值。
     *
     * @param string $level
     * @param array $settings
     * @return float
     */
    public static function threshold($level, array $settings)
    {
        if ($level === 'trusted' || $level === 'whitelist') {
            return (float) ($settings['thresholdTrusted'] ?? 0.9);
        }

        if ($level === 'normal') {
            return (float) ($settings['thresholdNormal'] ?? 0.8);
        }

        return (float) ($settings['thresholdNew'] ?? 0.7);
    }

    /**
     * 高风险类别。
     *
     * @param string $category
     * @return bool
     */
    public static function isHighRisk($category)
    {
        return in_array((string) $category, [
            'ad',
            'fraud',
            'porn',
            'illegal',
            'abuse_severe',
            'threat',
            'hate',
        ], true);
    }

    /**
     * 低风险类别。
     *
     * @param string $category
     * @return bool
     */
    public static function isLowRisk($category)
    {
        return in_array((string) $category, [
            'irrelevant',
            'self-promo',
            'abuse_mild',
            'normal',
        ], true);
    }
}
