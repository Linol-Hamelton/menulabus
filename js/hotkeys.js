(function () {
    'use strict';

    // Pluggable hotkey registry. Each page can push its own binding
    // before DOMContentLoaded via window.CleanmenuHotkeys.register().
    // Defaults cover the cross-page contract described in
    // docs/admin-menu-ux.md §5.3.
    var registry = [];

    function isTypingInField(target) {
        if (!target) return false;
        var tag = (target.tagName || '').toUpperCase();
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
        if (target.isContentEditable) return true;
        return false;
    }

    function register(binding) {
        if (!binding || typeof binding.key !== 'string' || typeof binding.handler !== 'function') return;
        registry.push(binding);
    }

    function matches(binding, event) {
        if (binding.key.length === 1) {
            // Case-sensitive for shifted keys like '?', case-insensitive for letters.
            if (binding.key === event.key) return true;
            if (binding.key.toLowerCase() === (event.key || '').toLowerCase()) return true;
            return false;
        }
        return binding.key === event.key;
    }

    function dispatch(event) {
        if (event.ctrlKey || event.metaKey || event.altKey) return;

        var typing = isTypingInField(event.target);
        for (var i = 0; i < registry.length; i++) {
            var b = registry[i];
            if (!matches(b, event)) continue;
            if (typing && !b.allowInField) continue;
            var consumed = b.handler(event);
            if (consumed === true) {
                event.preventDefault();
            }
            break;
        }
    }

    // Help overlay with the list of registered bindings — opened on '?'.
    var helpEl = null;
    function renderHelp() {
        if (helpEl) return helpEl;
        helpEl = document.createElement('div');
        helpEl.id = 'hotkeysHelpOverlay';
        helpEl.className = 'hotkeys-help-overlay';
        helpEl.setAttribute('role', 'dialog');
        helpEl.setAttribute('aria-label', 'Горячие клавиши');
        helpEl.hidden = true;

        var box = document.createElement('div');
        box.className = 'hotkeys-help-box';

        var title = document.createElement('h3');
        title.textContent = 'Горячие клавиши';
        box.appendChild(title);

        var list = document.createElement('dl');
        list.className = 'hotkeys-help-list';
        registry.forEach(function (b) {
            if (!b.description) return;
            var dt = document.createElement('dt');
            dt.innerHTML = '<kbd>' + escapeHtml(b.keyLabel || b.key) + '</kbd>';
            var dd = document.createElement('dd');
            dd.textContent = b.description;
            list.appendChild(dt);
            list.appendChild(dd);
        });
        box.appendChild(list);

        var hint = document.createElement('p');
        hint.className = 'hotkeys-help-hint';
        hint.textContent = 'Нажмите Esc, чтобы закрыть.';
        box.appendChild(hint);

        helpEl.appendChild(box);
        document.body.appendChild(helpEl);
        helpEl.addEventListener('click', function (event) {
            if (event.target === helpEl) toggleHelp(false);
        });
        return helpEl;
    }
    function toggleHelp(force) {
        renderHelp();
        var shouldShow = typeof force === 'boolean' ? force : helpEl.hidden;
        helpEl.hidden = !shouldShow;
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Default bindings — always present.
    register({
        key: '?',
        keyLabel: '?',
        description: 'Показать эту справку по горячим клавишам',
        allowInField: false,
        handler: function () { toggleHelp(); return true; },
    });

    register({
        key: 'Escape',
        keyLabel: 'Esc',
        description: 'Закрыть открытую модалку или эту справку',
        allowInField: true,
        handler: function () {
            if (helpEl && !helpEl.hidden) {
                toggleHelp(false);
                return true;
            }
            // Close any common modal patterns used in the project.
            var modals = document.querySelectorAll(
                '#payLinkModal:not([hidden]), #webhookHistoryPanel:not([hidden]), .modifier-modal.open, .modal.open'
            );
            var closed = false;
            modals.forEach(function (el) {
                if (el.id === 'webhookHistoryPanel') {
                    el.hidden = true;
                    closed = true;
                } else if (el.id === 'payLinkModal') {
                    var closer = document.getElementById('payLinkClose');
                    if (closer) { closer.click(); closed = true; }
                } else if (el.classList.contains('open')) {
                    el.classList.remove('open');
                    closed = true;
                }
            });
            return closed;
        },
    });

    register({
        key: '/',
        keyLabel: '/',
        description: 'Поставить фокус на поле поиска (если есть)',
        allowInField: false,
        handler: function () {
            var search = document.querySelector('input[type="search"], input[name="search"], input[name="q"], input.search-input');
            if (!search) return false;
            search.focus();
            if (typeof search.select === 'function') search.select();
            return true;
        },
    });

    register({
        key: 'n',
        keyLabel: 'n',
        description: 'Создать новый элемент (фокус на форму создания)',
        allowInField: false,
        handler: function () {
            var target = document.querySelector('[data-hotkey-new]')
                || document.querySelector('form#addItemForm input[name="name"]')
                || document.querySelector('form[action*="add"] input[name="name"]');
            if (!target) return false;
            target.focus();
            if (typeof target.scrollIntoView === 'function') {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return true;
        },
    });

    // Late bindings can still be added by page scripts before keydown fires.
    window.CleanmenuHotkeys = { register: register, toggleHelp: toggleHelp };

    document.addEventListener('keydown', dispatch);
})();
