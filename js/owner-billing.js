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

    // Map common YK / network errors to user-friendly Russian messages.
    function humanizeError(err) {
        var msg = (err && err.message) || String(err);
        if (msg === 'network_error') return 'Не удалось связаться с сервером — проверьте интернет и повторите.';
        if (msg === 'bad_response')  return 'Сервер вернул некорректный ответ. Попробуйте ещё раз.';
        if (/3ds.*timeout/i.test(msg)) return 'Истекло время подтверждения 3-D Secure. Попробуйте оформить ещё раз.';
        if (/invalid.*token|card.*invalid/i.test(msg)) return 'Не удалось сохранить карту — попробуйте другую.';
        if (/yookassa.*not.*configured|yookassa_not_configured/i.test(msg)) return 'YooKassa ещё не настроен. Свяжитесь с поддержкой.';
        return 'Ошибка: ' + msg;
    }

    async function call(action, payload) {
        let resp;
        try {
            resp = await fetch('/api/billing-action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(Object.assign({ action: action, csrf_token: csrf }, payload || {})),
            });
        } catch (e) {
            throw new Error('network_error');
        }
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
            if (!json.paymentUrl) throw new Error('no_payment_url');
            window.location.href = json.paymentUrl;
        } catch (err) {
            showFeedback(false, humanizeError(err));
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
                showFeedback(false, humanizeError(err));
                btn.disabled = false;
            }
        });
    });

    // Cancel subscription
    document.getElementById('billingCancelBtn')?.addEventListener('click', async function () {
        if (!window.confirm('Отменить подписку? Доступ останется до конца оплаченного периода.')) return;
        const btn = this;
        btn.disabled = true;
        try {
            await call('cancel_subscription');
            window.location.reload();
        } catch (err) {
            showFeedback(false, humanizeError(err));
            btn.disabled = false;
        }
    });

    // After-redirect feedback (?card_added=1)
    if (new URLSearchParams(window.location.search).get('card_added') === '1') {
        showFeedback(true, 'Карта успешно сохранена. Статус подписки обновится в течение минуты.');
    }
})();
