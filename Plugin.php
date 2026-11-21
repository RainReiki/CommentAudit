<?php

namespace TypechoPlugin\CommentAudit;

use Typecho\Db;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Widget\Exception as WidgetException;
use Typecho\Widget\Helper\Form;
use TypechoPlugin\CommentAudit\Admin\SettingsForm;
use Utils\Helper;
use Widget\Comments\Edit;
use Widget\Feedback;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 评论审核插件
 *
 * 本地硬规则先拦截，通过后交给 DeepSeek 审核；
 * 待审/垃圾/已通过继续使用 Typecho 原生评论管理页。
 *
 * @package CommentAudit
 * @author 羽忆
 * @version 1.0.15
 * @link https://lizihao.com/
 */
class Plugin implements PluginInterface
{
    public const VERSION = '1.0.15';
    public const PLUGIN_NAME = 'CommentAudit';

    /**
     * 当前请求的审核结果，供 finishComment 写日志使用。
     *
     * @var array|null
     */
    private static $pendingAudit = null;

    /**
     * 启用插件
     *
     * @throws PluginException
     */
    public static function activate()
    {
        self::installTables();

        // 只在首次启用时写入默认值，之后不覆盖后台设置。
        try {
            Options::alloc()->plugin(self::PLUGIN_NAME);
        } catch (\Throwable $e) {
            Helper::configPlugin(self::PLUGIN_NAME, self::defaultSettings());
        }

        Helper::removePanel(3, 'CommentAudit/panel.php');
        Helper::addPanel(3, 'CommentAudit/panel.php', _t('评论审核'), _t('审核规则与日志'), 'administrator');

        Helper::removeAction('comment-audit');
        Helper::addAction('comment-audit', 'TypechoPlugin\\CommentAudit\\Admin\\Action');

        // 评论决策：500 在 Coral 的 guardComment(0) 之后、insertSecret(1000) 之前。
        Feedback::pluginHandle()->comment_500 = [__CLASS__, 'filterComment'];
        Feedback::pluginHandle()->finishComment_500 = [__CLASS__, 'afterComment'];

        // 后台手动通过/垃圾/待审、删除评论。
        Edit::pluginHandle()->mark_20 = [__CLASS__, 'afterMark'];
        Edit::pluginHandle()->finishDelete_20 = [__CLASS__, 'afterDelete'];

        // 原生评论列表增强，默认关闭；在 NewAdmin 的 begin_15 之后执行。
        TypechoPlugin::factory('admin/footer.php')->begin_20 = [__CLASS__, 'adminFooter'];

        return _t('评论审核插件已启用，请到“管理 → 评论审核”配置规则。');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        Helper::removePanel(3, 'CommentAudit/panel.php');
        Helper::removeAction('comment-audit');

        // 数据表保留，避免误删历史日志和信誉数据。
        return _t('评论审核插件已禁用，数据表已保留。');
    }

    /** 使用 Typecho 的原生保存流程。 */
    public static function config(Form $form)
    {
        SettingsForm::render($form, self::settings());
    }

    public static function configHandle(array $settings, bool $isInit): void
    {
        if (!$isInit) {
            // Typecho's form reader treats an empty string as a missing value.
            if (($_POST['deepseekApiKey'] ?? null) === '') {
                $settings['deepseekApiKey'] = '';
            }
            Helper::configPlugin(self::PLUGIN_NAME, SettingsForm::merge(self::settings(), $settings));
        }
    }

    public static function assetUrl(string $file): string
    {
        return rtrim(Options::alloc()->pluginUrl, '/') . '/CommentAudit/assets/' . $file . '?v=' . self::VERSION;
    }

