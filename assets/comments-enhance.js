/* CommentAudit: optional native comment list enhancement. */
(function () {
    'use strict';

    var config = window.CommentAuditEnhance;
    if (!config || !config.actionUrl) {
        return;
    }

    var decisionLabels = {
        approved: '通过',
        waiting: '待审',
        rejected: '拒绝',
        spam: '垃圾'
    };

    var trustLabels = {
        new: '新用户',
        normal: '普通用户',
        trusted: '老访客',
        whitelist: '白名单'
    };

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, {once: true});
        } else {
            fn();
        }
    }

    function text(value) {
        return value === null || value === undefined ? '' : String(value);
    }

    function element(tag, className, content) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (content !== undefined) {
            node.textContent = text(content);
        }
        return node;
    }

    function findRows() {
        var form = document.querySelector('form[name="manage_comments"]');
        if (form) {
            return Array.prototype.slice.call(form.querySelectorAll('tbody tr'));
        }

        return Array.prototype.slice.call(document.querySelectorAll('.typecho-list-table tbody tr'));
    }

    function rowCoId(row) {
        var input = row.querySelector('input[name="coid[]"]');
        return input && input.value ? String(input.value) : '';
    }

    function parseComment(row) {
        var raw = row.getAttribute('data-comment');
        if (!raw) {
            return {};
        }
        try {
            return JSON.parse(raw);
        } catch (error) {
            return {};
        }
    }

    function decideTarget(row) {
        return row.querySelector('.comment-head') ||
            row.querySelector('.comment-meta') ||
            row.querySelector('.comment-body') ||
            row;
    }

    function renderLog(row, log, comment) {
        if (!log || row.querySelector('.commentaudit-enhance')) {
            return;
        }

        var target = decideTarget(row);
        if (!target) {
            return;
        }

        var box = element('div', 'commentaudit-enhance');
        var line = element('div', 'commentaudit-enhance-row');

        var decision = text(log.decision || '');
        var badge = element(
            'span',
            'commentaudit-enhance-badge commentaudit-enhance-badge-' + decision,
            decisionLabels[decision] || decision || '未记录'
        );
        line.appendChild(badge);

        if (log.trust_level) {
            line.appendChild(element('span', 'commentaudit-enhance-badge', trustLabels[log.trust_level] || log.trust_level));
        }

        if (log.reason_label || log.reason_code) {
            line.appendChild(element('span', 'commentaudit-enhance-reason', log.reason_label || log.reason_code));
        }

        if (log.ai_confidence !== null && log.ai_confidence !== undefined && Number(log.ai_confidence) > 0) {
            line.appendChild(element('span', 'commentaudit-enhance-reason', 'AI ' + Number(log.ai_confidence).toFixed(2)));
        }

        var ip = text(comment.ip || '');
        var email = text(comment.mail || '');
        var coid = rowCoId(row);

        function addBlacklistAction(label, handle) {
            var link = element('span', 'commentaudit-enhance-link', label);
            link.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                var message = '确认' + label + '？';
                if (email) {
                    message += '\n邮箱：' + email;
                }
                if (ip) {
                    message += '\nIP：' + ip;
                }
                if (handle === 'spam') {
                    message += '\n该评论会从待审列表移到垃圾评论。';
                } else if (handle === 'delete') {
                    message += '\n该评论会被直接删除，无法恢复。';
                }

                if (!window.confirm(message)) {
                    return;
                }

                var body = new URLSearchParams();
                if (email) {
                    body.append('email', email);
                }
                if (ip) {
                    body.append('ip', ip);
                }
                if (coid) {
                    body.append('coid', coid);
                }
                body.append('handle', handle);

                fetch(config.blacklistUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).then(function () {
                    window.location.reload();
                }).catch(function () {
                    window.alert('操作失败，请稍后重试。');
                });
            });
            line.appendChild(link);
        }

        if (config.blacklistUrl && (ip || email)) {
            if (coid) {
                addBlacklistAction('拉黑并垃圾', 'spam');
                addBlacklistAction('拉黑并删除', 'delete');
            } else {
                addBlacklistAction('拉黑', 'none');
            }
        } else if (config.blacklistUrl && coid) {
            addBlacklistAction('拉黑并垃圾', 'spam');
            addBlacklistAction('拉黑并删除', 'delete');
        }

        box.appendChild(line);
        target.appendChild(box);
    }

    function run() {
        var rows = findRows();
        if (!rows.length) {
            return;
        }

        var coids = [];
        rows.forEach(function (row) {
            var coid = rowCoId(row);
            if (coid) {
                coids.push(coid);
            }
        });

        if (!coids.length) {
            return;
        }

        var url = config.actionUrl + '&coids=' + encodeURIComponent(coids.join(','));

        fetch(url, {credentials: 'same-origin'})
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success || !data.logs) {
                    return;
                }

                rows.forEach(function (row) {
                    var coid = rowCoId(row);
                    if (!coid || !data.logs[coid]) {
                        return;
                    }
                    renderLog(row, data.logs[coid], parseComment(row));
                });
            })
            .catch(function () {
                // 增强失败时保持原生列表不变。
            });
    }

    ready(run);
})();
