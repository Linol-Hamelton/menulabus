(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-loyalty.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }

    // ---- Tiers ----
    var tTable = document.getElementById('loyaltyTiersTable');
    if (tTable) {
        tTable.addEventListener('click', function (event) {
            var save = event.target.closest('.btn-t-save');
            var arch = event.target.closest('.btn-t-archive');
            if (!save && !arch) return;
            var tr = event.target.closest('tr');
            if (!tr) return;

            if (save) {
                var id = parseInt(tr.getAttribute('data-tier-id') || '', 10) || null;
                var name = ((tr.querySelector('.t-name') || {}).value || '').trim();
                var min  = parseFloat((tr.querySelector('.t-min') || {}).value || '0') || 0;
                var cb   = parseFloat((tr.querySelector('.t-cb') || {}).value || '0') || 0;
                var so   = parseInt((tr.querySelector('.t-sort') || {}).value || '0', 10) || 0;
                if (name === '') { window.alert('Введите название тира.'); return; }
                save.disabled = true;
                api({ action: 'save_tier', id: id, name: name, min_spent: min, cashback_pct: cb, sort_order: so })
                    .then(function (r) {
                        save.disabled = false;
                        if (!r.ok || !r.data || !r.data.success) { window.alert('Не сохранилось'); return; }
                        window.location.reload();
                    }).catch(function () { save.disabled = false; });
            }

            if (arch) {
                var aid = parseInt(tr.getAttribute('data-tier-id') || '0', 10);
                if (!aid) return;
                if (!window.confirm('Архивировать тир #' + aid + '?')) return;
                arch.disabled = true;
                api({ action: 'archive_tier', id: aid }).then(function (r) {
                    if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); arch.disabled = false; return; }
                    window.location.reload();
                });
            }
        });
    }

    // ---- Promos ----
    var pTable = document.getElementById('loyaltyPromosTable');
    if (pTable) {
        pTable.addEventListener('click', function (event) {
            var save = event.target.closest('.btn-p-save');
            var arch = event.target.closest('.btn-p-archive');
            if (!save && !arch) return;
            var tr = event.target.closest('tr');
            if (!tr) return;

            if (save) {
                var id   = parseInt(tr.getAttribute('data-promo-id') || '', 10) || null;
                var code = ((tr.querySelector('.p-code') || {}).value || '').trim().toUpperCase();
                var pct  = ((tr.querySelector('.p-pct') || {}).value || '').trim();
                var amt  = ((tr.querySelector('.p-amt') || {}).value || '').trim();
                var minT = parseFloat((tr.querySelector('.p-min-total') || {}).value || '0') || 0;
                var from = ((tr.querySelector('.p-from') || {}).value || '').trim();
                var to   = ((tr.querySelector('.p-to') || {}).value || '').trim();
                var lim  = parseInt((tr.querySelector('.p-limit') || {}).value || '0', 10) || 0;
                var desc = ((tr.querySelector('.p-desc') || {}).value || '').trim();

                if (code === '') { window.alert('Укажите код.'); return; }
                if (pct === '' && amt === '') { window.alert('Укажите % или сумму скидки.'); return; }
                if (pct !== '' && amt !== '') { window.alert('Выберите одно: % или сумму.'); return; }

                save.disabled = true;
                api({
                    action: 'save_promo',
                    id: id, code: code,
                    discount_pct: pct === '' ? null : parseFloat(pct),
                    discount_amount: amt === '' ? null : parseFloat(amt),
                    min_order_total: minT,
                    valid_from: from === '' ? null : from.replace('T', ' ') + ':00',
                    valid_to:   to === '' ? null : to.replace('T', ' ') + ':00',
                    usage_limit: lim,
                    description: desc,
                }).then(function (r) {
                    save.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) {
                        window.alert('Не сохранилось: ' + ((r.data && r.data.error) || 'unknown'));
                        return;
                    }
                    window.location.reload();
                }).catch(function () { save.disabled = false; });
            }

            if (arch) {
                var aid = parseInt(tr.getAttribute('data-promo-id') || '0', 10);
                if (!aid) return;
                if (!window.confirm('Архивировать промо-код #' + aid + '?')) return;
                arch.disabled = true;
                api({ action: 'archive_promo', id: aid }).then(function (r) {
                    if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); arch.disabled = false; return; }
                    window.location.reload();
                });
            }
        });
    }
})();
