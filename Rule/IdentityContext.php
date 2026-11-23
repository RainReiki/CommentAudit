<?php

namespace TypechoPlugin\CommentAudit\Rule;

use Typecho\Common;
use Typecho\Db;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Bounded, local context for AI identity checks. Submitted contact details are not credentials.
 */
final class IdentityContext
{
    public static function build(array $comment): array
    {
        $db = Db::get();
        $options = Options::alloc();
        $author = trim((string) ($comment['author'] ?? ''));
        $mail = strtolower(trim((string) ($comment['mail'] ?? '')));
        $url = trim((string) ($comment['url'] ?? ''));
        $host = self::host($url);
        $coid = (int) ($comment['coid'] ?? 0);

        $owners = $db->fetchAll($db->select('uid', 'name', 'screenName')->from('table.users')
            ->where('group = ?', 'administrator')->order('uid', Db::SORT_ASC)->limit(8));
        $names = [];
        foreach ($owners as $owner) {
            foreach (['name', 'screenName'] as $key) {
                if (!empty($owner[$key])) {
                    $names[] = Common::subStr((string) $owner[$key], 0, 60, '');
                }
            }
        }

        $base = $db->select('coid', 'author', 'mail', 'url', 'status')->from('table.comments')
            ->where('type = ?', 'comment')->where('status IN ?', ['approved', 'waiting', 'spam']);
        if ($coid > 0) {
            // Historical scans must not use the comment itself or later comments as its reputation.
            $base->where('coid < ?', $coid);
        }
        $base->order('coid', Db::SORT_DESC);
        $candidates = [];
        foreach (['author' => $author, 'mail' => $mail, 'url' => $url] as $field => $value) {
            if ($value === '') {
                continue;
            }
            foreach ($db->fetchAll((clone $base)->where($field . ' = ?', $value)->limit(12)) as $row) {
                $candidates[(int) $row['coid']] = $row;
            }
        }
        $normalized = self::nameKey($author);
        foreach ($db->fetchAll((clone $base)->limit(120)) as $row) {
            if (($normalized !== '' && self::nameKey((string) $row['author']) === $normalized)
                || ($host !== '' && self::host((string) $row['url']) === $host)) {
                $candidates[(int) $row['coid']] = $row;
            }
        }
        krsort($candidates);

        // Aggregate related profiles without transmitting historical email addresses or IPs.
        $profiles = [];
        foreach ($candidates as $row) {
            $profileHost = self::host((string) $row['url']);
            $relation = $mail === '' || trim((string) $row['mail']) === ''
                ? 'unknown'
                : (strcasecmp($mail, trim((string) $row['mail'])) === 0 ? 'same_unverified_email' : 'different_email');
            $profileAuthor = Common::subStr((string) $row['author'], 0, 60, '');
            $key = json_encode([$profileAuthor, $profileHost, $relation]);
            if (!isset($profiles[$key])) {
                if (count($profiles) >= 6) {
                    continue;
                }
                $profiles[$key] = [
                    'nickname' => $profileAuthor,
                    'website_host' => $profileHost,
                    'email_relation' => $relation,
                    'approved' => 0, 'waiting' => 0, 'spam' => 0,
                ];
            }
            $profiles[$key][$row['status']]++;
        }

        $at = strrpos($mail, '@');
        $emailName = $at === false ? $mail : substr($mail, 0, $at);
        $emailDomain = $at === false ? '' : strtolower(trim(substr($mail, $at + 1)));
        $authenticatedOwner = false;
        $authorId = (int) ($comment['authorId'] ?? 0);
        if ($authorId > 0) {
            $isOwner = in_array($authorId, array_map('intval', array_column($owners, 'uid')), true);
            $authenticatedOwner = $isOwner && ($coid > 0
                || (User::alloc()->hasLogin() && (int) User::alloc()->uid === $authorId));
        }

        return [
            'site' => [
                'title' => Common::subStr((string) $options->title, 0, 100, ''),
                'host' => self::host((string) $options->siteUrl),
                'owner_names' => array_values(array_unique($names)),
            ],
            'current' => [
                'nickname' => Common::subStr($author, 0, 60, ''),
                'email_name' => Common::subStr($emailName, 0, 48, ''),
                'email_domain' => Common::subStr($emailDomain, 0, 100, ''),
                'email_format_valid' => $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false,
                'website_host' => $host,
                'website_is_homepage' => self::isHomepage($url),
                'authenticated_site_owner' => $authenticatedOwner,
            ],
            'related_profiles' => array_values($profiles),
            'history_note' => 'bounded_sample_of_submitted_details_not_verified_identities',
        ];
    }

    private static function host(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $host = parse_url(strpos($url, '://') === false ? 'https://' . $url : $url, PHP_URL_HOST);
        return is_string($host) ? strtolower(Common::subStr($host, 0, 253, '')) : '';
    }

    private static function isHomepage(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $path = parse_url(strpos($url, '://') === false ? 'https://' . $url : $url, PHP_URL_PATH);
        return !is_string($path) || $path === '' || $path === '/';
    }

    private static function nameKey(string $name): string
    {
        $name = mb_convert_kana(mb_strtolower(trim($name), 'UTF-8'), 'as', 'UTF-8');
        return preg_replace('/[\p{Z}\p{P}\p{S}\p{N}\p{Cf}]+/u', '', $name) ?? '';
    }
}
