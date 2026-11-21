<?php

use TypechoPlugin\CommentAudit\Logger;
use TypechoPlugin\CommentAudit\Plugin;
use TypechoPlugin\CommentAudit\Repository;
use TypechoPlugin\CommentAudit\Rule\WordFilter;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

include 'header.php';
include 'menu.php';

/** @var Options $options */
$plugin = Plugin::settings();
$tab = (string) $request->get('tab', 'overview');
$allowedTabs = ['overview', 'reputation', 'logs'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}

$panelBase = 'extending.php?panel=' . urlencode('CommentAudit/panel.php');
$pluginSettingsUrl = $options->adminUrl('options-plugin.php?config=CommentAudit', true);
$tabUrl = static function ($name) use ($options, $panelBase) {
    return $options->adminUrl($panelBase . '&tab=' . urlencode($name), true);
};
$actionUrl = static function ($do) use ($security) {
    return $security->getIndex('/action/comment-audit?do=' . urlencode($do));
};
$h = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$decisionLabel = static function ($decision) {
    $map = ['approved' => _t('通过'), 'waiting' => _t('待审'), 'rejected' => _t('拒绝'), 'spam' => _t('垃圾')];
    return $map[$decision] ?? (string) $decision;
};
$trustLabel = static function ($level) {
    $map = ['new' => _t('新用户'), 'normal' => _t('普通用户'), 'trusted' => _t('老访客'), 'whitelist' => _t('白名单')];
    return $map[$level] ?? (string) $level;
};
?>
<style>
.commentaudit-wrap {
    display: block;
    width: 100%;
    max-width: 1140px;
    margin: 0 auto;
    padding: 0 20px;
    box-sizing: border-box;
    --ca-bg: #fff;
    --ca-text: #22313f;
    --ca-muted: #6b7c8c;
    --ca-border: #d9e2ea;
    --ca-th-bg: #f8fafc;
    --ca-soft-bg: #f7fafc;
    --ca-input-bg: #fff;
    --ca-input-border: #ccd7e1;
    --ca-shadow: 0 1px 5px rgba(0, 0, 0, .06);
}
.commentaudit-tabs {
    clear: both;
    width: 100%;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 18px;
    padding: 0;
    list-style: none;
}
.commentaudit-tabs li {
    float: none;
    margin: 0;
}
.commentaudit-tabs li a {
    display: inline-block;
}
.commentaudit-card { background: var(--ca-bg); color: var(--ca-text); border: 1px solid var(--ca-border); border-radius: 8px; padding: 18px 20px; margin-bottom: 16px; box-shadow: var(--ca-shadow); }
.commentaudit-card h3 { margin: 0 0 8px; font-size: 15px; color: var(--ca-text); }
.commentaudit-card p.commentaudit-desc { color: var(--ca-muted); margin: 0 0 14px; line-height: 1.7; }
.commentaudit-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.commentaudit-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.commentaudit-field { margin-bottom: 14px; }
.commentaudit-field label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--ca-text); }
.commentaudit-field .description { color: var(--ca-muted); line-height: 1.6; }
.commentaudit-field textarea, .commentaudit-field input[type=text], .commentaudit-field input[type=password], .commentaudit-field input[type=number], .commentaudit-field select {
    width: 100%;
    box-sizing: border-box;
    background: var(--ca-input-bg);
    color: var(--ca-text);
    border: 1px solid var(--ca-input-border);
    border-radius: 6px;
    padding: 6px 8px;
}
.commentaudit-checkboxes label { display: inline-block; margin-right: 16px; font-weight: 400; }
.commentaudit-actions { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.commentaudit-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.commentaudit-table { width: 100%; border-collapse: collapse; color: var(--ca-text); }
.commentaudit-identity { word-break: break-all; }
.commentaudit-trust-form { display: flex; gap: 6px; align-items: center; }
.commentaudit-table th, .commentaudit-table td { border-bottom: 1px solid var(--ca-border); padding: 9px 8px; text-align: left; vertical-align: top; }
.commentaudit-table th { color: var(--ca-muted); font-weight: 600; background: var(--ca-th-bg); }
.commentaudit-muted { color: var(--ca-muted); }
.commentaudit-badge { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 12px; background: rgba(80, 120, 150, .14); color: inherit; }
.commentaudit-badge-approved { background: rgba(35, 122, 67, .18); }
.commentaudit-badge-waiting { background: rgba(154, 100, 0, .18); }
.commentaudit-badge-rejected, .commentaudit-badge-spam { background: rgba(165, 42, 42, .18); }
.commentaudit-result { white-space: pre-wrap; background: #0f1720; color: #d7e3ee; padding: 12px; border-radius: 8px; min-height: 80px; overflow: auto; }
.commentaudit-stat { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
.commentaudit-stat div { background: var(--ca-soft-bg); border: 1px solid var(--ca-border); border-radius: 8px; padding: 12px; }
.commentaudit-stat strong { display: block; font-size: 22px; margin-bottom: 4px; }

/* NewAdmin 夜间模式：link#darkcss 启用时生效；同时兼容系统深色。 */
@media (prefers-color-scheme: dark) {
    .commentaudit-wrap {
        --ca-bg: #16233a;
        --ca-text: #e2e8f0;
        --ca-muted: #94a3b8;
        --ca-border: #334155;
        --ca-th-bg: #1f2937;
        --ca-soft-bg: #1e293b;
        --ca-input-bg: #1f2937;
        --ca-input-border: #334155;
        --ca-shadow: 0 1px 5px rgba(0, 0, 0, .3);
    }
}
html:has(#darkcss:not([disabled])) .commentaudit-wrap,
html.dark .commentaudit-wrap,
body.dark .commentaudit-wrap,
#newadmin.dark .commentaudit-wrap,
.newadmin.dark .commentaudit-wrap {
    --ca-bg: #16233a;
    --ca-text: #e2e8f0;
    --ca-muted: #94a3b8;
    --ca-border: #334155;
    --ca-th-bg: #1f2937;
    --ca-soft-bg: #1e293b;
    --ca-input-bg: #1f2937;
    --ca-input-border: #334155;
    --ca-shadow: 0 1px 5px rgba(0, 0, 0, .3);
}
.commentaudit-log-excerpt { display: inline-block; max-width: 420px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
.commentaudit-log-action { width: 80px; text-align: right; }
.commentaudit-modal[hidden] { display: none; }
.commentaudit-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; padding: 24px; }
.commentaudit-modal-backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, .55); }
.commentaudit-modal-dialog { position: relative; width: min(720px, 100%); max-height: 88vh; overflow: auto; background: var(--ca-bg); color: var(--ca-text); border: 1px solid var(--ca-border); border-radius: 10px; box-shadow: 0 20px 60px rgba(0, 0, 0, .35); }
.commentaudit-modal-head { position: sticky; top: 0; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; background: var(--ca-bg); border-bottom: 1px solid var(--ca-border); }
.commentaudit-modal-head h3 { margin: 0; font-size: 16px; }
.commentaudit-modal-close { border: 0; background: transparent; color: var(--ca-text); font-size: 26px; line-height: 1; cursor: pointer; padding: 0 4px; }
.commentaudit-modal-body { padding: 16px 20px 20px; }
.commentaudit-log-full { white-space: pre-wrap; word-break: break-word; background: var(--ca-soft-bg); border: 1px solid var(--ca-border); border-radius: 8px; padding: 12px; margin-bottom: 14px; max-height: 220px; overflow: auto; line-height: 1.7; }
.commentaudit-log-detail-table th { width: 150px; white-space: nowrap; }
.commentaudit-modal-open { overflow: hidden; }
@media (max-width: 860px) {
    .commentaudit-grid, .commentaudit-grid-3, .commentaudit-stat { grid-template-columns: 1fr; }
    .commentaudit-log-table thead { display: none; }
    .commentaudit-log-table tbody tr { display: block; border: 1px solid var(--ca-border); border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
    .commentaudit-log-table td { display: block; border: 0; padding: 5px 0; text-align: left; }
    .commentaudit-log-table td::before { content: attr(data-label); display: inline-block; min-width: 70px; font-weight: 600; color: var(--ca-muted); }
    .commentaudit-log-table td.commentaudit-log-action { text-align: left; padding-top: 8px; }
    .commentaudit-log-table td.commentaudit-log-action::before { content: ''; min-width: 0; }
    .commentaudit-log-excerpt { display: inline; max-width: none; white-space: normal; }
    .commentaudit-modal { padding: 12px; }
    .commentaudit-modal-dialog { max-height: 92vh; }
    .commentaudit-modal-head, .commentaudit-modal-body { padding-left: 14px; padding-right: 14px; }
    .commentaudit-log-detail-table th { width: 110px; white-space: normal; }
    .commentaudit-reputation-table thead { display: none; }
    .commentaudit-reputation-table tbody tr { display: block; border: 1px solid var(--ca-border); border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
    .commentaudit-reputation-table td { display: block; border: 0; padding: 5px 0; text-align: left; }
    .commentaudit-reputation-table td::before { content: attr(data-label); display: inline-block; min-width: 88px; font-weight: 600; color: var(--ca-muted); }
    .commentaudit-reputation-table td.commentaudit-reputation-action { padding-top: 8px; }
    .commentaudit-reputation-table td.commentaudit-reputation-action::before { content: ''; min-width: 0; }
    .commentaudit-trust-form { flex-wrap: wrap; }
    .commentaudit-trust-form select { flex: 1 1 120px; max-width: 100%; }
}
</style>

<main class="main">
    <div class="body container commentaudit-wrap">
        <?php include 'page-title.php'; ?>

        <ul class="typecho-option-tabs commentaudit-tabs">
            <li<?php if ($tab === 'overview'): ?> class="current"<?php endif; ?>><a href="<?php echo $h($tabUrl('overview')); ?>"><?php _e('概览'); ?></a></li>
            <li<?php if ($tab === 'reputation'): ?> class="current"<?php endif; ?>><a href="<?php echo $h($tabUrl('reputation')); ?>"><?php _e('信誉管理'); ?></a></li>
            <li<?php if ($tab === 'logs'): ?> class="current"<?php endif; ?>><a href="<?php echo $h($tabUrl('logs')); ?>"><?php _e('审核日志'); ?></a></li>
        </ul>

        <?php if ($tab === 'overview'): ?>
            <?php
            $stats = [
                'approved' => 0,
                'waiting' => 0,
                'rejected' => 0,
                'spam' => 0,
                'total' => 0,
                'all_total' => 0,
                'today_start' => strtotime('today'),
                'generated_at' => time(),
            ];
            $recent = [];
            try {
                Repository::pruneLogs((int) $plugin['logRetention']);
            } catch (\Throwable $e) {
            }
            try {
                $stats = Repository::stats();
                $recent = Repository::recentLogs(15);
            } catch (\Throwable $e) {
            }
            ?>
            <div class="commentaudit-card">
                <h3><?php _e('今日概览'); ?></h3>
                <div class="commentaudit-stat">
                    <div><strong><?php echo (int) $stats['total']; ?></strong><?php _e('今日决策'); ?></div>
                    <div><strong><?php echo (int) $stats['approved']; ?></strong><?php _e('通过'); ?></div>
                    <div><strong><?php echo (int) $stats['waiting']; ?></strong><?php _e('待审'); ?></div>
                    <div><strong><?php echo (int) $stats['rejected']; ?></strong><?php _e('拒绝'); ?></div>
                    <div><strong><?php echo (int) $stats['spam']; ?></strong><?php _e('垃圾'); ?></div>
                </div>
                <p class="commentaudit-muted" style="margin:10px 0 0;">
                    <?php
                    echo _t(
                        '统计时间：%s；今日从 %s 起算；累计审核：%s 条。',
                        date('Y-m-d H:i:s', (int) $stats['generated_at']),
                        date('Y-m-d H:i', (int) $stats['today_start']),
                        (int) $stats['all_total']
                    );
                    ?>
                </p>
            </div>

            <div class="commentaudit-card">
                <h3><?php _e('运行状态'); ?></h3>
                <table class="commentaudit-table">
                    <tbody>
                    <tr><th><?php _e('插件版本'); ?></th><td><?php echo $h(Plugin::VERSION); ?></td></tr>
                    <tr><th><?php _e('DeepSeek 模型'); ?></th><td><?php echo $h($plugin['deepseekModel']); ?></td></tr>
                    <tr><th><?php _e('API Key'); ?></th><td><?php echo $plugin['deepseekApiKey'] !== '' ? _t('已配置') : _t('未配置'); ?></td></tr>
                    <tr><th><?php _e('SSL / 调试'); ?></th><td><?php echo !empty($plugin['deepseekSslVerify']) ? _t('SSL 验证开启') : _t('SSL 验证关闭'); ?> / <?php echo !empty($plugin['deepseekDebug']) ? _t('调试开启') : _t('调试关闭'); ?></td></tr>
                    </tbody>
                </table>
                <div class="commentaudit-actions">
                    <a class="btn" href="<?php echo $h($pluginSettingsUrl); ?>"><?php _e('打开插件设置'); ?></a>
                    <button type="button" class="btn" id="commentaudit-overview-test"
                            data-url="<?php echo $h($actionUrl('test-connection')); ?>"><?php _e('测试连接'); ?></button>
                    <span id="commentaudit-overview-test-result" class="commentaudit-muted"><?php _e('尚未测试'); ?></span>
                </div>
            </div>

            <div class="commentaudit-card">
                <h3><?php _e('规则摘要'); ?></h3>
                <?php
                $scopeText = [];
                foreach ((array) $plugin['wordScopes'] as $scope) {
                    $scopeMap = ['text' => _t('内容'), 'author' => _t('昵称'), 'mail' => _t('邮箱'), 'url' => _t('网址')];
                    $scopeText[] = $scopeMap[$scope] ?? $scope;
                }
                ?>
                <table class="commentaudit-table">
                    <tbody>
                    <tr><th><?php _e('明确违禁词 / 疑似词'); ?></th><td><?php echo count(WordFilter::lines($plugin['blockWords'])); ?> / <?php echo count(WordFilter::lines($plugin['suspectWords'])); ?></td></tr>
                    <tr><th><?php _e('词表作用域'); ?></th><td><?php echo $scopeText ? $h(implode('、', $scopeText)) : _t('未选择'); ?></td></tr>
                    <tr><th><?php _e('IP / 邮箱 / 网址黑名单'); ?></th><td><?php echo count(WordFilter::lines($plugin['ipBlacklist'])); ?> / <?php echo count(WordFilter::lines($plugin['emailBlacklist'])); ?> / <?php echo count(WordFilter::lines($plugin['urlBlacklist'])); ?></td></tr>
                    <tr><th><?php _e('本地预检'); ?></th><td><?php echo _t('IP %s 条 / %s 秒；链接上限 %s', (int) $plugin['ipRateLimit'], (int) $plugin['ipRateWindow'], (int) $plugin['linkLimit']); ?></td></tr>
                    <tr><th><?php _e('三档阈值'); ?></th><td><?php echo $h($plugin['thresholdNew']); ?> / <?php echo $h($plugin['thresholdNormal']); ?> / <?php echo $h($plugin['thresholdTrusted']); ?></td></tr>
                    <tr><th><?php _e('评论列表增强'); ?></th><td><?php echo !empty($plugin['advancedCommentList']) ? _t('已开启') : _t('未开启'); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <script>
            (function () {
                var button = document.getElementById('commentaudit-overview-test');
                var result = document.getElementById('commentaudit-overview-test-result');
                if (!button || !result) return;
                button.addEventListener('click', function () {
                    var url = button.getAttribute('data-url');
                    if (!url) return;
                    button.disabled = true;
                    result.textContent = '<?php _e('测试中...'); ?>';
                    fetch(url, {method: 'POST', credentials: 'same-origin'})
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            result.textContent = data && data.success ? '<?php _e('连接正常'); ?>' : '<?php _e('连接失败，请检查设置'); ?>';
                            button.disabled = false;
                        })
                        .catch(function () {
                            result.textContent = '<?php _e('测试失败'); ?>';
                            button.disabled = false;
                        });
                });
            })();
            </script>

            <div class="commentaudit-card">
                <h3><?php _e('最近决策'); ?></h3>
                <table class="commentaudit-table">
                    <thead><tr><th><?php _e('时间'); ?></th><th><?php _e('作者'); ?></th><th><?php _e('决策'); ?></th><th><?php _e('原因'); ?></th><th><?php _e('信誉'); ?></th></tr></thead>
                    <tbody>
                    <?php if (empty($recent)): ?>
                        <tr><td colspan="5" class="commentaudit-muted"><?php _e('暂无记录'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($recent as $row): ?>
                            <tr>
                                <td><?php echo $h(date('Y-m-d H:i', (int) $row['created'])); ?></td>
                                <td><?php echo $h($row['author']); ?></td>
                                <td><span class="commentaudit-badge commentaudit-badge-<?php echo $h($row['decision']); ?>"><?php echo $h($decisionLabel($row['decision'])); ?></span></td>
                                <td><?php echo $h(Logger::reasonLabel($row['reason_code'])); ?></td>
                                <td><?php echo $h($trustLabel($row['trust_level'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($tab === 'summary'): ?>
            <?php
            $scopeText = [];
            foreach ((array) $plugin['wordScopes'] as $scope) {
                $scopeMap = ['text' => _t('内容'), 'author' => _t('昵称'), 'mail' => _t('邮箱'), 'url' => _t('网址')];
                $scopeText[] = $scopeMap[$scope] ?? $scope;
            }
            ?>
            <div class="commentaudit-card">
                <h3><?php _e('基础与本地规则概览'); ?></h3>
                <p class="commentaudit-desc"><?php _e('基础连接、本地规则和 AI 阈值已集中到插件原生设置页；这里只做只读概览。'); ?></p>
                <table class="commentaudit-table">
                    <tbody>
                    <tr><th><?php _e('DeepSeek 模型'); ?></th><td><?php echo $h($plugin['deepseekModel']); ?></td></tr>
                    <tr><th><?php _e('API Key'); ?></th><td><?php echo $plugin['deepseekApiKey'] !== '' ? _t('已配置') : _t('未配置'); ?></td></tr>
                    <tr><th><?php _e('明确违禁词 / 疑似词'); ?></th><td><?php echo count(WordFilter::lines($plugin['blockWords'])); ?> / <?php echo count(WordFilter::lines($plugin['suspectWords'])); ?></td></tr>
                    <tr><th><?php _e('词表作用域'); ?></th><td><?php echo $scopeText ? $h(implode('、', $scopeText)) : _t('未选择'); ?></td></tr>
                    <tr><th><?php _e('IP / 邮箱 / 网址黑名单'); ?></th><td><?php echo count(WordFilter::lines($plugin['ipBlacklist'])); ?> / <?php echo count(WordFilter::lines($plugin['emailBlacklist'])); ?> / <?php echo count(WordFilter::lines($plugin['urlBlacklist'])); ?></td></tr>
                    <tr><th><?php _e('本地预检'); ?></th><td><?php echo _t('IP %s 条 / %s 秒；链接上限 %s', (int) $plugin['ipRateLimit'], (int) $plugin['ipRateWindow'], (int) $plugin['linkLimit']); ?></td></tr>
                    <tr><th><?php _e('三档阈值'); ?></th><td><?php echo $h($plugin['thresholdNew']); ?> / <?php echo $h($plugin['thresholdNormal']); ?> / <?php echo $h($plugin['thresholdTrusted']); ?></td></tr>
                    <tr><th><?php _e('评论列表增强'); ?></th><td><?php echo !empty($plugin['advancedCommentList']) ? _t('已开启') : _t('未开启'); ?></td></tr>
                    </tbody>
                </table>
                <div class="commentaudit-actions">
                    <a class="btn primary" href="<?php echo $h($pluginSettingsUrl); ?>"><?php _e('打开插件设置页编辑基础与本地规则'); ?></a>
                </div>
            </div>

            <div class="commentaudit-card">
                <h3><?php _e('高级功能'); ?></h3>
                <p class="commentaudit-desc"><?php _e('审核规则与日志面板只保留高级工具和运行结果，避免与插件基础设置重复。'); ?></p>
                <div class="commentaudit-actions">
                    <a class="btn" href="<?php echo $h($tabUrl('reputation')); ?>"><?php _e('信誉管理'); ?></a>
                    <a class="btn" href="<?php echo $h($tabUrl('logs')); ?>"><?php _e('审核日志'); ?></a>
                    <a class="btn" href="<?php echo $h($tabUrl('test')); ?>"><?php _e('测试工具'); ?></a>
                    <a class="btn" href="<?php echo $h($tabUrl('advanced')); ?>"><?php _e('高级设置'); ?></a>
                </div>
            </div>

        <?php elseif ($tab === 'rules'): ?>
            <form method="post" action="<?php echo $h($actionUrl('save-rules')); ?>">
                <div class="commentaudit-card">
                    <h3><?php _e('词表'); ?></h3>
                    <p class="commentaudit-desc"><?php _e('明确违禁词命中直接拒绝；疑似词只作为 AI 的参考信号，不会直接拦截。'); ?></p>
                    <div class="commentaudit-grid">
                        <div class="commentaudit-field">
                            <label><?php _e('明确违禁词'); ?></label>
                            <textarea name="blockWords" rows="10" placeholder="<?php _e('每行一个词'); ?>"><?php echo $h($plugin['blockWords']); ?></textarea>
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('疑似敏感词'); ?></label>
                            <textarea name="suspectWords" rows="10" placeholder="<?php _e('每行一个词'); ?>"><?php echo $h($plugin['suspectWords']); ?></textarea>
                        </div>
                    </div>
                    <div class="commentaudit-field commentaudit-checkboxes">
                        <label><?php _e('作用域'); ?></label>
                        <?php $scopes = is_array($plugin['wordScopes']) ? $plugin['wordScopes'] : []; ?>
                        <label><input type="checkbox" name="wordScopes[]" value="text"<?php if (in_array('text', $scopes, true)): ?> checked<?php endif; ?>> <?php _e('评论内容'); ?></label>
                        <label><input type="checkbox" name="wordScopes[]" value="author"<?php if (in_array('author', $scopes, true)): ?> checked<?php endif; ?>> <?php _e('昵称'); ?></label>
                        <label><input type="checkbox" name="wordScopes[]" value="mail"<?php if (in_array('mail', $scopes, true)): ?> checked<?php endif; ?>> <?php _e('邮箱'); ?></label>
                        <label><input type="checkbox" name="wordScopes[]" value="url"<?php if (in_array('url', $scopes, true)): ?> checked<?php endif; ?>> <?php _e('网址'); ?></label>
                    </div>
                </div>

                <div class="commentaudit-card">
                    <h3><?php _e('黑名单'); ?></h3>
                    <div class="commentaudit-grid">
                        <div class="commentaudit-field">
                            <label><?php _e('IP 黑名单'); ?></label>
                            <textarea name="ipBlacklist" rows="6" placeholder="1.2.3.4&#10;1.2.3.0/24"><?php echo $h($plugin['ipBlacklist']); ?></textarea>
                            <span class="description"><?php _e('每行一个 IP 或 CIDR。'); ?></span>
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('邮箱黑名单'); ?></label>
                            <textarea name="emailBlacklist" rows="6" placeholder="spam@example.com&#10;*@spam.com"><?php echo $h($plugin['emailBlacklist']); ?></textarea>
                            <span class="description"><?php _e('每行一个邮箱或域名。'); ?></span>
                        </div>
                    </div>
                    <div class="commentaudit-field">
                        <label><?php _e('网址黑名单'); ?></label>
                        <textarea name="urlBlacklist" rows="6" placeholder="spam.com&#10;*.spam.com&#10;https://spam.com/path"><?php echo $h($plugin['urlBlacklist']); ?></textarea>
                        <span class="description"><?php _e('支持域名、子域名和 URL 前缀；评论正文与评论者网址都会检查。'); ?></span>
                    </div>
                </div>

                <div class="commentaudit-card">
                    <h3><?php _e('本地预检'); ?></h3>
                    <div class="commentaudit-grid-3">
                        <div class="commentaudit-field">
                            <label><?php _e('同一 IP 评论数上限'); ?></label>
                            <input type="number" name="ipRateLimit" min="0" value="<?php echo (int) $plugin['ipRateLimit']; ?>">
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('统计窗口（秒）'); ?></label>
                            <input type="number" name="ipRateWindow" min="60" value="<?php echo (int) $plugin['ipRateWindow']; ?>">
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('链接数量上限'); ?></label>
                            <input type="number" name="linkLimit" min="0" value="<?php echo (int) $plugin['linkLimit']; ?>">
                        </div>
                    </div>
                </div>

                <div class="commentaudit-actions">
                    <button type="submit" class="btn primary"><?php _e('保存本地规则'); ?></button>
                </div>
            </form>

        <?php elseif ($tab === 'deepseek'): ?>
            <form method="post" action="<?php echo $h($actionUrl('save-deepseek')); ?>">
                <div class="commentaudit-card">
                    <h3><?php _e('连接信息'); ?></h3>
                    <p class="commentaudit-desc"><?php _e('API Key、模型、Base URL、超时、SSL 和调试日志已移动到插件原生设置页。'); ?></p>
                    <table class="commentaudit-table">
                        <tbody>
                        <tr><th><?php _e('API Key'); ?></th><td><?php echo $plugin['deepseekApiKey'] !== '' ? _t('已配置') : _t('未配置'); ?></td></tr>
                        <tr><th><?php _e('模型'); ?></th><td><?php echo $h($plugin['deepseekModel']); ?></td></tr>
                        <tr><th><?php _e('Base URL'); ?></th><td><?php echo $h($plugin['deepseekBaseUrl']); ?></td></tr>
                        <tr><th><?php _e('超时 / 最大 token'); ?></th><td><?php echo (int) $plugin['deepseekTimeout']; ?>s / <?php echo (int) $plugin['deepseekMaxTokens']; ?></td></tr>
                        <tr><th><?php _e('SSL / 调试'); ?></th><td><?php echo !empty($plugin['deepseekSslVerify']) ? _t('开启') : _t('关闭'); ?> / <?php echo !empty($plugin['deepseekDebug']) ? _t('开启') : _t('关闭'); ?></td></tr>
                        </tbody>
                    </table>
                    <div class="commentaudit-actions">
                        <a class="btn primary" href="<?php echo $h($pluginSettingsUrl); ?>"><?php _e('在插件设置页修改连接信息'); ?></a>
                        <button type="button" class="btn" id="commentaudit-test-connection"
                                data-url="<?php echo $h($actionUrl('test-connection')); ?>"><?php _e('测试连接'); ?></button>
                    </div>
                    <pre id="commentaudit-connection-result" class="commentaudit-result"><?php _e('尚未测试'); ?></pre>
                </div>

                <div class="commentaudit-card">
                    <h3><?php _e('审核阈值'); ?></h3>
                    <p class="commentaudit-desc"><?php _e('AI 判定 block 且置信度达到阈值时直接拒绝；低于阈值按风险类别和信誉处理。'); ?></p>
                    <div class="commentaudit-grid-3">
                        <div class="commentaudit-field">
                            <label><?php _e('新用户'); ?></label>
                            <input type="number" step="0.05" min="0.1" max="1" name="thresholdNew" value="<?php echo $h($plugin['thresholdNew']); ?>">
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('普通用户'); ?></label>
                            <input type="number" step="0.05" min="0.1" max="1" name="thresholdNormal" value="<?php echo $h($plugin['thresholdNormal']); ?>">
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('老访客'); ?></label>
                            <input type="number" step="0.05" min="0.1" max="1" name="thresholdTrusted" value="<?php echo $h($plugin['thresholdTrusted']); ?>">
                        </div>
                    </div>
                    <div class="commentaudit-grid">
                        <div class="commentaudit-field">
                            <label><?php _e('AI 失败策略'); ?></label>
                            <input type="text" value="<?php _e('转待审（固定）'); ?>" disabled>
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('缓存天数'); ?></label>
                            <input type="number" name="cacheTtl" min="1" max="30" value="<?php echo (int) $plugin['cacheTtl']; ?>">
                        </div>
                    </div>
                </div>

                <div class="commentaudit-actions">
                    <button type="submit" class="btn primary"><?php _e('保存 DeepSeek 设置'); ?></button>
                </div>
            </form>

            <form method="post" action="<?php echo $h($actionUrl('clear-cache')); ?>" class="commentaudit-actions">
                <button type="submit" class="btn"><?php _e('清空 AI 缓存'); ?></button>
            </form>

            <script>
            (function () {
                var button = document.getElementById('commentaudit-test-connection');
                var result = document.getElementById('commentaudit-connection-result');
                if (!button || !result) return;
                button.addEventListener('click', function () {
                    var url = button.getAttribute('data-url');
                    if (!url) return;
                    button.disabled = true;
                    result.textContent = '<?php _e('测试中...'); ?>';
                    fetch(url, {method: 'POST', credentials: 'same-origin'})
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            result.textContent = JSON.stringify(data, null, 2);
                            button.disabled = false;
                        })
                        .catch(function (error) {
                            result.textContent = '<?php _e('测试失败：'); ?>' + error;
                            button.disabled = false;
                        });
                });
            })();
            </script>

        <?php elseif ($tab === 'reputation'): ?>
            <?php
            $keyword = (string) $request->get('q', '');
            $rows = [];
            try {
                $rows = Repository::recentReputations($keyword, 100);
            } catch (\Throwable $e) {
            }
            ?>
            <div class="commentaudit-card">
                <h3><?php _e('信誉查询'); ?></h3>
                <form method="get" action="<?php echo $h($options->adminUrl($panelBase, true)); ?>">
                    <input type="hidden" name="tab" value="reputation">
                    <input type="text" name="q" value="<?php echo $h($keyword); ?>" placeholder="<?php _e('邮箱 / 昵称 / IP'); ?>">
                    <button type="submit" class="btn"><?php _e('搜索'); ?></button>
                </form>
                <p class="commentaudit-desc"><?php _e('新用户：通过 0 条；普通用户：通过 1–2 条；老访客：通过 ≥3 条且 90 天无 spam。'); ?></p>
                <div class="commentaudit-table-wrap">
                    <table class="commentaudit-table commentaudit-reputation-table">
                        <thead><tr><th><?php _e('身份'); ?></th><th><?php _e('等级'); ?></th><th><?php _e('通过'); ?></th><th><?php _e('待审'); ?></th><th><?php _e('垃圾'); ?></th><th><?php _e('24h 中度辱骂'); ?></th><th><?php _e('最近出现'); ?></th><th><?php _e('操作'); ?></th></tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="commentaudit-muted"><?php _e('暂无记录'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td data-label="<?php _e('身份'); ?>"><span class="commentaudit-identity"><?php echo $h($row['identity_value']); ?></span></td>
                                    <td data-label="<?php _e('等级'); ?>"><?php echo $h($trustLabel($row['trust_level'])); ?></td>
                                    <td data-label="<?php _e('通过'); ?>"><?php echo (int) $row['approved_count']; ?></td>
                                    <td data-label="<?php _e('待审'); ?>"><?php echo (int) $row['waiting_count']; ?></td>
                                    <td data-label="<?php _e('垃圾'); ?>"><?php echo (int) $row['spam_count']; ?></td>
                                    <td data-label="<?php _e('24h 中度辱骂'); ?>"><?php echo (int) $row['moderate_abuse_24h']; ?></td>
                                    <td data-label="<?php _e('最近出现'); ?>"><?php echo $row['last_seen'] ? $h(date('Y-m-d H:i', (int) $row['last_seen'])) : '-'; ?></td>
                                    <td class="commentaudit-reputation-action" data-label="<?php _e('操作'); ?>">
                                        <form method="post" action="<?php echo $h($actionUrl('set-trust')); ?>" class="commentaudit-trust-form">
                                            <input type="hidden" name="identity_hash" value="<?php echo $h($row['identity_hash']); ?>">
                                            <select name="mode">
                                                <option value="whitelist"><?php _e('白名单'); ?></option>
                                                <option value="auto"><?php _e('恢复自动'); ?></option>
                                            </select>
                                            <button type="submit" class="btn btn-s"><?php _e('保存'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($tab === 'logs'): ?>
            <?php
            $filters = [
                'decision' => (string) $request->get('decision', ''),
                'reason_code' => (string) $request->get('reason_code', ''),
                'trust_level' => (string) $request->get('trust_level', ''),
                'keyword' => (string) $request->get('keyword', ''),
            ];
            $rows = [];
            try {
                $rows = Repository::recentLogs(100, $filters);
            } catch (\Throwable $e) {
            }
            ?>
            <div class="commentaudit-card">
                <h3><?php _e('审核日志'); ?></h3>
                <form method="get" action="<?php echo $h($options->adminUrl($panelBase, true)); ?>" class="commentaudit-grid-3">
                    <input type="hidden" name="tab" value="logs">
                    <div class="commentaudit-field">
                        <label><?php _e('决策'); ?></label>
                        <select name="decision">
                            <option value=""><?php _e('全部'); ?></option>
                            <?php foreach (['approved', 'waiting', 'rejected', 'spam'] as $item): ?>
                                <option value="<?php echo $h($item); ?>"<?php if ($filters['decision'] === $item): ?> selected<?php endif; ?>><?php echo $h($decisionLabel($item)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="commentaudit-field">
                        <label><?php _e('信誉'); ?></label>
                        <select name="trust_level">
                            <option value=""><?php _e('全部'); ?></option>
                            <?php foreach (['new', 'normal', 'trusted', 'whitelist'] as $item): ?>
                                <option value="<?php echo $h($item); ?>"<?php if ($filters['trust_level'] === $item): ?> selected<?php endif; ?>><?php echo $h($trustLabel($item)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="commentaudit-field">
                        <label><?php _e('关键词'); ?></label>
                        <input type="text" name="keyword" value="<?php echo $h($filters['keyword']); ?>" placeholder="<?php _e('作者 / 邮箱 / IP / AI 原因'); ?>">
                    </div>
                    <div class="commentaudit-actions">
                        <button type="submit" class="btn"><?php _e('筛选'); ?></button>
                    </div>
                </form>

                <table class="commentaudit-table commentaudit-log-table">
                    <thead><tr><th><?php _e('评论内容'); ?></th><th><?php _e('评论者'); ?></th><th><?php _e('审核状态'); ?></th><th><?php _e('时间'); ?></th><th><?php _e('详情'); ?></th></tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="5" class="commentaudit-muted"><?php _e('暂无记录'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $excerpt = (string) ($row['text'] ?? '');
                            if (function_exists('mb_substr')) {
                                $excerpt = mb_substr($excerpt, 0, 80, 'UTF-8');
                            } else {
                                $excerpt = substr($excerpt, 0, 80);
                            }
                            $logDetail = json_encode([
                                'coid' => (int) ($row['coid'] ?? 0),
                                'cid' => (int) ($row['cid'] ?? 0),
                                'author' => (string) ($row['author'] ?? ''),
                                'mail' => (string) ($row['mail'] ?? ''),
                                'ip' => (string) ($row['ip'] ?? ''),
                                'text' => (string) ($row['text'] ?? ''),
                                'decision' => (string) ($row['decision'] ?? ''),
                                'decision_label' => $decisionLabel($row['decision'] ?? ''),
                                'reason_code' => (string) ($row['reason_code'] ?? ''),
                                'reason_label' => Logger::reasonLabel((string) ($row['reason_code'] ?? '')),
                                'trust_level' => (string) ($row['trust_level'] ?? ''),
                                'trust_label' => $trustLabel($row['trust_level'] ?? ''),
                                'ai_decision' => (string) ($row['ai_decision'] ?? ''),
                                'ai_category' => (string) ($row['ai_category'] ?? ''),
                                'ai_severity' => (string) ($row['ai_severity'] ?? ''),
                                'ai_confidence' => $row['ai_confidence'] !== null ? (float) $row['ai_confidence'] : null,
                                'ai_reason' => (string) ($row['ai_reason'] ?? ''),
                                'on_topic' => (int) ($row['on_topic'] ?? 0),
                                'faithful' => (int) ($row['faithful'] ?? 0),
                                'threat_type' => (string) ($row['threat_type'] ?? ''),
                                'latency_ms' => (int) ($row['latency_ms'] ?? 0),
                                'tokens' => (int) ($row['tokens'] ?? 0),
                                'manual' => (int) ($row['manual'] ?? 0),
                                'created' => (int) ($row['created'] ?? 0),
                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
                            ?>
                            <tr>
                                <td data-label="<?php _e('评论内容'); ?>"><span class="commentaudit-log-excerpt"><?php echo $h($excerpt !== '' ? $excerpt : _t('（无内容）')); ?></span></td>
                                <td data-label="<?php _e('评论者'); ?>"><?php echo $h($row['author'] !== '' ? $row['author'] : _t('匿名')); ?></td>
                                <td data-label="<?php _e('审核状态'); ?>"><span class="commentaudit-badge commentaudit-badge-<?php echo $h($row['decision']); ?>"><?php echo $h($decisionLabel($row['decision'])); ?></span></td>
                                <td data-label="<?php _e('时间'); ?>"><?php echo $h(date('Y-m-d H:i', (int) $row['created'])); ?></td>
                                <td class="commentaudit-log-action"><button type="button" class="btn commentaudit-log-detail" data-commentaudit-log="<?php echo $h($logDetail); ?>"><?php _e('详情'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="commentaudit-log-modal" class="commentaudit-modal" hidden>
                <div class="commentaudit-modal-backdrop" data-commentaudit-close></div>
                <div class="commentaudit-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="commentaudit-log-modal-title">
                    <div class="commentaudit-modal-head">
                        <h3 id="commentaudit-log-modal-title"><?php _e('审核详情'); ?></h3>
                        <button type="button" class="commentaudit-modal-close" data-commentaudit-close aria-label="<?php _e('关闭'); ?>">×</button>
                    </div>
                    <div class="commentaudit-modal-body">
                        <div class="commentaudit-log-full" id="commentaudit-log-full"></div>
                        <table class="commentaudit-table commentaudit-log-detail-table">
                            <tbody>
                            <tr><th><?php _e('审核状态'); ?></th><td id="commentaudit-detail-decision"></td></tr>
                            <tr><th><?php _e('原因'); ?></th><td id="commentaudit-detail-reason"></td></tr>
                            <tr><th><?php _e('评论者'); ?></th><td id="commentaudit-detail-author"></td></tr>
                            <tr><th><?php _e('邮箱'); ?></th><td id="commentaudit-detail-mail"></td></tr>
                            <tr><th><?php _e('IP'); ?></th><td id="commentaudit-detail-ip"></td></tr>
                            <tr><th><?php _e('信誉'); ?></th><td id="commentaudit-detail-trust"></td></tr>
                            <tr><th><?php _e('AI 决策 / 类别'); ?></th><td id="commentaudit-detail-ai"></td></tr>
                            <tr><th><?php _e('AI 置信度 / 严重度'); ?></th><td id="commentaudit-detail-confidence"></td></tr>
                            <tr><th><?php _e('AI 原因'); ?></th><td id="commentaudit-detail-ai-reason"></td></tr>
                            <tr><th><?php _e('主题相关 / 忠实'); ?></th><td id="commentaudit-detail-flags"></td></tr>
                            <tr><th><?php _e('威胁类型'); ?></th><td id="commentaudit-detail-threat"></td></tr>
                            <tr><th><?php _e('耗时 / tokens'); ?></th><td id="commentaudit-detail-cost"></td></tr>
                            <tr><th><?php _e('评论 ID / 文章 ID'); ?></th><td id="commentaudit-detail-ids"></td></tr>
                            <tr><th><?php _e('时间 / 人工处理'); ?></th><td id="commentaudit-detail-time"></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <script>
            (function () {
                var modal = document.getElementById('commentaudit-log-modal');
                if (!modal) return;
                var fields = {
                    decision: document.getElementById('commentaudit-detail-decision'),
                    reason: document.getElementById('commentaudit-detail-reason'),
                    author: document.getElementById('commentaudit-detail-author'),
                    mail: document.getElementById('commentaudit-detail-mail'),
                    ip: document.getElementById('commentaudit-detail-ip'),
                    trust: document.getElementById('commentaudit-detail-trust'),
                    ai: document.getElementById('commentaudit-detail-ai'),
                    confidence: document.getElementById('commentaudit-detail-confidence'),
                    aiReason: document.getElementById('commentaudit-detail-ai-reason'),
                    flags: document.getElementById('commentaudit-detail-flags'),
                    threat: document.getElementById('commentaudit-detail-threat'),
                    cost: document.getElementById('commentaudit-detail-cost'),
                    ids: document.getElementById('commentaudit-detail-ids'),
                    time: document.getElementById('commentaudit-detail-time'),
                    full: document.getElementById('commentaudit-log-full')
                };
                var lastFocus = null;
                function val(value, fallback) {
                    return value === undefined || value === null || value === '' ? (fallback || '-') : String(value);
                }
                function fill(data) {
                    fields.decision.textContent = val(data.decision_label);
                    fields.reason.textContent = val(data.reason_label);
                    fields.author.textContent = val(data.author, '匿名');
                    fields.mail.textContent = val(data.mail);
                    fields.ip.textContent = val(data.ip);
                    fields.trust.textContent = val(data.trust_label);
                    fields.ai.textContent = val(data.ai_decision) + ' / ' + val(data.ai_category);
                    var confidence = data.ai_confidence === null ? '-' : Number(data.ai_confidence).toFixed(2);
                    fields.confidence.textContent = confidence + ' / ' + val(data.ai_severity);
                    fields.aiReason.textContent = val(data.ai_reason);
                    fields.flags.textContent = (Number(data.on_topic) ? '相关' : '不相关') + ' / ' + (Number(data.faithful) ? '忠实' : '不忠实');
                    fields.threat.textContent = val(data.threat_type);
                    fields.cost.textContent = (Number(data.latency_ms) ? data.latency_ms + 'ms' : '-') + ' / ' + (Number(data.tokens) || '-');
                    fields.ids.textContent = (Number(data.coid) > 0 ? '#' + data.coid : '未入库') + ' / #' + (Number(data.cid) || '-');
                    fields.time.textContent = data.created ? new Date(Number(data.created) * 1000).toLocaleString() + (Number(data.manual) ? ' / 人工处理' : '') : '-';
                    fields.full.textContent = val(data.text, '（无内容）');
                }
                function openModal(button) {
                    var raw = button.getAttribute('data-commentaudit-log');
                    if (!raw) return;
                    try {
                        fill(JSON.parse(raw));
                    } catch (error) {
                        return;
                    }
                    lastFocus = button;
                    modal.hidden = false;
                    document.body.classList.add('commentaudit-modal-open');
                }
                function closeModal() {
                    modal.hidden = true;
                    document.body.classList.remove('commentaudit-modal-open');
                    if (lastFocus) lastFocus.focus();
                }
                document.addEventListener('click', function (event) {
                    var button = event.target.closest ? event.target.closest('.commentaudit-log-detail') : null;
                    if (button) {
                        event.preventDefault();
                        openModal(button);
                        return;
                    }
                    if (event.target.closest && event.target.closest('[data-commentaudit-close]')) {
                        closeModal();
                    }
                });
                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && !modal.hidden) closeModal();
                });
            })();
            </script>

        <?php elseif ($tab === 'test'): ?>
            <div class="commentaudit-card">
                <h3><?php _e('规则测试'); ?></h3>
                <p class="commentaudit-desc"><?php _e('测试不会写入评论，也不会写入审核日志。'); ?></p>
                <form id="commentaudit-test-form" method="post" action="<?php echo $h($actionUrl('test')); ?>">
                    <div class="commentaudit-grid">
                        <div class="commentaudit-field">
                            <label><?php _e('昵称'); ?></label>
                            <input type="text" name="author" value="测试用户">
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('邮箱'); ?></label>
                            <input type="text" name="mail" value="test@example.com">
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('IP'); ?></label>
                            <input type="text" name="ip" value="127.0.0.1">
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('评论者网址'); ?></label>
                            <input type="text" name="url" value="">
                        </div>
                    </div>
                    <div class="commentaudit-field">
                        <label><?php _e('评论内容'); ?></label>
                        <textarea name="text" rows="5" required>这文章写得不错，学习了。</textarea>
                    </div>
                    <div class="commentaudit-grid">
                        <div class="commentaudit-field">
                            <label><?php _e('文章标题'); ?></label>
                            <input type="text" name="title" value="测试文章">
                        </div>
                        <div class="commentaudit-field">
                            <label><?php _e('文章分类'); ?></label>
                            <input type="text" name="category" value="">
                        </div>
                    </div>
                    <div class="commentaudit-field">
                        <label><?php _e('文章摘要'); ?></label>
                        <textarea name="excerpt" rows="2"></textarea>
                    </div>
                    <div class="commentaudit-field">
                        <label><?php _e('文章标签（逗号分隔）'); ?></label>
                        <input type="text" name="tags" value="">
                    </div>
                    <div class="commentaudit-actions">
                        <button type="submit" class="btn primary"><?php _e('运行测试'); ?></button>
                    </div>
                </form>
                <pre id="commentaudit-test-result" class="commentaudit-result"><?php _e('等待测试...'); ?></pre>
            </div>
            <script>
            (function () {
                var form = document.getElementById('commentaudit-test-form');
                var result = document.getElementById('commentaudit-test-result');
                if (!form || !result) return;
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    result.textContent = '<?php _e('测试中...'); ?>';
                    fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin'})
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            result.textContent = JSON.stringify(data, null, 2);
                        })
                        .catch(function (error) {
                            result.textContent = '<?php _e('测试失败：'); ?>' + error;
                        });
                });
            })();
            </script>

        <?php elseif ($tab === 'advanced'): ?>
            <form method="post" action="<?php echo $h($actionUrl('save-advanced')); ?>">
                <div class="commentaudit-card">
                    <h3><?php _e('高级设置'); ?></h3>
                    <div class="commentaudit-field commentaudit-checkboxes">
                        <label>
                            <input type="checkbox" name="advancedCommentList" value="1"<?php if (!empty($plugin['advancedCommentList'])): ?> checked<?php endif; ?>>
                            <?php _e('启用评论列表增强'); ?>
                        </label>
                        <p class="description"><?php _e('默认关闭。开启后会在 Typecho 原生评论列表显示审核原因、AI 详情和快捷拉黑；不改核心文件，关闭后恢复原样。'); ?></p>
                    </div>
                    <div class="commentaudit-field">
                        <label><?php _e('审核日志保留天数'); ?></label>
                        <input type="number" name="logRetention" min="1" max="3650" value="<?php echo (int) $plugin['logRetention']; ?>">
                    </div>
                    <div class="commentaudit-actions">
                        <button type="submit" class="btn primary"><?php _e('保存高级设置'); ?></button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>

<?php
include 'copyright.php';
include 'common-js.php';
include 'footer.php';
?>