    /**
     * 个人配置，无需使用。
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 评论提交时的审核入口。
     *
     * @param array $comment
     * @param mixed $content
     * @param array|null $commentAgain
     * @return array
     * @throws WidgetException
     */
    public static function filterComment($comment, $content, $commentAgain = null)
    {
        if (!is_array($comment)) {
            return $comment;
        }

        // 管理员、编辑、文章作者由核心逻辑直接放行，不再覆盖。
        if (is_object($content) && method_exists($content, 'allow') && $content->allow('edit')) {
            $comment['status'] = 'approved';
            return $comment;
        }

        try {
            $settings = self::settings();
            $article = self::articleContext($content);
            $result = Engine::decide($comment, $article, $settings);
        } catch (\Throwable $e) {
            // 审核引擎异常时保持核心的 waiting，绝不自动通过。
            self::$pendingAudit = [
                'decision' => 'waiting',
                'reason_code' => 'engine_error',
                'reason' => $e->getMessage(),
                'trust_level' => 'new',
                'ai' => [],
                'flags' => ['engine_error'],
            ];
            $comment['status'] = 'waiting';
            return $comment;
        }

        if (($result['decision'] ?? '') === 'rejected') {
            $result['coid'] = 0;
            try {
                Logger::write($comment, $result, 0);
            } catch (\Throwable $e) {
                // 日志写入失败也不能让前台看到内部错误。
            }
            throw new WidgetException(_t('评论提交失败，请稍后再试。'));
        }

        $comment['status'] = ($result['decision'] ?? '') === 'approved' ? 'approved' : 'waiting';
        self::$pendingAudit = $result;

        return $comment;
    }

    /**
     * 评论入库后写日志、更新信誉。
     *
     * @param Feedback $feedback
     */
    public static function afterComment($feedback)
    {
        $result = self::$pendingAudit;
        self::$pendingAudit = null;

        if (empty($result) || !is_object($feedback)) {
            return;
        }

        $coid = (int) $feedback->coid;
        if ($coid <= 0) {
            return;
        }

        $comment = [
            'coid' => $coid,
            'cid' => (int) $feedback->cid,
            'author' => (string) $feedback->author,
            'mail' => (string) $feedback->mail,
            'ip' => (string) $feedback->ip,
            'text' => (string) $feedback->text,
            'status' => (string) $feedback->status,
        ];

        $result['coid'] = $coid;
        $result['decision'] = (string) $feedback->status;

        try {
            Logger::write($comment, $result, $coid);
            Repository::recordOutcome($comment, (string) $feedback->status, $result);
        } catch (\Throwable $e) {
            // 日志/信誉失败不能影响评论本身。
        }
    }

    /**
     * 管理员手动改状态。
     *
     * @param array $comment
     * @param mixed $widget
     * @param string $status
     */
    public static function afterMark($comment, $widget, $status)
    {
        if (!is_array($comment)) {
            return;
        }

        $oldStatus = (string) ($comment['status'] ?? '');
        $newStatus = (string) $status;
        $coid = (int) ($comment['coid'] ?? 0);

        try {
            $uid = (int) User::alloc()->uid;
            Logger::markManual($coid, $newStatus, $uid);
            Repository::adjustForMark($comment, $oldStatus, $newStatus);
        } catch (\Throwable $e) {
            // 手动改判的附属操作失败，不影响核心状态更新。
        }
    }

    /**
     * 删除评论后回退信誉计数。
     *
     * @param array $comment
     * @param mixed $widget
     */
    public static function afterDelete($comment, $widget)
    {
        if (!is_array($comment)) {
            return;
        }

        try {
            Repository::adjustForDelete($comment);
        } catch (\Throwable $e) {
            // 删除评论不应因信誉统计失败而失败。
        }
    }

