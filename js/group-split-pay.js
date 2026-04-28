// group-split-pay.js (Phase 13A.1, 2026-04-28)
//
// Drives the split-bill payment block on /group.php that becomes visible
// once the group order has been submitted to the kitchen. Three modes:
//   * host     — one big payer covers the entire remaining total
//   * per_seat — each guest pays for their own seat's items
//   * equal    — split the remaining total across N people equally
//
// Mode is persisted via /api/save-group-order.php?action=split_mode (the
// existing save endpoint accepts split_mode payload). Each pay-action
// fires POST /api/group-create-payment-intent.php and redirects to the
// returned YK confirmation_url.
//
// Live update: poll the page every 8s while at least one intent is
// pending — when a YK webhook flips it to paid (or the group becomes
// fully paid), the user sees their payment land without manual refresh.

(function () {
    'use strict';

    const root = document.querySelector('.group-payment');
    if (!root) return;

    const groupCode = root.dataset.groupCode;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const blocks = {
        host:     root.querySelector('.group-pay-host'),
        per_seat: root.querySelector('.group-pay-per-seat'),
        equal:    root.querySelector('.group-pay-equal'),
    };

    function showMode(mode) {
        Object.keys(blocks).forEach(function (m) {
            if (!blocks[m]) return;
            blocks[m].hidden = (m !== mode);
        });
    }

    // Mode picker — radios live inside <fieldset class="group-split-mode">.
    root.querySelectorAll('input[name="gSplitMode"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const mode = radio.value;
            showMode(mode);
            // Persist to server (best-effort, ignore failure).
            fetch('/api/save-group-order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    action: 'set_split_mode',
                    group_code: groupCode,
                    split_mode: mode,
                    csrf_token: csrf,
                }),
            }).catch(function () { /* non-fatal */ });
        });
    });

    async function startPayment(payload) {
        const btn = payload._btn;
        if (btn) { btn.disabled = true; btn.dataset.originalText = btn.textContent; btn.textContent = 'Создаём ссылку…'; }
        try {
            const resp = await fetch('/api/group-create-payment-intent.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(Object.assign({ group_code: groupCode, csrf_token: csrf }, payload)),
            });
            const json = await resp.json();
            if (!json || !json.success) {
                throw new Error(json && json.error ? json.error : 'Не удалось создать платёж');
            }
            // Redirect into YK checkout.
            window.location.href = json.paymentUrl;
        } catch (e) {
            if (btn) { btn.disabled = false; btn.textContent = btn.dataset.originalText || btn.textContent; }
            alert('Ошибка оплаты: ' + (e && e.message ? e.message : 'неизвестно'));
        }
    }

    // Pay buttons — three flavours.
    root.querySelectorAll('[data-pay-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const action = btn.dataset.payAction;
            if (action === 'host') {
                const payerName = window.prompt('Ваше имя (для чека)?', 'Хост') || 'Хост';
                startPayment({
                    payer_label: payerName,
                    _btn: btn,
                });
            } else if (action === 'seat') {
                const seat = btn.dataset.seat;
                startPayment({
                    payer_label: seat,   // seat label doubles as payer label
                    seat_label: seat,
                    _btn: btn,
                });
            } else if (action === 'equal') {
                const nameEl  = root.querySelector('#gEqualPayerName');
                const countEl = root.querySelector('#gEqualShareCount');
                const name = (nameEl?.value || '').trim();
                const cnt  = Math.max(1, Math.min(20, parseInt(countEl?.value || '2', 10)));
                if (!name) { alert('Введите ваше имя'); nameEl?.focus(); return; }
                startPayment({
                    payer_label: name,
                    share_count: cnt,
                    _btn: btn,
                });
            }
        });
    });

    // Live polling — only while there are pending intents and group is not paid.
    function hasPendingWork() {
        if (root.dataset.status === 'paid') return false;
        return !!root.querySelector('.group-intent--pending');
    }
    if (hasPendingWork()) {
        setTimeout(function tick() {
            // Just reload the page — server-side render is the source of truth.
            // 8 seconds is long enough not to hammer FPM, short enough that
            // a YK webhook landing within 10-20s of completion shows up
            // before the user refreshes manually.
            window.location.reload();
        }, 8000);
    }
})();
