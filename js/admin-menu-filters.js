(function () {
    'use strict';

    var table = document.querySelector('table.menu-items-table');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    var bar = document.getElementById('menuFilterBar');
    if (!bar) return;

    var searchInput = document.getElementById('menuFilterSearch');
    var availSelect = document.getElementById('menuFilterAvailability');
    var sortSelect  = document.getElementById('menuFilterSort');
    var resetBtn    = document.getElementById('menuFilterReset');
    var countEl     = document.getElementById('menuFilterCount');

    var STORAGE_KEY = 'cleanmenu:admin-menu-filters:v1';
    var SEARCH_DEBOUNCE_MS = 200;

    // Row-level derived data: name, id, priceNum, stopState — read once
    // and cached on the <tr> so filter + sort passes stay O(n) without
    // re-querying textContent.
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.sortable-row'));
    var lastRowSeparator = tbody.querySelector('tr.last-row');
    rows.forEach(function (row) {
        var cells = row.children;
        row._cm = {
            id:       parseInt(row.getAttribute('data-item-id') || '0', 10),
            category: row.getAttribute('data-category') || '',
            // Expected order: drag, bulk-check, id, name, category, price, stop-cell, actions.
            name:     (cells[3] && cells[3].textContent || '').trim().toLowerCase(),
            priceNum: parsePrice(cells[5] && cells[5].textContent),
            stopBtn:  row.querySelector('.stop-btn'),
        };
    });

    function parsePrice(text) {
        if (!text) return 0;
        var digits = text.replace(/[^0-9,.-]/g, '').replace(',', '.');
        var n = parseFloat(digits);
        return isNaN(n) ? 0 : n;
    }

    function isOnStop(row) {
        // Stop state: button without '--active' = available=1; with '--active' = stop.
        return !!(row._cm.stopBtn && row._cm.stopBtn.classList.contains('stop-btn--active'));
    }

    // Store original DOM order so "default" sort can be restored losslessly.
    rows.forEach(function (row, index) { row._cm.originalIndex = index; });

    var activeCategory = (document.querySelector('.admin-menu-categories .tab-btn.active') || {}).dataset;
    activeCategory = activeCategory ? activeCategory.tab : '';

    var state = loadState();
    applyStateToControls();

    function loadState() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            if (raw) return Object.assign({}, defaultState(), JSON.parse(raw));
        } catch (e) { /* noop */ }
        return defaultState();
    }
    function defaultState() {
        return { q: '', availability: 'all', sort: 'default' };
    }
    function saveState() {
        try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) { /* noop */ }
    }
    function applyStateToControls() {
        if (searchInput) searchInput.value = state.q || '';
        if (availSelect) availSelect.value = state.availability || 'all';
        if (sortSelect)  sortSelect.value  = state.sort || 'default';
    }

    function apply() {
        var q = (state.q || '').trim().toLowerCase();
        var av = state.availability || 'all';
        var visible = 0;

        rows.forEach(function (row) {
            var match = true;
            if (q !== '') {
                match = row._cm.name.indexOf(q) !== -1
                     || String(row._cm.id).indexOf(q) !== -1;
            }
            if (match && av === 'available') match = !isOnStop(row);
            if (match && av === 'stop')      match =  isOnStop(row);

            if (match) {
                row.classList.remove('filter-hidden');
                visible++;
            } else {
                row.classList.add('filter-hidden');
            }
        });

        // Sort the visible rows if needed. Hidden rows stay in the DOM
        // so drag-n-drop positions aren't silently rewritten by a sort.
        var sorted = rows.slice();
        switch (state.sort) {
            case 'name_asc':   sorted.sort(function (a, b) { return a._cm.name.localeCompare(b._cm.name); }); break;
            case 'name_desc':  sorted.sort(function (a, b) { return b._cm.name.localeCompare(a._cm.name); }); break;
            case 'price_asc':  sorted.sort(function (a, b) { return a._cm.priceNum - b._cm.priceNum; });      break;
            case 'price_desc': sorted.sort(function (a, b) { return b._cm.priceNum - a._cm.priceNum; });      break;
            default:           sorted.sort(function (a, b) { return a._cm.originalIndex - b._cm.originalIndex; });
        }
        sorted.forEach(function (row) { tbody.insertBefore(row, lastRowSeparator); });

        if (countEl) {
            if (q !== '' || av !== 'all') {
                countEl.textContent = 'Показано ' + visible + ' из ' + rows.length;
                countEl.hidden = false;
            } else {
                countEl.hidden = true;
            }
        }

        // Drag-n-drop loses meaning under name/price sort — drop handles visually
        // so operators don't try to reorder a non-default sort.
        var custom = state.sort !== 'default';
        rows.forEach(function (row) {
            row.classList.toggle('drag-disabled', custom);
            if (custom) {
                row.removeAttribute('draggable');
            } else {
                row.setAttribute('draggable', 'true');
            }
        });
    }

    var searchTimer = null;
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                state.q = searchInput.value || '';
                saveState();
                apply();
            }, SEARCH_DEBOUNCE_MS);
        });
    }
    if (availSelect) {
        availSelect.addEventListener('change', function () {
            state.availability = availSelect.value || 'all';
            saveState();
            apply();
        });
    }
    if (sortSelect) {
        sortSelect.addEventListener('change', function () {
            state.sort = sortSelect.value || 'default';
            saveState();
            apply();
        });
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            state = defaultState();
            applyStateToControls();
            saveState();
            apply();
        });
    }

    // If the stop-button is toggled (admin-menu-page.js swaps its class live),
    // availability filter needs a pass so newly-stopped items hide/unhide.
    tbody.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.stop-btn') : null;
        if (!btn) return;
        // Give the existing stop handler a tick to flip the class.
        setTimeout(apply, 50);
    });

    apply();
})();
