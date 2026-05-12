(function () {
    'use strict';

    var modal = document.getElementById('orderRefundModal');
    if (!modal) return;

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

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

    modal.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.matches && target.matches('[data-refund-modal-close]')) {
            event.preventDefault();
            closeDialog(modal);
        }
    });

    function showMsg(text, isError) {
        var el = document.getElementById('orderRefundMsg');
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
        el.classList.toggle('recipe-save-msg-success', !isError);
        el.classList.toggle('recipe-save-msg-error', !!isError);
    }

    function fmtMoney(n) {
        var v = Number(n || 0);
        return v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
    }

    // Modes (full/partial) — toggle amount field.
    modal.addEventListener('change', function (event) {
        var t = event.target;
        if (!t || t.name !== 'refundMode') return;
        var wrap = document.getElementById('orderRefundAmountWrap');
        if (!wrap) return;
        if (t.value === 'partial') {
            wrap.hidden = false;
            var input = document.getElementById('orderRefundAmount');
            if (input && !input.value) {
                input.value = (document.getElementById('orderRefundOrderTotal') || {}).value || '0';
            }
        } else {
            wrap.hidden = true;
        }
    });

    // Delegated trigger: any .js-refund-trigger element with data-order-id + data-order-total.
    document.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('.js-refund-trigger') : null;
        if (!trigger) return;
        event.preventDefault();
        var orderId = parseInt(trigger.getAttribute('data-order-id') || '0', 10);
        var total = parseFloat(trigger.getAttribute('data-order-total') || '0');
        if (!orderId) return;

        (document.getElementById('orderRefundOrderId') || {}).value = String(orderId);
        (document.getElementById('orderRefundOrderTotal') || {}).value = String(total);
        var subtitle = document.getElementById('orderRefundSubtitle');
        if (subtitle) subtitle.textContent = 'Заказ #' + orderId + ' · ' + fmtMoney(total);
        var amountInput = document.getElementById('orderRefundAmount');
        if (amountInput) amountInput.value = total.toFixed(2);
        var reasonInput = document.getElementById('orderRefundReason');
        if (reasonInput) reasonInput.value = '';
        var radios = modal.querySelectorAll('input[name="refundMode"]');
        for (var i = 0; i < radios.length; i++) {
            radios[i].checked = radios[i].value === 'full';
        }
        var wrap = document.getElementById('orderRefundAmountWrap');
        if (wrap) wrap.hidden = true;
        showMsg('');
        openDialog(modal);
    });

    var btnSubmit = document.getElementById('orderRefundSubmit');
    if (btnSubmit) {
        btnSubmit.addEventListener('click', function () {
            var orderId = parseInt((document.getElementById('orderRefundOrderId') || {}).value || '0', 10);
            if (!orderId) {
                showMsg('Не определён заказ.', true);
                return;
            }
            var mode = 'full';
            var radios = modal.querySelectorAll('input[name="refundMode"]');
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) { mode = radios[i].value; break; }
            }
            var amount = parseFloat((document.getElementById('orderRefundAmount') || {}).value || '0');
            var reason = ((document.getElementById('orderRefundReason') || {}).value || '').trim();
            var payload = { action: 'create', order_id: orderId, mode: mode, csrf_token: csrfToken };
            if (mode === 'partial') {
                if (!isFinite(amount) || amount <= 0) {
                    showMsg('Сумма должна быть > 0.', true);
                    return;
                }
                payload.amount = amount;
            }
            if (reason) payload.reason = reason;

            btnSubmit.disabled = true;
            fetch('/api/save-refund.php', {
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
            }).then(function (res) {
                btnSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    var fiscal = res.data.fiscal || {};
                    var note = 'Возврат оформлен на ' + fmtMoney(res.data.amount);
                    if (fiscal.status === 'wait') {
                        note += ' · чек коррекции ожидает ОФД';
                    } else if (fiscal.status === 'done') {
                        note += ' · чек коррекции готов';
                    } else if (fiscal.status === 'fail') {
                        note += ' · фискализация не удалась (см. логи)';
                    }
                    showMsg(note, false);
                    setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    var err = (res.data && res.data.error) || 'unknown';
                    showMsg('Ошибка: ' + err, true);
                }
            }).catch(function (e) {
                btnSubmit.disabled = false;
                showMsg('Сеть: ' + (e && e.message || ''), true);
            });
        });
    }
})();
