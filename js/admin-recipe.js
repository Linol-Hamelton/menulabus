(function () {
    'use strict';

    var section = document.getElementById('recipeSection');
    if (!section) return;

    var menuItemId = parseInt(section.getAttribute('data-item-id') || '0', 10);
    if (!menuItemId) return;

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token'))
        || (document.querySelector('meta[name="csrf-token"]') || {}).content
        || '';

    var rowsEl  = document.getElementById('recipeRows');
    var addSel  = document.getElementById('recipeAddIngredient');
    var addQty  = document.getElementById('recipeAddQty');
    var addBtn  = document.getElementById('recipeAddBtn');
    var saveBtn = document.getElementById('recipeSaveBtn');
    var msgEl   = document.getElementById('recipeSaveMsg');

    var rows = []; // {ingredient_id, ingredient_name, unit, quantity}

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

    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function render() {
        if (!rowsEl) return;
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
        api({ action: 'get_recipe', menu_item_id: menuItemId }).then(function (r) {
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

    if (rowsEl) {
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

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var iid = parseInt(addSel.value || '0', 10);
            var qty = parseFloat(addQty.value || '0') || 0;
            if (!iid || qty <= 0) {
                window.alert('Выберите ингредиент и укажите положительное количество.');
                return;
            }
            // Replace if already in the list, so toggling stays non-destructive.
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

    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            saveBtn.disabled = true;
            var payload = rows.map(function (r) { return { ingredient_id: r.ingredient_id, quantity: r.quantity }; });
            api({ action: 'set_recipe', menu_item_id: menuItemId, rows: payload }).then(function (r) {
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

    load();
})();
