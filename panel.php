<?php

use TypechoPlugin\CommentAudit\Logger;
use TypechoPlugin\CommentAudit\Plugin;
use TypechoPlugin\CommentAudit\Repository;
use TypechoPlugin\CommentAudit\Rule\WordFilter;
use TypechoPlugin\CommentAudit\Update\Guard;
use Widget\Options;

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

/** @var Options $options */
$user->pass('administrator');
$adminPath = __TYPECHO_ROOT_DIR__ . '/' . trim(
    defined('__TYPECHO_ADMIN_DIR__') ? __TYPECHO_ADMIN_DIR__ : 'admin',
    '/\\'
);
$plugin = Plugin::settings();
$tabs = [
    'overview' => _t('概览'),
    'reputation' => _t('信誉管理'),
    'logs' => _t('审核日志'),
    'history' => _t('历史扫描'),
    'advanced' => _t('高级设置'),
    'updates' => _t('在线升级'),
];
$tab = $request->get('tab', 'overview');
$tab = is_string($tab) && isset($tabs[$tab]) ? $tab : 'overview';
if (Guard::pending()) { $tab = 'updates'; }

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

require $adminPath . '/header.php';
require $adminPath . '/menu.php';
?>
<link rel="stylesheet" href="<?php echo $h(Plugin::assetUrl('admin.css')); ?>">

