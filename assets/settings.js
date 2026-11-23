(function () {
    'use strict';

    document.querySelectorAll('.commentaudit-config').forEach(function (form) {
        var tablist = form.querySelector('.commentaudit-config-tabs');
        if (!tablist) return;

        var tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
        var panels = tabs.map(function (tab) {
            return form.querySelector('#' + tab.getAttribute('aria-controls'));
        });
        if (!tabs.length || panels.some(function (panel) { return !panel; })) return;

        function select(index) {
            tabs.forEach(function (tab, position) {
                var selected = position === index;
                tab.setAttribute('aria-selected', String(selected));
                tab.tabIndex = selected ? 0 : -1;
                panels[position].hidden = !selected;
            });
        }

        tabs.forEach(function (tab, index) {
            panels[index].setAttribute('role', 'tabpanel');
            panels[index].setAttribute('aria-labelledby', tab.id);
            tab.addEventListener('click', function () { select(index); });
            tab.addEventListener('keydown', function (event) {
                var next;
                switch (event.key) {
                    case 'ArrowLeft':
                        next = (index + tabs.length - 1) % tabs.length;
                        break;
                    case 'ArrowRight':
                        next = (index + 1) % tabs.length;
                        break;
                    case 'Home':
                        next = 0;
                        break;
                    case 'End':
                        next = tabs.length - 1;
                        break;
                    default:
                        return;
                }
                event.preventDefault();
                select(next);
                tabs[next].focus();
            });
        });

        // Hidden panels stay in the form. Reveal the first invalid field before native validation focuses it.
        var validating = false;
        form.addEventListener('invalid', function (event) {
            if (validating) return;
            var index = panels.indexOf(event.target.closest('[role="tabpanel"]'));
            if (index === -1) return;
            validating = true;
            select(index);
            setTimeout(function () { validating = false; }, 0);
        }, true);

        select(0);
        tablist.hidden = false;
    });
})();

(function () {
    'use strict';

    var input = document.querySelector('input[name="deepseekModel"][data-commentaudit-models]');
    if (!input || !input.parentNode) return;

    var url = input.getAttribute('data-commentaudit-models');
    if (!url) return;

    var tools = document.createElement('div');
    tools.className = 'commentaudit-model-tools';
    tools.style.marginTop = '8px';
    tools.style.display = 'flex';
    tools.style.alignItems = 'center';
    tools.style.gap = '8px';
    tools.style.flexWrap = 'wrap';

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn';
    button.textContent = '获取模型列表';

    var status = document.createElement('span');
    status.className = 'commentaudit-muted';
    status.setAttribute('role', 'status');
    status.textContent = '保存设置后可获取列表';

    var list = document.createElement('datalist');
    list.id = 'commentaudit-model-list';
    input.setAttribute('list', list.id);

    tools.appendChild(button);
    tools.appendChild(status);
    tools.appendChild(list);
    input.parentNode.insertBefore(tools, input.nextSibling);

    button.addEventListener('click', function () {
        button.disabled = true;
        status.textContent = '获取中…';
        fetch(url, { method: 'POST', credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    status.textContent = (data && data.message) || '获取失败，请检查服务地址和密钥';
                    return;
                }
                var models = data.models || [];
                list.innerHTML = '';
                models.forEach(function (id) {
                    var option = document.createElement('option');
                    option.value = id;
                    list.appendChild(option);
                });
                status.textContent = models.length
                    ? '找到 ' + models.length + ' 个模型，点击输入框选择'
                    : '没有找到模型';
            })
            .catch(function () {
                status.textContent = '请求失败，请检查网络或登录状态';
            })
            .finally(function () {
                button.disabled = false;
            });
    });
})();
