(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-location.php', {
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

    var table = document.getElementById('locationsTable');
    if (!table) return;

    table.addEventListener('click', function (event) {
        var save = event.target.closest('.btn-l-save');
        var del  = event.target.closest('.btn-l-delete');
        if (!save && !del) return;
        var tr = event.target.closest('tr');
        if (!tr) return;

        if (save) {
            var id = parseInt(tr.getAttribute('data-location-id') || '', 10) || null;
            var name    = ((tr.querySelector('.l-name') || {}).value || '').trim();
            var address = ((tr.querySelector('.l-address') || {}).value || '').trim();
            var phone   = ((tr.querySelector('.l-phone') || {}).value || '').trim();
            var tz      = ((tr.querySelector('.l-tz') || {}).value || '').trim() || 'Europe/Moscow';
            var sort    = parseInt((tr.querySelector('.l-sort') || {}).value || '0', 10) || 0;
            var active  = !!(tr.querySelector('.l-active') || {}).checked;
            if (name === '') { window.alert('Название обязательно.'); return; }
            save.disabled = true;
            api({ action: 'save', id: id, name: name, address: address, phone: phone, timezone: tz, sort_order: sort, active: active })
                .then(function (r) {
                    save.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) { window.alert('Не сохранилось'); return; }
                    window.location.reload();
                }).catch(function () { save.disabled = false; });
        }

        if (del) {
            var did = parseInt(tr.getAttribute('data-location-id') || '0', 10);
            if (!did) return;
            if (!window.confirm('Деактивировать локацию #' + did + '? История сохранится.')) return;
            del.disabled = true;
            api({ action: 'delete', id: did }).then(function (r) {
                if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); del.disabled = false; return; }
                window.location.reload();
            });
        }
    });
})();
