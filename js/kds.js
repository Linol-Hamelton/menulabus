(function () {
    'use strict';

    var board = document.getElementById('kdsBoard');
    if (!board) return;

    var stationId = board.getAttribute('data-station-id');
    if (stationId === null || stationId === '') return;

    var statusLabelsRaw = board.getAttribute('data-status-labels') || '{}';
    var statusLabels = {};
    try { statusLabels = JSON.parse(statusLabelsRaw); } catch (e) { /* noop */ }

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token'))
        || (document.querySelector('meta[name="csrf-token"]') || {}).content
        || '';

    var counterEl = document.getElementById('kdsCounter');
    var clockEl = document.getElementById('kdsClock');
    var lastKnownTs = 0;
    var currentItems = [];
    var evtSource = null;
    var pollTimer = null;

    function formatAge(iso) {
        if (!iso) return '';
        var ts = Date.parse(iso.replace(' ', 'T'));
        if (isNaN(ts)) return '';
        var diffSec = Math.max(0, Math.floor((Date.now() - ts) / 1000));
        if (diffSec < 60) return diffSec + ' сек';
        var m = Math.floor(diffSec / 60);
        if (m < 60) return m + ' мин';
        var h = Math.floor(m / 60);
        return h + ' ч ' + (m % 60) + ' мин';
    }

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderBoard() {
        if (!currentItems || currentItems.length === 0) {
            board.innerHTML = '<div class="kds-empty">Очередь пуста. Ждём заказ.</div>';
            if (counterEl) counterEl.textContent = '0';
            return;
        }

        var byOrder = {};
        currentItems.forEach(function (row) {
            var oid = row.order_id;
            if (!byOrder[oid]) {
                byOrder[oid] = {
                    order_id: oid,
                    order_status: row.order_status,
                    delivery_type: row.delivery_type,
                    delivery_details: row.delivery_details,
                    order_created_at: row.order_created_at,
                    items: [],
                };
            }
            byOrder[oid].items.push(row);
        });

        var html = '';
        Object.keys(byOrder).forEach(function (oid) {
            var order = byOrder[oid];
            var age = formatAge(order.order_created_at);
            var deliveryLabel = order.delivery_type === 'table'
                ? 'Стол ' + escHtml(order.delivery_details)
                : order.delivery_type === 'delivery'
                    ? 'Доставка'
                    : order.delivery_type === 'takeaway'
                        ? 'Самовывоз'
                        : 'Бар';

            html += '<article class="kds-order kds-order-status-' + escHtml(order.order_status) + '">'
                  + '<header class="kds-order-head">'
                  + '<span class="kds-order-id">#' + escHtml(oid) + '</span>'
                  + '<span class="kds-order-delivery">' + deliveryLabel + '</span>'
                  + '<span class="kds-order-age" title="' + escHtml(order.order_created_at) + '">' + age + '</span>'
                  + '</header>'
                  + '<ul class="kds-order-items">';

            order.items.forEach(function (it) {
                var isCooking = it.status === 'cooking';
                var isReady   = it.status === 'ready';
                var statusLabel = statusLabels[it.status] || it.status;
                var btnHtml = '';
                if (it.status === 'queued') {
                    btnHtml = '<button type="button" class="kds-btn kds-btn-primary" data-kds-action="cooking" data-status-row-id="' + it.id + '">Начать</button>';
                } else if (it.status === 'cooking') {
                    btnHtml = '<button type="button" class="kds-btn kds-btn-success" data-kds-action="ready" data-status-row-id="' + it.id + '">Готово</button>';
                }
                html += '<li class="kds-item kds-item-status-' + it.status + '">'
                     +  '<div class="kds-item-main">'
                     +    '<span class="kds-item-qty">×' + (it.quantity || 1) + '</span>'
                     +    '<span class="kds-item-name">' + escHtml(it.item_name) + '</span>'
                     +  '</div>'
                     +  '<div class="kds-item-meta">'
                     +    '<span class="kds-item-status">' + escHtml(statusLabel) + '</span>'
                     +    btnHtml
                     +  '</div>'
                     +  '</li>';
            });

            html += '</ul></article>';
        });

        board.innerHTML = html;
        if (counterEl) counterEl.textContent = String(currentItems.length);
    }

    function applyUpdate(payload) {
        if (!payload) return;
        if (typeof payload.timestamp === 'number') lastKnownTs = payload.timestamp;
        if (Array.isArray(payload.items)) {
            currentItems = payload.items;
            renderBoard();
        }
    }

    function connectSse() {
        if (typeof EventSource === 'undefined') {
            startLongPoll();
            return;
        }
        try {
            var url = '/kds/sse.php?station=' + encodeURIComponent(stationId) + '&t=' + lastKnownTs;
            evtSource = new EventSource(url);
        } catch (e) {
            startLongPoll();
            return;
        }
        evtSource.addEventListener('update', function (ev) {
            try { applyUpdate(JSON.parse(ev.data)); } catch (e) { /* noop */ }
            evtSource.close();
            setTimeout(connectSse, 250);
        });
        evtSource.addEventListener('ping', function () { /* keep-alive */ });
        evtSource.addEventListener('error', function () {
            try { evtSource.close(); } catch (e) { /* noop */ }
            setTimeout(connectSse, 3000);
        });
    }

    function startLongPoll() {
        // Fallback for browsers that do not ship EventSource (rare).
        function tick() {
            fetch('/kds/sse.php?station=' + encodeURIComponent(stationId) + '&t=' + lastKnownTs, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'text/event-stream' },
            }).then(function (r) { return r.text(); }).then(function (body) {
                // Parse the first `event: update` frame if present.
                var m = /data:\s*(\{[\s\S]*?\})/m.exec(body);
                if (m) {
                    try { applyUpdate(JSON.parse(m[1])); } catch (e) { /* noop */ }
                }
                pollTimer = setTimeout(tick, 2000);
            }).catch(function () {
                pollTimer = setTimeout(tick, 5000);
            });
        }
        tick();
    }

    board.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('[data-kds-action]') : null;
        if (!btn) return;
        var action = btn.getAttribute('data-kds-action') || '';
        var rowId = parseInt(btn.getAttribute('data-status-row-id') || '0', 10);
        if (!rowId || !action) return;
        btn.disabled = true;
        fetch('/kds/action.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ status_row_id: rowId, status: action, csrf_token: csrfToken }),
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (result) {
            if (!result.ok || !result.data || !result.data.success) {
                btn.disabled = false;
                window.alert('Не удалось обновить статус: ' + ((result.data && result.data.error) || 'unknown'));
                return;
            }
            // SSE will push the fresh board; if latency is noticeable we also
            // optimistically strip the row here.
            if (result.data.status === 'ready') {
                var card = btn.closest('.kds-item');
                if (card) card.classList.add('kds-item-flash-ready');
            }
        })
        .catch(function () {
            btn.disabled = false;
            window.alert('Сетевая ошибка');
        });
    });

    function updateClock() {
        if (!clockEl) return;
        var d = new Date();
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        clockEl.textContent = hh + ':' + mm;
    }
    updateClock();
    setInterval(updateClock, 30000);

    // Kick off SSE after initial render (empty state shows while we wait for first frame).
    renderBoard();
    connectSse();
})();
