(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-egais.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; }, function () {
                return { ok: r.ok, data: { success: false, error: 'invalid_json' } };
            });
        });
    }

    function openDialog(dlg) {
        if (!dlg) return;
        if (typeof dlg.showModal === 'function') {
            try { dlg.showModal(); } catch (_) { dlg.setAttribute('open', 'open'); }
        } else { dlg.setAttribute('open', 'open'); }
    }
    function closeDialog(dlg) {
        if (!dlg) return;
        if (typeof dlg.close === 'function') {
            try { dlg.close(); } catch (_) { dlg.removeAttribute('open'); }
        } else { dlg.removeAttribute('open'); }
    }
    function showMsg(id, text, isError) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
        el.classList.toggle('recipe-save-msg-success', !isError);
        el.classList.toggle('recipe-save-msg-error', !!isError);
    }
    function escHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Global close handler.
    document.addEventListener('click', function (event) {
        var t = event.target;
        if (t && t.matches && t.matches('[data-egais-modal-close]')) {
            var dlg = t.closest('dialog');
            if (dlg) { event.preventDefault(); closeDialog(dlg); }
        }
    });

    // Load alc ingredients from embedded JSON.
    var alcIngredients = [];
    try {
        var alcData = document.getElementById('egaisAlcIngredients');
        if (alcData) alcIngredients = JSON.parse(alcData.textContent || '[]');
    } catch (_) { alcIngredients = []; }

    function ingredientOptionsHtml(selectedId) {
        var html = '<option value="">— не привязано —</option>';
        alcIngredients.forEach(function (i) {
            var sel = String(selectedId || '') === String(i.id) ? ' selected' : '';
            html += '<option value="' + i.id + '" data-code="' + escHtml(i.code) + '" data-unit="' + escHtml(i.unit) + '"' + sel + '>' + escHtml(i.name) + '</option>';
        });
        return html;
    }

    // ---- Create invoice modal ----
    var invModal = document.getElementById('egaisInvoiceModal');
    var invCreate = document.getElementById('egaisCreateInvoiceOpen');
    var invSubmit = document.getElementById('egaisInvoiceSubmit');
    var itemsList = document.getElementById('egaisItemsList');
    var itemRowIndex = 0;

    function addItemRow() {
        if (!itemsList) return;
        var idx = ++itemRowIndex;
        var div = document.createElement('div');
        div.className = 'egais-item-row inv-edit-row';
        div.setAttribute('data-row-idx', String(idx));
        div.innerHTML =
            '<label class="inv-edit-field" style="flex:2 1 0;">' +
                '<span class="inv-edit-label">Продукт</span>' +
                '<select class="js-egais-item-ingredient">' + ingredientOptionsHtml(null) + '</select>' +
            '</label>' +
            '<label class="inv-edit-field">' +
                '<span class="inv-edit-label">АСНА код</span>' +
                '<input type="text" class="js-egais-item-code" maxlength="64" required>' +
            '</label>' +
            '<label class="inv-edit-field">' +
                '<span class="inv-edit-label">Кол-во</span>' +
                '<input type="number" step="0.001" min="0" class="js-egais-item-qty" value="0">' +
            '</label>' +
            '<label class="inv-edit-field">' +
                '<span class="inv-edit-label">Цена/ед, ₽</span>' +
                '<input type="number" step="0.01" min="0" class="js-egais-item-price" value="0">' +
            '</label>' +
            '<button type="button" class="admin-checkout-btn cancel js-egais-item-remove" title="Удалить позицию">×</button>';
        itemsList.appendChild(div);
        // Auto-fill alc_code from selected ingredient.
        var sel = div.querySelector('.js-egais-item-ingredient');
        var codeInput = div.querySelector('.js-egais-item-code');
        sel.addEventListener('change', function () {
            var opt = sel.options[sel.selectedIndex];
            if (opt && opt.getAttribute('data-code') && !codeInput.value) {
                codeInput.value = opt.getAttribute('data-code');
            }
        });
    }

    if (invCreate && invModal) {
        invCreate.addEventListener('click', function () {
            // Reset
            ['egaisTtnNumber','egaisSupplierName','egaisSupplierInn','egaisNotes'].forEach(function(id){
                var el = document.getElementById(id);
                if (el) el.value = '';
            });
            var dt = document.getElementById('egaisTtnDate');
            if (dt) dt.value = (new Date()).toISOString().slice(0, 10);
            if (itemsList) itemsList.innerHTML = '';
            itemRowIndex = 0;
            addItemRow();
            showMsg('egaisInvoiceMsg', '');
            openDialog(invModal);
        });
    }

    if (invModal) {
        invModal.addEventListener('click', function (event) {
            var addBtn = event.target.closest('.js-egais-add-item');
            if (addBtn) { event.preventDefault(); addItemRow(); return; }
            var rem = event.target.closest('.js-egais-item-remove');
            if (rem) {
                event.preventDefault();
                var row = rem.closest('.egais-item-row');
                if (row && itemsList && itemsList.children.length > 1) {
                    row.remove();
                }
            }
        });
    }

    if (invSubmit) {
        invSubmit.addEventListener('click', function () {
            var ttn = ((document.getElementById('egaisTtnNumber') || {}).value || '').trim();
            var date = ((document.getElementById('egaisTtnDate') || {}).value || '').trim();
            var inn = ((document.getElementById('egaisSupplierInn') || {}).value || '').trim();
            var sname = ((document.getElementById('egaisSupplierName') || {}).value || '').trim();
            var notes = ((document.getElementById('egaisNotes') || {}).value || '').trim();
            if (!ttn) { showMsg('egaisInvoiceMsg', 'Укажите номер ТТН.', true); return; }
            if (!date) { showMsg('egaisInvoiceMsg', 'Укажите дату ТТН.', true); return; }
            if (!/^(\d{10}|\d{12})$/.test(inn)) { showMsg('egaisInvoiceMsg', 'ИНН должен быть 10 или 12 цифр.', true); return; }

            var items = [];
            (itemsList ? itemsList.querySelectorAll('.egais-item-row') : []).forEach(function (row) {
                var code = (row.querySelector('.js-egais-item-code') || {}).value || '';
                if (!code.trim()) return;
                var sel = row.querySelector('.js-egais-item-ingredient');
                var ing = sel && sel.value ? parseInt(sel.value, 10) : null;
                var unit = '';
                if (sel) {
                    var opt = sel.options[sel.selectedIndex];
                    if (opt) unit = opt.getAttribute('data-unit') || '';
                }
                items.push({
                    ingredient_id: ing,
                    alc_code: code.trim(),
                    quantity: parseFloat((row.querySelector('.js-egais-item-qty') || {}).value || '0') || 0,
                    unit: unit,
                    price_per_unit: parseFloat((row.querySelector('.js-egais-item-price') || {}).value || '0') || 0,
                });
            });
            if (!items.length) { showMsg('egaisInvoiceMsg', 'Добавьте минимум 1 позицию с АСНА кодом.', true); return; }

            invSubmit.disabled = true;
            api({
                action: 'create_invoice',
                ttn_number: ttn,
                ttn_date: date,
                supplier_inn: inn,
                supplier_name: sname,
                notes: notes,
                items: items,
            }).then(function (res) {
                invSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    showMsg('egaisInvoiceMsg', 'Сохранено. Обновляем…', false);
                    setTimeout(function () { window.location.reload(); }, 350);
                } else {
                    showMsg('egaisInvoiceMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            }).catch(function (e) {
                invSubmit.disabled = false;
                showMsg('egaisInvoiceMsg', 'Сеть: ' + (e && e.message || ''), true);
            });
        });
    }

    // ---- Accept invoice ----
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-egais-accept') : null;
        if (!btn) return;
        event.preventDefault();
        var id = parseInt(btn.getAttribute('data-invoice-id') || '0', 10);
        if (!id) return;
        if (!window.confirm('Принять ТТН и добавить алкоголь на склад?')) return;
        btn.disabled = true;
        api({ action: 'accept_invoice', id: id, apply_to_stock: true }).then(function (res) {
            if (res.ok && res.data && res.data.success) {
                window.location.reload();
            } else {
                alert('Ошибка: ' + ((res.data && res.data.error) || 'unknown'));
                btn.disabled = false;
            }
        });
    });

    // ---- Reject invoice modal ----
    var rejectModal = document.getElementById('egaisRejectModal');
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-egais-reject') : null;
        if (!btn || !rejectModal) return;
        event.preventDefault();
        var id = parseInt(btn.getAttribute('data-invoice-id') || '0', 10);
        if (!id) return;
        (document.getElementById('egaisRejectInvoiceId') || {}).value = String(id);
        var reasonEl = document.getElementById('egaisRejectReason');
        if (reasonEl) reasonEl.value = '';
        showMsg('egaisRejectMsg', '');
        openDialog(rejectModal);
    });
    var rejectSubmit = document.getElementById('egaisRejectSubmit');
    if (rejectSubmit) {
        rejectSubmit.addEventListener('click', function () {
            var id = parseInt((document.getElementById('egaisRejectInvoiceId') || {}).value || '0', 10);
            var reason = ((document.getElementById('egaisRejectReason') || {}).value || '').trim();
            if (!id) { showMsg('egaisRejectMsg', 'Не выбрана ТТН.', true); return; }
            rejectSubmit.disabled = true;
            api({ action: 'reject_invoice', id: id, reason: reason }).then(function (res) {
                rejectSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    window.location.reload();
                } else {
                    showMsg('egaisRejectMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            });
        });
    }
})();
