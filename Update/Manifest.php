<?php

namespace TypechoPlugin\CommentAudit\Update;

if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }

final class Manifest
{
    public static function path(string $path): void
    {
        if (strlen($path) > 200 || !preg_match('~^[A-Za-z0-9_][A-Za-z0-9_./-]*$~D', $path)
            || preg_match('~(^|/)(\.|\.\.|\.git)(/|$)|//~', $path)
            || preg_match('~(^|/)(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|/|$)~i', $path)
            || preg_match('~[.](?:/|$)~', $path)
            || $path === 'update-manifest.json' || strpos($path, '.github/') === 0
            || strpos($path, 'tests/') === 0 || strpos($path, 'tools/') === 0) {
            throw new \RuntimeException('升级清单包含非法路径：' . $path);
        }
    }

    public static function validate($manifest, string $version): void
    {
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1
            || ($manifest['version'] ?? '') !== $version
            || !preg_match('/^[a-f0-9]{40}$/D', (string) ($manifest['commit'] ?? ''))
            || !is_array($manifest['files'] ?? null) || empty($manifest['files']['Plugin.php'])
            || count($manifest['files']) > 1500 || !is_array($manifest['migrations'] ?? null)
            || version_compare(PHP_VERSION, (string) ($manifest['php'] ?? '8.0'), '<')) {
            throw new \RuntimeException('升级清单格式或 PHP 版本不符合要求。');
        }
        $total = 0; $seen = [];
        foreach (['Plugin.php', 'Update/Guard.php', 'Update/Updater.php', 'Update/GitHub.php',
            'Update/Database.php', 'Update/Manifest.php'] as $required) {
            if (!isset($manifest['files'][$required])) { throw new \RuntimeException('升级清单缺少恢复所需文件：' . $required); }
        }
        foreach ($manifest['files'] as $path => $entry) {
            self::path((string) $path);
            $lower = strtolower($path);
            if (isset($seen[$lower]) || !is_array($entry) || !is_int($entry['size'] ?? null)
                || $entry['size'] < 0 || $entry['size'] > 10485760
                || !preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['sha256'] ?? ''))) {
                throw new \RuntimeException('升级文件信息无效：' . $path);
            }
            $seen[$lower] = true;
            $total += $entry['size'];
        }
        if ($total > 52428800) { throw new \RuntimeException('升级包超过大小限制。'); }
        $last = '0.0.0';
        foreach ($manifest['migrations'] as $migration) {
            $v = GitHub::version((string) ($migration['version'] ?? ''));
            $path = (string) ($migration['file'] ?? '');
            if (!version_compare($v, $last, '>') || version_compare($v, $version, '>')
                || $path !== 'migrations/' . $v . '.json' || !isset($manifest['files'][$path])) {
                throw new \RuntimeException('数据库迁移清单必须按版本递增并保留全部历史。');
            }
            $last = $v;
        }
    }
}
