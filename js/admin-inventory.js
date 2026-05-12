(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-inventory.php', {
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

    function fmt(n) {
        if (n === null || n === undefined || n === '') return '—';
        var s = Number(n).toString();
        return s;
    }

    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ---- Ingredients ----
    var ingrTable = document.getElementById('invIngredientsTable');

    // Phase 33: read the current unit string from the unit cell.
    //   Canonical units: <select> value.
    //   "Другое…" sentinel: read from the adjacent <input class="inv-unit-other">.
    function readUnit(tr) {
        if (!tr) return '';
        var sel = tr.querySelector('.inv-unit-select');
        if (sel) {
            if (sel.value === '__other__') {
                var other = tr.querySelector('.inv-unit-other');
                return ((other && other.value) || '').trim();
            }
            return (sel.value || '').trim();
        }
        // Backwards-compat: pages that still ship the old <input class="inv-unit">.
        var legacy = tr.querySelector('.inv-unit');
        return ((legacy && legacy.value) || '').trim();
    }

    // Toggle the inline "Другое" input when the <select> changes.
    if (ingrTable) {
        ingrTable.addEventListener('change', function (event) {
            var sel = event.target && event.target.classList && event.target.classList.contains('inv-unit-select')
                ? event.target : null;
            if (!sel) return;
            var cell = sel.closest('.inv-unit-cell');
            if (!cell) return;
            var other = cell.querySelector('.inv-unit-other');
            if (!other) return;
            if (sel.value === '__other__') {
                other.hidden = false;
                other.focus();
            } else {
                other.hidden = true;
                other.value = '';
            }
        });
    }

    if (ingrTable) {
        ingrTable.addEventListener('click', function (event) {
            var tr = event.target && event.target.closest ? event.target.closest('tr') : null;
            if (!tr) return;

            // Phase 34.1 — kebab toggle reveals the ± adjust + history controls
            // inside the stock cell so the default row stays clean.
            var kebab = event.target.closest('.inv-kebab-btn');
            if (kebab) {
                var ctrls = tr.querySelector('.inv-adjust-controls');
                if (ctrls) {
                    ctrls.hidden = !ctrls.hidden;
                    if (!ctrls.hidden) {
                        var d = ctrls.querySelector('.inv-adjust-delta');
                        if (d) d.focus();
                    }
                }
                return;
            }

            var save = event.target.closest('.btn-inv-save');
            var arch = event.target.closest('.btn-inv-archive');
            var rest = event.target.closest('.btn-inv-restore');
            var hist = event.target.closest('.btn-inv-history');
            var apply = event.target.closest('.btn-inv-apply');

            if (save) {
                var id = parseInt(tr.getAttribute('data-ingredient-id') || '', 10) || null;
                var name  = ((tr.querySelector('.inv-name') || {}).value || '').trim();
                var unit  = readUnit(tr) || 'шт';
                var threshold = parseFloat((tr.querySelector('.inv-threshold') || {}).value || '0') || 0;
                var cost = parseFloat((tr.querySelector('.inv-cost') || {}).value || '0') || 0;
                var supplier = (tr.querySelector('.inv-supplier') || {}).value || '';
                var stockQty;
                if (id) {
                    // For existing rows, keep the current stock — adjustments go through Apply.
                    stockQty = parseFloat((tr.querySelector('.inv-stock-value') || {}).textContent || '0') || 0;
                } else {
                    stockQty = parseFloat((tr.querySelector('.inv-new-stock') || {}).value || '0') || 0;
                }

                if (name === '') { window.alert('Укажите название.'); return; }
                save.disabled = true;
                api({
                    action: 'save_ingredient',
                    id: id,
                    name: name, unit: unit, stock_qty: stockQty,
                    reorder_threshold: threshold, cost_per_unit: cost,
                    supplier_id: supplier === '' ? null : parseInt(supplier, 10),
                }).then(function (r) {
                    save.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) {
                        window.alert('Не сохранилось: ' + ((r.data && r.data.error) || 'unknown'));
                        return;
                    }
                    window.location.reload();
                }).catch(function () { save.disabled = false; window.alert('Сетевая ошибка'); });
            }

            if (apply) {
                var iid = parseInt(tr.getAttribute('data-ingredient-id') || '0', 10);
                if (!iid) return;
                var delta = parseFloat((tr.querySelector('.inv-adjust-delta') || {}).value || '0') || 0;
                if (!delta) { window.alert('Введите положительное или отрицательное число.'); return; }
                var reason = delta > 0 ? 'receipt' : 'waste';
                if (!window.confirm('Изменить остаток на ' + delta + '?\nПричина: ' + reason)) return;
                apply.disabled = true;
                api({ action: 'adjust_stock', id: iid, delta: delta, reason: reason }).then(function (r) {
                    apply.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) {
                        window.alert('Не получилось: ' + ((r.data && r.data.error) || 'unknown'));
                        return;
                    }
                    var ing = r.data.ingredient || {};
                    var cell = tr.querySelector('.inv-stock-value');
                    if (cell) cell.textContent = fmt(ing.stock_qty);
                    var deltaInput = tr.querySelector('.inv-adjust-delta');
                    if (deltaInput) deltaInput.value = '';
                }).catch(function () { apply.disabled = false; window.alert('Сетевая ошибка'); });
            }

            if (arch) {
                var aid = parseInt(tr.getAttribute('data-ingredient-id') || '0', 10);
                if (!aid) return;
                if (!window.confirm('Архивировать ингредиент #' + aid + '?')) return;
                arch.disabled = true;
                api({ action: 'archive_ingredient', id: aid }).then(function (r) {
                    arch.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); return; }
                    window.location.reload();
                }).catch(function () { arch.disabled = false; });
            }

            if (rest) {
                var rid = parseInt(tr.getAttribute('data-ingredient-id') || '0', 10);
                if (!rid) return;
                rest.disabled = true;
                api({ action: 'restore_ingredient', id: rid }).then(function (r) {
                    rest.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); return; }
                    window.location.reload();
                }).catch(function () { rest.disabled = false; });
            }

            if (hist) {
                var hid = parseInt(tr.getAttribute('data-ingredient-id') || '0', 10);
                if (!hid) return;
                loadHistory(hid, (tr.querySelector('.inv-name') || {}).value);
            }
        });
    }

    var histPanel  = document.getElementById('invHistoryPanel');
    var histTbody  = histPanel ? histPanel.querySelector('tbody') : null;
    var histMeta   = histPanel ? histPanel.querySelector('.inv-history-meta') : null;

    function loadHistory(id, name) {
        if (!histPanel || !histTbody) return;
        api({ action: 'list_movements', id: id, limit: 100 }).then(function (r) {
            if (!r.ok || !r.data || !r.data.success) { window.alert('История недоступна'); return; }
            histMeta.textContent = 'Ингредиент #' + id + (name ? ' · ' + name : '') + ' — последние ' + (r.data.movements || []).length + ' движений';
            histTbody.innerHTML = '';
            (r.data.movements || []).forEach(function (m) {
                var tr = document.createElement('tr');
                tr.className = parseFloat(m.delta) < 0 ? 'inv-mv-out' : 'inv-mv-in';
                var meta = [];
                if (m.order_id) meta.push('заказ #' + m.order_id);
                if (m.menu_item_id) meta.push('блюдо #' + m.menu_item_id);
                tr.innerHTML = ''
                    + '<td>#' + (m.id || '') + '</td>'
                    + '<td>' + escHtml(fmt(m.delta)) + '</td>'
                    + '<td>' + escHtml(m.reason || '') + '</td>'
                    + '<td>' + escHtml(meta.join(' · ')) + '</td>'
                    + '<td>' + escHtml(m.note || '') + '</td>'
                    + '<td>' + escHtml(m.created_at || '') + '</td>';
                histTbody.appendChild(tr);
            });
            histPanel.hidden = false;
            histPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }).catch(function () { window.alert('Сетевая ошибка'); });
    }

    // ---- Suppliers ----
    var supTable = document.getElementById('invSuppliersTable');
    if (supTable) {
        supTable.addEventListener('click', function (event) {
            var save = event.target.closest('.btn-sup-save');
            if (!save) return;
            var tr = event.target.closest('tr');
            if (!tr) return;
            var id = parseInt(tr.getAttribute('data-supplier-id') || '', 10) || null;
            var name = ((tr.querySelector('.sup-name') || {}).value || '').trim();
            var contact = ((tr.querySelector('.sup-contact') || {}).value || '').trim();
            var notes = ((tr.querySelector('.sup-notes') || {}).value || '').trim();
            if (name === '') { window.alert('Название обязательно.'); return; }
            save.disabled = true;
            api({ action: 'save_supplier', id: id, name: name, contact: contact, notes: notes }).then(function (r) {
                save.disabled = false;
                if (!r.ok || !r.data || !r.data.success) { window.alert('Не сохранилось'); return; }
                window.location.reload();
            }).catch(function () { save.disabled = false; });
        });
    }

    // ---- Phase 34: client-side filtering ----
    // Server-side already pre-filtered by URL params (?q, ?supplier, ?stock).
    // Live inputs apply additional filters without reloading the page.
    var filterSearch   = document.getElementById('invFilterSearch');
    var filterSupplier = document.getElementById('invFilterSupplier');
    var filterStock    = document.getElementById('invFilterStock');
    var filterReset    = document.getElementById('invFilterReset');

    function applyFilters() {
        var q = (filterSearch && filterSearch.value || '').trim().toLowerCase();
        var sup = filterSupplier ? filterSupplier.value : '';
        var stat = filterStock ? filterStock.value : '';

        // Phase 34.1: filter both the desktop table rows and mobile cards.
        var hosts = document.querySelectorAll('.inv-table-wrapper tbody tr[data-ingredient-id], .inv-mcard[data-ingredient-id]');
        hosts.forEach(function (host) {
            if ((host.getAttribute('data-ingredient-id') || '') === '') return;
            // Source of truth for name: desktop has .inv-name input; mobile has .inv-mcard-name text.
            var name = '';
            var nameInput = host.querySelector('.inv-name');
            if (nameInput && typeof nameInput.value === 'string') {
                name = nameInput.value;
            } else {
                var nameSpan = host.querySelector('.inv-mcard-name');
                if (nameSpan) name = nameSpan.textContent || '';
            }
            name = name.toLowerCase();
            var supVal = host.getAttribute('data-supplier-id') || '';
            var stockStatus = host.getAttribute('data-stock-status') || '';
            var hide = false;
            if (q && name.indexOf(q) === -1) hide = true;
            if (!hide && sup !== '') {
                if (sup === '0' && supVal !== '') hide = true;
                if (sup !== '0' && supVal !== sup) hide = true;
            }
            if (!hide && stat !== '' && stockStatus !== stat) hide = true;
            host.hidden = hide;
        });
    }

    if (filterSearch)   filterSearch.addEventListener('input', applyFilters);
    if (filterSupplier) filterSupplier.addEventListener('change', applyFilters);
    if (filterStock)    filterStock.addEventListener('change', applyFilters);
    if (filterReset) {
        filterReset.addEventListener('click', function () {
            if (filterSearch)   filterSearch.value = '';
            if (filterSupplier) filterSupplier.value = '';
            if (filterStock)    filterStock.value = '';
            applyFilters();
        });
    }
    // Run once on load — page may have arrived with pre-filled inputs.
    applyFilters();

    // ---- Phase 34: bulk selection + bulk-archive ----
    var bulkBar     = document.getElementById('invBulkBar');
    var bulkCountEl = document.getElementById('invBulkCount');
    var bulkArchive = document.getElementById('invBulkArchive');
    var bulkClear   = document.getElementById('invBulkClear');
    var selectAll   = document.getElementById('invSelectAll');

    // Phase 34.1: bulk selection works across both the desktop table AND
    // the mobile-card list (only one is visible at any time, but checkbox
    // state must roll up the same way).
    function collectChecked() {
        var ids = {};
        document.querySelectorAll('.inv-row-check:checked').forEach(function (cb) {
            var host = cb.closest('[data-ingredient-id]');
            if (!host) return;
            var id = parseInt(host.getAttribute('data-ingredient-id') || '0', 10);
            if (id > 0) ids[id] = true;
        });
        return Object.keys(ids).map(function (k) { return parseInt(k, 10); });
    }

    function refreshBulkBar() {
        if (!bulkBar || !bulkCountEl) return;
        var ids = collectChecked();
        bulkCountEl.textContent = String(ids.length);
        bulkBar.hidden = ids.length === 0;
    }

    // Phase 34.1 — listen for any .inv-row-check change anywhere on the page
    // (covers both desktop table rows and mobile cards).
    document.addEventListener('change', function (event) {
        if (event.target && event.target.classList && event.target.classList.contains('inv-row-check')) {
            refreshBulkBar();
        }
    });
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checked = selectAll.checked;
            document.querySelectorAll('[data-ingredient-id] .inv-row-check').forEach(function (cb) {
                var host = cb.closest('[data-ingredient-id]');
                // Skip hidden (filtered-out) rows/cards so "select all" matches what user sees.
                if (host && host.hidden) return;
                cb.checked = checked;
            });
            refreshBulkBar();
        });
    }
    if (bulkClear) {
        bulkClear.addEventListener('click', function () {
            document.querySelectorAll('.inv-row-check:checked').forEach(function (cb) { cb.checked = false; });
            if (selectAll) selectAll.checked = false;
            refreshBulkBar();
        });
    }
    if (bulkArchive) {
        bulkArchive.addEventListener('click', function () {
            var ids = collectChecked();
            if (ids.length === 0) return;
            if (!window.confirm('Архивировать ' + ids.length + ' ингредиент(ов)?')) return;
            bulkArchive.disabled = true;
            api({ action: 'bulk_archive_ingredients', ids: ids }).then(function (r) {
                bulkArchive.disabled = false;
                if (!r.ok || !r.data || !r.data.success) {
                    window.alert('Не получилось: ' + ((r.data && r.data.error) || 'unknown'));
                    return;
                }
                window.location.reload();
            }).catch(function () { bulkArchive.disabled = false; window.alert('Сетевая ошибка'); });
        });
    }

    // ---- Phase 34.1: slide-down create panel ----
    var createToggle = document.getElementById('invNewToggle');
    var createPanel  = document.getElementById('invCreatePanel');
    var createCancel = document.getElementById('invCreateCancel');
    var createSubmit = document.getElementById('invCreateSubmit');
    var createUnit   = document.getElementById('invNewUnit');
    var createUnitOtherWrap = document.getElementById('invNewUnitOtherWrap');
    var createUnitOther = document.getElementById('invNewUnitOther');

    if (createToggle && createPanel) {
        createToggle.addEventListener('click', function () {
            createPanel.hidden = !createPanel.hidden;
            if (!createPanel.hidden) {
                var n = document.getElementById('invNewName');
                if (n) n.focus();
            }
        });
    }
    if (createCancel && createPanel) {
        createCancel.addEventListener('click', function () { createPanel.hidden = true; });
    }
    if (createUnit && createUnitOtherWrap) {
        createUnit.addEventListener('change', function () {
            createUnitOtherWrap.hidden = createUnit.value !== '__other__';
            if (!createUnitOtherWrap.hidden && createUnitOther) createUnitOther.focus();
        });
    }
    if (createSubmit) {
        createSubmit.addEventListener('click', function () {
            var name = (document.getElementById('invNewName').value || '').trim();
            if (!name) { window.alert('Укажите название.'); return; }
            var unit;
            if (createUnit && createUnit.value === '__other__') {
                unit = (createUnitOther && createUnitOther.value || '').trim() || 'шт';
            } else {
                unit = (createUnit && createUnit.value || '').trim() || 'шт';
            }
            var stock = parseFloat(document.getElementById('invNewStock').value || '0') || 0;
            var thr   = parseFloat(document.getElementById('invNewThreshold').value || '0') || 0;
            var cost  = parseFloat(document.getElementById('invNewCost').value || '0') || 0;
            var supRaw = document.getElementById('invNewSupplier').value || '';
            createSubmit.disabled = true;
            api({
                action: 'save_ingredient',
                id: null,
                name: name, unit: unit, stock_qty: stock,
                reorder_threshold: thr, cost_per_unit: cost,
                supplier_id: supRaw === '' ? null : parseInt(supRaw, 10),
            }).then(function (r) {
                createSubmit.disabled = false;
                if (!r.ok || !r.data || !r.data.success) {
                    window.alert('Не сохранилось: ' + ((r.data && r.data.error) || 'unknown'));
                    return;
                }
                window.location.reload();
            }).catch(function () { createSubmit.disabled = false; window.alert('Сетевая ошибка'); });
        });
    }

    // ---- Phase 34.1: edit-ingredient modal ----
    var editModal     = document.getElementById('invEditModal');
    var editIdInput   = document.getElementById('invEditId');
    var editSubtitle  = document.getElementById('invEditSubtitle');
    var editName      = document.getElementById('invEditName');
    var editUnit      = document.getElementById('invEditUnit');
    var editUnitOtherWrap = document.getElementById('invEditUnitOtherWrap');
    var editUnitOther = document.getElementById('invEditUnitOther');
    var editStockRO   = document.getElementById('invEditStockReadonly');
    var editThreshold = document.getElementById('invEditThreshold');
    var editCost      = document.getElementById('invEditCost');
    var editSupplier  = document.getElementById('invEditSupplier');
    var editSave      = document.getElementById('invEditSave');
    var editMsg       = document.getElementById('invEditMsg');

    function closeEditModal() {
        if (!editModal) return;
        try { editModal.close(); } catch (_) { editModal.removeAttribute('open'); }
        if (editMsg) { editMsg.hidden = true; editMsg.textContent = ''; }
    }

    function openEditModalById(id) {
        if (!editModal) return;
        // Read field values from the desktop row (it's still in DOM even when hidden).
        var tr = document.querySelector('.inv-ingredients-table tbody tr[data-ingredient-id="' + id + '"]');
        if (!tr) {
            window.alert('Строка ингредиента не найдена.');
            return;
        }
        editIdInput.value = String(id);
        var nameVal = (tr.querySelector('.inv-name') || {}).value || '';
        editName.value = nameVal;
        editSubtitle.textContent = '#' + id + ' · ' + nameVal;
        var unitVal = readUnit(tr) || 'шт';
        var canonical = ['г','кг','мл','л','шт','порц','упак'].indexOf(unitVal) !== -1;
        if (canonical) {
            editUnit.value = unitVal;
            editUnitOtherWrap.hidden = true;
            editUnitOther.value = '';
        } else {
            editUnit.value = '__other__';
            editUnitOtherWrap.hidden = false;
            editUnitOther.value = unitVal;
        }
        editStockRO.textContent = ((tr.querySelector('.inv-stock-value') || {}).textContent || '0');
        editThreshold.value = (tr.querySelector('.inv-threshold') || {}).value || '0';
        editCost.value = (tr.querySelector('.inv-cost') || {}).value || '0';
        editSupplier.value = (tr.querySelector('.inv-supplier') || {}).value || '';
        var requiresVsd = document.getElementById('invEditRequiresVsd');
        if (requiresVsd) {
            requiresVsd.checked = (tr.getAttribute('data-requires-vsd') === '1');
        }
        var isAlc = document.getElementById('invEditIsAlcohol');
        var alcCodeWrap = document.getElementById('invEditAlcCodeWrap');
        var alcCode = document.getElementById('invEditAlcCode');
        if (isAlc) {
            isAlc.checked = (tr.getAttribute('data-is-alcohol') === '1');
            if (alcCodeWrap) alcCodeWrap.hidden = !isAlc.checked;
        }
        if (alcCode) alcCode.value = tr.getAttribute('data-alc-code') || '';
        var isSf = document.getElementById('invEditIsSemiFinished');
        var yieldWrap = document.getElementById('invEditYieldWrap');
        var yieldInput = document.getElementById('invEditYieldPerBatch');
        if (isSf) {
            isSf.checked = (tr.getAttribute('data-is-semi-finished') === '1');
            if (yieldWrap) yieldWrap.hidden = !isSf.checked;
        }
        if (yieldInput) yieldInput.value = tr.getAttribute('data-yield-per-batch') || '0';
        try { editModal.showModal(); } catch (_) { editModal.setAttribute('open', ''); }
    }

    if (editModal) {
        editModal.addEventListener('click', function (ev) {
            if (ev.target && ev.target.matches && ev.target.matches('[data-inv-modal-close]')) {
                ev.preventDefault();
                closeEditModal();
            }
        });
    }
    if (editUnit && editUnitOtherWrap) {
        editUnit.addEventListener('change', function () {
            editUnitOtherWrap.hidden = editUnit.value !== '__other__';
            if (!editUnitOtherWrap.hidden && editUnitOther) editUnitOther.focus();
        });
    }
    // Phase 39: toggle alc_code field visibility based on is_alcohol checkbox
    var isAlcCheckbox = document.getElementById('invEditIsAlcohol');
    var alcCodeWrapEl = document.getElementById('invEditAlcCodeWrap');
    if (isAlcCheckbox && alcCodeWrapEl) {
        isAlcCheckbox.addEventListener('change', function () {
            alcCodeWrapEl.hidden = !isAlcCheckbox.checked;
        });
    }
    // Phase 40: toggle yield_per_batch field visibility
    var isSfCheckbox = document.getElementById('invEditIsSemiFinished');
    var yieldWrapEl = document.getElementById('invEditYieldWrap');
    if (isSfCheckbox && yieldWrapEl) {
        isSfCheckbox.addEventListener('change', function () {
            yieldWrapEl.hidden = !isSfCheckbox.checked;
        });
    }
    if (editSave) {
        editSave.addEventListener('click', function () {
            var id = parseInt(editIdInput.value || '0', 10);
            if (!id) return;
            var name = (editName.value || '').trim();
            if (!name) { window.alert('Укажите название.'); return; }
            var unit = editUnit.value === '__other__'
                ? ((editUnitOther.value || '').trim() || 'шт')
                : (editUnit.value || 'шт');
            var thr = parseFloat(editThreshold.value || '0') || 0;
            var cost = parseFloat(editCost.value || '0') || 0;
            var sup = editSupplier.value || '';
            var stockQty = parseFloat(editStockRO.textContent || '0') || 0;
            var requiresVsdEl = document.getElementById('invEditRequiresVsd');
            var requiresVsd = !!(requiresVsdEl && requiresVsdEl.checked);
            var isAlcEl = document.getElementById('invEditIsAlcohol');
            var alcCodeEl = document.getElementById('invEditAlcCode');
            var isAlcohol = !!(isAlcEl && isAlcEl.checked);
            var alcCodeVal = (alcCodeEl && alcCodeEl.value || '').trim();
            editSave.disabled = true;
            api({
                action: 'save_ingredient',
                id: id,
                name: name, unit: unit, stock_qty: stockQty,
                reorder_threshold: thr, cost_per_unit: cost,
                supplier_id: sup === '' ? null : parseInt(sup, 10),
            }).then(function (r) {
                if (!r.ok || !r.data || !r.data.success) {
                    editSave.disabled = false;
                    editMsg.hidden = false;
                    editMsg.className = 'recipe-save-msg recipe-save-msg-error';
                    editMsg.textContent = 'Не сохранилось: ' + ((r.data && r.data.error) || 'unknown');
                    return null;
                }
                // Phase 38: persist requires_vsd flag via /api/save-vsd.php
                return fetch('/api/save-vsd.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'toggle_requires',
                        ingredient_id: id,
                        requires: requiresVsd,
                        csrf_token: csrfToken,
                    }),
                });
            }).then(function (res) {
                if (res === null) return null;
                // Phase 39: persist is_alcohol + alc_code via /api/save-egais.php
                return fetch('/api/save-egais.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'toggle_alcohol',
                        ingredient_id: id,
                        is_alcohol: isAlcohol,
                        alc_code: alcCodeVal,
                        csrf_token: csrfToken,
                    }),
                });
            }).then(function (res) {
                if (res === null) return null;
                // Phase 40: persist is_semi_finished + yield_per_batch
                var isSfEl = document.getElementById('invEditIsSemiFinished');
                var yieldEl = document.getElementById('invEditYieldPerBatch');
                var isSf = !!(isSfEl && isSfEl.checked);
                var yieldVal = parseFloat((yieldEl && yieldEl.value) || '0') || 0;
                return fetch('/api/save-semi-finished.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'toggle',
                        ingredient_id: id,
                        is_semi_finished: isSf,
                        yield_per_batch: yieldVal,
                        csrf_token: csrfToken,
                    }),
                });
            }).then(function (res) {
                if (res === null) return;
                editSave.disabled = false;
                window.location.reload();
            }).catch(function () { editSave.disabled = false; window.alert('Сетевая ошибка'); });
        });
    }

    // ---- Phase 34.1: adjust-stock modal (mobile entrypoint) ----
    var adjustModal = document.getElementById('invAdjustModal');
    var adjustId    = document.getElementById('invAdjustId');
    var adjustSub   = document.getElementById('invAdjustSubtitle');
    var adjustDelta = document.getElementById('invAdjustDelta');
    var adjustSave  = document.getElementById('invAdjustSave');
    var adjustMsg   = document.getElementById('invAdjustMsg');

    function closeAdjustModal() {
        if (!adjustModal) return;
        try { adjustModal.close(); } catch (_) { adjustModal.removeAttribute('open'); }
        if (adjustMsg) { adjustMsg.hidden = true; adjustMsg.textContent = ''; }
        if (adjustDelta) adjustDelta.value = '';
    }

    function openAdjustModalById(id) {
        if (!adjustModal) return;
        var host = document.querySelector('[data-ingredient-id="' + id + '"]');
        if (!host) return;
        adjustId.value = String(id);
        var nameVal = (host.querySelector('.inv-name') || {}).value
            || (host.querySelector('.inv-mcard-name') || {}).textContent
            || '';
        adjustSub.textContent = '#' + id + ' · ' + nameVal.trim();
        try { adjustModal.showModal(); } catch (_) { adjustModal.setAttribute('open', ''); }
        setTimeout(function () { if (adjustDelta) adjustDelta.focus(); }, 50);
    }

    if (adjustModal) {
        adjustModal.addEventListener('click', function (ev) {
            if (ev.target && ev.target.matches && ev.target.matches('[data-inv-adjust-close]')) {
                ev.preventDefault();
                closeAdjustModal();
            }
        });
    }
    if (adjustSave) {
        adjustSave.addEventListener('click', function () {
            var id = parseInt(adjustId.value || '0', 10);
            if (!id) return;
            var delta = parseFloat(adjustDelta.value || '0') || 0;
            if (!delta) { window.alert('Введите положительное или отрицательное число.'); return; }
            var reason = delta > 0 ? 'receipt' : 'waste';
            adjustSave.disabled = true;
            api({ action: 'adjust_stock', id: id, delta: delta, reason: reason }).then(function (r) {
                adjustSave.disabled = false;
                if (!r.ok || !r.data || !r.data.success) {
                    adjustMsg.hidden = false;
                    adjustMsg.className = 'recipe-save-msg recipe-save-msg-error';
                    adjustMsg.textContent = 'Не получилось: ' + ((r.data && r.data.error) || 'unknown');
                    return;
                }
                window.location.reload();
            }).catch(function () { adjustSave.disabled = false; window.alert('Сетевая ошибка'); });
        });
    }

    // ---- Phase 34.1: mobile card actions (Edit / ± Adjust / Archive / Restore) ----
    document.addEventListener('click', function (ev) {
        var editBtn = ev.target.closest && ev.target.closest('.js-edit-ing');
        if (editBtn) {
            var id = parseInt(editBtn.getAttribute('data-ing-id') || '0', 10);
            if (id) openEditModalById(id);
            return;
        }
        var adjBtn = ev.target.closest && ev.target.closest('.js-adjust-ing');
        if (adjBtn) {
            var aid = parseInt(adjBtn.getAttribute('data-ing-id') || '0', 10);
            if (aid) openAdjustModalById(aid);
            return;
        }
        var mArch = ev.target.closest && ev.target.closest('.inv-mcard .btn-inv-archive');
        if (mArch) {
            var card = mArch.closest('.inv-mcard');
            var mid = parseInt(card && card.getAttribute('data-ingredient-id') || '0', 10);
            if (!mid) return;
            if (!window.confirm('Архивировать ингредиент #' + mid + '?')) return;
            mArch.disabled = true;
            api({ action: 'archive_ingredient', id: mid }).then(function (r) {
                mArch.disabled = false;
                if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); return; }
                window.location.reload();
            }).catch(function () { mArch.disabled = false; });
            return;
        }
        var mRest = ev.target.closest && ev.target.closest('.inv-mcard .btn-inv-restore');
        if (mRest) {
            var rcard = mRest.closest('.inv-mcard');
            var rid = parseInt(rcard && rcard.getAttribute('data-ingredient-id') || '0', 10);
            if (!rid) return;
            mRest.disabled = true;
            api({ action: 'restore_ingredient', id: rid }).then(function (r) {
                mRest.disabled = false;
                if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); return; }
                window.location.reload();
            }).catch(function () { mRest.disabled = false; });
        }
    });

    // ---- Phase 34.5: mobile supplier create-panel + edit modal ----
    var supToggle  = document.getElementById('invSupNewToggle');
    var supPanel   = document.getElementById('invSupCreatePanel');
    var supCancel  = document.getElementById('invSupCreateCancel');
    var supSubmit  = document.getElementById('invSupCreateSubmit');

    if (supToggle && supPanel) {
        supToggle.addEventListener('click', function () {
            supPanel.hidden = !supPanel.hidden;
            if (!supPanel.hidden) {
                var n = document.getElementById('invSupNewName');
                if (n) n.focus();
            }
        });
    }
    if (supCancel && supPanel) {
        supCancel.addEventListener('click', function () { supPanel.hidden = true; });
    }
    if (supSubmit) {
        supSubmit.addEventListener('click', function () {
            var name = (document.getElementById('invSupNewName').value || '').trim();
            if (!name) { window.alert('Укажите название поставщика.'); return; }
            var contact = (document.getElementById('invSupNewContact').value || '').trim();
            var notes   = (document.getElementById('invSupNewNotes').value || '').trim();
            supSubmit.disabled = true;
            api({
                action: 'save_supplier',
                id: null,
                name: name,
                contact: contact || null,
                notes: notes || null,
            }).then(function (r) {
                supSubmit.disabled = false;
                if (!r.ok || !r.data || !r.data.success) {
                    window.alert('Не сохранилось: ' + ((r.data && r.data.error) || 'unknown'));
                    return;
                }
                window.location.reload();
            }).catch(function () { supSubmit.disabled = false; window.alert('Сетевая ошибка'); });
        });
    }

    // Edit-supplier modal
    var supEditModal    = document.getElementById('invSupEditModal');
    var supEditIdInput  = document.getElementById('invSupEditId');
    var supEditSubtitle = document.getElementById('invSupEditSubtitle');
    var supEditName     = document.getElementById('invSupEditName');
    var supEditContact  = document.getElementById('invSupEditContact');
    var supEditNotes    = document.getElementById('invSupEditNotes');
    var supEditSave     = document.getElementById('invSupEditSave');
    var supEditMsg      = document.getElementById('invSupEditMsg');

    function closeSupEditModal() {
        if (!supEditModal) return;
        try { supEditModal.close(); } catch (_) { supEditModal.removeAttribute('open'); }
        if (supEditMsg) { supEditMsg.hidden = true; supEditMsg.textContent = ''; }
    }

    function openSupEditModalById(id) {
        if (!supEditModal) return;
        // Pull current values from the mobile card's data-attributes (they're
        // pre-populated server-side; data-attrs avoid an extra API round-trip).
        var card = document.querySelector('.inv-mcard--supplier[data-supplier-id="' + id + '"]');
        var nameVal = '', contactVal = '', notesVal = '';
        if (card) {
            nameVal    = card.getAttribute('data-sup-name')    || '';
            contactVal = card.getAttribute('data-sup-contact') || '';
            notesVal   = card.getAttribute('data-sup-notes')   || '';
        } else {
            // Fallback: read from the desktop table row (still in DOM, just hidden via CSS).
            var tr = document.querySelector('#invSuppliersTable tbody tr[data-supplier-id="' + id + '"]');
            if (!tr) { window.alert('Поставщик не найден.'); return; }
            nameVal    = (tr.querySelector('.sup-name')    || {}).value || '';
            contactVal = (tr.querySelector('.sup-contact') || {}).value || '';
            notesVal   = (tr.querySelector('.sup-notes')   || {}).value || '';
        }
        supEditIdInput.value = String(id);
        supEditName.value    = nameVal;
        supEditContact.value = contactVal;
        supEditNotes.value   = notesVal;
        supEditSubtitle.textContent = '#' + id + ' · ' + nameVal;
        try { supEditModal.showModal(); } catch (_) { supEditModal.setAttribute('open', ''); }
    }

    if (supEditModal) {
        supEditModal.addEventListener('click', function (ev) {
            if (ev.target && ev.target.matches && ev.target.matches('[data-inv-sup-modal-close]')) {
                ev.preventDefault();
                closeSupEditModal();
            }
        });
    }
    if (supEditSave) {
        supEditSave.addEventListener('click', function () {
            var id = parseInt(supEditIdInput.value || '0', 10);
            if (!id) return;
            var name = (supEditName.value || '').trim();
            if (!name) { window.alert('Укажите название поставщика.'); return; }
            var contact = (supEditContact.value || '').trim();
            var notes   = (supEditNotes.value   || '').trim();
            supEditSave.disabled = true;
            api({
                action: 'save_supplier',
                id: id,
                name: name,
                contact: contact || null,
                notes: notes || null,
            }).then(function (r) {
                supEditSave.disabled = false;
                if (!r.ok || !r.data || !r.data.success) {
                    supEditMsg.hidden = false;
                    supEditMsg.className = 'recipe-save-msg recipe-save-msg-error';
                    supEditMsg.textContent = 'Не сохранилось: ' + ((r.data && r.data.error) || 'unknown');
                    return;
                }
                window.location.reload();
            }).catch(function () { supEditSave.disabled = false; window.alert('Сетевая ошибка'); });
        });
    }

    // Bind .js-edit-sup click → open supplier-edit modal.
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('.js-edit-sup');
        if (!btn) return;
        var id = parseInt(btn.getAttribute('data-sup-id') || '0', 10);
        if (id) openSupEditModalById(id);
    });
})();
