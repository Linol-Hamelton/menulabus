(function () {
    'use strict';

    var board = document.querySelector('.reservations-board');
    if (!board) return;

    var csrfToken = board.getAttribute('data-csrf-token')
        || (document.body && document.body.getAttribute('data-csrf-token'))
        || '';

    var confirmTexts = {
        cancelled: 'Отменить эту бронь?',
        no_show:   'Отметить, что гость не пришёл?',
    };

    board.addEventListener('click', function (event) {
        var btn = event.target.closest('.btn-resv-action');
        if (!btn) return;

        var card = btn.closest('.reservation-card');
        if (!card) return;

        var reservationId = parseInt(card.getAttribute('data-reservation-id') || '0', 10);
        var newStatus = btn.getAttribute('data-resv-action') || '';
        if (!reservationId || !newStatus) return;

        if (confirmTexts[newStatus] && !window.confirm(confirmTexts[newStatus])) {
            return;
        }

        var actionButtons = card.querySelectorAll('.btn-resv-action');
        actionButtons.forEach(function (b) { b.disabled = true; });

        fetch('/api/reservations/update-status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                reservation_id: reservationId,
                status: newStatus,
                csrf_token: csrfToken,
            }),
            credentials: 'same-origin',
        })
        .then(function (resp) { return resp.json().then(function (data) { return { ok: resp.ok, data: data }; }); })
        .then(function (result) {
            if (!result.ok || !result.data || !result.data.success) {
                var msg = (result.data && result.data.error) ? result.data.error : 'unknown_error';
                window.alert('Не удалось обновить бронь: ' + msg);
                actionButtons.forEach(function (b) { b.disabled = false; });
                return;
            }
            window.location.reload();
        })
        .catch(function () {
            window.alert('Сетевая ошибка. Попробуйте ещё раз.');
            actionButtons.forEach(function (b) { b.disabled = false; });
        });
    });
})();
