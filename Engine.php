<?php

namespace TypechoPlugin\CommentAudit;

use TypechoPlugin\CommentAudit\Ai\DeepSeekClient;
use TypechoPlugin\CommentAudit\Rule\Blacklist;
use TypechoPlugin\CommentAudit\Rule\LocalPrecheck;
use TypechoPlugin\CommentAudit\Rule\Trust;
use TypechoPlugin\CommentAudit\Rule\WordFilter;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 审核决策引擎。
 *
 * 顺序：本地硬规则 → 本地软标记 → DeepSeek → 信誉分级。
 */
class Engine
{
    /**
     * @param array $comment
     * @param array $article
     * @param array $settings
     * @return array
     */
    public static function decide(array $comment, array $article, array $settings)
    {
        $rawText = (string) ($comment['text'] ?? '');
        $text = WordFilter::normalize($rawText);
        $author = trim((string) ($comment['author'] ?? ''));
        $mail = trim((string) ($comment['mail'] ?? ''));
        $url = trim((string) ($comment['url'] ?? ''));
        $ip = trim((string) ($comment['ip'] ?? ''));
        $cid = (int) ($comment['cid'] ?? 0);

        // 保留原始正文用于 URL 提取；词表匹配内部会自行 strip_tags。
        $comment['author'] = $author;
        $comment['mail'] = $mail;
        $comment['url'] = $url;
        $comment['ip'] = $ip;
        $comment['cid'] = $cid;

        $reputation = Repository::reputation($comment);
        $trustLevel = (string) $reputation['computed_level'];

        // 1. 本地硬规则
        if ($ip !== '' && Blacklist::matchIp($ip, $settings['ipBlacklist'] ?? '')) {
            return self::reject('local_ip_blacklist', $trustLevel);
        }

        if ($mail !== '' && Blacklist::matchEmail($mail, $settings['emailBlacklist'] ?? '')) {
            return self::reject('local_email_blacklist', $trustLevel);
        }

        if (Blacklist::matchUrl($rawText, $url, $settings['urlBlacklist'] ?? '')) {
            return self::reject('local_url_blacklist', $trustLevel);
        }

        if (WordFilter::matchBlock($comment, $settings) !== null) {
            return self::reject('local_block_word', $trustLevel);
        }

        // 2. 本地软标记
        $flags = LocalPrecheck::flags($comment, $settings, $trustLevel);

        $ipWindow = max(60, (int) ($settings['ipRateWindow'] ?? 600));
        $ipLimit = max(0, (int) ($settings['ipRateLimit'] ?? 5));
        $ipRecent = Repository::recentIpCount($ip, $ipWindow);
        if ($ipLimit > 0 && $ipRecent >= $ipLimit) {
            return self::waiting('local_rate_limit', $trustLevel, [], $flags);
        }

        $duplicates = Repository::duplicateCount($cid, $rawText, 30);
        if ($duplicates > 0) {
            return self::reject('local_duplicate', $trustLevel, [], $flags);
        }

        // 3. DeepSeek
        $features = [
            'trust_level' => $trustLevel,
            'approved_count' => (int) ($reputation['approved_count'] ?? 0),
            'spam_count' => (int) ($reputation['spam_count'] ?? 0),
            'last_spam_at' => (int) ($reputation['last_spam_at'] ?? 0),
            'link_count' => LocalPrecheck::linkCount($rawText),
            'comment_length' => function_exists('mb_strlen')
                ? mb_strlen($text, 'UTF-8')
                : strlen($text),
            'ip_recent_count' => $ipRecent,
            'suspect_word' => WordFilter::matchSuspect($comment, $settings),
            'flags' => $flags,
        ];

        $aiComment = $comment;
        $aiComment['text'] = $text;
        $ai = DeepSeekClient::audit($aiComment, $article, $features, $settings);

        // 4. 决策合并
        return self::merge($ai, $trustLevel, $comment, $settings, $flags);
    }

