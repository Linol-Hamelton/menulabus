(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        return fetch('/api/save-staff.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(Object.assign({ csrf_token: csrfToken }, body)),
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }

    // Clock in / clock out.
    var inBtn  = document.getElementById('clockInBtn');
    var outBtn = document.getElementById('clockOutBtn');
    if (inBtn) inBtn.addEventListener('click', function () {
        inBtn.disabled = true;
        api({ action: 'clock_in' }).then(function (r) {
            if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); inBtn.disabled = false; return; }
            window.location.reload();
        });
    });
    if (outBtn) outBtn.addEventListener('click', function () {
        outBtn.disabled = true;
        api({ action: 'clock_out' }).then(function (r) {
            if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); outBtn.disabled = false; return; }
            window.location.reload();
        });
    });

    // Shift CRUD (manager-only, the table exists only in that mode).
    var shiftsTable = document.getElementById('staffShiftsTable');
    if (shiftsTable) {
        shiftsTable.addEventListener('click', function (event) {
            var save = event.target.closest('.btn-s-save');
            var del  = event.target.closest('.btn-s-delete');
            if (!save && !del) return;
            var tr = event.target.closest('tr');
            if (!tr) return;

            if (save) {
                var id = parseInt(tr.getAttribute('data-shift-id') || '', 10) || null;
                var uid = ((tr.querySelector('.s-user') || {}).value || '').toString();
                var role = ((tr.querySelector('.s-role') || {}).value || '').trim();
                var startVal = ((tr.querySelector('.s-start') || {}).value || '').replace('T', ' ');
                var endVal   = ((tr.querySelector('.s-end') || {}).value || '').replace('T', ' ');
                var note = ((tr.querySelector('.s-note') || {}).value || '').trim();
                if (!role || !startVal || !endVal) { window.alert('Заполните роль и даты.'); return; }
                save.disabled = true;
                api({
                    action: 'save_shift',
                    id: id,
                    user_id: uid === '' ? null : parseInt(uid, 10),
                    role: role,
                    starts_at: startVal + ':00',
                    ends_at: endVal + ':00',
                    note: note,
                }).then(function (r) {
                    save.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) { window.alert('Не сохранилось'); return; }
                    window.location.reload();
                });
            }

            if (del) {
                var did = parseInt(tr.getAttribute('data-shift-id') || '0', 10);
                if (!did) return;
                if (!window.confirm('Удалить смену #' + did + '?')) return;
                del.disabled = true;
                api({ action: 'delete_shift', id: did }).then(function (r) {
                    if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); del.disabled = false; return; }
                    tr.remove();
                });
            }
        });
    }

    // Tips compute / save.
    var computeBtn = document.getElementById('tipsComputeBtn');
    var saveBtn    = document.getElementById('tipsSaveBtn');
    var fromEl     = document.getElementById('tipsFrom');
    var toEl       = document.getElementById('tipsTo');
    var resEl      = document.getElementById('tipsResult');
    var lastResult = null;

    if (computeBtn && resEl) {
        computeBtn.addEventListener('click', function () {
            var fromVal = (fromEl.value || '').replace('T', ' ') + ':00';
            var toVal   = (toEl.value   || '').replace('T', ' ') + ':00';
            computeBtn.disabled = true;
            api({ action: 'compute_tips', period_from: fromVal, period_to: toVal }).then(function (r) {
                computeBtn.disabled = false;
                if (!r.ok || !r.data || !r.data.success) { resEl.textContent = 'Ошибка расчёта.'; return; }
                lastResult = r.data;
                renderTips(r.data, fromVal, toVal);
                saveBtn.hidden = false;
            });
        });
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            if (!lastResult) return;
            var fromVal = (fromEl.value || '').replace('T', ' ') + ':00';
            var toVal   = (toEl.value   || '').replace('T', ' ') + ':00';
            saveBtn.disabled = true;
            api({
                action: 'save_tip_split',
                period_from: fromVal,
                period_to: toVal,
                pool: lastResult.pool,
                allocation: lastResult.allocation,
            }).then(function (r) {
                saveBtn.disabled = false;
                if (!r.ok || !r.data || !r.data.success) { window.alert('Не сохранилось'); return; }
                window.location.reload();
            });
        });
    }

    function escHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function renderTips(d, fromVal, toVal) {
        if (!resEl) return;
        var html = '<p><strong>Период:</strong> ' + escHtml(fromVal) + ' — ' + escHtml(toVal) + '</p>'
                 + '<p><strong>Пул:</strong> ' + Number(d.pool).toFixed(2) + ' ₽</p>'
                 + '<table class="staff-tips-table"><thead><tr><th>User</th><th>Минут</th><th>Доля</th><th>₽</th></tr></thead><tbody>';
        (d.allocation || []).forEach(function (a) {
            html += '<tr><td>#' + a.user_id + '</td><td>' + a.minutes + '</td>'
                 +  '<td>' + (a.share * 100).toFixed(2) + '%</td>'
                 +  '<td>' + Number(a.amount).toFixed(2) + '</td></tr>';
        });
        html += '</tbody></table>';
        resEl.innerHTML = html;
    }
})();
