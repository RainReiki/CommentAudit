<?php

namespace TypechoPlugin\CommentAudit\Rule;

use Typecho\Common;
use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class ConversationContext
{
    /**
     * Follow only this reply's ancestors. Never include other branches or later replies.
     */
    public static function build(array $comment): array
    {
        $parent = max(0, (int) ($comment['parent'] ?? 0));
        $context = [
            'is_reply' => $parent > 0,
            'parent_id' => $parent,
            'ancestors' => [],
            'incomplete' => false,
        ];
        if ($parent === 0) {
            return $context;
        }

        $db = Db::get();
        $cid = (int) ($comment['cid'] ?? 0);
        $currentId = (int) ($comment['coid'] ?? 0);
        $seen = $currentId > 0 ? [$currentId => true] : [];
        while ($parent > 0 && count($context['ancestors']) < 6) {
            if (isset($seen[$parent])) {
                break;
            }
            $seen[$parent] = true;
            $query = $db->select('coid', 'cid', 'parent', 'author', 'text')->from('table.comments')
                ->where('coid = ?', $parent)->where('cid = ?', $cid)->where('type = ?', 'comment')->limit(1);
            if ($currentId > 0) {
                $query->where('coid < ?', $currentId);
            }
            $row = $db->fetchRow($query);
            if (!$row) {
                break;
            }
            $text = trim(strip_tags((string) ($row['text'] ?? '')));
            $limit = $context['ancestors'] ? 320 : 800;
            // Oldest-first order is applied below; direct parent keeps the largest text budget.
            $context['ancestors'][] = [
                'id' => (int) $row['coid'],
                'author' => Common::subStr((string) ($row['author'] ?? ''), 0, 60, ''),
                'text' => Common::subStr($text, 0, $limit, ''),
                'text_truncated' => Common::strLen($text) > $limit,
            ];
            $parent = max(0, (int) ($row['parent'] ?? 0));
        }
        $context['incomplete'] = $parent > 0;
        $context['ancestors'] = array_reverse($context['ancestors']);
        return $context;
    }
}
