(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-inventory.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }

    function fmt(n) {
        if (n === null || n === undefined || n === '') return '—';
        var s = Number(n).toString();
        return s;
    }

    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ---- Ingredients ----
    var ingrTable = document.getElementById('invIngredientsTable');
    if (ingrTable) {
        ingrTable.addEventListener('click', function (event) {
            var tr = event.target && event.target.closest ? event.target.closest('tr') : null;
            if (!tr) return;

            var save = event.target.closest('.btn-inv-save');
            var arch = event.target.closest('.btn-inv-archive');
            var rest = event.target.closest('.btn-inv-restore');
            var hist = event.target.closest('.btn-inv-history');
            var apply = event.target.closest('.btn-inv-apply');

            if (save) {
                var id = parseInt(tr.getAttribute('data-ingredient-id') || '', 10) || null;
                var name  = ((tr.querySelector('.inv-name') || {}).value || '').trim();
                var unit  = ((tr.querySelector('.inv-unit') || {}).value || '').trim() || 'шт';
                var threshold = parseFloat((tr.querySelector('.inv-threshold') || {}).value || '0') || 0;
                var cost = parseFloat((tr.querySelector('.inv-cost') || {}).value || '0') || 0;
                var supplier = (tr.querySelector('.inv-supplier') || {}).value || '';
                var stockQty;
                if (id) {
                    // For existing rows, keep the current stock — adjustments go through Apply.
                    stockQty = parseFloat((tr.querySelector('.inv-stock-cell') || {}).textContent || '0') || 0;
                } else {
                    stockQty = parseFloat((tr.querySelector('.inv-new-stock') || {}).value || '0') || 0;
                }

                if (name === '') { window.alert('Укажите название.'); return; }
                save.disabled = true;
                api({
                    action: 'save_ingredient',
                    id: id,
                    name: name, unit: unit, stock_qty: stockQty,
                    reorder_threshold: threshold, cost_per_unit: cost,
                    supplier_id: supplier === '' ? null : parseInt(supplier, 10),
                }).then(function (r) {
                    save.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) {
                        window.alert('Не сохранилось: ' + ((r.data && r.data.error) || 'unknown'));
                        return;
                    }
                    window.location.reload();
                }).catch(function () { save.disabled = false; window.alert('Сетевая ошибка'); });
            }

            if (apply) {
                var iid = parseInt(tr.getAttribute('data-ingredient-id') || '0', 10);
                if (!iid) return;
                var delta = parseFloat((tr.querySelector('.inv-adjust-delta') || {}).value || '0') || 0;
                if (!delta) { window.alert('Введите положительное или отрицательное число.'); return; }
                var reason = delta > 0 ? 'receipt' : 'waste';
                if (!window.confirm('Изменить остаток на ' + delta + '?\nПричина: ' + reason)) return;
                apply.disabled = true;
                api({ action: 'adjust_stock', id: iid, delta: delta, reason: reason }).then(function (r) {
                    apply.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) {
                        window.alert('Не получилось: ' + ((r.data && r.data.error) || 'unknown'));
                        return;
                    }
                    var ing = r.data.ingredient || {};
                    var cell = tr.querySelector('.inv-stock-cell');
                    if (cell) cell.textContent = fmt(ing.stock_qty);
                    var deltaInput = tr.querySelector('.inv-adjust-delta');
                    if (deltaInput) deltaInput.value = '';
                }).catch(function () { apply.disabled = false; window.alert('Сетевая ошибка'); });
            }

            if (arch) {
                var aid = parseInt(tr.getAttribute('data-ingredient-id') || '0', 10);
                if (!aid) return;
                if (!window.confirm('Архивировать ингредиент #' + aid + '?')) return;
                arch.disabled = true;
                api({ action: 'archive_ingredient', id: aid }).then(function (r) {
                    arch.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); return; }
                    window.location.reload();
                }).catch(function () { arch.disabled = false; });
            }

            if (rest) {
                var rid = parseInt(tr.getAttribute('data-ingredient-id') || '0', 10);
                if (!rid) return;
                rest.disabled = true;
                api({ action: 'restore_ingredient', id: rid }).then(function (r) {
                    rest.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); return; }
                    window.location.reload();
                }).catch(function () { rest.disabled = false; });
            }

            if (hist) {
                var hid = parseInt(tr.getAttribute('data-ingredient-id') || '0', 10);
                if (!hid) return;
                loadHistory(hid, (tr.querySelector('.inv-name') || {}).value);
            }
        });
    }

    var histPanel  = document.getElementById('invHistoryPanel');
    var histTbody  = histPanel ? histPanel.querySelector('tbody') : null;
    var histMeta   = histPanel ? histPanel.querySelector('.inv-history-meta') : null;

    function loadHistory(id, name) {
        if (!histPanel || !histTbody) return;
        api({ action: 'list_movements', id: id, limit: 100 }).then(function (r) {
            if (!r.ok || !r.data || !r.data.success) { window.alert('История недоступна'); return; }
            histMeta.textContent = 'Ингредиент #' + id + (name ? ' · ' + name : '') + ' — последние ' + (r.data.movements || []).length + ' движений';
            histTbody.innerHTML = '';
            (r.data.movements || []).forEach(function (m) {
                var tr = document.createElement('tr');
                tr.className = parseFloat(m.delta) < 0 ? 'inv-mv-out' : 'inv-mv-in';
                var meta = [];
                if (m.order_id) meta.push('заказ #' + m.order_id);
                if (m.menu_item_id) meta.push('блюдо #' + m.menu_item_id);
                tr.innerHTML = ''
                    + '<td>#' + (m.id || '') + '</td>'
                    + '<td>' + escHtml(fmt(m.delta)) + '</td>'
                    + '<td>' + escHtml(m.reason || '') + '</td>'
                    + '<td>' + escHtml(meta.join(' · ')) + '</td>'
                    + '<td>' + escHtml(m.note || '') + '</td>'
                    + '<td>' + escHtml(m.created_at || '') + '</td>';
                histTbody.appendChild(tr);
            });
            histPanel.hidden = false;
            histPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }).catch(function () { window.alert('Сетевая ошибка'); });
    }

    // ---- Suppliers ----
    var supTable = document.getElementById('invSuppliersTable');
    if (supTable) {
        supTable.addEventListener('click', function (event) {
            var save = event.target.closest('.btn-sup-save');
            if (!save) return;
            var tr = event.target.closest('tr');
            if (!tr) return;
            var id = parseInt(tr.getAttribute('data-supplier-id') || '', 10) || null;
            var name = ((tr.querySelector('.sup-name') || {}).value || '').trim();
            var contact = ((tr.querySelector('.sup-contact') || {}).value || '').trim();
            var notes = ((tr.querySelector('.sup-notes') || {}).value || '').trim();
            if (name === '') { window.alert('Название обязательно.'); return; }
            save.disabled = true;
            api({ action: 'save_supplier', id: id, name: name, contact: contact, notes: notes }).then(function (r) {
                save.disabled = false;
                if (!r.ok || !r.data || !r.data.success) { window.alert('Не сохранилось'); return; }
                window.location.reload();
            }).catch(function () { save.disabled = false; });
        });
    }
})();
