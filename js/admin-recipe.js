(function () {
    'use strict';

    function getCsrf() {
        return (document.body && document.body.getAttribute('data-csrf-token'))
            || (document.querySelector('meta[name="csrf-token"]') || {}).content
            || '';
    }

    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function api(payload, csrf) {
        var body = Object.assign({ csrf_token: csrf }, payload || {});
        return fetch('/api/save-inventory.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }

    function init(rootEl, explicitItemId) {
        // Phase 16: now supports two call modes:
        //   1) legacy auto-init via #recipeSection wrapper (removed from
        //      admin/menu.php; only seeds remain in case any other page
        //      uses it).
        //   2) explicit init from modal: window.AdminRecipe.init(root, id).

        var menuItemId = 0;

        if (rootEl && explicitItemId) {
            menuItemId = parseInt(explicitItemId, 10) || 0;
        } else {
            var legacy = document.getElementById('recipeSection');
            if (!legacy) return;
            menuItemId = parseInt(legacy.getAttribute('data-item-id') || '0', 10);
        }

        if (!menuItemId) return;

        var csrfToken = getCsrf();
        var rowsEl  = document.getElementById('recipeRows');
        var addSel  = document.getElementById('recipeAddIngredient');
        var addQty  = document.getElementById('recipeAddQty');
        var addBtn  = document.getElementById('recipeAddBtn');
        var saveBtn = document.getElementById('recipeSaveBtn');
        var msgEl   = document.getElementById('recipeSaveMsg');

        if (!rowsEl) return;

        var rows = [];

        function render() {
            if (rows.length === 0) {
                rowsEl.innerHTML = '<div class="recipe-empty">Рецепт пуст — заказы этого блюда не будут списывать со склада.</div>';
                return;
            }
            var html = '<ul class="recipe-list">';
            rows.forEach(function (r, idx) {
                html += '<li class="recipe-row" data-recipe-idx="' + idx + '">'
                     +   '<span class="recipe-ing">' + escHtml(r.ingredient_name) + '</span>'
                     +   '<input type="number" step="0.001" min="0" class="recipe-qty" value="' + Number(r.quantity) + '">'
                     +   '<span class="recipe-unit">' + escHtml(r.unit) + '</span>'
                     +   '<button type="button" class="admin-checkout-btn cancel recipe-del">×</button>'
                     + '</li>';
            });
            html += '</ul>';
            rowsEl.innerHTML = html;
        }

        function setMsg(text, kind) {
            if (!msgEl) return;
            msgEl.hidden = false;
            msgEl.textContent = text;
            msgEl.className = 'recipe-save-msg recipe-save-msg-' + (kind || 'info');
        }

        function load() {
            api({ action: 'get_recipe', menu_item_id: menuItemId }, csrfToken).then(function (r) {
                if (!r.ok || !r.data || !r.data.success) {
                    rowsEl.innerHTML = '<div class="recipe-empty">Не удалось загрузить рецепт.</div>';
                    return;
                }
                rows = (r.data.recipe || []).map(function (row) {
                    return {
                        ingredient_id: parseInt(row.ingredient_id, 10),
                        ingredient_name: row.ingredient_name,
                        unit: row.unit,
                        quantity: parseFloat(row.quantity),
                    };
                });
                render();
            });
        }

        // Idempotent listener bind (in case re-init under a new modal session)
        if (!rowsEl.dataset.bound) {
            rowsEl.dataset.bound = '1';
            rowsEl.addEventListener('input', function (event) {
                var q = event.target && event.target.classList && event.target.classList.contains('recipe-qty')
                    ? event.target : null;
                if (!q) return;
                var li = q.closest('.recipe-row');
                if (!li) return;
                var idx = parseInt(li.getAttribute('data-recipe-idx') || '-1', 10);
                if (idx < 0 || idx >= rows.length) return;
                rows[idx].quantity = parseFloat(q.value) || 0;
            });
            rowsEl.addEventListener('click', function (event) {
                var del = event.target && event.target.closest ? event.target.closest('.recipe-del') : null;
                if (!del) return;
                var li = del.closest('.recipe-row');
                var idx = parseInt(li.getAttribute('data-recipe-idx') || '-1', 10);
                if (idx >= 0) {
                    rows.splice(idx, 1);
                    render();
                }
            });
        }

        if (addBtn && !addBtn.dataset.bound) {
            addBtn.dataset.bound = '1';
            addBtn.addEventListener('click', function () {
                var iid = parseInt(addSel.value || '0', 10);
                var qty = parseFloat(addQty.value || '0') || 0;
                if (!iid || qty <= 0) {
                    window.alert('Выберите ингредиент и укажите положительное количество.');
                    return;
                }
                var existingIdx = rows.findIndex(function (r) { return r.ingredient_id === iid; });
                var opt = addSel.options[addSel.selectedIndex];
                var label = opt ? opt.textContent.replace(/\s*\([^)]*\)\s*$/, '') : '';
                var unit = opt ? (opt.getAttribute('data-unit') || '') : '';
                if (existingIdx >= 0) {
                    rows[existingIdx].quantity = qty;
                } else {
                    rows.push({ ingredient_id: iid, ingredient_name: label, unit: unit, quantity: qty });
                }
                addSel.value = '';
                addQty.value = '';
                render();
            });
        }

        if (saveBtn && !saveBtn.dataset.bound) {
            saveBtn.dataset.bound = '1';
            saveBtn.addEventListener('click', function () {
                saveBtn.disabled = true;
                var payload = rows.map(function (r) { return { ingredient_id: r.ingredient_id, quantity: r.quantity }; });
                api({ action: 'set_recipe', menu_item_id: menuItemId, rows: payload }, csrfToken).then(function (r) {
                    saveBtn.disabled = false;
                    if (!r.ok || !r.data || !r.data.success) {
                        setMsg('Не сохранилось.', 'error');
                        return;
                    }
                    setMsg('Рецепт сохранён.', 'success');
                }).catch(function () {
                    saveBtn.disabled = false;
                    setMsg('Сетевая ошибка.', 'error');
                });
            });
        }

        // Phase 33: CSV-import modal trigger. Bound once per page-life; reuses
        // the load() closure to refresh the grid after a successful import.
        var importBtn = document.getElementById('recipeImportBtn');
        if (importBtn && !importBtn.dataset.bound) {
            importBtn.dataset.bound = '1';
            importBtn.addEventListener('click', function () {
                openImportModal(csrfToken, load);
            });
        }

        load();
    }

    // --- Phase 33: recipe CSV-import modal handling ---
    function openImportModal(csrfToken, onSuccess) {
        var dlg = document.getElementById('recipeImportModal');
        if (!dlg) { window.alert('Модалка импорта не найдена.'); return; }
        var form     = document.getElementById('recipeImportForm');
        var fileEl   = document.getElementById('recipeImportFile');
        var autoEl   = document.getElementById('recipeImportAutoCreate');
        var summary  = document.getElementById('recipeImportSummary');
        var submit   = document.getElementById('recipeImportSubmit');

        function close() {
            summary.hidden = true;
            summary.innerHTML = '';
            if (fileEl) fileEl.value = '';
            try { dlg.close(); } catch (_) {}
        }

        // Bind once per page load.
        if (!dlg.dataset.bound) {
            dlg.dataset.bound = '1';
            dlg.addEventListener('click', function (ev) {
                if (ev.target && ev.target.matches && ev.target.matches('[data-recipe-import-close]')) {
                    ev.preventDefault();
                    close();
                }
            });
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                if (!fileEl.files || fileEl.files.length === 0) {
                    window.alert('Выберите CSV файл.');
                    return;
                }
                var mode = (form.querySelector('input[name="recipeImportMode"]:checked') || {}).value || 'merge';
                if (mode === 'replace' && !window.confirm(
                    'Режим «Заменить»: для каждого блюда из CSV будут удалены ВСЕ существующие строки рецепта и заменены на новые. Продолжить?'
                )) {
                    return;
                }

                var fd = new FormData();
                fd.append('csv_file', fileEl.files[0]);
                fd.append('mode', mode);
                fd.append('auto_create', autoEl.checked ? '1' : '0');
                fd.append('csrf_token', csrfToken);

                submit.disabled = true;
                summary.hidden = false;
                summary.innerHTML = '<p>Загрузка…</p>';

                fetch('/api/import-recipes.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
                    body: fd,
                }).then(function (r) {
                    return r.json().then(function (d) { return { ok: r.ok, data: d }; });
                }).then(function (resp) {
                    submit.disabled = false;
                    renderSummary(resp);
                    if (resp.ok && resp.data && resp.data.success && typeof onSuccess === 'function') {
                        // Re-load the parent recipe grid so newly imported rows
                        // appear without forcing a full page reload.
                        onSuccess();
                    }
                }).catch(function (err) {
                    submit.disabled = false;
                    summary.innerHTML = '<p class="recipe-save-msg-error">Сетевая ошибка: ' + escHtml(String(err && err.message || err)) + '</p>';
                });
            });
        }

        function renderSummary(resp) {
            if (!resp.ok || !resp.data) {
                summary.innerHTML = '<p class="recipe-save-msg-error">Ошибка: ' + escHtml(JSON.stringify(resp.data || {})) + '</p>';
                return;
            }
            var data = resp.data;
            if (!data.success) {
                var s = data.summary || {};
                var errs = (s.errors || []).map(function (e) {
                    return '<li>Стр. ' + (e.line || '—') + ': ' + escHtml(e.message || '') + '</li>';
                }).join('');
                summary.innerHTML = ''
                    + '<p class="recipe-save-msg-error">Не удалось импортировать: ' + escHtml(data.error || 'unknown') + '</p>'
                    + (errs ? '<ul>' + errs + '</ul>' : '');
                return;
            }
            var s = data.summary || {};
            var parts = [
                '<p class="recipe-save-msg-success"><strong>✓ Импорт завершён</strong></p>',
                '<ul>',
                '<li>Блюд затронуто: ' + (s.dishes_touched || 0) + '</li>',
                '<li>Строк добавлено: ' + (s.inserted || 0) + '</li>',
                '<li>Строк обновлено: ' + (s.updated || 0) + '</li>',
            ];
            if (s.deleted) parts.push('<li>Строк удалено (режим Replace): ' + s.deleted + '</li>');
            if (s.ingredients_created) parts.push('<li>Создано новых ингредиентов: ' + s.ingredients_created + '</li>');
            if ((s.errors || []).length) {
                parts.push('<li>Ошибок: ' + s.errors.length + '<ul>');
                s.errors.forEach(function (e) {
                    parts.push('<li>Стр. ' + (e.line || '—') + ': ' + escHtml(e.message || '') + '</li>');
                });
                parts.push('</ul></li>');
            }
            if ((s.warnings || []).length) {
                parts.push('<li>Предупреждения:<ul>');
                s.warnings.forEach(function (w) { parts.push('<li>' + escHtml(w) + '</li>'); });
                parts.push('</ul></li>');
            }
            parts.push('</ul>');
            summary.innerHTML = parts.join('');
        }

        summary.hidden = true;
        summary.innerHTML = '';
        try {
            if (typeof dlg.showModal === 'function') {
                dlg.showModal();
            } else {
                dlg.setAttribute('open', '');
            }
        } catch (_) {
            dlg.setAttribute('open', '');
        }
    }

    window.AdminRecipe = { init: init };

    // Auto-bootstrap if the legacy markup happens to be on the page.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
})();
