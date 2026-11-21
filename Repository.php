<?php

namespace TypechoPlugin\CommentAudit;

use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 审核日志、信誉、AI 缓存和运行状态的数据访问层。
 */
class Repository
{
    /**
     * @return Db
     */
    private static function db()
    {
        return Db::get();
    }

    /**
     * 生成评论者身份。
     *
     * @param array $comment
     * @return array{hash:string,type:string,value:string}
     */
    public static function identity(array $comment)
    {
        $mail = strtolower(trim((string) ($comment['mail'] ?? '')));
        if ($mail !== '') {
            return [
                'hash' => hash('sha256', 'mail:' . $mail),
                'type' => 'mail',
                'value' => $mail,
            ];
        }

        $author = strtolower(trim((string) ($comment['author'] ?? '')));
        $ip = trim((string) ($comment['ip'] ?? ''));
        $value = $author . '|' . $ip;

        return [
            'hash' => hash('sha256', 'author_ip:' . $value),
            'type' => 'author_ip',
            'value' => $value,
        ];
    }

    /**
     * 获取信誉行，没有则返回新用户默认值。
     *
     * @param array $comment
     * @return array
     */
    public static function reputation(array $comment)
    {
        $identity = self::identity($comment);
        $db = self::db();

        $row = $db->fetchRow(
            $db->select()->from('table.commentaudit_reputation')
                ->where('identity_hash = ?', $identity['hash'])
                ->limit(1)
        );

        if (!$row) {
            $row = [
                'identity_hash' => $identity['hash'],
                'identity_type' => $identity['type'],
                'identity_value' => $identity['value'],
                'trust_level' => 'new',
                'approved_count' => 0,
                'spam_count' => 0,
                'waiting_count' => 0,
                'first_seen' => 0,
                'last_seen' => 0,
                'last_spam_at' => 0,
                'moderate_abuse_24h' => 0,
                'last_moderate_abuse_at' => 0,
                'updated' => 0,
            ];
        }

        $row['computed_level'] = self::computeTrustLevel($row);
        $row['identity'] = $identity;

        return $row;
    }

    /**
     * 信誉等级。
     *
     * @param array $reputation
     * @return string
     */
    public static function computeTrustLevel(array $reputation)
    {
        if (($reputation['trust_level'] ?? '') === 'whitelist') {
            return 'whitelist';
        }

        $now = time();
        $lastSpam = (int) ($reputation['last_spam_at'] ?? 0);

        // 90 天内有 spam 记录，直接回到新用户。
        if ($lastSpam > 0 && $now - $lastSpam < 90 * 86400) {
            return 'new';
        }

        $approved = (int) ($reputation['approved_count'] ?? 0);
        if ($approved >= 3) {
            return 'trusted';
        }
        if ($approved >= 1) {
            return 'normal';
        }

        return 'new';
    }

    /**
     * 当前评论的信任等级。
     *
     * @param array $comment
     * @return string
     */
    public static function trustLevel(array $comment)
    {
        return self::reputation($comment)['computed_level'];
    }

    /**
     * 24 小时内中度辱骂次数。
     *
     * @param array $comment
     * @return int
     */
    public static function moderateAbuseCount(array $comment)
    {
        $row = self::reputation($comment);
        $last = (int) ($row['last_moderate_abuse_at'] ?? 0);

        if ($last <= 0 || time() - $last >= 86400) {
            return 0;
        }

        return (int) ($row['moderate_abuse_24h'] ?? 0);
    }

