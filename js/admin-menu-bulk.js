(function () {
    'use strict';

    var table = document.querySelector('table.menu-items-table');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    var selectAll = table.querySelector('.bulk-select-all');
    var rowChecks = function () { return tbody.querySelectorAll('.bulk-select-row'); };
    var bar       = document.getElementById('bulkActionBar');
    var countEl   = document.getElementById('bulkActionCount');
    var moveSelect = document.getElementById('bulkMoveCategory');
    if (!bar) return;

    var csrfToken = table.getAttribute('data-csrf-token')
        || (document.body && document.body.getAttribute('data-csrf-token'))
        || '';

    function selectedIds() {
        var ids = [];
        rowChecks().forEach(function (cb) {
            if (cb.checked) {
                var id = parseInt(cb.value || '0', 10);
                if (id > 0) ids.push(id);
            }
        });
        return ids;
    }

    function refreshBar() {
        var ids = selectedIds();
        countEl.textContent = String(ids.length);
        bar.hidden = ids.length === 0;

        if (selectAll) {
            var total = rowChecks().length;
            selectAll.checked = total > 0 && ids.length === total;
            selectAll.indeterminate = ids.length > 0 && ids.length < total;
        }
    }

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

    function sendBulk(action, extra) {
        var ids = selectedIds();
        if (ids.length === 0) return;

        var body = Object.assign({
            action: action,
            ids: ids,
            csrf_token: csrfToken,
        }, extra || {});

        return fetch('/bulk-menu-action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        })
        .then(function (resp) { return resp.json().then(function (data) { return { ok: resp.ok, data: data }; }); })
        .then(function (result) {
            if (!result.ok || !result.data || !result.data.success) {
                var code = (result.data && result.data.error) || 'unknown';
                showToast('Ошибка: ' + code, 'error');
                return { ok: false };
            }
            showToast('Готово. Затронуто: ' + (result.data.affected || 0), 'success');
            return { ok: true, affected: result.data.affected || 0 };
        })
        .catch(function () {
            showToast('Сетевая ошибка', 'error');
            return { ok: false };
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var on = selectAll.checked;
            rowChecks().forEach(function (cb) { cb.checked = on; });
            refreshBar();
        });
    }

    tbody.addEventListener('change', function (event) {
        if (event.target && event.target.classList && event.target.classList.contains('bulk-select-row')) {
            refreshBar();
        }
    });

    bar.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.bulk-action-btn') : null;
        if (!btn) return;
        var action = btn.getAttribute('data-bulk-action') || '';

        if (action === 'clear') {
            rowChecks().forEach(function (cb) { cb.checked = false; });
            refreshBar();
            return;
        }

        var confirmText = null;
        if (action === 'archive') {
            confirmText = 'Архивировать выбранные позиции? Это действие можно отменить через страницу архива.';
        }
        if (confirmText && !window.confirm(confirmText)) return;

        btn.disabled = true;
        sendBulk(action).then(function (result) {
            btn.disabled = false;
            if (result && result.ok) {
                setTimeout(function () { window.location.reload(); }, 600);
            }
        });
    });

    if (moveSelect) {
        moveSelect.addEventListener('change', function () {
            var category = moveSelect.value || '';
            if (category === '') return;
            if (selectedIds().length === 0) {
                window.alert('Сначала выберите позиции.');
                moveSelect.value = '';
                return;
            }
            if (!window.confirm('Перенести выбранные позиции в категорию "' + category + '"?')) {
                moveSelect.value = '';
                return;
            }
            moveSelect.disabled = true;
            sendBulk('move', { category: category }).then(function (result) {
                moveSelect.disabled = false;
                moveSelect.value = '';
                if (result && result.ok) {
                    setTimeout(function () { window.location.reload(); }, 600);
                }
            });
        });
    }

    refreshBar();
})();
