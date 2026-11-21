<?php

namespace TypechoPlugin\CommentAudit;

use Typecho\Db;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Widget\Exception as WidgetException;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Typecho\Widget\Helper\Form\Element\Number;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Layout;
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
 * @version 1.0.5
 * @link https://lizihao.com/
 */
class Plugin implements PluginInterface
{
    public const VERSION = '1.0.5';
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

    /**
     * 插件配置页只保留入口，详细设置进入独立面板。
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $options = Options::alloc();
        $panelUrl = $options->adminUrl('extending.php?panel=' . urlencode('CommentAudit/panel.php'), true);
        $settings = self::settings();

        $layout = new Layout('div', ['class' => 'typecho-option']);
        $layout->html(
            '<p>' . _t('这里的“基础信息”只包含 DeepSeek 连接参数；规则、信誉、日志和高级功能仍在独立面板中配置。') . '</p>' .
            '<p><a class="btn primary" href="' . htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8') . '">'
            . _t('打开评论审核设置') . '</a></p>' .
            '<p class="description">' . _t('待审核、垃圾、已通过仍然使用 Typecho 原生评论管理页。') . '</p>'
        );
        $form->addItem($layout);

        $addSection = static function ($title, $description, $group) use ($form) {
            $section = new Layout('div', ['class' => 'typecho-option commentaudit-config-section']);
            $section->setAttribute('data-ca-group', $group);
            $section->html(
                '<h3 style="margin:18px 0 4px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>' .
                '<p class="description" style="margin:0 0 8px;">' .
                htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>'
            );
            $form->addItem($section);
        };

        // Typecho 的插件配置页会遍历已有配置键并逐个调用 getInput()，
        // 因此这里为每个配置键保留一个输入项。基础/本地设置在这里编辑。
        foreach ($settings as $name => $value) {
            if ($name === 'blockWords') {
                $addSection(_t('本地审核规则'), _t('明确违禁词直接拒绝；疑似词只作为 AI 的参考信号。'), 'local');
                $textarea = new Textarea('blockWords', null, (string) $value, _t('明确违禁词'), _t('每行一个词。'));
                $textarea->setAttribute('rows', '8');
                $form->addInput($textarea);
            } elseif ($name === 'suspectWords') {
                $textarea = new Textarea('suspectWords', null, (string) $value, _t('疑似敏感词'), _t('每行一个词，交给 AI 判断。'));
                $textarea->setAttribute('rows', '8');
                $form->addInput($textarea);
            } elseif ($name === 'wordScopes') {
                $scopes = is_array($value) ? $value : [];
                $checkbox = new Checkbox(
                    'wordScopes',
                    ['text' => _t('评论内容'), 'author' => _t('昵称'), 'mail' => _t('邮箱'), 'url' => _t('网址')],
                    $scopes,
                    _t('词表作用域')
                );
                $form->addInput($checkbox->multiMode());
            } elseif ($name === 'ipBlacklist') {
                $textarea = new Textarea('ipBlacklist', null, (string) $value, _t('IP 黑名单'), _t('每行一个 IP 或 CIDR，例如 1.2.3.4 或 1.2.3.0/24。'));
                $textarea->setAttribute('rows', '5');
                $form->addInput($textarea);
            } elseif ($name === 'emailBlacklist') {
                $textarea = new Textarea('emailBlacklist', null, (string) $value, _t('邮箱黑名单'), _t('每行一个邮箱或域名，例如 spam@example.com 或 *@spam.com。'));
                $textarea->setAttribute('rows', '5');
                $form->addInput($textarea);
            } elseif ($name === 'urlBlacklist') {
                $textarea = new Textarea('urlBlacklist', null, (string) $value, _t('网址黑名单'), _t('每行一个域名、子域名或 URL 前缀，例如 spam.com、*.spam.com、https://spam.com/path。'));
                $textarea->setAttribute('rows', '5');
                $form->addInput($textarea);
            } elseif ($name === 'ipRateLimit') {
                $addSection(_t('本地预检'), _t('在调用 AI 之前先处理明显的频率和链接异常。'), 'precheck');
                $form->addInput(new Number('ipRateLimit', null, (string) $value, _t('同一 IP 评论数上限'), _t('0 表示不限制。')));
            } elseif ($name === 'ipRateWindow') {
                $form->addInput(new Number('ipRateWindow', null, (string) $value, _t('统计窗口（秒）'), _t('默认 600 秒。')));
            } elseif ($name === 'linkLimit') {
                $form->addInput(new Number('linkLimit', null, (string) $value, _t('链接数量上限'), _t('超过后只打 suspect 标记，不直接拒绝。')));
            } elseif ($name === 'deepseekApiKey') {
                $addSection(_t('DeepSeek 连接'), _t('连接信息只保存在这里；规则面板中的“AI 审核”只保留阈值和缓存。'), 'deepseek');
                $password = new Password(
                    'deepseekApiKey',
                    null,
                    (string) $value,
                    _t('DeepSeek API Key'),
                    _t('留空保存时不会清空已有 Key。')
                );
                $form->addInput($password);
            } elseif ($name === 'deepseekModel') {
                $form->addInput(new Text(
                    'deepseekModel',
                    null,
                    (string) $value,
                    _t('DeepSeek 模型'),
                    _t('默认 deepseek-flash，可切换 deepseek-v4-pro。')
                ));
            } elseif ($name === 'deepseekBaseUrl') {
                $form->addInput(new Text(
                    'deepseekBaseUrl',
                    null,
                    (string) $value,
                    _t('DeepSeek Base URL'),
                    _t('默认 https://api.deepseek.com。')
                ));
            } elseif ($name === 'deepseekTimeout') {
                $form->addInput(new Number(
                    'deepseekTimeout',
                    null,
                    (string) $value,
                    _t('DeepSeek 超时（秒）'),
                    _t('建议 8–15 秒。')
                ));
            } elseif ($name === 'deepseekMaxTokens') {
                $form->addInput(new Number(
                    'deepseekMaxTokens',
                    null,
                    (string) $value,
                    _t('DeepSeek 最大 token'),
                    _t('默认 1200，推理模型需要给 JSON 输出留空间。')
                ));
            } elseif ($name === 'deepseekSslVerify') {
                $form->addInput(new Radio(
                    'deepseekSslVerify',
                    ['1' => _t('开启'), '0' => _t('关闭')],
                    (string) $value,
                    _t('验证 SSL 证书'),
                    _t('生产环境请开启；本地自签名证书可临时关闭。')
                ));
            } elseif ($name === 'deepseekDebug') {
                $form->addInput(new Radio(
                    'deepseekDebug',
                    ['1' => _t('开启'), '0' => _t('关闭')],
                    (string) $value,
                    _t('DeepSeek 调试日志'),
                    _t('仅排障时开启。')
                ));
            } elseif ($name === 'thresholdNew') {
                $addSection(_t('AI 审核策略'), _t('AI 判定 block 时使用的三档置信度阈值。'), 'ai');
                $number = new Number('thresholdNew', null, (string) $value, _t('新用户阈值'), _t('默认 0.7。'));
                $number->setAttribute('step', '0.05');
                $form->addInput($number);
            } elseif ($name === 'thresholdNormal') {
                $number = new Number('thresholdNormal', null, (string) $value, _t('普通用户阈值'), _t('默认 0.8。'));
                $number->setAttribute('step', '0.05');
                $form->addInput($number);
            } elseif ($name === 'thresholdTrusted') {
                $number = new Number('thresholdTrusted', null, (string) $value, _t('老访客阈值'), _t('默认 0.9。'));
                $number->setAttribute('step', '0.05');
                $form->addInput($number);
            } elseif ($name === 'cacheTtl') {
                $form->addInput(new Number('cacheTtl', null, (string) $value, _t('AI 缓存天数'), _t('默认 7 天。')));
            } elseif ($name === 'advancedCommentList') {
                $addSection(_t('高级与兼容'), _t('这些选项影响后台展示和日志维护，不影响前台评论审核逻辑。'), 'advanced');
                $form->addInput(new Radio(
                    'advancedCommentList',
                    ['1' => _t('开启'), '0' => _t('关闭')],
                    (string) $value,
                    _t('评论列表增强'),
                    _t('在 Typecho 原生评论列表显示审核原因、AI 详情和快捷拉黑。')
                ));
            } elseif ($name === 'logRetention') {
                $form->addInput(new Number(
                    'logRetention',
                    null,
                    (string) $value,
                    _t('审核日志保留天数'),
                    _t('默认 90 天，范围 1–3650 天。')
                ));
            } else {
                $hidden = new Hidden($name, null, is_scalar($value) ? (string) $value : '');
                $form->addInput($hidden);
            }
        }

        $groupMap = [
            'blockWords' => 'local',
            'suspectWords' => 'local',
            'wordScopes' => 'local',
            'ipBlacklist' => 'local',
            'emailBlacklist' => 'local',
            'urlBlacklist' => 'local',
            'ipRateLimit' => 'precheck',
            'ipRateWindow' => 'precheck',
            'linkLimit' => 'precheck',
            'deepseekApiKey' => 'deepseek',
            'deepseekModel' => 'deepseek',
            'deepseekBaseUrl' => 'deepseek',
            'deepseekTimeout' => 'deepseek',
            'deepseekMaxTokens' => 'deepseek',
            'deepseekSslVerify' => 'deepseek',
            'deepseekDebug' => 'deepseek',
            'thresholdNew' => 'ai',
            'thresholdNormal' => 'ai',
            'thresholdTrusted' => 'ai',
            'cacheTtl' => 'ai',
            'advancedCommentList' => 'advanced',
            'logRetention' => 'advanced',
        ];

        foreach ($form->getInputs() as $name => $input) {
            if (isset($groupMap[$name])) {
                $input->setAttribute('data-ca-group', $groupMap[$name]);
            }
        }

        $ui = new Layout('div', ['class' => 'commentaudit-config-ui']);
        $ui->html(<<<'HTML'
<style>
.commentaudit-config-tabs { display: flex; flex-wrap: wrap; gap: 8px; width: 100%; float: none; margin: 8px 0 16px; padding: 0; list-style: none; }
.commentaudit-config-tabs li { float: none; margin: 0; }
.commentaudit-config-tabs li a { display: inline-block; cursor: pointer; }
.commentaudit-config-section { display: block; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('form');
    if (!form) {
        return;
    }

    var elements = Array.prototype.slice.call(form.querySelectorAll('[data-ca-group]'));
    if (!elements.length) {
        return;
    }

    var tabs = [
        ['deepseek', '基础连接'],
        ['local', '本地规则'],
        ['precheck', '本地预检'],
        ['ai', 'AI 策略'],
        ['advanced', '高级设置']
    ];

    var nav = document.createElement('ul');
    nav.className = 'typecho-option-tabs commentaudit-config-tabs';

    tabs.forEach(function (item) {
        var li = document.createElement('li');
        var link = document.createElement('a');
        link.href = '#';
        link.textContent = item[1];
        link.setAttribute('data-ca-tab', item[0]);
        li.appendChild(link);
        nav.appendChild(li);
    });

    var first = elements[0];
    first.parentNode.insertBefore(nav, first);

    function activate(group) {
        elements.forEach(function (element) {
            element.style.display = element.getAttribute('data-ca-group') === group ? '' : 'none';
        });
        Array.prototype.forEach.call(nav.querySelectorAll('a'), function (link) {
            link.parentNode.className = link.getAttribute('data-ca-tab') === group ? 'current' : '';
        });
    }

    nav.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-ca-tab]') : null;
        if (!link) {
            return;
        }
        event.preventDefault();
        activate(link.getAttribute('data-ca-tab'));
    });

    activate('deepseek');
});
</script>
HTML
        );
        $form->addItem($ui);
    }

    /**
     * 插件配置页保存时，保留空的 API Key，避免误清空。
     *
     * @param array $settings
     * @param bool $isInit
     */
    public static function configHandle($settings, $isInit)
    {
        $current = self::settings();
        $settings = is_array($settings) ? $settings : [];

        if (empty($settings['deepseekApiKey'])) {
            $settings['deepseekApiKey'] = $current['deepseekApiKey'];
        }

        if (isset($settings['wordScopes']) && !is_array($settings['wordScopes'])) {
            $settings['wordScopes'] = $current['wordScopes'];
        }

        // 表单中未提交的隐藏字段会以 null 出现，保留原值，避免误清空规则。
        $merged = $current;
        foreach ($settings as $key => $value) {
            if ($value !== null) {
                $merged[$key] = $value;
            }
        }

        Helper::configPlugin(self::PLUGIN_NAME, $merged);
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

            $pluginUrl = Options::alloc()->pluginUrl . '/CommentAudit/assets';
            $actionUrl = Helper::security()->getIndex('/action/comment-audit?do=audit-info');
            $blacklistUrl = Helper::security()->getIndex('/action/comment-audit?do=blacklist');
            ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars($pluginUrl . '/admin.css', ENT_QUOTES, 'UTF-8'); ?>">
            <script>
            window.CommentAuditEnhance = {
                actionUrl: <?php echo json_encode($actionUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                blacklistUrl: <?php echo json_encode($blacklistUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                pluginUrl: <?php echo json_encode($pluginUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
            };
            </script>
            <script src="<?php echo htmlspecialchars($pluginUrl . '/comments-enhance.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
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
