// provider-tenant.js (Phase 14.7) — drive provider/tenant.php action buttons.
(function () {
    'use strict';
    const root = document.querySelector('.provider-tenant');
    if (!root) return;
    const tenantId = parseInt(root.dataset.tenantId || '0', 10);
    if (!tenantId) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        || document.body?.dataset?.csrfToken
        || '';
    const feedback = document.getElementById('provFeedback');

    function show(ok, msg) {
        if (!feedback) return;
        feedback.hidden = false;
        feedback.textContent = (ok ? '✅ ' : '❌ ') + msg;
        feedback.className = 'billing-action-feedback billing-action-feedback--' + (ok ? 'ok' : 'err');
    }

    async function call(action, payload) {
        const body = Object.assign({ action: action, tenant_id: tenantId, csrf_token: csrf }, payload || {});
        const resp = await fetch('/api/provider/tenant-action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(body),
        });
        const json = await resp.json().catch(() => ({ success: false, error: 'bad_response' }));
        if (!json.success) throw new Error(json.message || json.error || 'unknown_error');
        return json;
    }

    document.addEventListener('click', async function (e) {
        const t = e.target;
        if (!(t instanceof HTMLElement)) return;
        const action = t.dataset.provAction;
        if (!action) return;

        let payload = {};
        if (action === 'extend_trial') {
            const days = window.prompt('На сколько дней продлить trial?', '7');
            if (!days) return;
            payload.days = parseInt(days, 10);
        } else if (action === 'comp') {
            const amount = window.prompt('Сумма зачисления в рублях (0 — просто продлить период бесплатно)', '0');
            if (amount === null) return;
            payload.amount_kop = Math.max(0, Math.round(parseFloat(amount) * 100));
            payload.reason = window.prompt('Причина (для аудит-лога)', 'comp') || 'comp';
        } else if (action.startsWith('force_')) {
            if (!window.confirm('Точно?')) return;
        }

        t.disabled = true;
        try {
            await call(action, payload);
            show(true, 'Готово, перезагружаю…');
            setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            show(false, err.message);
            t.disabled = false;
        }
    });
})();
