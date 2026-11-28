<?php

namespace TypechoPlugin\CommentAudit\Update;

if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }

/** A process-wide reader lock keeps plugin requests outside the update window. */
final class Guard
{
    private static $reader;
    private static $writer;

    public static function directory(): string
    {
        $dir = defined('COMMENTAUDIT_UPDATE_DIR') ? COMMENTAUDIT_UPDATE_DIR
            : sys_get_temp_dir() . '/commentaudit-' . substr(hash('sha256', dirname(__DIR__)), 0, 24);
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) {
            throw new \RuntimeException('无法创建升级工作目录。');
        }
        return $dir;
    }

    public static function enter(): void
    {
        if (self::$reader || self::$writer) { return; }
        $handle = fopen(self::directory() . '/lock', 'c+');
        if (!$handle || !flock($handle, LOCK_SH | LOCK_NB)) {
            if ($handle) { fclose($handle); }
            throw new \Typecho\Widget\Exception('评论审核插件正在升级，请稍后重试。', 503);
        }
        self::$reader = $handle;
    }

    public static function exclusive(): void
    {
        if (self::$reader) {
            flock(self::$reader, LOCK_UN);
            fclose(self::$reader);
            self::$reader = null;
        }
        $handle = fopen(self::directory() . '/lock', 'c+');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) { fclose($handle); }
            throw new \RuntimeException('仍有评论审核请求正在运行，请稍后重试升级。');
        }
        self::$writer = $handle;
    }

    public static function release(): void
    {
        if (self::$writer) {
            flock(self::$writer, LOCK_UN);
            fclose(self::$writer);
            self::$writer = null;
        }
    }

    public static function pending(): bool
    {
        return is_file(self::directory() . '/journal.json');
    }

    public static function assertReady(): void
    {
        if (!self::$writer && self::pending()) {
            throw new \RuntimeException('上次升级中断，请到“评论审核 → 在线升级”恢复后再继续。');
        }
    }

    /** Load the previous updater implementation after an interrupted self-update. */
    public static function recoveryRuntime(): void
    {
        if (!self::pending()) { return; }
        $journal = json_decode((string) file_get_contents(self::directory() . '/journal.json'), true);
        $id = $journal['id'] ?? '';
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) { throw new \RuntimeException('升级恢复状态损坏。'); }
        $runtime = self::directory() . '/' . $id . '/runtime';
        foreach (['Manifest', 'GitHub', 'Database', 'Updater'] as $class) {
            if (!class_exists(__NAMESPACE__ . '\\' . $class, false)) {
                $file = $runtime . '/' . $class . '.php';
                if (!is_file($file)) { throw new \RuntimeException('缺少升级恢复运行文件。'); }
                require_once $file;
            }
        }
    }
}
