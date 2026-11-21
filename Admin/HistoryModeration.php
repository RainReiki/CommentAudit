<?php

namespace TypechoPlugin\CommentAudit\Admin;

use Typecho\Db;
use Typecho\Widget\Terminal;
use Widget\Comments\Edit;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class HistoryModeration
{
    public const BATCH_SIZE = 50;

    private const METHODS = [
        'approved' => 'approvedComment',
        'waiting' => 'waitingComment',
        'spam' => 'spamComment',
        'delete' => 'deleteComment',
    ];

    public static function apply(array $changes): array
    {
        $byId = [];
        foreach ($changes as $change) {
            $id = $change['id'] ?? null;
            $from = $change['from'] ?? null;
            $to = $change['to'] ?? null;
            if (!is_int($id) || $id < 1 || isset($byId[$id])
                || !is_string($from) || !isset(self::METHODS[$from]) || $from === 'delete'
                || !is_string($to) || !isset(self::METHODS[$to])) {
                throw new \InvalidArgumentException('Invalid historical moderation request');
            }
            $byId[$id] = ['from' => $from, 'to' => $to];
        }

        $before = self::statuses(array_keys($byId));
        $groups = [];
        foreach ($byId as $id => $change) {
            if (($before[$id] ?? null) === $change['from'] && $change['from'] !== $change['to']) {
                $groups[$change['to']][] = $id;
            }
        }

        foreach ($groups as $status => $ids) {
            $method = self::METHODS[$status];
            // Let Typecho update counts and run its moderation hooks, with the redirect sandboxed.
            Edit::allocWithAlias('commentaudit-history', null, ['coid' => $ids], static function (Edit $edit) use ($method): void {
                try {
                    $edit->$method();
                } catch (Terminal $e) {
                    // Native moderation redirects after applying the change.
                } catch (\Throwable $e) {
                    error_log('[CommentAudit] Typecho moderation returned an error: ' . get_class($e));
                }
            });
        }

        $after = $groups ? self::statuses(array_keys($byId)) : $before;
        $results = [];
        foreach ($byId as $id => $change) {
            $status = $after[$id] ?? null;
            if ($change['to'] === 'delete' && $status === null && ($before[$id] ?? null) === $change['from']) {
                $outcome = 'updated';
            } elseif ($status === $change['to']) {
                $outcome = ($before[$id] ?? null) === $change['to'] ? 'unchanged' : 'updated';
            } elseif ($status === null || !isset(self::METHODS[$status])) {
                $outcome = 'missing';
            } elseif ($status !== $change['from']) {
                $outcome = 'stale';
            } else {
                $outcome = 'failed';
            }
            $results[] = ['id' => $id, 'status' => $status, 'outcome' => $outcome];
        }
        return $results;
    }

    private static function statuses(array $ids): array
    {
        $db = Db::get();
        $rows = $db->fetchAll($db->select('coid', 'status')->from('table.comments')
            ->where('type = ?', 'comment')->where('coid IN ?', $ids));
        return array_column($rows, 'status', 'coid');
    }
}
