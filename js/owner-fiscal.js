// owner-fiscal.js (Phase 13A.3, 2026-04-28)
//
// Drives the fiscal config tab on /owner.php?tab=fiscal:
//   * Show/hide АТОЛ-specific fields based on the provider radio.
//   * Save button → POST /api/save-fiscal-settings.php with all fields.
//   * Test connection button → POST ?test=1 with the same payload;
//     show success or АТОЛ error inline.
//   * Re-emit form → POST ?reemit=<orderId>; show new uuid or error.

(function () {
    'use strict';

    const form = document.getElementById('fiscalConfigForm');
    if (!form) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const fields = form.querySelector('.fiscal-atol-fields');
    const feedback = document.getElementById('fiscalFeedback');
    const reemitFeedback = document.getElementById('fiscalReemitFeedback');

    function showFeedback(el, ok, message) {
        if (!el) return;
        el.hidden = false;
        el.textContent = (ok ? '✅ ' : '❌ ') + message;
        el.className = 'fiscal-action-feedback ' + (ok ? 'fiscal-action-feedback--ok' : 'fiscal-action-feedback--err');
    }

    function collectPayload() {
        const fd = new FormData(form);
        const out = {};
        fd.forEach(function (v, k) { out[k] = v; });
        // Checkbox needs explicit '0' if unchecked
        if (!form.querySelector('[name="fiscal_atol_sandbox"]').checked) {
            out['fiscal_atol_sandbox'] = '';
        }
        out['csrf_token'] = csrf;
        return out;
    }

    // Toggle АТОЛ fields visibility
    form.querySelectorAll('input[name="fiscal_provider"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const isAtol = radio.value === 'atol' && radio.checked;
            if (fields) fields.hidden = !isAtol;
            const testBtn = document.getElementById('fiscalTestBtn');
            if (testBtn) testBtn.disabled = !isAtol;
        });
    });

    async function postJson(url, payload) {
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(payload),
        });
        return resp.json().catch(function () { return { success: false, error: 'bad_response' }; });
    }

    document.getElementById('fiscalSaveBtn')?.addEventListener('click', async function (e) {
        e.preventDefault();
        const json = await postJson('/api/save-fiscal-settings.php', collectPayload());
        showFeedback(feedback, !!json.success, json.success ? 'Сохранено' : (json.error || 'Не удалось сохранить'));
    });

    document.getElementById('fiscalTestBtn')?.addEventListener('click', async function (e) {
        e.preventDefault();
        showFeedback(feedback, true, 'Проверяю…');
        const json = await postJson('/api/save-fiscal-settings.php?test=1', collectPayload());
        showFeedback(feedback, !!json.success,
            json.success ? ('OK · token ' + (json.token_prefix || '?') + ' · sandbox=' + (json.sandbox ? 'yes' : 'no'))
                         : (json.error || 'Не удалось'));
    });

    document.getElementById('fiscalReemitBtn')?.addEventListener('click', async function (e) {
        e.preventDefault();
        const orderId = parseInt(document.getElementById('fiscalReemitOrderId').value || '0', 10);
        if (orderId <= 0) { showFeedback(reemitFeedback, false, 'Укажите ID заказа'); return; }
        showFeedback(reemitFeedback, true, 'Выписываю чек для заказа #' + orderId + '…');
        const json = await postJson('/api/save-fiscal-settings.php?reemit=' + encodeURIComponent(orderId), {
            csrf_token: csrf,
        });
        showFeedback(reemitFeedback, !!json.success,
            json.success ? ('OK · uuid ' + (json.uuid ? String(json.uuid).slice(0, 18) + '…' : 'без uuid'))
                         : (json.error || 'Не удалось'));
    });
})();
