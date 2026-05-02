(function () {
    'use strict';

    var form = document.getElementById('reservationForm');
    if (!form) return;

    var statusBox = document.getElementById('reservationStatus');
    var submitBtn = document.getElementById('reservationSubmit');
    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token'))
        || (form.querySelector('input[name="csrf_token"]') || {}).value
        || '';

    var errorMessages = {
        table_label_required:     'Укажите номер или название стола.',
        guests_count_out_of_range:'Количество гостей должно быть от 1 до 50.',
        starts_ends_required:     'Заполните дату начала и окончания.',
        datetime_unparseable:     'Не удалось распознать дату/время.',
        window_inverted:          'Окончание должно быть позже начала.',
        starts_in_past:           'Время начала не может быть в прошлом.',
        guest_contact_required:   'Введите имя и телефон.',
        slot_taken:               'Этот стол уже занят на выбранное время.',
        csrf_mismatch:            'Ошибка безопасности. Обновите страницу.',
        method_not_allowed:       'Метод не разрешён.',
        db_failure:               'Не удалось сохранить бронь, попробуйте позже.',
    };

    function setStatus(text, kind) {
        if (!statusBox) return;
        statusBox.hidden = false;
        statusBox.textContent = text;
        statusBox.className = 'reservation-status reservation-status-' + (kind || 'info');
    }

    function toIsoLocal(value) {
        if (!value) return '';
        return value.replace('T', ' ') + ':00';
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (statusBox) statusBox.hidden = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Отправляем…';

        var data = new FormData(form);
        var payload = {
            table_label:  (data.get('table_label')  || '').toString().trim(),
            guests_count: parseInt(data.get('guests_count') || '0', 10),
            starts_at:    toIsoLocal((data.get('starts_at') || '').toString()),
            ends_at:      toIsoLocal((data.get('ends_at') || '').toString()),
            note:         (data.get('note') || '').toString(),
            csrf_token:   csrfToken,
        };
        if (data.get('guest_name'))  payload.guest_name  = (data.get('guest_name')  || '').toString().trim();
        if (data.get('guest_phone')) payload.guest_phone = (data.get('guest_phone') || '').toString().trim();

        fetch('/api/reservations/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
            credentials: 'same-origin',
        })
        .then(function (resp) { return resp.json().then(function (data) { return { ok: resp.ok, data: data }; }); })
        .then(function (result) {
            if (result.ok && result.data && result.data.success) {
                setStatus('Заявка #' + result.data.reservation_id + ' принята. Мы пришлём подтверждение.', 'success');
                form.reset();
                submitBtn.disabled = false;
                submitBtn.textContent = 'Забронировать ещё';
                return;
            }
            var code = (result.data && result.data.error) ? result.data.error : 'unknown';
            setStatus(errorMessages[code] || ('Ошибка: ' + code), 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Забронировать';

            // Waitlist fallback (Phase 8.4): if the slot is taken, offer to join the queue.
            if (code === 'slot_taken') {
                offerWaitlist(payload);
            }
        })
        .catch(function () {
            setStatus('Сетевая ошибка. Попробуйте ещё раз.', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Забронировать';
        });
    });

    function offerWaitlist(payload) {
        if (!statusBox) return;
        var wrap = document.createElement('div');
        wrap.className = 'reservation-waitlist-offer';
        wrap.innerHTML = '<p>Можем поставить вас в очередь. Если стол освободится — позвоним.</p>';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'checkout-btn';
        btn.textContent = 'Встать в очередь';
        wrap.appendChild(btn);
        statusBox.appendChild(wrap);

        btn.addEventListener('click', function () {
            btn.disabled = true;
            var preferredDate = (payload.starts_at || '').substring(0, 10);
            var preferredTime = (payload.starts_at || '').substring(11, 16);
            fetch('/api/save-waitlist.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'create',
                    guest_name:  payload.guest_name || null,
                    guest_phone: payload.guest_phone || '',
                    guests_count: payload.guests_count,
                    preferred_date: preferredDate,
                    preferred_time: preferredTime,
                    note: payload.note || null,
                    csrf_token: csrfToken,
                }),
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success) {
                    setStatus('Вы в очереди #' + d.id + '. Мы свяжемся, как только стол освободится.', 'success');
                    wrap.remove();
                } else {
                    btn.disabled = false;
                    alert('Не получилось: ' + ((d && d.error) || 'unknown'));
                }
            })
            .catch(function () {
                btn.disabled = false;
                alert('Сетевая ошибка');
            });
        });
    }
})();
