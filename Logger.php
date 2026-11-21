<?php

namespace TypechoPlugin\CommentAudit;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 审核日志写入与原因码。
 */
class Logger
{
    /**
     * 写入一条审核日志。
     *
     * @param array $comment
     * @param array $result
     * @param int $coid
     */
    public static function write(array $comment, array $result, $coid = 0)
    {
        $db = Db::get();
        $ai = is_array($result['ai'] ?? null) ? $result['ai'] : [];

        $db->query($db->insert('table.commentaudit_logs')->rows([
            'coid' => (int) $coid,
            'cid' => (int) ($comment['cid'] ?? 0),
            'author' => (string) ($comment['author'] ?? ''),
            'mail' => (string) ($comment['mail'] ?? ''),
            'ip' => (string) ($comment['ip'] ?? ''),
            'text' => (string) ($comment['text'] ?? ''),
            'decision' => (string) ($result['decision'] ?? 'waiting'),
            'reason_code' => (string) ($result['reason_code'] ?? ''),
            'trust_level' => (string) ($result['trust_level'] ?? 'new'),
            'ai_decision' => isset($ai['decision']) ? (string) $ai['decision'] : null,
            'ai_category' => isset($ai['category']) ? (string) $ai['category'] : null,
            'ai_severity' => isset($ai['severity']) ? (string) $ai['severity'] : null,
            'ai_confidence' => isset($ai['confidence']) ? (float) $ai['confidence'] : 0,
            'ai_reason' => isset($ai['reason']) ? (string) $ai['reason'] : (string) ($result['reason'] ?? ''),
            'on_topic' => !empty($ai['on_topic']) ? 1 : 0,
            'faithful' => !empty($ai['faithful']) ? 1 : 0,
            'threat_type' => isset($ai['threat_type']) ? (string) $ai['threat_type'] : null,
            'latency_ms' => isset($ai['latency_ms']) ? (int) $ai['latency_ms'] : 0,
            'tokens' => isset($ai['tokens']) ? (int) $ai['tokens'] : 0,
            'manual' => 0,
            'manual_by' => 0,
            'created' => time(),
        ]));
    }

    /**
     * 标记人工改判。
     *
     * @param int $coid
     * @param string $decision
     * @param int $uid
     */
    public static function markManual($coid, $decision, $uid)
    {
        Repository::markManual($coid, $decision, $uid);
    }

    /**
     * 原因码中文说明。
     *
     * @param string $code
     * @return string
     */
    public static function reasonLabel($code)
    {
        $map = [
            'local_ip_blacklist' => 'IP 黑名单',
            'local_email_blacklist' => '邮箱黑名单',
            'local_url_blacklist' => '网址黑名单',
            'local_block_word' => '明确违禁词',
            'local_rate_limit' => '同一 IP 评论过于频繁',
            'local_duplicate' => '重复评论',
            'engine_error' => '审核引擎异常',
            'ai_block_high' => 'AI 高置信拒绝',
            'ai_block_low_high_risk' => 'AI 低置信 + 高风险类别',
            'ai_block_low_low_risk' => 'AI 低置信 + 低风险类别',
            'ai_pass' => 'AI 通过',
            'ai_review' => 'AI 转待审',
            'ai_error' => 'AI 审核失败',
            'ai_missing_key' => '未配置 DeepSeek API Key',
            'ai_circuit_open' => 'AI 熔断中',
            'ai_unknown' => 'AI 结果无法判断',
            'abuse_mild' => '轻度负面评论',
            'abuse_moderate_first' => '中度辱骂第一次',
            'abuse_moderate_repeat' => '中度辱骂 24 小时内第 3 次',
            'abuse_moderate_threat' => '中度辱骂并含威胁',
            'abuse_severe' => '严重辱骂',
            'threat_genuine' => '真实威胁',
            'threat_ambiguous' => '威胁语境不明确',
            'threat_joke' => '威胁为玩笑',
            'hate_obvious' => '明显仇恨言论',
            'hate_off_topic' => '仇恨言论且与文章无关',
            'hate_suspect_on_topic' => '疑似仇恨且与文章相关',
            'hate_suspect_off_topic' => '疑似仇恨且与文章无关',
            'hate_low_on_topic' => '低置信仇恨且与文章相关',
            'hate_low_off_topic' => '低置信仇恨且与文章无关',
            'new_user_first' => '新用户首条评论',
            'off_topic' => '与文章无关',
        ];

        return $map[$code] ?? $code;
    }
}
