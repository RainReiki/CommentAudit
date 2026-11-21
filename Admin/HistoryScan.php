<?php

namespace TypechoPlugin\CommentAudit\Admin;

use Typecho\Common;
use Typecho\Db;
use TypechoPlugin\CommentAudit\Engine;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class HistoryScan
{
    public const SCOPES = ['approved', 'waiting', 'spam', 'all'];

    public static function batch(string $scope, bool $withAi, int $after, ?int $through, array $settings): array
    {
        $db = Db::get();
        $query = $db->select('table.comments.*', ['table.contents.title' => 'article_title'])
            ->from('table.comments')
            ->join('table.contents', 'table.contents.cid = table.comments.cid', Db::LEFT_JOIN)
            ->where('table.comments.type = ?', 'comment');
        if ($withAi) {
            $query->select('table.comments.*', [
                'table.contents.title' => 'article_title',
                'table.contents.text' => 'article_text',
            ]);
        }
        if ($scope === 'all') {
            $query->where('table.comments.status IN ?', ['approved', 'waiting', 'spam']);
        } else {
            $query->where('table.comments.status = ?', $scope);
        }

        if ($through === null) {
            $latest = $db->fetchRow((clone $query)->select('table.comments.coid')
                ->order('table.comments.coid', Db::SORT_DESC)->limit(1));
            $through = (int) ($latest['coid'] ?? 0);
        }

        // Only one potentially slow AI call per request.
        $limit = $withAi ? 1 : 40;
        $comments = $db->fetchAll($query->where('table.comments.coid > ?', $after)
            ->where('table.comments.coid <= ?', $through)->order('table.comments.coid', Db::SORT_ASC)->limit($limit + 1));

        $pauseReason = null;
        $results = [];
        foreach (array_slice($comments, 0, $limit) as $comment) {
            $articleTitle = (string) ($comment['article_title'] ?? '');
            $articleText = trim(strip_tags((string) ($comment['article_text'] ?? '')));
            $result = Engine::preview($comment, [
                'title' => $articleTitle,
                'content' => $articleText,
                'excerpt' => Common::subStr($articleText, 0, 200, ''),
            ], $settings, $withAi);

            if (!empty($result['ai']['error'])) {
                // Leave the cursor before the failed comment so resuming retries it.
                $pauseReason = $result['ai']['diagnostic'] ?? $result['reason'];
                break;
            }

            $after = (int) $comment['coid'];
            $results[] = [
                'id' => $after,
                'articleId' => (int) $comment['cid'],
                'article' => $articleTitle,
                'author' => (string) ($comment['author'] ?? ''),
                'excerpt' => Common::subStr(trim(strip_tags((string) ($comment['text'] ?? ''))), 0, 180),
                'status' => $comment['status'],
                'suggestion' => $result['decision'],
                'reason' => $result['reason'],
                'aiReason' => Common::subStr((string) ($result['ai']['reason'] ?? ''), 0, 160),
            ];
        }

        return [
            'through' => $through,
            'after' => $after,
            'done' => $pauseReason === null && count($comments) <= $limit,
            'pauseReason' => $pauseReason,
            'results' => $results,
        ];
    }
}
