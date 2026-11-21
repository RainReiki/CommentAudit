(function () {
    'use strict';

    var root = document.querySelector('.commentaudit-scan');
    if (!root) return;

    var form = root.querySelector('.commentaudit-scan-form');
    var scope = form.elements.scope;
    var mode = form.elements.mode;
    var start = form.querySelector('[type=submit]');
    var pause = form.querySelector('[data-scan-pause]');
    var reset = form.querySelector('[data-scan-reset]');
    var status = form.querySelector('[data-scan-status]');
    var completed = form.querySelector('[data-scan-completed]');
    var resultsCard = root.querySelector('.commentaudit-scan-results');
    var showFlagged = root.querySelector('[data-scan-flagged]');
    var list = root.querySelector('[data-scan-list]');
    var empty = root.querySelector('[data-scan-empty]');
    var pages = root.querySelector('[data-scan-pages]');
    var previous = root.querySelector('[data-scan-prev]');
    var next = root.querySelector('[data-scan-next]');
    var pageLabel = root.querySelector('[data-scan-page]');
    var template = root.querySelector('[data-scan-result]');
    var toolbar = root.querySelector('[data-scan-toolbar]');
    var selectAll = root.querySelector('[data-scan-select-all]');
    var clear = root.querySelector('[data-scan-clear]');
    var selectedLabel = root.querySelector('[data-scan-count]');
    var bulkMenu = root.querySelector('[data-scan-bulk]');
    var bulkToggle = root.querySelector('[data-scan-menu-toggle]');
    var moderationStatus = root.querySelector('[data-scan-moderation-status]');
    var confirmDialog = root.querySelector('[data-scan-confirm]');
    var confirmText = root.querySelector('[data-scan-confirm-text]');
    var statuses = {approved: '已通过', waiting: '待审核', spam: '垃圾评论'};
    var suggestions = {approved: '未发现问题', waiting: '建议复核', rejected: '建议拦截'};
    var selected = new Set();
    var byId = new Map();
    var pageSize = 20;
    var moderationBatch = Number(root.dataset.moderateBatch);
    var completionKey = 'commentaudit-history-completed:' + window.location.pathname + window.location.search;
    var scan = null;
    var running = false;
    var moderating = false;
    var selectIncoming = false;
    var pauseRequested = false;
    var page = 0;

    function renderSelection() {
        selectedLabel.textContent = '已选 ' + selected.size + ' 条'
            + (running && selected.size ? '，暂停或完成扫描后可处理' : '');
        selectAll.disabled = moderating || !scan.flagged.some(function (result) {
            return !result.handled && !result.missing && !selected.has(result.id);
        });
        clear.disabled = moderating || !selected.size;
        var bulkDisabled = running || moderating || !selected.size;
        bulkToggle.setAttribute('aria-disabled', String(bulkDisabled));
        if (bulkDisabled) bulkMenu.open = false;
    }

    function updateVisibleSelection() {
        list.querySelectorAll('[data-scan-select]').forEach(function (checkbox) {
            checkbox.checked = selected.has(Number(checkbox.value));
        });
        renderSelection();
    }

    function renderResult(result) {
        var entry = template.content.cloneNode(true);
        var label = entry.querySelector('[data-scan-suggestion]');
        entry.querySelector('[data-scan-name]').textContent =
            '#' + result.id + ' · ' + (result.author || '匿名') + (result.article ? ' · ' + result.article : '');
        label.textContent = result.suggestion === 'approved' && scan.mode === 'local'
            ? '未命中本地规则'
            : suggestions[result.suggestion];
        label.classList.add('commentaudit-badge-' + result.suggestion);
        entry.querySelector('[data-scan-excerpt]').textContent = result.excerpt || '（无正文）';
        entry.querySelector('[data-scan-reason]').textContent =
            (result.deleted ? '已删除' : result.missing ? '评论已不存在' : '当前状态：' + statuses[result.status])
            + (result.handled ? ' · 已处理' : '') + ' · ' + result.reason
            + (result.aiReason ? ' · ' + result.aiReason : '');

        var checkbox = entry.querySelector('[data-scan-select]');
        var singleMode = entry.querySelector('[data-scan-single]');
        var singleButton = entry.querySelector('[data-scan-apply-one]');
        checkbox.disabled = moderating || !!result.missing;
        singleMode.disabled = running || moderating || !!result.missing;
        singleButton.disabled = true;
        singleMode.addEventListener('change', function () { singleButton.disabled = !singleMode.value; });
        checkbox.value = result.id;
        checkbox.checked = selected.has(result.id);
        checkbox.addEventListener('change', function () {
            if (checkbox.checked) selected.add(result.id);
            else {
                selected.delete(result.id);
                selectIncoming = false;
            }
            renderSelection();
        });
        singleButton.addEventListener('click', function () { moderate([result.id], singleMode.value); });

        var url = new URL(root.dataset.commentsUrl, window.location.href);
        url.searchParams.set('cid', result.articleId);
        url.searchParams.set('status', result.status);
        var link = entry.querySelector('[data-scan-link]');
        link.href = url.href;
        link.hidden = !!result.missing;
        return entry;
    }

    function render() {
        scope.disabled = mode.disabled = !!scan;
        start.disabled = running || moderating || !!(scan && scan.done);
        start.hidden = !!(scan && scan.done);
        start.textContent = scan ? '继续扫描' : '开始扫描';
        pause.hidden = !running;
        pause.disabled = pauseRequested;
        reset.hidden = running || moderating || !scan;
        resultsCard.hidden = !scan;
        if (!scan) return;
        toolbar.hidden = !scan.results.length;
        renderSelection();

        var items = showFlagged.checked ? scan.flagged : scan.results;
        var pageCount = Math.max(1, Math.ceil(items.length / pageSize));
        page = Math.min(page, pageCount - 1);
        list.replaceChildren.apply(list, items.slice(page * pageSize, (page + 1) * pageSize).map(renderResult));
        empty.hidden = items.length > 0;
        empty.textContent = scan.results.length && showFlagged.checked ? '目前没有需要处理的评论。' : '暂无扫描结果。';
        pages.hidden = pageCount < 2;
        pageLabel.textContent = (page + 1) + ' / ' + pageCount;
        previous.disabled = page === 0;
        next.disabled = page + 1 >= pageCount;
    }

    var scanMessage = '';
    function setStatus(message) {
        scanMessage = message;
        var pending = scan ? scan.flagged.filter(function (result) { return !result.handled && !result.missing; }).length : 0;
        status.textContent = message + (scan ? ' 已扫描 ' + scan.results.length + ' 条，需处理 ' + pending + ' 条。' : '');
    }

    async function post(url, body, timeout) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, timeout);
        try {
            var response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                body: body,
                signal: controller.signal
            });
            if (!response.ok) throw new Error();
            return await response.json();
        } finally {
            clearTimeout(timer);
        }
    }

    async function moderate(ids, mode, refreshAfterSuccess) {
        if (running || moderating || (!statuses[mode] && mode !== 'delete') || confirmDialog.open) return;

        var changes = ids.map(function (id) {
            var result = byId.get(id);
            if (!result || result.missing) return null;
            return {id: id, from: result.status, to: mode};
        }).filter(Boolean);

        if (!changes.length) {
            moderationStatus.textContent = '请选择要处理的评论。';
            return;
        }

        var description = mode === 'delete'
            ? '评论将被永久删除，无法撤销。'
            : '将评论设为「' + statuses[mode] + '」。';
        confirmText.textContent = '将处理 ' + changes.length + ' 条评论。' + description;
        var confirmed = await new Promise(function (resolve) {
            confirmDialog.returnValue = '';
            confirmDialog.addEventListener('close', function () {
                resolve(confirmDialog.returnValue === 'apply');
            }, {once: true});
            confirmDialog.showModal();
        });
        if (!confirmed) return;

        var counts = {updated: 0, unchanged: 0, stale: 0, missing: 0, failed: 0};
        var issue = '';
        moderating = true;
        render();
        try {
            for (var offset = 0; offset < changes.length; offset += moderationBatch) {
                moderationStatus.textContent = '正在处理，已确认 ' + offset + ' / ' + changes.length + ' 条…';
                var body = new URLSearchParams({changes: JSON.stringify(changes.slice(offset, offset + moderationBatch))});
                var batch = await post(root.dataset.moderateUrl, body, 20000);
                if (!batch.success) {
                    issue = batch.message;
                    break;
                }
                batch.results.forEach(function (item) {
                    var result = byId.get(item.id);
                    counts[item.outcome]++;
                    if (mode === 'delete' && item.outcome === 'updated') result.deleted = true;
                    if (item.outcome === 'missing' || result.deleted) result.missing = true;
                    else if (item.status) result.status = item.status;
                    if (item.outcome === 'updated' || item.outcome === 'unchanged') result.handled = true;
                    if (item.outcome !== 'failed') selected.delete(item.id);
                });
            }
        } catch (error) {
            issue = error.name === 'AbortError'
                ? '等待服务器响应超时，可在本页重试所选评论。'
                : '连接中断，可在本页重试所选评论。';
        } finally {
            moderating = false;
            render();
            setStatus(scanMessage);
            var processed = counts.updated + counts.unchanged;
            var summary = '已处理 ' + processed + ' 条。';
            if (counts.unchanged) summary += ' ' + counts.unchanged + ' 条已是所选状态。';
            if (counts.stale) summary += ' ' + counts.stale + ' 条状态已改变，请复核后再处理。';
            if (counts.missing) summary += ' ' + counts.missing + ' 条已不存在。';
            if (counts.failed) summary += ' ' + counts.failed + ' 条未能更新，可继续重试。';
            moderationStatus.textContent = summary + (issue ? ' ' + issue : '');
            if (refreshAfterSuccess && !issue && processed === changes.length) {
                try {
                    sessionStorage.setItem(completionKey, JSON.stringify({processed: processed, scope: scan.scope, mode: scan.mode}));
                } catch (error) {
                    // The page can still refresh if browser storage is unavailable.
                }
                window.location.reload();
            }
        }
    }

    async function run() {
        if (running || moderating || (scan && scan.done)) return;
        completed.hidden = true;
        if (!scan) {
            scan = {scope: scope.value, mode: mode.value, after: 0, through: null, done: false, results: [], flagged: []};
        }
        running = true;
        pauseRequested = false;
        render();
        setStatus('正在扫描…');

        try {
            while (!pauseRequested && !scan.done) {
                var body = new URLSearchParams({scope: scan.scope, mode: scan.mode, after: scan.after});
                if (scan.through !== null) body.set('through', scan.through);
                var batch = await post(form.action, body, Number(root.dataset.scanTimeout));
                if (!batch.success) {
                    setStatus(batch.message);
                    return;
                }
                scan.through = batch.through;
                scan.after = batch.after;
                scan.done = batch.done;
                scan.results.push.apply(scan.results, batch.results);
                batch.results.forEach(function (result) { byId.set(result.id, result); });
                var flagged = batch.results.filter(function (result) {
                    return result.suggestion !== 'approved';
                });
                scan.flagged.push.apply(scan.flagged, flagged);
                if (selectIncoming) flagged.forEach(function (result) { selected.add(result.id); });
                render();
                if (batch.pauseReason) {
                    setStatus('已暂停。' + batch.pauseReason);
                    return;
                }
                if (!pauseRequested || scan.done) {
                    setStatus(scan.done ? '扫描完成。' : '正在扫描…');
                }
            }
            if (!scan.done && pauseRequested) setStatus('已暂停。');
        } catch (error) {
            setStatus(error.name === 'AbortError'
                ? '扫描等待超时，可在本页继续扫描。'
                : '扫描中断。请检查登录状态或网络，再继续扫描。');
        } finally {
            running = false;
            render();
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        run();
    });
    pause.addEventListener('click', function () {
        pauseRequested = true;
        pause.disabled = true;
        setStatus('当前批次结束后暂停。');
    });
    reset.addEventListener('click', function () {
        scan = null;
        selectIncoming = false;
        selected.clear();
        byId.clear();
        page = 0;
        moderationStatus.textContent = '';
        setStatus('');
        render();
        scope.focus();
    });
    selectAll.addEventListener('click', function () {
        selectIncoming = true;
        scan.flagged.forEach(function (result) {
            if (!result.handled && !result.missing) selected.add(result.id);
        });
        updateVisibleSelection();
    });
    clear.addEventListener('click', function () {
        selectIncoming = false;
        selected.clear();
        updateVisibleSelection();
    });
    bulkToggle.addEventListener('click', function (event) {
        if (bulkToggle.getAttribute('aria-disabled') === 'true') event.preventDefault();
    });
    bulkMenu.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !bulkMenu.open) return;
        event.preventDefault();
        bulkMenu.open = false;
        bulkToggle.focus();
    });
    bulkMenu.addEventListener('click', function (event) {
        var choice = event.target.closest('[data-scan-bulk-action]');
        if (!choice) return;
        bulkMenu.open = false;
        bulkToggle.focus();
        moderate(Array.from(selected), choice.dataset.scanBulkAction, true);
    });
    showFlagged.addEventListener('change', function () { page = 0; render(); });
    previous.addEventListener('click', function () { page--; render(); });
    next.addEventListener('click', function () { page++; render(); });
    try {
        var completion = JSON.parse(sessionStorage.getItem(completionKey));
        sessionStorage.removeItem(completionKey);
        if (completion) {
            scope.value = completion.scope;
            if (completion.mode !== 'ai' || !mode.querySelector('[value=ai]').disabled) {
                mode.value = completion.mode;
            }
            completed.textContent = '已处理 ' + completion.processed + ' 条。';
            completed.hidden = false;
        }
    } catch (error) {
        // Browser storage is optional. Normal scan controls still work.
    }
    render();
})();
