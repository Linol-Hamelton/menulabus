(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-courier.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; }, function () {
                return { ok: r.ok, data: { success: false, error: 'invalid_json' } };
            });
        });
    }

    // Manager assigns / unassigns courier via select change.
    document.addEventListener('change', function (event) {
        var sel = event.target;
        if (!sel || !sel.matches || !sel.matches('.js-courier-select')) return;
        var orderId = parseInt(sel.getAttribute('data-order-id') || '0', 10);
        if (!orderId) return;
        var courierId = parseInt(sel.value || '0', 10);
        sel.disabled = true;
        var action = courierId > 0 ? 'assign' : 'unassign';
        api({ action: action, order_id: orderId, courier_id: courierId > 0 ? courierId : null })
            .then(function (res) {
                sel.disabled = false;
                if (!(res.ok && res.data && res.data.success)) {
                    alert('Ошибка: ' + ((res.data && res.data.error) || 'unknown'));
                }
            });
    });

    // Courier pick-up
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-courier-pickup') : null;
        if (!btn) return;
        event.preventDefault();
        var orderId = parseInt(btn.getAttribute('data-order-id') || '0', 10);
        if (!orderId) return;
        if (!window.confirm('Подтвердить, что заказ забран?')) return;
        btn.disabled = true;
        api({ action: 'pick_up', order_id: orderId }).then(function (res) {
            if (res.ok && res.data && res.data.success) {
                window.location.reload();
            } else {
                alert('Ошибка: ' + ((res.data && res.data.error) || 'unknown'));
                btn.disabled = false;
            }
        });
    });

    // Courier deliver
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-courier-deliver') : null;
        if (!btn) return;
        event.preventDefault();
        var orderId = parseInt(btn.getAttribute('data-order-id') || '0', 10);
        if (!orderId) return;
        if (!window.confirm('Подтвердить доставку?')) return;
        btn.disabled = true;
        api({ action: 'deliver', order_id: orderId }).then(function (res) {
            if (res.ok && res.data && res.data.success) {
                window.location.reload();
            } else {
                alert('Ошибка: ' + ((res.data && res.data.error) || 'unknown'));
                btn.disabled = false;
            }
        });
    });
})();
