<?php
if (!defined('__TYPECHO_ADMIN__')) { exit; }
$pendingUpdate = \TypechoPlugin\CommentAudit\Update\Guard::pending();
?>
<section class="commentaudit-card commentaudit-updater" id="commentaudit-updater"
    data-check="<?php echo $h($actionUrl('update-check')); ?>"
    data-install="<?php echo $h($actionUrl('update-install')); ?>"
    data-recover="<?php echo $h($actionUrl('update-recover')); ?>"
    data-pending="<?php echo $pendingUpdate ? '1' : '0'; ?>">
    <h3><?php _e('插件在线升级'); ?></h3>
    <p><?php _e('当前版本'); ?> <strong><?php echo $h(\TypechoPlugin\CommentAudit\Plugin::VERSION); ?></strong>
        · <a href="https://github.com/RainReiki/CommentAudit/releases" target="_blank" rel="noopener noreferrer">GitHub Releases</a></p>
    <p class="description"><?php _e('只下载有变化的文件，保留未变动文件。升级期间暂停售理插件请求；失败时恢复文件和插件数据表。跨版本数据库迁移按版本顺序执行。'); ?></p>
    <p class="description"><?php _e('服务器需要 PHP cURL、插件目录写入权限，以及足够的文件备份空间和数据库建表权限。'); ?></p>
    <?php if ($pendingUpdate): ?>
        <p class="commentaudit-update-warning"><?php _e('检测到上次升级中断，审核已暂停。请先恢复，完成后刷新页面。'); ?></p>
    <?php endif; ?>
    <div class="commentaudit-actions">
        <button type="button" class="btn" data-update-check<?php if ($pendingUpdate): ?> disabled<?php endif; ?>><?php _e('检查更新'); ?></button>
        <button type="button" class="btn primary" data-update-install hidden><?php _e('升级到新版本'); ?></button>
        <button type="button" class="btn" data-update-recover<?php if (!$pendingUpdate): ?> hidden<?php endif; ?>><?php _e('恢复中断的升级'); ?></button>
    </div>
    <p class="commentaudit-update-status" role="status" aria-live="polite" data-update-status></p>
    <div data-update-detail hidden>
        <p data-update-plan></p>
        <details><summary><?php _e('变动文件和迁移版本'); ?></summary><pre data-update-files></pre></details>
        <details><summary><?php _e('版本说明'); ?></summary><pre data-update-notes></pre></details>
    </div>
    <noscript><p><?php _e('在线升级需要启用 JavaScript。'); ?></p></noscript>
</section>
<script src="<?php echo $h(\TypechoPlugin\CommentAudit\Plugin::assetUrl('updates.js')); ?>" defer></script>