    /**
     * 同一 IP 在时间窗口内的评论数。
     *
     * @param string $ip
     * @param int $window
     * @return int
     */
    public static function recentIpCount($ip, $window = 600)
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return 0;
        }

        $db = self::db();
        $row = $db->fetchRow(
            $db->select(['COUNT(coid)' => 'num'])->from('table.comments')
                ->where('ip = ?', $ip)
                ->where('created > ?', time() - max(60, (int) $window))
        );

        return (int) ($row['num'] ?? 0);
    }

    /**
     * 同一文章下重复内容数量。
     *
     * @param int $cid
     * @param string $text
     * @param int $days
     * @return int
     */
    public static function duplicateCount($cid, $text, $days = 30)
    {
        $cid = (int) $cid;
        $text = trim((string) $text);
        if ($cid <= 0 || $text === '') {
            return 0;
        }

        $db = self::db();
        $row = $db->fetchRow(
            $db->select(['COUNT(coid)' => 'num'])->from('table.comments')
                ->where('cid = ?', $cid)
                ->where('text = ?', $text)
                ->where('created > ?', time() - max(1, (int) $days) * 86400)
                ->where('status <> ?', 'spam')
        );

        return (int) ($row['num'] ?? 0);
    }

    /**
     * 记录一次评论结果，更新信誉。
     *
     * @param array $comment
     * @param string $status
     * @param array $result
     */
    public static function recordOutcome(array $comment, $status, array $result)
    {
        $db = self::db();
        $identity = self::identity($comment);
        $now = time();

        $row = $db->fetchRow(
            $db->select()->from('table.commentaudit_reputation')
                ->where('identity_hash = ?', $identity['hash'])
                ->limit(1)
        );

        $approved = (int) ($row['approved_count'] ?? 0);
        $spam = (int) ($row['spam_count'] ?? 0);
        $waiting = (int) ($row['waiting_count'] ?? 0);
        $lastSpam = (int) ($row['last_spam_at'] ?? 0);
        $abuseCount = (int) ($row['moderate_abuse_24h'] ?? 0);
        $lastAbuse = (int) ($row['last_moderate_abuse_at'] ?? 0);

        if ($status === 'approved') {
            $approved++;
        } elseif ($status === 'spam') {
            $spam++;
            $lastSpam = $now;
        } else {
            $waiting++;
        }

        if (($result['ai']['category'] ?? '') === 'abuse_moderate') {
            if ($lastAbuse <= 0 || $now - $lastAbuse >= 86400) {
                $abuseCount = 1;
            } else {
                $abuseCount++;
            }
            $lastAbuse = $now;
        }

        $payload = [
            'trust_level' => self::computeTrustLevel([
                'trust_level' => (string) ($row['trust_level'] ?? 'new'),
                'approved_count' => $approved,
                'spam_count' => $spam,
                'last_spam_at' => $lastSpam,
            ]),
            'approved_count' => $approved,
            'spam_count' => $spam,
            'waiting_count' => $waiting,
            'last_seen' => $now,
            'last_spam_at' => $lastSpam,
            'moderate_abuse_24h' => $abuseCount,
            'last_moderate_abuse_at' => $lastAbuse,
            'updated' => $now,
        ];

        if ($row) {
            $db->query(
                $db->update('table.commentaudit_reputation')
                    ->rows($payload)
                    ->where('identity_hash = ?', $identity['hash'])
            );
        } else {
            $payload['identity_hash'] = $identity['hash'];
            $payload['identity_type'] = $identity['type'];
            $payload['identity_value'] = $identity['value'];
            $payload['first_seen'] = $now;
            $db->query($db->insert('table.commentaudit_reputation')->rows($payload));
        }
    }

    /**
     * 管理员手动改状态后的信誉调整。
     *
     * @param array $comment
     * @param string $oldStatus
     * @param string $newStatus
     */
    public static function adjustForMark(array $comment, $oldStatus, $newStatus)
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        self::adjustCounts($comment, $oldStatus, $newStatus);
    }

    /**
     * 删除评论后的信誉回退。
     *
     * @param array $comment
     */
    public static function adjustForDelete(array $comment)
    {
        $status = (string) ($comment['status'] ?? '');
        if ($status === '') {
            return;
        }

        self::adjustCounts($comment, $status, '');
    }

    /**
     * 调整信誉计数。
     *
     * @param array $comment
     * @param string $oldStatus
     * @param string $newStatus
     */
    private static function adjustCounts(array $comment, $oldStatus, $newStatus)
    {
        $db = self::db();
        $identity = self::identity($comment);

        $row = $db->fetchRow(
            $db->select()->from('table.commentaudit_reputation')
                ->where('identity_hash = ?', $identity['hash'])
                ->limit(1)
        );

        if (!$row) {
            return;
        }

        $approved = (int) ($row['approved_count'] ?? 0);
        $spam = (int) ($row['spam_count'] ?? 0);
        $waiting = (int) ($row['waiting_count'] ?? 0);
        $lastSpam = (int) ($row['last_spam_at'] ?? 0);

        foreach ([$oldStatus, $newStatus] as $index => $status) {
            $delta = $index === 0 ? -1 : 1;
            if ($status === 'approved') {
                $approved = max(0, $approved + $delta);
            } elseif ($status === 'spam') {
                $spam = max(0, $spam + $delta);
                if ($delta > 0) {
                    $lastSpam = time();
                }
            } elseif ($status === 'waiting') {
                $waiting = max(0, $waiting + $delta);
            }
        }

        $db->query(
            $db->update('table.commentaudit_reputation')
                ->rows([
                    'trust_level' => self::computeTrustLevel([
                        'trust_level' => (string) ($row['trust_level'] ?? 'new'),
                        'approved_count' => $approved,
                        'spam_count' => $spam,
                        'last_spam_at' => $lastSpam,
                    ]),
                    'approved_count' => $approved,
                    'spam_count' => $spam,
                    'waiting_count' => $waiting,
                    'last_spam_at' => $lastSpam,
                    'updated' => time(),
                ])
                ->where('identity_hash = ?', $identity['hash'])
        );
    }

    /**
     * 读取 AI 缓存。
     *
     * @param string $key
     * @return array|null
     */
    public static function getCache($key)
    {
        $db = self::db();
        $row = $db->fetchRow(
            $db->select()->from('table.commentaudit_ai_cache')
                ->where('cache_key = ?', $key)
                ->limit(1)
        );

        if (!$row || (int) $row['expires'] <= time()) {
            return null;
        }

        $data = json_decode((string) $row['response'], true);
        return is_array($data) ? $data : null;
    }

    /**
     * 写入 AI 缓存。
     *
     * @param string $key
     * @param string $model
     * @param string $promptVersion
     * @param array $result
     * @param int $ttlDays
     */
    public static function setCache($key, $model, $promptVersion, array $result, $ttlDays = 7)
    {
        $db = self::db();
        $now = time();
        $expires = $now + max(1, (int) $ttlDays) * 86400;
        $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $row = $db->fetchRow(
            $db->select('id')->from('table.commentaudit_ai_cache')
                ->where('cache_key = ?', $key)
                ->limit(1)
        );

        if ($row) {
            $db->query(
                $db->update('table.commentaudit_ai_cache')
                    ->rows([
                        'model' => $model,
                        'prompt_version' => $promptVersion,
                        'response' => $response,
                        'created' => $now,
                        'expires' => $expires,
                    ])
                    ->where('cache_key = ?', $key)
            );
        } else {
            $db->query($db->insert('table.commentaudit_ai_cache')->rows([
                'cache_key' => $key,
                'model' => $model,
                'prompt_version' => $promptVersion,
                'response' => $response,
                'created' => $now,
                'expires' => $expires,
            ]));
        }
    }

    /**
     * 清空 AI 缓存。
     */
    public static function clearCache()
    {
        $db = self::db();
        $db->query($db->delete('table.commentaudit_ai_cache'));
    }

    /**
     * 按保留天数清理审核日志。
     *
     * @param int $days
     */
    public static function pruneLogs($days = 90)
    {
        $days = max(1, (int) $days);
        $db = self::db();
        $db->query(
            $db->delete('table.commentaudit_logs')
                ->where('created < ?', time() - $days * 86400)
        );
    }

    /**
     * 读取运行状态。
     *
     * @param string $name
     * @return array
     */
    public static function getState($name)
    {
        $db = self::db();
        $row = $db->fetchRow(
            $db->select()->from('table.commentaudit_state')
                ->where('name = ?', $name)
                ->limit(1)
        );

        if (!$row) {
            return [];
        }

        $data = json_decode((string) $row['value'], true);
        return is_array($data) ? $data : [];
    }

    /**
     * 写入运行状态。
     *
     * @param string $name
     * @param array $value
     */
    public static function setState($name, array $value)
    {
        $db = self::db();
        $now = time();
        $data = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $row = $db->fetchRow(
            $db->select('name')->from('table.commentaudit_state')
                ->where('name = ?', $name)
                ->limit(1)
        );

        if ($row) {
            $db->query(
                $db->update('table.commentaudit_state')
                    ->rows(['value' => $data, 'updated' => $now])
                    ->where('name = ?', $name)
            );
        } else {
            $db->query($db->insert('table.commentaudit_state')->rows([
                'name' => $name,
                'value' => $data,
                'updated' => $now,
            ]));
        }
    }

    /**
     * 日志查询。
     *
     * @param int $limit
     * @param array $filters
     * @return array
     */
    public static function recentLogs($limit = 100, array $filters = [])
    {
        $db = self::db();
        $select = $db->select()->from('table.commentaudit_logs');

        if (!empty($filters['decision'])) {
            $select->where('decision = ?', $filters['decision']);
        }
        if (!empty($filters['reason_code'])) {
            $select->where('reason_code = ?', $filters['reason_code']);
        }
        if (!empty($filters['trust_level'])) {
            $select->where('trust_level = ?', $filters['trust_level']);
        }
        if (!empty($filters['keyword'])) {
            $select->where(
                'author LIKE ? OR mail LIKE ? OR ip LIKE ? OR text LIKE ? OR ai_reason LIKE ?',
                '%' . $filters['keyword'] . '%',
                '%' . $filters['keyword'] . '%',
                '%' . $filters['keyword'] . '%',
                '%' . $filters['keyword'] . '%',
                '%' . $filters['keyword'] . '%'
            );
        }

        $select->order('id', Db::SORT_DESC)->limit(max(1, (int) $limit));
        return $db->fetchAll($select);
    }

    /**
     * 日志详情。
     *
     * @param int $coid
     * @return array|null
     */
    public static function logByCoid($coid)
    {
        $db = self::db();
        $row = $db->fetchRow(
            $db->select()->from('table.commentaudit_logs')
                ->where('coid = ?', (int) $coid)
                ->order('id', Db::SORT_DESC)
                ->limit(1)
        );

        return $row ?: null;
    }

    /**
     * 批量查询日志，供评论列表增强使用。
     *
     * @param array $coids
     * @return array
     */
    public static function logsByCoids(array $coids)
    {
        $coids = array_values(array_filter(array_map('intval', $coids)));
        if (empty($coids)) {
            return [];
        }

        $db = self::db();
        $rows = $db->fetchAll(
            $db->select()->from('table.commentaudit_logs')
                ->where('coid IN (' . implode(',', $coids) . ')')
                ->order('id', Db::SORT_DESC)
        );

        $result = [];
        foreach ($rows as $row) {
            $coid = (int) $row['coid'];
            if (!isset($result[$coid])) {
                $result[$coid] = $row;
            }
        }

        return $result;
    }

    /**
     * 手动改判日志。
     *
     * @param int $coid
     * @param string $decision
     * @param int $uid
     */
    public static function markManual($coid, $decision, $uid)
    {
        $coid = (int) $coid;
        if ($coid <= 0) {
            return;
        }

        $db = self::db();
        $db->query(
            $db->update('table.commentaudit_logs')
                ->rows([
                    'manual' => 1,
                    'manual_by' => (int) $uid,
                    'decision' => (string) $decision,
                ])
                ->where('coid = ?', $coid)
        );
    }

    /**
     * 概览统计。
     *
     * @return array
     */
    public static function stats()
    {
        $db = self::db();
        $today = strtotime('today');

        $count = static function ($decision = null, $from = null) use ($db) {
            $select = $db->select(['COUNT(id)' => 'num'])
                ->from('table.commentaudit_logs');

            if ($decision !== null) {
                $select->where('decision = ?', $decision);
            }
            if ($from !== null) {
                $select->where('created >= ?', $from);
            }

            $row = $db->fetchObject($select);
            return $row && isset($row->num) ? (int) $row->num : 0;
        };

        $stats = [
            'approved' => $count('approved', $today),
            'waiting' => $count('waiting', $today),
            'rejected' => $count('rejected', $today),
            'spam' => $count('spam', $today),
            'total' => $count(null, $today),
            'all_total' => $count(),
            'today_start' => $today,
            'generated_at' => time(),
        ];

        return $stats;
    }

    /**
     * 信誉列表。
     *
     * @param string $keyword
     * @param int $limit
     * @return array
     */
    public static function recentReputations($keyword = '', $limit = 100)
    {
        $db = self::db();
        $select = $db->select()->from('table.commentaudit_reputation');

        if ($keyword !== '') {
            $select->where(
                'identity_value LIKE ?',
                '%' . $keyword . '%'
            );
        }

        $select->order('last_seen', Db::SORT_DESC)->limit(max(1, (int) $limit));
        return $db->fetchAll($select);
    }
}
