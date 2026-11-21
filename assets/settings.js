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
