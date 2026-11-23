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

    var select = document.createElement('select');
    select.className = 'text';
    select.style.maxWidth = '320px';
    select.style.display = 'none';
    select.setAttribute('aria-label', '模型列表');

    tools.appendChild(button);
    tools.appendChild(select);
    tools.appendChild(status);
    input.parentNode.insertBefore(tools, input.nextSibling);

    button.addEventListener('click', function () {
        button.disabled = true;
        select.style.display = 'none';
        select.innerHTML = '';
        status.textContent = '获取中…';
        fetch(url, { method: 'POST', credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    status.textContent = (data && data.message) || '获取失败，请检查服务地址和密钥';
                    return;
                }
                var models = data.models || [];
                if (!models.length) {
                    status.textContent = '没有找到模型';
                    return;
                }

                var current = input.value.trim();
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '选择模型…';
                select.appendChild(placeholder);

                var foundCurrent = false;
                models.forEach(function (id) {
                    var option = document.createElement('option');
                    option.value = id;
                    option.textContent = id;
                    select.appendChild(option);
                    if (id === current) {
                        foundCurrent = true;
                    }
                });

                if (current && !foundCurrent) {
                    var currentOption = document.createElement('option');
                    currentOption.value = current;
                    currentOption.textContent = current + '（当前）';
                    select.appendChild(currentOption);
                }

                select.value = current || '';
                select.style.display = '';
                status.textContent = '找到 ' + models.length + ' 个模型，请选择';
            })
            .catch(function () {
                status.textContent = '请求失败，请检查网络或登录状态';
            })
            .finally(function () {
                button.disabled = false;
            });
    });

    select.addEventListener('change', function () {
        if (!select.value) return;
        input.value = select.value;
        status.textContent = '已选择 ' + select.value;
    });
})();
