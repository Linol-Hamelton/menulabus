(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-vsd.php', {
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
        } else {
            dlg.setAttribute('open', 'open');
        }
    }
    function closeDialog(dlg) {
        if (!dlg) return;
        if (typeof dlg.close === 'function') {
            try { dlg.close(); } catch (_) { dlg.removeAttribute('open'); }
        } else {
            dlg.removeAttribute('open');
        }
    }

    function showMsg(id, text, isError) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
        el.classList.toggle('recipe-save-msg-success', !isError);
        el.classList.toggle('recipe-save-msg-error', !!isError);
    }

    // Generic close handler for [data-vsd-modal-close]
    document.addEventListener('click', function (event) {
        var t = event.target;
        if (t && t.matches && t.matches('[data-vsd-modal-close]')) {
            var dlg = t.closest('dialog');
            if (dlg) {
                event.preventDefault();
                closeDialog(dlg);
            }
        }
    });

    // --- Create modal ---
    var createModal = document.getElementById('vsdCreateModal');
    var createOpen = document.getElementById('vsdCreateOpen');
    var createSubmit = document.getElementById('vsdCreateSubmit');
    var ingredientSel = document.getElementById('vsdCreateIngredient');

    if (createOpen && createModal) {
        createOpen.addEventListener('click', function () {
            // Reset form fields
            ['vsdCreateNumber','vsdCreateQty','vsdCreateUnit','vsdCreateSupplierName','vsdCreateSupplierInn','vsdCreateNotes'].forEach(function(id){
                var el = document.getElementById(id);
                if (!el) return;
                if (el.tagName === 'SELECT') el.value = '';
                else if (el.type === 'number') el.value = '0';
                else el.value = '';
            });
            if (ingredientSel) ingredientSel.value = '';
            var dateEl = document.getElementById('vsdCreateDate');
            if (dateEl) dateEl.value = (new Date()).toISOString().slice(0, 10);
            showMsg('vsdCreateMsg', '');
            openDialog(createModal);
        });
    }

    // Pre-fill unit from selected ingredient
    if (ingredientSel) {
        ingredientSel.addEventListener('change', function () {
            var opt = ingredientSel.options[ingredientSel.selectedIndex];
            if (!opt) return;
            var unitField = document.getElementById('vsdCreateUnit');
            if (unitField && !unitField.value) {
                unitField.placeholder = opt.getAttribute('data-unit') || 'из ингредиента';
            }
        });
    }

    if (createSubmit) {
        createSubmit.addEventListener('click', function () {
            var ingredientId = parseInt((ingredientSel || {}).value || '0', 10);
            var vsdNumber = ((document.getElementById('vsdCreateNumber') || {}).value || '').trim();
            var vsdDate = ((document.getElementById('vsdCreateDate') || {}).value || '').trim();
            var quantity = parseFloat((document.getElementById('vsdCreateQty') || {}).value || '0');
            var unit = ((document.getElementById('vsdCreateUnit') || {}).value || '').trim();
            var supplierName = ((document.getElementById('vsdCreateSupplierName') || {}).value || '').trim();
            var supplierInn = ((document.getElementById('vsdCreateSupplierInn') || {}).value || '').trim();
            var notes = ((document.getElementById('vsdCreateNotes') || {}).value || '').trim();

            if (!ingredientId) { showMsg('vsdCreateMsg', 'Выберите ингредиент.', true); return; }
            if (!vsdNumber) { showMsg('vsdCreateMsg', 'Укажите номер ВСД.', true); return; }
            if (!vsdDate) { showMsg('vsdCreateMsg', 'Укажите дату выдачи.', true); return; }
            if (supplierInn && !/^(\d{10}|\d{12})$/.test(supplierInn)) {
                showMsg('vsdCreateMsg', 'ИНН должен быть 10 или 12 цифр.', true);
                return;
            }

            createSubmit.disabled = true;
            api({
                action: 'create',
                ingredient_id: ingredientId,
                vsd_number: vsdNumber,
                vsd_date: vsdDate,
                quantity: quantity,
                unit: unit,
                supplier_name: supplierName,
                supplier_inn: supplierInn,
                notes: notes,
            }).then(function (res) {
                createSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    showMsg('vsdCreateMsg', 'Сохранено. Обновляем…', false);
                    setTimeout(function () { window.location.reload(); }, 350);
                } else {
                    showMsg('vsdCreateMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            }).catch(function (e) {
                createSubmit.disabled = false;
                showMsg('vsdCreateMsg', 'Сеть: ' + (e && e.message || ''), true);
            });
        });
    }

    // --- Accept (with confirm) ---
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-vsd-accept') : null;
        if (!btn) return;
        event.preventDefault();
        var id = parseInt(btn.getAttribute('data-vsd-id') || '0', 10);
        if (!id) return;
        if (!window.confirm('Гасить ВСД и добавить товар на склад?')) return;
        btn.disabled = true;
        api({ action: 'accept', id: id, apply_to_stock: true }).then(function (res) {
            if (res.ok && res.data && res.data.success) {
                window.location.reload();
            } else {
                alert('Ошибка: ' + ((res.data && res.data.error) || 'unknown'));
                btn.disabled = false;
            }
        });
    });

    // --- Reject (with reason modal) ---
    var rejectModal = document.getElementById('vsdRejectModal');
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-vsd-reject') : null;
        if (!btn || !rejectModal) return;
        event.preventDefault();
        var id = parseInt(btn.getAttribute('data-vsd-id') || '0', 10);
        if (!id) return;
        (document.getElementById('vsdRejectId') || {}).value = String(id);
        var reasonEl = document.getElementById('vsdRejectReason');
        if (reasonEl) reasonEl.value = '';
        showMsg('vsdRejectMsg', '');
        openDialog(rejectModal);
    });

    var rejectSubmit = document.getElementById('vsdRejectSubmit');
    if (rejectSubmit) {
        rejectSubmit.addEventListener('click', function () {
            var id = parseInt((document.getElementById('vsdRejectId') || {}).value || '0', 10);
            var reason = ((document.getElementById('vsdRejectReason') || {}).value || '').trim();
            if (!id) { showMsg('vsdRejectMsg', 'Не выбран ВСД.', true); return; }
            rejectSubmit.disabled = true;
            api({ action: 'reject', id: id, reason: reason }).then(function (res) {
                rejectSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    window.location.reload();
                } else {
                    showMsg('vsdRejectMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            });
        });
    }
})();
