<?php

use TypechoPlugin\CommentAudit\Plugin;

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

$scanHasKey = trim((string) $plugin['deepseekApiKey']) !== '';
$scanActions = [
    'spam' => _t('标记为垃圾'),
    'waiting' => _t('转为待审核'),
    'delete' => _t('直接删除'),
    'approved' => _t('设为通过'),
];
?>
<div class="commentaudit-scan"
     data-comments-url="<?php echo $h($options->adminUrl('manage-comments.php', true)); ?>"
     data-moderate-url="<?php echo $h($actionUrl('history-moderate')); ?>"
     data-moderate-batch="<?php echo \TypechoPlugin\CommentAudit\Admin\HistoryModeration::BATCH_SIZE; ?>"
     data-scan-timeout="<?php echo (max(3, (int) $plugin['deepseekTimeout']) + 15) * 1000; ?>">
    <section class="commentaudit-card">
        <form class="commentaudit-scan-form" method="post" action="<?php echo $h($actionUrl('history-scan')); ?>">
            <div class="commentaudit-grid">
                <div class="commentaudit-field">
                    <label for="ca-scan-scope"><?php _e('扫描范围'); ?></label>
                    <select id="ca-scan-scope" name="scope">
                        <option value="approved"><?php _e('已通过'); ?></option>
                        <option value="waiting"><?php _e('待审核'); ?></option>
                        <option value="spam"><?php _e('垃圾评论'); ?></option>
                        <option value="all"><?php _e('所有状态'); ?></option>
                    </select>
                </div>
                <div class="commentaudit-field">
                    <label for="ca-scan-mode"><?php _e('扫描方式'); ?></label>
                    <select id="ca-scan-mode" name="mode" aria-describedby="ca-scan-mode-help">
                        <option value="local"><?php _e('仅用本地规则'); ?></option>
                        <option value="ai"<?php if (!$scanHasKey): ?> disabled<?php endif; ?>><?php _e('本地规则 + DeepSeek'); ?></option>
                    </select>
                    <p class="description" id="ca-scan-mode-help"><?php
                        echo $h($scanHasKey
                            ? _t('选择 DeepSeek 后，未被本地规则直接拦截的评论会连同文章上下文提交给 DeepSeek，可能产生费用。')
                            : _t('在插件设置中填写密钥后，即可使用 DeepSeek 扫描。'));
                    ?></p>
                </div>
            </div>
            <div class="commentaudit-actions">
                <button type="submit" class="btn primary" disabled><?php _e('开始扫描'); ?></button>
                <button type="button" class="btn" data-scan-pause hidden><?php _e('暂停'); ?></button>
                <button type="button" class="btn" data-scan-reset hidden><?php _e('重新选择'); ?></button>
            </div>
            <p class="commentaudit-desc commentaudit-scan-note"><?php _e('扫描时可以先勾选评论，暂停或完成后即可处理。刷新页面会清除本次扫描结果。'); ?></p>
            <p class="commentaudit-scan-status" data-scan-completed role="status" hidden></p>
            <div class="commentaudit-scan-status" data-scan-status role="status"></div>
            <noscript><p><?php _e('请启用 JavaScript 后使用历史扫描。'); ?></p></noscript>
        </form>
    </section>

    <section class="commentaudit-card commentaudit-scan-results" hidden>
        <div class="commentaudit-scan-results-head">
            <h3><?php _e('扫描结果'); ?></h3>
            <label><input type="checkbox" data-scan-flagged checked> <?php _e('只看需处理的评论'); ?></label>
        </div>
        <div class="commentaudit-scan-toolbar" data-scan-toolbar hidden>
            <div class="commentaudit-actions">
                <button type="button" class="btn" data-scan-select-all><?php _e('全选需处理（含其他页）'); ?></button>
                <button type="button" class="btn" data-scan-clear><?php _e('取消选择'); ?></button>
                <span class="commentaudit-muted" data-scan-count></span>
            </div>
            <details class="commentaudit-scan-menu" data-scan-bulk>
                <summary class="btn" data-scan-menu-toggle aria-disabled="true"><?php _e('选中项'); ?> <span aria-hidden="true">▾</span></summary>
                <ul>
                    <?php foreach ($scanActions as $value => $label): ?>
                        <li><button type="button" data-scan-bulk-action="<?php echo $h($value); ?>"><?php echo $h($label); ?></button></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </div>
        <p class="commentaudit-scan-status" data-scan-moderation-status role="status"></p>
        <p class="commentaudit-muted" data-scan-empty hidden></p>
        <div data-scan-list></div>
        <div class="commentaudit-actions" data-scan-pages hidden>
            <button type="button" class="btn" data-scan-prev><?php _e('上一页'); ?></button>
            <span data-scan-page aria-live="polite"></span>
            <button type="button" class="btn" data-scan-next><?php _e('下一页'); ?></button>
        </div>
    </section>
    <dialog class="commentaudit-modal commentaudit-scan-confirm" data-scan-confirm aria-labelledby="ca-scan-confirm-title">
        <div class="commentaudit-modal-body">
            <h3 id="ca-scan-confirm-title"><?php _e('确认处理评论'); ?></h3>
            <p data-scan-confirm-text></p>
            <form method="dialog" class="commentaudit-actions">
                <button class="btn" value="cancel" autofocus><?php _e('取消'); ?></button>
                <button class="btn primary" value="apply"><?php _e('确认处理'); ?></button>
            </form>
        </div>
    </dialog>
    <template data-scan-result>
        <article class="commentaudit-scan-result">
            <header>
                <label><input type="checkbox" data-scan-select> <strong data-scan-name></strong></label>
                <span class="commentaudit-badge" data-scan-suggestion></span>
            </header>
            <p data-scan-excerpt></p>
            <span class="commentaudit-muted" data-scan-reason></span>
            <div class="commentaudit-actions">
                <select data-scan-single aria-label="<?php _e('单条处理方式'); ?>">
                    <option value=""><?php _e('选择操作'); ?></option>
                    <?php foreach ($scanActions as $value => $label): ?>
                        <option value="<?php echo $h($value); ?>"><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn" data-scan-apply-one><?php _e('处理这条'); ?></button>
                <a data-scan-link target="_blank" rel="noopener"><?php _e('打开该文章的评论'); ?></a>
            </div>
        </article>
    </template>
</div>
<script src="<?php echo $h(Plugin::assetUrl('history-scan.js')); ?>" defer></script>
