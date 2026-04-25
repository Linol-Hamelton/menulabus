(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-kitchen-station.php', {
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

    // ---- Station CRUD ----
    function readRow(tr) {
        return {
            id:         parseInt(tr.getAttribute('data-station-id') || '', 10) || null,
            label:      (tr.querySelector('.st-label') || {}).value || '',
            slug:       ((tr.querySelector('.st-slug') || {}).value || '').toLowerCase(),
            sort_order: parseInt((tr.querySelector('.st-sort') || {}).value || '0', 10) || 0,
            active:     !!(tr.querySelector('.st-active') || {}).checked,
        };
    }

    var body = document.getElementById('kitchenStationsBody');
    if (body) {
        body.addEventListener('click', function (event) {
            var save = event.target && event.target.closest ? event.target.closest('.btn-st-save') : null;
            var del  = event.target && event.target.closest ? event.target.closest('.btn-st-delete') : null;
            if (!save && !del) return;

            var tr = event.target.closest('tr');
            if (!tr) return;

            if (save) {
                var row = readRow(tr);
                if (row.label === '' || row.slug === '') {
                    window.alert('Заполните название и slug.');
                    return;
                }
                save.disabled = true;
                api({ action: 'save', id: row.id, label: row.label, slug: row.slug, active: row.active, sort_order: row.sort_order })
                    .then(function (result) {
                        save.disabled = false;
                        if (!result.ok || !result.data || !result.data.success) {
                            window.alert('Не сохранилось: ' + ((result.data && result.data.error) || 'unknown'));
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(function () { save.disabled = false; window.alert('Сетевая ошибка'); });
            }

            if (del) {
                var id = parseInt(tr.getAttribute('data-station-id') || '0', 10);
                if (!id) return;
                if (!window.confirm('Удалить станцию #' + id + '? Исторические записи на KDS не удалятся.')) return;
                del.disabled = true;
                api({ action: 'delete', id: id }).then(function (result) {
                    if (!result.ok || !result.data || !result.data.success) {
                        del.disabled = false;
                        window.alert('Не удалилось');
                        return;
                    }
                    tr.remove();
                });
            }
        });
    }

    // ---- Menu-item routing ----
    // Each checkbox edit rewrites the full station set for its item — matches
    // the setMenuItemStations contract server-side.
    var routing = document.getElementById('kitchenRoutingTable');
    if (routing) {
        var pending = {}; // itemId -> debounce timer

        routing.addEventListener('change', function (event) {
            var cb = event.target && event.target.classList && event.target.classList.contains('routing-toggle')
                ? event.target
                : null;
            if (!cb) return;
            var itemId = parseInt(cb.getAttribute('data-item-id') || '0', 10);
            if (!itemId) return;

            if (pending[itemId]) clearTimeout(pending[itemId]);
            pending[itemId] = setTimeout(function () {
                var row = routing.querySelector('tr[data-item-id="' + itemId + '"]');
                if (!row) return;
                var stationIds = [];
                row.querySelectorAll('.routing-toggle:checked').forEach(function (el) {
                    var sid = parseInt(el.getAttribute('data-station-id') || '0', 10);
                    if (sid) stationIds.push(sid);
                });

                row.classList.add('routing-row-saving');
                api({ action: 'set_item_stations', item_id: itemId, station_ids: stationIds })
                    .then(function (result) {
                        row.classList.remove('routing-row-saving');
                        if (!result.ok || !result.data || !result.data.success) {
                            window.alert('Не сохранилось для блюда #' + itemId);
                            return;
                        }
                        row.classList.add('routing-row-saved');
                        setTimeout(function () { row.classList.remove('routing-row-saved'); }, 800);
                    })
                    .catch(function () {
                        row.classList.remove('routing-row-saving');
                        window.alert('Сетевая ошибка');
                    });
            }, 250);
        });
    }
})();