    /**
     * 可选：原生评论列表增强。
     */
    public static function adminFooter()
    {
        try {
            $settings = self::settings();
            if (empty($settings['advancedCommentList'])) {
                return;
            }

            $request = Options::alloc()->request;
            if (strpos((string) $request->getRequestUri(), 'manage-comments.php') === false) {
                return;
            }

            $actionUrl = Helper::security()->getIndex('/action/comment-audit?do=audit-info');
            $blacklistUrl = Helper::security()->getIndex('/action/comment-audit?do=blacklist');
            ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars(self::assetUrl('admin.css'), ENT_QUOTES, 'UTF-8'); ?>">
            <script>
            window.CommentAuditEnhance = {
                actionUrl: <?php echo json_encode($actionUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                blacklistUrl: <?php echo json_encode($blacklistUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
            };
            </script>
            <script src="<?php echo htmlspecialchars(self::assetUrl('comments-enhance.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
            <?php
        } catch (\Throwable $e) {
            // 增强脚本失败时静默退出。
        }
    }

    /**
     * 当前插件设置。
     *
     * @return array
     */
    public static function settings()
    {
        $defaults = self::defaultSettings();

        try {
            $config = Options::alloc()->plugin(self::PLUGIN_NAME);
            $values = $config->toArray();
        } catch (\Throwable $e) {
            $values = [];
        }

        $settings = array_merge($defaults, is_array($values) ? $values : []);

        if (!is_array($settings['wordScopes'])) {
            $settings['wordScopes'] = $defaults['wordScopes'];
        }

        return $settings;
    }

    /**
     * 默认设置。
     *
     * @return array
     */
    public static function defaultSettings()
    {
        return [
            'blockWords' => '',
            'suspectWords' => '',
            'wordScopes' => ['text', 'author', 'mail'],
            'ipBlacklist' => '',
            'emailBlacklist' => '',
            'urlBlacklist' => '',
            'ipRateLimit' => 5,
            'ipRateWindow' => 600,
            'linkLimit' => 3,
            'deepseekApiKey' => '',
            'deepseekBaseUrl' => 'https://api.deepseek.com',
            'deepseekModel' => 'deepseek-flash',
            'deepseekTimeout' => 8,
            'deepseekMaxTokens' => 1200,
            'deepseekSslVerify' => 1,
            'deepseekDebug' => 0,
            'thresholdNew' => 0.7,
            'thresholdNormal' => 0.8,
            'thresholdTrusted' => 0.9,
            'aiFailPolicy' => 'review',
            'cacheTtl' => 7,
            'advancedCommentList' => 0,
            'logRetention' => 90,
        ];
    }

    /**
     * 将 Archive 对象转成审核上下文。
     *
     * @param mixed $content
     * @return array
     */
    private static function articleContext($content)
    {
        $article = [
            'cid' => 0,
            'title' => '',
            'excerpt' => '',
            'content' => '',
            'category' => '',
            'tags' => [],
        ];

        if (!is_object($content)) {
            return $article;
        }

        try {
            $article['cid'] = (int) ($content->cid ?? 0);
            $article['title'] = (string) ($content->title ?? '');
            $article['excerpt'] = trim(strip_tags((string) ($content->excerpt ?? '')));
            $article['content'] = trim(strip_tags((string) ($content->text ?? '')));
            $article['category'] = (string) ($content->category ?? '');

            if (isset($content->tags) && is_array($content->tags)) {
                $article['tags'] = array_values(array_map('strval', $content->tags));
            }
        } catch (\Throwable $e) {
            // 文章上下文获取失败时仍可审核评论本身。
        }

        return $article;
    }

    /**
     * SQLite 建表语句。
     *
     * @param string $prefix
     * @return array
     */
    private static function sqliteSchema($prefix)
    {
        $logs = $prefix . 'commentaudit_logs';
        $reputation = $prefix . 'commentaudit_reputation';
        $cache = $prefix . 'commentaudit_ai_cache';
        $state = $prefix . 'commentaudit_state';

        return [
            "CREATE TABLE IF NOT EXISTS `{$logs}` (
                `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                `coid` INTEGER NOT NULL DEFAULT 0,
                `cid` INTEGER NOT NULL DEFAULT 0,
                `author` TEXT DEFAULT NULL,
                `mail` TEXT DEFAULT NULL,
                `ip` TEXT DEFAULT NULL,
                `text` TEXT,
                `decision` TEXT NOT NULL DEFAULT 'waiting',
                `reason_code` TEXT NOT NULL DEFAULT '',
                `trust_level` TEXT NOT NULL DEFAULT 'new',
                `ai_decision` TEXT DEFAULT NULL,
                `ai_category` TEXT DEFAULT NULL,
                `ai_severity` TEXT DEFAULT NULL,
                `ai_confidence` REAL NOT NULL DEFAULT 0,
                `ai_reason` TEXT,
                `on_topic` INTEGER NOT NULL DEFAULT 0,
                `faithful` INTEGER NOT NULL DEFAULT 0,
                `threat_type` TEXT DEFAULT NULL,
                `latency_ms` INTEGER NOT NULL DEFAULT 0,
                `tokens` INTEGER NOT NULL DEFAULT 0,
                `manual` INTEGER NOT NULL DEFAULT 0,
                `manual_by` INTEGER NOT NULL DEFAULT 0,
                `created` INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE INDEX IF NOT EXISTS `{$logs}_coid` ON `{$logs}` (`coid`)",
            "CREATE INDEX IF NOT EXISTS `{$logs}_cid` ON `{$logs}` (`cid`)",
            "CREATE INDEX IF NOT EXISTS `{$logs}_decision_created` ON `{$logs}` (`decision`, `created`)",
            "CREATE INDEX IF NOT EXISTS `{$logs}_reason_code` ON `{$logs}` (`reason_code`)",
            "CREATE INDEX IF NOT EXISTS `{$logs}_trust_level` ON `{$logs}` (`trust_level`)",
            "CREATE TABLE IF NOT EXISTS `{$reputation}` (
                `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                `identity_hash` TEXT NOT NULL,
                `identity_type` TEXT NOT NULL DEFAULT 'mail',
                `identity_value` TEXT DEFAULT NULL,
                `trust_level` TEXT NOT NULL DEFAULT 'new',
                `approved_count` INTEGER NOT NULL DEFAULT 0,
                `spam_count` INTEGER NOT NULL DEFAULT 0,
                `waiting_count` INTEGER NOT NULL DEFAULT 0,
                `first_seen` INTEGER NOT NULL DEFAULT 0,
                `last_seen` INTEGER NOT NULL DEFAULT 0,
                `last_spam_at` INTEGER NOT NULL DEFAULT 0,
                `moderate_abuse_24h` INTEGER NOT NULL DEFAULT 0,
                `last_moderate_abuse_at` INTEGER NOT NULL DEFAULT 0,
                `updated` INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS `{$reputation}_identity_hash` ON `{$reputation}` (`identity_hash`)",
            "CREATE INDEX IF NOT EXISTS `{$reputation}_trust_level` ON `{$reputation}` (`trust_level`)",
            "CREATE INDEX IF NOT EXISTS `{$reputation}_last_seen` ON `{$reputation}` (`last_seen`)",
            "CREATE TABLE IF NOT EXISTS `{$cache}` (
                `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                `cache_key` TEXT NOT NULL,
                `model` TEXT DEFAULT NULL,
                `prompt_version` TEXT DEFAULT NULL,
                `response` TEXT,
                `created` INTEGER NOT NULL DEFAULT 0,
                `expires` INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS `{$cache}_cache_key` ON `{$cache}` (`cache_key`)",
            "CREATE INDEX IF NOT EXISTS `{$cache}_expires` ON `{$cache}` (`expires`)",
            "CREATE TABLE IF NOT EXISTS `{$state}` (
                `name` TEXT NOT NULL PRIMARY KEY,
                `value` TEXT,
                `updated` INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    /**
     * 建表。
     *
     * @throws PluginException
     */
    private static function installTables()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        $logs = $prefix . 'commentaudit_logs';
        $reputation = $prefix . 'commentaudit_reputation';
        $cache = $prefix . 'commentaudit_ai_cache';
        $state = $prefix . 'commentaudit_state';
        $isSqlite = stripos((string) $db->getAdapterName(), 'sqlite') !== false;

        if ($isSqlite) {
            $sqls = self::sqliteSchema($prefix);
        } else {
            $sqls = [
            "CREATE TABLE IF NOT EXISTS `{$logs}` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `coid` int(10) unsigned NOT NULL DEFAULT 0,
                `cid` int(10) unsigned NOT NULL DEFAULT 0,
                `author` varchar(150) DEFAULT NULL,
                `mail` varchar(150) DEFAULT NULL,
                `ip` varchar(64) DEFAULT NULL,
                `text` mediumtext,
                `decision` varchar(16) NOT NULL DEFAULT 'waiting',
                `reason_code` varchar(64) NOT NULL DEFAULT '',
                `trust_level` varchar(16) NOT NULL DEFAULT 'new',
                `ai_decision` varchar(16) DEFAULT NULL,
                `ai_category` varchar(32) DEFAULT NULL,
                `ai_severity` varchar(16) DEFAULT NULL,
                `ai_confidence` decimal(4,3) NOT NULL DEFAULT 0.000,
                `ai_reason` text,
                `on_topic` tinyint(1) NOT NULL DEFAULT 0,
                `faithful` tinyint(1) NOT NULL DEFAULT 0,
                `threat_type` varchar(16) DEFAULT NULL,
                `latency_ms` int(10) unsigned NOT NULL DEFAULT 0,
                `tokens` int(10) unsigned NOT NULL DEFAULT 0,
                `manual` tinyint(1) NOT NULL DEFAULT 0,
                `manual_by` int(10) unsigned NOT NULL DEFAULT 0,
                `created` int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `coid` (`coid`),
                KEY `cid` (`cid`),
                KEY `decision_created` (`decision`,`created`),
                KEY `reason_code` (`reason_code`),
                KEY `trust_level` (`trust_level`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$reputation}` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `identity_hash` char(64) NOT NULL,
                `identity_type` varchar(16) NOT NULL DEFAULT 'mail',
                `identity_value` varchar(255) DEFAULT NULL,
                `trust_level` varchar(16) NOT NULL DEFAULT 'new',
                `approved_count` int(10) unsigned NOT NULL DEFAULT 0,
                `spam_count` int(10) unsigned NOT NULL DEFAULT 0,
                `waiting_count` int(10) unsigned NOT NULL DEFAULT 0,
                `first_seen` int(10) unsigned NOT NULL DEFAULT 0,
                `last_seen` int(10) unsigned NOT NULL DEFAULT 0,
                `last_spam_at` int(10) unsigned NOT NULL DEFAULT 0,
                `moderate_abuse_24h` int(10) unsigned NOT NULL DEFAULT 0,
                `last_moderate_abuse_at` int(10) unsigned NOT NULL DEFAULT 0,
                `updated` int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `identity_hash` (`identity_hash`),
                KEY `trust_level` (`trust_level`),
                KEY `last_seen` (`last_seen`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$cache}` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `cache_key` char(64) NOT NULL,
                `model` varchar(64) DEFAULT NULL,
                `prompt_version` varchar(32) DEFAULT NULL,
                `response` mediumtext,
                `created` int(10) unsigned NOT NULL DEFAULT 0,
                `expires` int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `cache_key` (`cache_key`),
                KEY `expires` (`expires`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$state}` (
                `name` varchar(64) NOT NULL,
                `value` text,
                `updated` int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ];
        }

        try {
            foreach ($sqls as $sql) {
                $db->query($sql, Db::WRITE);
            }
        } catch (\Throwable $e) {
            throw new PluginException(_t('评论审核数据表创建失败：%s', $e->getMessage()));
        }
    }
}
