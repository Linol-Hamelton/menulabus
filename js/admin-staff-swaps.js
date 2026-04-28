// admin-staff-swaps.js (Phase 13A.2, 2026-04-28)
//
// Wires the shift-swap UI in admin/staff.php to the existing
// /api/shift-swap-action.php endpoint (Phase 7.4 v2). Handles five
// transitions:
//   * employee  → "Запросить замену"        action=request
//   * employee  → "Отменить запрос"         action=cancel
//   * employee  → (volunteering happens via Telegram for now; no
//                  inline UI for browsing other people's requests yet)
//   * manager   → "Одобрить"                action=approve
//   * manager   → "Отклонить"               action=deny
//
// All buttons use a tiny event-delegation pattern. After a successful
// action the page reloads — server-side render is the source of truth.

(function () {
    'use strict';

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content
            || document.body?.dataset?.csrfToken
            || '';
    }

    async function call(payload) {
        const resp = await fetch('/api/shift-swap-action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(Object.assign({ csrf_token: csrf() }, payload)),
        });
        const json = await resp.json().catch(function () { return { success: false, error: 'bad_response' }; });
        if (!json || !json.success) {
            throw new Error(json && json.error ? json.error : 'unknown_error');
        }
        return json;
    }

    document.addEventListener('click', async function (e) {
        const t = e.target;
        if (!(t instanceof HTMLElement)) return;

        // Employee — request a swap for their own shift
        if (t.classList.contains('btn-swap-request')) {
            const shiftId = parseInt(t.dataset.shiftId || '0', 10);
            if (!shiftId) return;
            const note = window.prompt('Комментарий для менеджера (необязательно)', '') || '';
            try {
                t.disabled = true;
                await call({ action: 'request', shift_id: shiftId, note: note });
                window.location.reload();
            } catch (err) {
                alert('Ошибка: ' + err.message);
                t.disabled = false;
            }
            return;
        }

        // Employee — cancel their open swap request
        if (t.classList.contains('btn-swap-cancel')) {
            const swapId = parseInt(t.dataset.swapId || '0', 10);
            if (!swapId) return;
            if (!window.confirm('Отменить запрос на замену?')) return;
            try {
                t.disabled = true;
                await call({ action: 'cancel', swap_id: swapId });
                window.location.reload();
            } catch (err) {
                alert('Ошибка: ' + err.message);
                t.disabled = false;
            }
            return;
        }

        // Manager — approve (volunteer offered)
        if (t.classList.contains('btn-swap-approve')) {
            const swapId = parseInt(t.dataset.swapId || '0', 10);
            if (!swapId) return;
            if (!window.confirm('Одобрить замену? Смена будет переназначена волонтёру.')) return;
            try {
                t.disabled = true;
                await call({ action: 'approve', swap_id: swapId });
                window.location.reload();
            } catch (err) {
                alert('Ошибка: ' + err.message);
                t.disabled = false;
            }
            return;
        }

        // Manager — deny
        if (t.classList.contains('btn-swap-deny')) {
            const swapId = parseInt(t.dataset.swapId || '0', 10);
            if (!swapId) return;
            if (!window.confirm('Отклонить запрос?')) return;
            try {
                t.disabled = true;
                await call({ action: 'deny', swap_id: swapId });
                window.location.reload();
            } catch (err) {
                alert('Ошибка: ' + err.message);
                t.disabled = false;
            }
        }
    });
})();
