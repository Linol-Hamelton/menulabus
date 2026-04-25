(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        return fetch('/api/save-group-order.php', {
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

    // Creation form (pre-group view).
    var createBtn = document.getElementById('gCreateBtn');
    if (createBtn) {
        createBtn.addEventListener('click', function () {
            var table = (document.getElementById('gTable') || {}).value || '';
            createBtn.disabled = true;
            api({ action: 'create', table_label: table.trim() }).then(function (r) {
                if (!r.ok || !r.data || !r.data.success) {
                    createBtn.disabled = false;
                    window.alert('Не получилось создать.');
                    return;
                }
                window.location.href = '/group.php?code=' + encodeURIComponent(r.data.code);
            }).catch(function () { createBtn.disabled = false; });
        });
    }

    // Live group view.
    var live = document.querySelector('.group-live');
    if (!live) return;
    var code = live.getAttribute('data-group-code') || '';

    var seatInput = document.getElementById('gSeat');
    var miSelect  = document.getElementById('gMenuItem');
    var qtyInput  = document.getElementById('gQty');
    var addBtn    = document.getElementById('gAddBtn');
    var submitBtn = document.getElementById('gSubmitBtn');

    function setSeatCookie(value) {
        if (!value) return;
        document.cookie = 'cleanmenu_group_seat=' + encodeURIComponent(value) + '; path=/; max-age=' + (60 * 60 * 6);
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var seat = (seatInput.value || '').trim();
            var mid  = parseInt(miSelect.value || '0', 10);
            var qty  = parseInt(qtyInput.value || '1', 10);
            if (!seat || !mid || !qty) { window.alert('Заполните имя, блюдо и количество.'); return; }
            setSeatCookie(seat);
            addBtn.disabled = true;
            api({ action: 'add_item', code: code, seat_label: seat, menu_item_id: mid, quantity: qty }).then(function (r) {
                addBtn.disabled = false;
                if (!r.ok || !r.data || !r.data.success) {
                    window.alert('Не получилось: ' + ((r.data && r.data.error) || 'unknown'));
                    return;
                }
                window.location.reload();
            }).catch(function () { addBtn.disabled = false; });
        });
    }

    live.addEventListener('click', function (event) {
        var del = event.target && event.target.classList && event.target.classList.contains('group-del')
            ? event.target : null;
        if (!del) return;
        var li = del.closest('li[data-item-row-id]');
        if (!li) return;
        var id = parseInt(li.getAttribute('data-item-row-id') || '0', 10);
        if (!id) return;
        if (!window.confirm('Убрать из общего заказа?')) return;
        api({ action: 'remove_item', code: code, item_id: id }).then(function (r) {
            if (r.ok && r.data && r.data.success) {
                li.remove();
            } else {
                window.alert('Не получилось');
            }
        });
    });

    if (submitBtn) {
        submitBtn.addEventListener('click', function () {
            var mode = 'single';
            var picked = document.querySelector('input[name="gMode"]:checked');
            if (picked) mode = picked.value;
            if (!window.confirm('Отправить на кухню? После этого нельзя менять заказ.')) return;
            submitBtn.disabled = true;
            api({ action: 'submit', code: code, mode: mode }).then(function (r) {
                if (!r.ok || !r.data || !r.data.success) {
                    submitBtn.disabled = false;
                    window.alert('Не получилось: ' + ((r.data && r.data.error) || 'unknown'));
                    return;
                }
                var ids = (r.data.order_ids || []).join(', ');
                window.alert('Заказы созданы: #' + ids);
                window.location.reload();
            }).catch(function () { submitBtn.disabled = false; });
        });
    }
})();