    /**
     * 合并 AI 结果与信誉规则。
     *
     * @param array $ai
     * @param string $trustLevel
     * @param array $comment
     * @param array $settings
     * @param array $flags
     * @return array
     */
    private static function merge(array $ai, $trustLevel, array $comment, array $settings, array $flags)
    {
        $decision = (string) ($ai['decision'] ?? 'review');
        $category = (string) ($ai['category'] ?? 'normal');
        $severity = (string) ($ai['severity'] ?? 'low');
        $confidence = (float) ($ai['confidence'] ?? 0);
        $threatType = (string) ($ai['threat_type'] ?? 'none');
        $onTopic = !empty($ai['on_topic']);
        $faithful = !empty($ai['faithful']);
        $threshold = Trust::threshold($trustLevel, $settings);

        if ($ai['error'] ?? false) {
            $errorReason = (string) ($ai['reason'] ?? 'ai_error');
            if (!in_array($errorReason, ['ai_error', 'ai_missing_key', 'ai_circuit_open'], true)) {
                $errorReason = 'ai_error';
            }
            return self::waiting($errorReason, $trustLevel, $ai, $flags);
        }

        // 中度辱骂：第一次待审；24h 第 3 次拒绝；叠加威胁/仇恨/严重辱骂拒绝。
        if ($category === 'abuse_moderate') {
            if ($threatType === 'genuine') {
                return self::reject('abuse_moderate_threat', $trustLevel, $ai, $flags);
            }

            // 历史计数不包含当前这条；当前是第 3 条时拒绝。
            $count = Repository::moderateAbuseCount($comment);
            if ($count + 1 >= 3) {
                return self::reject('abuse_moderate_repeat', $trustLevel, $ai, $flags);
            }

            return self::waiting('abuse_moderate_first', $trustLevel, $ai, $flags);
        }

        if ($category === 'abuse_severe') {
            return self::reject('abuse_severe', $trustLevel, $ai, $flags);
        }

        // 威胁：看语境。
        if ($category === 'threat' || $threatType === 'genuine') {
            if ($threatType === 'genuine') {
                return self::reject('threat_genuine', $trustLevel, $ai, $flags);
            }

            if ($threatType === 'joke') {
                if ($trustLevel === 'new') {
                    return self::waiting('new_user_first', $trustLevel, $ai, $flags);
                }
                return self::approved('threat_joke', $trustLevel, $ai, $flags);
            }

            return self::waiting('threat_ambiguous', $trustLevel, $ai, $flags);
        }

        // 仇恨：明显拒绝；与文章无关拒绝；与文章相关按置信度和信誉。
        if ($category === 'hate') {
            if ($confidence >= 0.8 || $severity === 'high') {
                return self::reject('hate_obvious', $trustLevel, $ai, $flags);
            }

            if (!$onTopic) {
                return self::reject('hate_off_topic', $trustLevel, $ai, $flags);
            }

            if (!$faithful) {
                return self::waiting('hate_suspect_on_topic', $trustLevel, $ai, $flags);
            }

            if ($decision === 'review') {
                return self::waiting('ai_review', $trustLevel, $ai, $flags);
            }

            if ($decision === 'block') {
                return self::waiting('hate_suspect_on_topic', $trustLevel, $ai, $flags);
            }

            if ($confidence >= 0.5) {
                if ($trustLevel === 'trusted' || $trustLevel === 'whitelist') {
                    return self::approved('hate_suspect_on_topic', $trustLevel, $ai, $flags);
                }
                return self::waiting('hate_suspect_on_topic', $trustLevel, $ai, $flags);
            }

            if ($trustLevel === 'new') {
                return self::waiting('hate_low_on_topic', $trustLevel, $ai, $flags);
            }

            return self::approved('hate_low_on_topic', $trustLevel, $ai, $flags);
        }

        // 轻度负面：AI 判定 pass 时直接放行；block 时按阈值和信誉处理。
        if ($category === 'abuse_mild') {
            if ($decision === 'pass') {
                return self::approved('abuse_mild', $trustLevel, $ai, $flags);
            }

            if ($decision === 'block' && $confidence < $threshold
                && ($trustLevel === 'trusted' || $trustLevel === 'whitelist')) {
                return self::approved('abuse_mild', $trustLevel, $ai, $flags);
            }

            return self::waiting('abuse_mild', $trustLevel, $ai, $flags);
        }

        // 通用 AI 结果。
        if ($decision === 'review') {
            return self::waiting('ai_review', $trustLevel, $ai, $flags);
        }

        if ($decision === 'block') {
            if ($confidence >= $threshold) {
                return self::reject('ai_block_high', $trustLevel, $ai, $flags);
            }

            if (Trust::isHighRisk($category)) {
                return self::waiting('ai_block_low_high_risk', $trustLevel, $ai, $flags);
            }

            if (($trustLevel === 'trusted' || $trustLevel === 'whitelist') && Trust::isLowRisk($category)) {
                return self::approved('ai_block_low_low_risk', $trustLevel, $ai, $flags);
            }

            return self::waiting('ai_block_low_low_risk', $trustLevel, $ai, $flags);
        }

        if ($decision === 'pass') {
            if ($category === 'politics' && (!$onTopic || !$faithful)) {
                return self::waiting('off_topic', $trustLevel, $ai, $flags);
            }

            // 综合判断后，AI 明确 pass 即放行，不再按新用户首条额外拦截。
            return self::approved('ai_pass', $trustLevel, $ai, $flags);
        }

        return self::waiting('ai_unknown', $trustLevel, $ai, $flags);
    }

    /**
     * @param string $reason
     * @param string $trustLevel
     * @param array $ai
     * @param array $flags
     * @return array
     */
    private static function approved($reason, $trustLevel, array $ai = [], array $flags = [])
    {
        return self::result('approved', $reason, $trustLevel, $ai, $flags);
    }

    /**
     * @param string $reason
     * @param string $trustLevel
     * @param array $ai
     * @param array $flags
     * @return array
     */
    private static function waiting($reason, $trustLevel, array $ai = [], array $flags = [])
    {
        return self::result('waiting', $reason, $trustLevel, $ai, $flags);
    }

    /**
     * @param string $reason
     * @param string $trustLevel
     * @param array $ai
     * @param array $flags
     * @return array
     */
    private static function reject($reason, $trustLevel, array $ai = [], array $flags = [])
    {
        return self::result('rejected', $reason, $trustLevel, $ai, $flags);
    }

    /**
     * @param string $decision
     * @param string $reason
     * @param string $trustLevel
     * @param array $ai
     * @param array $flags
     * @return array
     */
    private static function result($decision, $reason, $trustLevel, array $ai = [], array $flags = [])
    {
        return [
            'decision' => $decision,
            'reason_code' => $reason,
            'reason' => Logger::reasonLabel($reason),
            'trust_level' => $trustLevel,
            'ai' => $ai,
            'flags' => $flags,
        ];
    }
}