<main class="main">
    <div class="body container commentaudit-wrap commentaudit-scope">
        <header class="commentaudit-hero">
            <div>
                <p class="commentaudit-eyebrow">CommentAudit</p>
                <h2><?php echo $h($tabs[$tab]); ?></h2>
                <p><?php
                    $intro = [
                        'overview' => _t('查看评论审核统计、运行状态和最近决策。'),
                        'reputation' => _t('查找访客信誉，设置白名单或恢复自动评级。'),
                        'logs' => _t('筛选历史审核记录并查看判定详情。'),
                        'history' => _t('用当前规则检查站内已有的评论，找出需要复核的内容。'),
                        'advanced' => _t('管理评论列表增强、日志保留和 AI 缓存。'),
                        'updates' => _t('从 GitHub Releases 检查正式版本，仅更新差异文件。'),
                    ];
                    echo $h($intro[$tab]);
                ?></p>
            </div>
            <a class="btn" href="<?php echo $h($pluginSettingsUrl); ?>"><?php _e('打开插件设置'); ?></a>
        </header>
        <nav class="commentaudit-nav" aria-label="<?php _e('评论审核页面'); ?>">
            <?php foreach ($tabs as $name => $label): ?>
                <a href="<?php echo $h($tabUrl($name)); ?>"<?php if ($tab === $name): ?> aria-current="page"<?php endif; ?>><?php echo $h($label); ?></a>
            <?php endforeach; ?>
        </nav>

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
                <p class="commentaudit-summary-note commentaudit-muted">
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
                    <button type="button" class="btn" id="commentaudit-overview-test"
                            data-url="<?php echo $h($actionUrl('test-connection')); ?>"><?php _e('测试连接'); ?></button>
                    <span id="commentaudit-overview-test-result" role="status" class="commentaudit-muted"><?php _e('尚未测试'); ?></span>
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
                    result.textContent = <?php echo json_encode(_t('测试中...'), JSON_HEX_TAG); ?>;
                    fetch(url, {method: 'POST', credentials: 'same-origin'})
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            var latency = data && data.result && Number(data.result.latency_ms) > 0
                                ? Number(data.result.latency_ms)
                                : 0;
                            var text = data && data.success
                                ? <?php echo json_encode(_t('连接正常'), JSON_HEX_TAG); ?>
                                : (data && data.message) || <?php echo json_encode(_t('连接失败，请检查设置'), JSON_HEX_TAG); ?>;
                            if (latency > 0) {
                                text += ' · ' + latency + 'ms';
                            }
                            result.textContent = text;
                            button.disabled = false;
                        })
                        .catch(function () {
                            result.textContent = <?php echo json_encode(_t('测试失败'), JSON_HEX_TAG); ?>;
                            button.disabled = false;
                        });
                });
            })();
            </script>

            <div class="commentaudit-card">
                <h3><?php _e('最近决策'); ?></h3>
                <table class="commentaudit-table commentaudit-log-table">
                    <thead><tr><th><?php _e('时间'); ?></th><th><?php _e('作者'); ?></th><th><?php _e('决策'); ?></th><th><?php _e('原因'); ?></th><th><?php _e('信誉'); ?></th></tr></thead>
                    <tbody>
                    <?php if (empty($recent)): ?>
                        <tr><td colspan="5" class="commentaudit-muted"><?php _e('暂无记录'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($recent as $row): ?>
                            <tr>
                                <td data-label="<?php _e('时间'); ?>"><?php echo $h(date('Y-m-d H:i', (int) $row['created'])); ?></td>
                                <td data-label="<?php _e('作者'); ?>"><?php echo $h($row['author']); ?></td>
                                <td data-label="<?php _e('决策'); ?>"><span class="commentaudit-badge commentaudit-badge-<?php echo $h($row['decision']); ?>"><?php echo $h($decisionLabel($row['decision'])); ?></span></td>
                                <td data-label="<?php _e('原因'); ?>"><?php echo $h(Logger::reasonLabel($row['reason_code'])); ?></td>
                                <td data-label="<?php _e('信誉'); ?>"><?php echo $h($trustLabel($row['trust_level'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

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
                <form class="commentaudit-search" method="get" action="<?php echo $h($options->adminUrl('extending.php', true)); ?>">
                    <input type="hidden" name="panel" value="CommentAudit/panel.php">
                    <input type="hidden" name="tab" value="reputation">
                    <input aria-label="<?php _e('搜索访客'); ?>" type="search" name="q" value="<?php echo $h($keyword); ?>" placeholder="<?php _e('邮箱 / 昵称 / IP'); ?>">
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
                                            <select name="mode" aria-label="<?php _e('信誉模式'); ?>">
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
                <form method="get" action="<?php echo $h($options->adminUrl('extending.php', true)); ?>" class="commentaudit-grid-3">
                    <input type="hidden" name="panel" value="CommentAudit/panel.php">
                    <input type="hidden" name="tab" value="logs">
                    <div class="commentaudit-field">
                        <label for="ca-filter-decision"><?php _e('决策'); ?></label>
                        <select id="ca-filter-decision" name="decision">
                            <option value=""><?php _e('全部'); ?></option>
                            <?php foreach (['approved', 'waiting', 'rejected', 'spam'] as $item): ?>
                                <option value="<?php echo $h($item); ?>"<?php if ($filters['decision'] === $item): ?> selected<?php endif; ?>><?php echo $h($decisionLabel($item)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="commentaudit-field">
                        <label for="ca-filter-trust_level"><?php _e('信誉'); ?></label>
                        <select id="ca-filter-trust_level" name="trust_level">
                            <option value=""><?php _e('全部'); ?></option>
                            <?php foreach (['new', 'normal', 'trusted', 'whitelist'] as $item): ?>
                                <option value="<?php echo $h($item); ?>"<?php if ($filters['trust_level'] === $item): ?> selected<?php endif; ?>><?php echo $h($trustLabel($item)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="commentaudit-field">
                        <label for="ca-filter-keyword"><?php _e('关键词'); ?></label>
                        <input type="text" id="ca-filter-keyword" name="keyword" value="<?php echo $h($filters['keyword']); ?>" placeholder="<?php _e('作者 / 邮箱 / IP / AI 原因'); ?>">
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
                                'identity_summary' => Logger::identitySummary($row['identity']),
                                'identity_evidence' => $row['identity']['evidence'] ?? [],
                                'conversation_summary' => Logger::conversationSummary($row['conversation']),
                                'conversation_ancestors' => $row['conversation']['ancestors'] ?? [],
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

            <dialog id="commentaudit-log-modal" class="commentaudit-modal" aria-labelledby="commentaudit-log-modal-title">
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
                            <tr><th><?php _e('审核上下文'); ?></th><td id="commentaudit-detail-conversation"></td></tr>
                            <tr><th><?php _e('上级对话摘要'); ?></th><td id="commentaudit-detail-ancestors"></td></tr>
                            <tr><th><?php _e('身份检查'); ?></th><td id="commentaudit-detail-identity"></td></tr>
                            <tr><th><?php _e('身份检查依据'); ?></th><td id="commentaudit-detail-identity-evidence"></td></tr>
                            <tr><th><?php _e('主题相关 / 忠实'); ?></th><td id="commentaudit-detail-flags"></td></tr>
                            <tr><th><?php _e('威胁类型'); ?></th><td id="commentaudit-detail-threat"></td></tr>
                            <tr><th><?php _e('耗时 / tokens'); ?></th><td id="commentaudit-detail-cost"></td></tr>
                            <tr><th><?php _e('评论 ID / 文章 ID'); ?></th><td id="commentaudit-detail-ids"></td></tr>
                            <tr><th><?php _e('时间 / 人工处理'); ?></th><td id="commentaudit-detail-time"></td></tr>
                            </tbody>
                        </table>
                    </div>
            </dialog>

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
                    conversation: document.getElementById('commentaudit-detail-conversation'),
                    ancestors: document.getElementById('commentaudit-detail-ancestors'),
                    identity: document.getElementById('commentaudit-detail-identity'),
                    identityEvidence: document.getElementById('commentaudit-detail-identity-evidence'),
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
                    fields.conversation.textContent = val(data.conversation_summary);
                    fields.ancestors.textContent = Array.isArray(data.conversation_ancestors) && data.conversation_ancestors.length
                        ? data.conversation_ancestors.map(function (item) {
                            return '#' + item.id + ' ' + val(item.author, '匿名') + '：' + val(item.text)
                                + (item.text_truncated ? '（已截取）' : '');
                        }).join('\n') : '无';
                    fields.ancestors.style.whiteSpace = 'pre-wrap';
                    fields.identity.textContent = val(data.identity_summary, '未记录');
                    fields.identityEvidence.textContent = Array.isArray(data.identity_evidence) && data.identity_evidence.length
                        ? data.identity_evidence.join('；') : '无';
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
                    modal.showModal();
                }
                function closeModal() {
                    modal.close();
                }
                document.addEventListener('click', function (event) {
                    var button = event.target.closest ? event.target.closest('.commentaudit-log-detail') : null;
                    if (button) {
                        event.preventDefault();
                        openModal(button);
                        return;
                    }
                    if (event.target === modal || event.target.closest('[data-commentaudit-close]')) {
                        closeModal();
                    }
                });
                modal.addEventListener('close', function () {
                    if (lastFocus) lastFocus.focus();
                });
            })();
            </script>

        <?php elseif ($tab === 'history'): ?>
            <?php require __DIR__ . '/Admin/history-panel.php'; ?>
        <?php elseif ($tab === 'updates'): ?>
            <?php require __DIR__ . '/Admin/update-panel.php'; ?>
        <?php elseif ($tab === 'advanced'): ?>
            <form method="post" action="<?php echo $h($actionUrl('save-advanced')); ?>">
                <div class="commentaudit-grid">
                    <section class="commentaudit-card">
                        <h3><?php _e('评论列表'); ?></h3>
                        <div class="commentaudit-field">
                            <label class="commentaudit-toggle">
                                <input type="checkbox" name="advancedCommentList" value="1"<?php if (!empty($plugin['advancedCommentList'])): ?> checked<?php endif; ?>>
                                <?php _e('启用评论列表增强'); ?>
                            </label>
                            <p class="description"><?php _e('在原生评论列表显示审核理由、信誉、AI 置信度和快捷拉黑操作。'); ?></p>
                        </div>
                    </section>
                    <section class="commentaudit-card">
                        <h3><?php _e('数据保留'); ?></h3>
                        <div class="commentaudit-field">
                            <label for="ca-log-retention"><?php _e('审核日志保留（天）'); ?></label>
                            <input id="ca-log-retention" type="number" name="logRetention" min="1" max="3650" required value="<?php echo (int) $plugin['logRetention']; ?>">
                            <p class="description"><?php _e('打开概览时会清理超期日志。默认保留 90 天。'); ?></p>
                        </div>
                        <div class="commentaudit-field">
                            <label for="ca-cache-ttl"><?php _e('新增 AI 缓存有效期（天）'); ?></label>
                            <input id="ca-cache-ttl" type="number" name="cacheTtl" min="1" required value="<?php echo (int) $plugin['cacheTtl']; ?>">
                            <p class="description"><?php _e('保存后，新写入的缓存采用此有效期。默认 7 天。'); ?></p>
                        </div>
                    </section>
                </div>
                <div class="commentaudit-actions commentaudit-save">
                    <button type="submit" class="btn primary"><?php _e('保存高级设置'); ?></button>
                </div>
            </form>
            <section class="commentaudit-card">
                <h3><?php _e('缓存维护'); ?></h3>
                <p class="commentaudit-desc"><?php _e('清空后，下次审核相同内容时会重新调用 AI。'); ?></p>
                <form method="post" action="<?php echo $h($actionUrl('clear-cache')); ?>">
                    <button type="submit" class="btn"><?php _e('清空 AI 缓存'); ?></button>
                </form>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php
require $adminPath . '/copyright.php';
require $adminPath . '/common-js.php';
require $adminPath . '/footer.php';
?>
