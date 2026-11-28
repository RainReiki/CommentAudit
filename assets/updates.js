(function () {
    'use strict';
    var root = document.getElementById('commentaudit-updater');
    if (!root) return;
    var check = root.querySelector('[data-update-check]');
    var install = root.querySelector('[data-update-install]');
    var recover = root.querySelector('[data-update-recover]');
    var status = root.querySelector('[data-update-status]');
    var detail = root.querySelector('[data-update-detail]');
    var version = null;
    function busy(value) {
        root.setAttribute('aria-busy', String(value));
        check.disabled = value || root.dataset.pending === '1';
        install.disabled = value;
        recover.disabled = value;
    }
    function request(action, values) {
        busy(true);
        return fetch(root.dataset[action], {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams(values || {}).toString()
        }).then(function (response) {
            if (!response.ok) throw new Error('请求失败，请刷新页面重试。');
            return response.json();
        }).then(function (result) {
            if (!result.success) throw new Error(result.message || '操作失败。');
            return result.data;
        }).finally(function () { busy(false); });
    }
    check.addEventListener('click', function () {
        install.hidden = true;
        detail.hidden = true;
        version = null;
        status.textContent = '正在检查 GitHub Releases…';
        request('check').then(function (data) {
            if (!data.available) {
                status.textContent = '当前已是最新版本。';
                return;
            }
            version = data.version;
            var plan = data.plan;
            status.textContent = '发现新版本 ' + version + '。';
            root.querySelector('[data-update-plan]').textContent = '更新 ' + plan.write.length + ' 个文件，删除 '
                + plan.delete.length + ' 个旧文件，下载约 ' + Math.ceil(plan.bytes / 1024) + ' KB，数据库迁移 '
                + plan.migrations.length + ' 个版本。';
            root.querySelector('[data-update-files]').textContent = plan.write.map(function (path) { return '更新 ' + path; })
                .concat(plan.delete.map(function (path) { return '删除 ' + path; }))
                .concat(plan.migrations.map(function (migration) { return '数据库 ' + migration.version; })).join('\n');
            root.querySelector('[data-update-notes]').textContent = data.notes || '本版本未提供说明。';
            detail.hidden = false;
            install.textContent = '升级到 ' + version;
            install.hidden = false;
        }).catch(function (error) { status.textContent = error.message; });
    });
    install.addEventListener('click', function () {
        if (!version) return;
        status.textContent = '正在下载差异文件、备份并升级。请等待完成…';
        request('install', {version: version}).then(function (data) {
            install.hidden = true;
            status.textContent = '已升级到 ' + data.version + '，请刷新页面。';
            check.disabled = true;
        }).catch(function (error) {
            install.hidden = true;
            recover.hidden = false;
            status.textContent = error.message + ' 如果连接中断，请点击恢复后刷新页面。';
        });
    });
    recover.addEventListener('click', function () {
        status.textContent = '正在恢复升级状态…';
        request('recover').then(function (data) {
            status.textContent = data.status === 'committed' ? '升级已完成，请刷新页面。' : '恢复已完成，请刷新页面。';
            recover.hidden = true;
            check.disabled = true;
        }).catch(function (error) { status.textContent = error.message; });
    });
})();
