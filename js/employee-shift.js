(function () {
    'use strict';

    var dock = document.querySelector('.shift-dock');
    if (!dock) return;

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token'))
        || (dock.getAttribute('data-csrf-token')) || '';

    var openModal   = document.getElementById('shiftOpenModal');
    var closeModal  = document.getElementById('shiftCloseModal');
    var encashModal = document.getElementById('shiftEncashModal');
    var bottleModal = document.getElementById('bottleOpenModal');

    function api(action, body) {
        var payload = Object.assign({ action: action, csrf_token: csrfToken }, body || {});
        return fetch('/api/save-cashier-shift.php', {
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

    function showMsg(elId, text, isError) {
        var el = document.getElementById(elId);
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
        el.classList.toggle('recipe-save-msg-success', !isError);
        el.classList.toggle('recipe-save-msg-error', !!isError);
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

    function bindClose(dlg) {
        if (!dlg) return;
        dlg.addEventListener('click', function (event) {
            var target = event.target;
            if (target && target.matches && target.matches('[data-shift-modal-close]')) {
                event.preventDefault();
                closeDialog(dlg);
            }
        });
    }
    bindClose(openModal);
    bindClose(closeModal);
    bindClose(encashModal);
    bindClose(bottleModal);

    function fmtMoney(value) {
        var n = Number(value || 0);
        if (!isFinite(n)) n = 0;
        return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
    }

    function fillZReport(report) {
        if (!report || !closeModal) return;
        var map = {
            orders_count: String(report.orders_count || 0),
            gross_total: fmtMoney(report.gross_total),
            tips_total: fmtMoney(report.tips_total),
            refund_total: fmtMoney(report.refund_total),
            encashment_total: fmtMoney(report.encashment_total),
            expected_cash: fmtMoney(report.expected_cash),
        };
        Object.keys(map).forEach(function (k) {
            var el = closeModal.querySelector('[data-z="' + k + '"]');
            if (el) el.textContent = map[k];
        });
        var cashInput = document.getElementById('shiftCloseCash');
        if (cashInput) cashInput.value = (Number(report.expected_cash || 0)).toFixed(2);
    }

    // Open shift
    var btnOpen = dock.querySelector('.js-shift-open');
    if (btnOpen) {
        btnOpen.addEventListener('click', function () {
            showMsg('shiftOpenMsg', '');
            var input = document.getElementById('shiftOpenCash');
            if (input) input.value = '0';
            openDialog(openModal);
        });
    }
    var btnOpenSubmit = document.getElementById('shiftOpenSubmit');
    if (btnOpenSubmit) {
        btnOpenSubmit.addEventListener('click', function () {
            var cash = parseFloat((document.getElementById('shiftOpenCash') || {}).value || '0');
            var notes = ((document.getElementById('shiftOpenNotes') || {}).value || '').trim();
            if (!isFinite(cash) || cash < 0) {
                showMsg('shiftOpenMsg', 'Сумма размена должна быть ≥ 0.', true);
                return;
            }
            btnOpenSubmit.disabled = true;
            api('open', { opening_cash: cash, notes: notes }).then(function (res) {
                btnOpenSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    showMsg('shiftOpenMsg', 'Смена открыта. Обновляем…', false);
                    setTimeout(function () { window.location.reload(); }, 350);
                } else {
                    showMsg('shiftOpenMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            }).catch(function (e) {
                btnOpenSubmit.disabled = false;
                showMsg('shiftOpenMsg', 'Сеть: ' + (e && e.message || ''), true);
            });
        });
    }

    // Close shift
    var btnClose = dock.querySelector('.js-shift-close');
    if (btnClose) {
        btnClose.addEventListener('click', function () {
            showMsg('shiftCloseMsg', '');
            api('status').then(function (res) {
                if (res.ok && res.data && res.data.success && res.data.shift) {
                    fillZReport(res.data.report || {});
                    openDialog(closeModal);
                } else {
                    alert('Не удалось получить статус смены.');
                }
            });
        });
    }
    var btnCloseSubmit = document.getElementById('shiftCloseSubmit');
    if (btnCloseSubmit) {
        btnCloseSubmit.addEventListener('click', function () {
            var shiftId = parseInt(dock.getAttribute('data-shift-id') || '0', 10);
            if (!shiftId) {
                showMsg('shiftCloseMsg', 'Не определён ID смены.', true);
                return;
            }
            var cash = parseFloat((document.getElementById('shiftCloseCash') || {}).value || '0');
            var notes = ((document.getElementById('shiftCloseNotes') || {}).value || '').trim();
            if (!isFinite(cash) || cash < 0) {
                showMsg('shiftCloseMsg', 'Сумма должна быть ≥ 0.', true);
                return;
            }
            btnCloseSubmit.disabled = true;
            api('close', { shift_id: shiftId, closing_cash: cash, notes: notes }).then(function (res) {
                btnCloseSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    showMsg('shiftCloseMsg', 'Смена закрыта.', false);
                    setTimeout(function () { window.location.reload(); }, 350);
                } else {
                    showMsg('shiftCloseMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            }).catch(function (e) {
                btnCloseSubmit.disabled = false;
                showMsg('shiftCloseMsg', 'Сеть: ' + (e && e.message || ''), true);
            });
        });
    }

    // Encashment
    var btnEncash = dock.querySelector('.js-shift-encash');
    if (btnEncash) {
        btnEncash.addEventListener('click', function () {
            showMsg('shiftEncashMsg', '');
            var input = document.getElementById('shiftEncashAmount');
            if (input) input.value = '0';
            openDialog(encashModal);
        });
    }
    var btnEncashSubmit = document.getElementById('shiftEncashSubmit');
    if (btnEncashSubmit) {
        btnEncashSubmit.addEventListener('click', function () {
            var shiftId = parseInt(dock.getAttribute('data-shift-id') || '0', 10);
            if (!shiftId) {
                showMsg('shiftEncashMsg', 'Нет открытой смены.', true);
                return;
            }
            var amount = parseFloat((document.getElementById('shiftEncashAmount') || {}).value || '0');
            var reason = (document.getElementById('shiftEncashReason') || {}).value || 'other';
            if (!isFinite(amount) || amount <= 0) {
                showMsg('shiftEncashMsg', 'Сумма должна быть > 0.', true);
                return;
            }
            btnEncashSubmit.disabled = true;
            api('encash', { shift_id: shiftId, amount: amount, reason: reason }).then(function (res) {
                btnEncashSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    showMsg('shiftEncashMsg', 'Инкассация записана.', false);
                    setTimeout(function () { window.location.reload(); }, 350);
                } else {
                    showMsg('shiftEncashMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            }).catch(function (e) {
                btnEncashSubmit.disabled = false;
                showMsg('shiftEncashMsg', 'Сеть: ' + (e && e.message || ''), true);
            });
        });
    }

    // Phase 39: Вскрыть бутылку (alc_openings) — calls /api/save-egais.php
    var btnBottle = dock.querySelector('.js-shift-bottle');
    if (btnBottle && bottleModal) {
        btnBottle.addEventListener('click', function () {
            showMsg('bottleOpenMsg', '');
            var sel = document.getElementById('bottleOpenIngredient');
            if (sel) sel.value = '';
            var vol = document.getElementById('bottleOpenVolume');
            if (vol) vol.value = '750';
            var notes = document.getElementById('bottleOpenNotes');
            if (notes) notes.value = '';
            openDialog(bottleModal);
        });
    }
    var btnBottleSubmit = document.getElementById('bottleOpenSubmit');
    if (btnBottleSubmit) {
        btnBottleSubmit.addEventListener('click', function () {
            var ingId = parseInt((document.getElementById('bottleOpenIngredient') || {}).value || '0', 10);
            if (!ingId) { showMsg('bottleOpenMsg', 'Выберите продукт.', true); return; }
            var vol = parseInt((document.getElementById('bottleOpenVolume') || {}).value || '0', 10);
            if (!isFinite(vol) || vol <= 0) { showMsg('bottleOpenMsg', 'Объём должен быть > 0.', true); return; }
            var notes = ((document.getElementById('bottleOpenNotes') || {}).value || '').trim();
            btnBottleSubmit.disabled = true;
            fetch('/api/save-egais.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    action: 'open_bottle',
                    ingredient_id: ingId,
                    bottle_volume_ml: vol,
                    notes: notes,
                    csrf_token: csrfToken,
                }),
            }).then(function (r) {
                return r.json().then(function (d) { return { ok: r.ok, data: d }; }, function () {
                    return { ok: r.ok, data: { success: false, error: 'invalid_json' } };
                });
            }).then(function (res) {
                btnBottleSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    showMsg('bottleOpenMsg', 'Бутылка вскрыта (акт записан).', false);
                    setTimeout(function () { closeDialog(bottleModal); }, 600);
                } else {
                    showMsg('bottleOpenMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            }).catch(function (e) {
                btnBottleSubmit.disabled = false;
                showMsg('bottleOpenMsg', 'Сеть: ' + (e && e.message || ''), true);
            });
        });
    }
})();
