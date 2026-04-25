(function () {
    'use strict';

    var table = document.getElementById('waitlistTable');
    if (!table) return;
    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    table.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.btn-wl') : null;
        if (!btn) return;
        var tr = event.target.closest('tr');
        if (!tr) return;
        var id = parseInt(tr.getAttribute('data-waitlist-id') || '0', 10);
        var status = btn.getAttribute('data-status') || '';
        if (!id || !status) return;

        if (status === 'cancelled' && !window.confirm('Отметить как отказ?')) return;

        btn.disabled = true;
        fetch('/api/save-waitlist.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ action: 'update_status', id: id, status: status, csrf_token: csrfToken }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) {
                window.alert('Не получилось: ' + ((d && d.error) || 'unknown'));
                btn.disabled = false;
                return;
            }
            if (status === 'seated' || status === 'cancelled' || status === 'expired') {
                tr.remove();
            } else {
                window.location.reload();
            }
        })
        .catch(function () {
            btn.disabled = false;
            window.alert('Сетевая ошибка');
        });
    });
})();
