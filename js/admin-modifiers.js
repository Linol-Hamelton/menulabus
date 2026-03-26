/* admin-modifiers.js — Admin CRUD for modifier groups and options */
(function () {
    'use strict';

    var itemId = 0;
    var csrf   = '';
    var groups = [];

    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="csrf_token"]')?.value
            || document.body?.getAttribute('data-csrf-token')
            || '';
    }

    function init() {
        var wrap = document.getElementById('modifiersSection');
        if (!wrap) return;
        itemId = parseInt(wrap.dataset.itemId, 10) || 0;
        csrf   = getCsrfToken();
        if (!itemId) return;
        loadModifiers();
        document.getElementById('addModifierGroupBtn')
            ?.addEventListener('click', addGroup);
    }

    function api(body) {
        var token = csrf || getCsrfToken();
        return fetch('/api/save-modifiers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify(Object.assign({ csrf_token: token }, body))
        }).then(function (r) { return r.json(); });
    }

    function loadModifiers() {
        api({ action: 'get', item_id: itemId }).then(function (data) {
            groups = data.groups || [];
            renderGroups();
        }).catch(function () { /* table not yet created */ });
    }

    function renderGroups() {
        var list = document.getElementById('modifierGroupList');
        if (!list) return;
        list.innerHTML = '';
        if (groups.length === 0) {
            list.innerHTML = '<p class="mod-empty">Модификаторов нет</p>';
            return;
        }
        groups.forEach(function (g) {
            var div = document.createElement('div');
            div.className = 'mod-group';
            div.dataset.groupId = g.id;
            div.innerHTML =
                '<div class="mod-group-header">' +
                    '<span class="mod-group-name">' + escHtml(g.name) + '</span>' +
                    '<span class="mod-group-type">' + (g.type === 'radio' ? 'Один вариант' : 'Несколько') + (g.required ? ', обязательно' : '') + '</span>' +
                    '<button class="mod-btn-sm mod-del-group mod-btn-icon-only" data-group-id="' + g.id + '" aria-label="Удалить группу" title="Удалить группу">' +
                        '<svg class="btn-inline-icon" aria-hidden="true" viewBox="0 0 256 256"><use href="/images/icons/phosphor-sprite.svg#x"></use></svg>' +
                    '</button>' +
                '</div>' +
                '<div class="mod-options" id="opts-' + g.id + '">' + renderOptions(g) + '</div>' +
                '<div class="mod-add-option-row">' +
                    '<input type="text" class="mod-opt-name" placeholder="Вариант" maxlength="100">' +
                    '<input type="number" class="mod-opt-price" placeholder="+₽" step="0.01" min="0" max="9999">' +
                    '<button class="mod-btn-sm mod-add-opt" data-group-id="' + g.id + '">' +
                        '<svg class="btn-inline-icon" aria-hidden="true" viewBox="0 0 256 256"><use href="/images/icons/phosphor-sprite.svg#plus"></use></svg>' +
                        '<span>Вариант</span>' +
                    '</button>' +
                '</div>';
            list.appendChild(div);
        });

        list.querySelectorAll('.mod-del-group').forEach(function (btn) {
            btn.addEventListener('click', function () { deleteGroup(parseInt(this.dataset.groupId, 10)); });
        });
        list.querySelectorAll('.mod-del-opt').forEach(function (btn) {
            btn.addEventListener('click', function () { deleteOption(parseInt(this.dataset.optionId, 10)); });
        });
        list.querySelectorAll('.mod-add-opt').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var gid   = parseInt(this.dataset.groupId, 10);
                var row   = this.closest('.mod-add-option-row');
                var name  = row.querySelector('.mod-opt-name').value.trim();
                var price = parseFloat(row.querySelector('.mod-opt-price').value) || 0;
                if (!name) return;
                addOption(gid, name, price);
            });
        });
    }

    function renderOptions(group) {
        if (!group.options || !group.options.length) return '<p class="mod-empty">Вариантов нет</p>';
        return group.options.map(function (o) {
            return '<div class="mod-option">' +
                '<span>' + escHtml(o.name) + (o.price_delta ? ' (+' + o.price_delta + ' ₽)' : '') + '</span>' +
                '<button class="mod-btn-sm mod-del-opt mod-btn-icon-only" data-option-id="' + o.id + '" aria-label="Удалить вариант" title="Удалить вариант">' +
                    '<svg class="btn-inline-icon" aria-hidden="true" viewBox="0 0 256 256"><use href="/images/icons/phosphor-sprite.svg#x"></use></svg>' +
                '</button>' +
            '</div>';
        }).join('');
    }

    function addGroup() {
        var nameEl = document.getElementById('newGroupName');
        var typeEl = document.getElementById('newGroupType');
        var reqEl  = document.getElementById('newGroupRequired');
        var name   = (nameEl?.value || '').trim();
        if (!name) { nameEl?.focus(); return; }
        api({
            action: 'save_group', item_id: itemId, name: name,
            type: typeEl?.value || 'radio', required: reqEl?.checked || false, sort_order: groups.length
        }).then(function (data) {
            if (data.success) {
                if (nameEl) nameEl.value = '';
                loadModifiers();
            }
        });
    }

    function deleteGroup(groupId) {
        if (!confirm('Удалить группу модификаторов?')) return;
        api({ action: 'delete_group', group_id: groupId }).then(function () { loadModifiers(); });
    }

    function addOption(groupId, name, priceDelta) {
        api({
            action: 'save_option', group_id: groupId, name: name,
            price_delta: priceDelta, sort_order: 0
        }).then(function (data) {
            if (data.success) loadModifiers();
        });
    }

    function deleteOption(optionId) {
        api({ action: 'delete_option', option_id: optionId }).then(function () { loadModifiers(); });
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
