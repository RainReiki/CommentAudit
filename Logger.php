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
            'ai_reason' => self::storedReason($ai, (string) ($result['reason'] ?? '')),
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
     * Keep structured identity evidence in the existing text field so old installations need no schema migration.
     */
    private static function storedReason(array $ai, string $fallback): string
    {
        $reason = (string) ($ai['reason'] ?? $fallback);
        if (!is_array($ai['identity'] ?? null) && !is_array($ai['conversation'] ?? null)) {
            return $reason;
        }
        return json_encode([
            '_commentaudit' => 'identity-v1',
            'reason' => $reason,
            'identity' => $ai['identity'] ?? null,
            'conversation' => $ai['conversation'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function expandLog(array $row): array
    {
        $details = json_decode((string) ($row['ai_reason'] ?? ''), true);
        $row['identity'] = null;
        $row['conversation'] = null;
        if (is_array($details) && ($details['_commentaudit'] ?? '') === 'identity-v1'
            && is_string($details['reason'] ?? null)) {
            $row['ai_reason'] = $details['reason'];
            $row['identity'] = is_array($details['identity'] ?? null) ? $details['identity'] : null;
            $row['conversation'] = is_array($details['conversation'] ?? null) ? $details['conversation'] : null;
        }
        return $row;
    }

    public static function identitySummary(?array $identity): string
    {
        if ($identity === null) {
            return _t('未记录');
        }
        $risks = ['low' => _t('低'), 'medium' => _t('中'), 'high' => _t('高')];
        $categories = [
            'none' => _t('未发现明确疑点'), 'name_confusion' => _t('昵称混淆'),
            'profile_conflict' => _t('身份组合不一致'), 'possible_impersonation' => _t('疑似冒用身份'),
        ];
        $summary = ($risks[$identity['risk'] ?? ''] ?? _t('未知')) . ' · '
            . ($categories[$identity['category'] ?? ''] ?? _t('需复核'));

        if (isset($identity['score'])) {
            $summary .= _t('（%d 分）', (int) $identity['score']);
        }

        return $summary;
    }

    public static function conversationSummary(?array $context): string
    {
        if ($context === null) {
            return _t('旧记录未保存对话上下文');
        }
        if (empty($context['is_reply'])) {
            return _t('一级评论 · 参考文章');
        }
        $count = count($context['ancestors'] ?? []);
        return _t('回复评论 · 父评论 #%d · 读取 %d 条上级对话', (int) ($context['parent_id'] ?? 0), $count)
            . (!empty($context['incomplete']) ? _t('（上下文不完整）') : '');
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
            'history_suspect_word' => '命中疑似敏感词',
            'history_link_limit' => '链接数量超过上限',
            'history_local_pass' => '未命中本地规则',
            'history_moderate_abuse' => '疑似中度辱骂',
            'identity_review' => '身份信息存在疑点，转人工审核',
            'identity_local_risk' => '昵称、邮箱或网址存在风险组合，转人工审核',
            'identity_high_risk' => '昵称、邮箱或网址风险很高，直接拒绝',
        ];

        return $map[$code] ?? $code;
    }
}
