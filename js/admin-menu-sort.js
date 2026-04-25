(function () {
    'use strict';

    var table = document.querySelector('table.menu-items-table');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    var csrfToken = table.getAttribute('data-csrf-token')
        || (document.body && document.body.getAttribute('data-csrf-token'))
        || '';

    // Debounced save buffer: keyed by category, value is the latest
    // [{id, position}] list seen in the DOM for that category. When the
    // debounce fires we flush one POST per changed category.
    var pendingByCategory = {};
    var saveTimer = null;
    var SAVE_DELAY_MS = 500;

    function showToast(text, kind) {
        var toast = document.getElementById('menu-sort-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'menu-sort-toast';
            toast.className = 'menu-sort-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = text;
        toast.className = 'menu-sort-toast menu-sort-toast-' + (kind || 'info');
        toast.hidden = false;
        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(function () { toast.hidden = true; }, 2500);
    }

    function snapshotCategory(category) {
        var rows = tbody.querySelectorAll('tr.sortable-row[data-category="' + cssEscape(category) + '"]');
        var order = [];
        rows.forEach(function (row, index) {
            var id = parseInt(row.getAttribute('data-item-id') || '0', 10);
            if (id > 0) {
                order.push({ id: id, position: index });
            }
        });
        return order;
    }

    function flushPending() {
        var categories = Object.keys(pendingByCategory);
        if (categories.length === 0) return;
        categories.forEach(function (category) {
            var payload = pendingByCategory[category];
            delete pendingByCategory[category];
            sendOrder(category, payload);
        });
    }

    function scheduleSave(category) {
        pendingByCategory[category] = snapshotCategory(category);
        clearTimeout(saveTimer);
        saveTimer = setTimeout(flushPending, SAVE_DELAY_MS);
    }

    function sendOrder(category, order) {
        if (!order || order.length === 0) return;
        fetch('/save-menu-order.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                category: category,
                order: order,
                csrf_token: csrfToken,
            }),
            credentials: 'same-origin',
        })
        .then(function (resp) { return resp.json().then(function (data) { return { ok: resp.ok, data: data }; }); })
        .then(function (result) {
            if (!result.ok || !result.data || !result.data.success) {
                var code = (result.data && result.data.error) || 'unknown';
                showToast('Не удалось сохранить порядок: ' + code, 'error');
                return;
            }
            showToast('Порядок сохранён (' + result.data.updated + ')', 'success');
        })
        .catch(function () {
            showToast('Сетевая ошибка при сохранении порядка', 'error');
        });
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/["\\]/g, '\\$&');
    }

    // HTML5 drag-n-drop wiring.
    var dragging = null;

    tbody.addEventListener('dragstart', function (event) {
        var tr = event.target && event.target.closest ? event.target.closest('tr.sortable-row') : null;
        if (!tr) return;
        dragging = tr;
        tr.classList.add('is-dragging');
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            // Required by Firefox for dragstart to fire.
            try { event.dataTransfer.setData('text/plain', tr.getAttribute('data-item-id') || ''); } catch (e) { /* noop */ }
        }
    });

    tbody.addEventListener('dragend', function () {
        if (dragging) {
            dragging.classList.remove('is-dragging');
            dragging = null;
        }
        tbody.querySelectorAll('.drop-above, .drop-below').forEach(function (el) {
            el.classList.remove('drop-above', 'drop-below');
        });
    });

    tbody.addEventListener('dragover', function (event) {
        if (!dragging) return;
        var target = event.target && event.target.closest ? event.target.closest('tr.sortable-row') : null;
        if (!target || target === dragging) return;
        if (target.getAttribute('data-category') !== dragging.getAttribute('data-category')) {
            return; // no cross-category drop in this iteration
        }
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';

        tbody.querySelectorAll('.drop-above, .drop-below').forEach(function (el) {
            el.classList.remove('drop-above', 'drop-below');
        });
        var rect = target.getBoundingClientRect();
        var above = event.clientY < (rect.top + rect.height / 2);
        target.classList.add(above ? 'drop-above' : 'drop-below');
    });

    tbody.addEventListener('drop', function (event) {
        if (!dragging) return;
        var target = event.target && event.target.closest ? event.target.closest('tr.sortable-row') : null;
        if (!target || target === dragging) return;
        if (target.getAttribute('data-category') !== dragging.getAttribute('data-category')) {
            return;
        }
        event.preventDefault();

        var rect = target.getBoundingClientRect();
        var above = event.clientY < (rect.top + rect.height / 2);
        if (above) {
            target.parentNode.insertBefore(dragging, target);
        } else {
            target.parentNode.insertBefore(dragging, target.nextSibling);
        }

        tbody.querySelectorAll('.drop-above, .drop-below').forEach(function (el) {
            el.classList.remove('drop-above', 'drop-below');
        });

        scheduleSave(dragging.getAttribute('data-category') || '');
    });
})();
