// Reservation availability picker (Polish 12.2.4, 2026-04-27).
//
// Listens for changes to the table_label and starts_at fields on
// /reservation.php. When both are filled, fetches busy slots for that
// (table, date) from /api/reservations/availability.php and renders them as
// a compact read-only list under the date inputs. The list is purely
// informational — the form still submits to /api/reservations/create.php
// which is the authoritative slot conflict gate (returns 409 on
// overlap).
//
// The fetch is debounced 250ms so rapid keystrokes don't spam the
// endpoint. The previous fetch is aborted when a new one starts so
// late responses don't overwrite a more recent state.

(function () {
    'use strict';

    const form = document.getElementById('reservationForm');
    const target = document.getElementById('reservationBusySlots');
    if (!form || !target) return;

    const tableInput = form.querySelector('input[name="table_label"]');
    const startsInput = form.querySelector('input[name="starts_at"]');
    if (!tableInput || !startsInput) return;

    let debounceTimer = null;
    let inflight = null;

    function formatTime(dt) {
        const d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        return hh + ':' + mm;
    }

    function render(state) {
        if (state.kind === 'hidden') {
            target.hidden = true;
            target.textContent = '';
            return;
        }
        target.hidden = false;
        target.replaceChildren();

        const head = document.createElement('div');
        head.className = 'reservation-busy-slots-head';
        head.textContent = state.headline;
        target.appendChild(head);

        if (state.kind === 'busy' && state.slots.length > 0) {
            const list = document.createElement('ul');
            list.className = 'reservation-busy-slots-list';
            state.slots.forEach(function (s) {
                const li = document.createElement('li');
                li.textContent = formatTime(s.starts_at) + '–' + formatTime(s.ends_at);
                list.appendChild(li);
            });
            target.appendChild(list);
        }
    }

    function refresh() {
        const tableLabel = (tableInput.value || '').trim();
        const startsAt = (startsInput.value || '').trim();

        if (!tableLabel || !startsAt) {
            render({ kind: 'hidden' });
            return;
        }

        const datePart = startsAt.slice(0, 10);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(datePart)) {
            render({ kind: 'hidden' });
            return;
        }

        if (inflight) {
            inflight.abort();
            inflight = null;
        }

        const ctrl = new AbortController();
        inflight = ctrl;

        const url = '/api/reservations/availability.php?'
            + 'table_label=' + encodeURIComponent(tableLabel)
            + '&date=' + encodeURIComponent(datePart);

        fetch(url, {
            method: 'GET',
            signal: ctrl.signal,
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || json.success !== true) {
                    render({ kind: 'hidden' });
                    return;
                }
                const slots = Array.isArray(json.busy) ? json.busy : [];
                if (slots.length === 0) {
                    render({
                        kind: 'free',
                        headline: 'Стол свободен на ' + datePart + ' — займите любой удобный слот.',
                    });
                } else {
                    render({
                        kind: 'busy',
                        headline: 'Занятые слоты на ' + datePart + ' для стола «' + tableLabel + '»:',
                        slots: slots,
                    });
                }
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                render({ kind: 'hidden' });
            });
    }

    function schedule() {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(refresh, 250);
    }

    tableInput.addEventListener('input', schedule);
    tableInput.addEventListener('change', schedule);
    startsInput.addEventListener('input', schedule);
    startsInput.addEventListener('change', schedule);

    if (tableInput.value && startsInput.value) {
        schedule();
    }
})();
