// signup.js (Phase 14.6, 2026-05-03)
//
// Handles plan selection (clickable cards) + form submission to
// /api/signup.php. On success redirects to the new tenant subdomain.

(function () {
    'use strict';

    const form = document.getElementById('signupForm');
    if (!form) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        || form.querySelector('input[name="csrf_token"]')?.value
        || '';
    const planInput = document.getElementById('signupPlanId');
    const slugInput = document.getElementById('signupSlug');
    const feedback = document.getElementById('signupFeedback');

    function showFeedback(ok, message) {
        if (!feedback) return;
        feedback.hidden = false;
        feedback.textContent = (ok ? '✅ ' : '❌ ') + message;
        feedback.className = 'signup-feedback signup-feedback--' + (ok ? 'ok' : 'err');
    }

    // Clickable plan cards.
    document.querySelectorAll('.signup-plan-card').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('.signup-plan-card').forEach((c) => c.classList.remove('is-default'));
            card.classList.add('is-default');
            if (planInput) planInput.value = card.dataset.planId || 'trial';
        });
    });

    // Auto-suggest slug from brand name (only if user hasn't typed slug yet).
    const brandInput = form.querySelector('input[name="brand_name"]');
    let slugTouched = false;
    slugInput?.addEventListener('input', () => { slugTouched = true; });
    brandInput?.addEventListener('input', function () {
        if (slugTouched) return;
        const slug = brandInput.value
            .toLowerCase()
            .replace(/[ё]/g, 'e')
            .replace(/[а-я]/g, (c) => {
                const map = {а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ж:'zh',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'h',ц:'c',ч:'ch',ш:'sh',щ:'sch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya'};
                return map[c] || '';
            })
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 32);
        if (slug && slugInput) slugInput.value = slug;
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        if (!submitBtn) return;
        submitBtn.disabled = true;
        showFeedback(true, 'Создаём ваш ресторан…');

        const fd = new FormData(form);
        const payload = {};
        fd.forEach((v, k) => { payload[k] = v; });
        payload.csrf_token = csrf;

        try {
            const resp = await fetch('/api/signup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(payload),
            });
            const json = await resp.json();
            if (!json.success) {
                throw new Error(json.message || json.error || 'unknown_error');
            }
            showFeedback(true, 'Готово! Перенаправляем на ' + json.tenant_url);
            setTimeout(() => { window.location.href = json.tenant_url + '/auth.php'; }, 1500);
        } catch (err) {
            showFeedback(false, err.message);
            submitBtn.disabled = false;
        }
    });
})();
