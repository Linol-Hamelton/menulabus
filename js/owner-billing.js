// owner-billing.js (Phase 14.5, 2026-05-03)
//
// Drives the billing tab on /owner.php?tab=billing:
//   * "Заменить карту" / "Добавить карту" → POST update_payment_method
//     → redirect to YK confirmation_url
//   * "Перейти на Pro / Понизить" → POST change_plan with confirm
//   * "Отменить подписку" → POST cancel_subscription with confirm

(function () {
    'use strict';

    const root = document.querySelector('.billing-tab');
    if (!root) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        || document.body?.dataset?.csrfToken
        || '';
    const feedback = document.getElementById('billingFeedback');

    function showFeedback(ok, message) {
        if (!feedback) return;
        feedback.hidden = false;
        feedback.textContent = (ok ? '✅ ' : '❌ ') + message;
        feedback.className = 'billing-action-feedback billing-action-feedback--' + (ok ? 'ok' : 'err');
    }

    async function call(action, payload) {
        const resp = await fetch('/api/billing-action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(Object.assign({ action: action, csrf_token: csrf }, payload || {})),
        });
        const json = await resp.json().catch(() => ({ success: false, error: 'bad_response' }));
        if (!json.success) {
            throw new Error(json.message || json.error || 'unknown_error');
        }
        return json;
    }

    // Update card → YK redirect
    document.getElementById('billingUpdateCardBtn')?.addEventListener('click', async function (e) {
        e.preventDefault();
        const btn = e.currentTarget;
        btn.disabled = true;
        showFeedback(true, 'Готовим страницу оплаты…');
        try {
            const json = await call('update_payment_method');
            window.location.href = json.paymentUrl;
        } catch (err) {
            showFeedback(false, err.message);
            btn.disabled = false;
        }
    });

    // Change plan
    root.querySelectorAll('.billing-change-plan-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const newPlan = btn.dataset.targetPlan;
            if (!newPlan) return;
            const labels = { starter: 'Starter', pro: 'Pro' };
            if (!window.confirm('Сменить тариф на ' + (labels[newPlan] || newPlan) + '? Изменение применится сразу, следующее списание будет по новой цене.')) return;
            btn.disabled = true;
            try {
                await call('change_plan', { plan_id: newPlan });
                window.location.reload();
            } catch (err) {
                alert('Ошибка: ' + err.message);
                btn.disabled = false;
            }
        });
    });

    // Cancel subscription
    document.getElementById('billingCancelBtn')?.addEventListener('click', async function () {
        if (!window.confirm('Отменить подписку? Доступ останется до конца оплаченного периода.')) return;
        try {
            await call('cancel_subscription');
            window.location.reload();
        } catch (err) {
            alert('Ошибка: ' + err.message);
        }
    });

    // After-redirect feedback (?card_added=1)
    if (new URLSearchParams(window.location.search).get('card_added') === '1') {
        showFeedback(true, 'Карта успешно сохранена. Статус подписки обновится в течение минуты.');
    }
})();
