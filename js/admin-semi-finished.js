(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-semi-finished.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; }, function () {
                return { ok: r.ok, data: { success: false, error: 'invalid_json' } };
            });
        });
    }

    function openDialog(dlg) {
        if (!dlg) return;
        if (typeof dlg.showModal === 'function') {
            try { dlg.showModal(); } catch (_) { dlg.setAttribute('open', 'open'); }
        } else { dlg.setAttribute('open', 'open'); }
    }
    function closeDialog(dlg) {
        if (!dlg) return;
        if (typeof dlg.close === 'function') {
            try { dlg.close(); } catch (_) { dlg.removeAttribute('open'); }
        } else { dlg.removeAttribute('open'); }
    }
    function showMsg(id, text, isError) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
        el.classList.toggle('recipe-save-msg-success', !isError);
        el.classList.toggle('recipe-save-msg-error', !!isError);
    }
    function escHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Global close handler
    document.addEventListener('click', function (event) {
        var t = event.target;
        if (t && t.matches && t.matches('[data-sf-modal-close]')) {
            var dlg = t.closest('dialog');
            if (dlg) { event.preventDefault(); closeDialog(dlg); }
        }
    });

    // Embedded ingredient list
    var ingredients = [];
    try {
        var data = document.getElementById('sfIngredientsData');
        if (data) ingredients = JSON.parse(data.textContent || '[]');
    } catch (_) { ingredients = []; }

    function ingredientOptionsHtml(selectedId) {
        var html = '<option value="">— выберите —</option>';
        ingredients.forEach(function (i) {
            var sel = String(selectedId || '') === String(i.id) ? ' selected' : '';
            html += '<option value="' + i.id + '" data-unit="' + escHtml(i.unit) + '"' + sel + '>' + escHtml(i.name) + ' (' + escHtml(i.unit) + ')</option>';
        });
        return html;
    }

    function addRecipeRow(presetChildId, presetQty) {
        var list = document.getElementById('sfRecipeItems');
        if (!list) return;
        var row = document.createElement('div');
        row.className = 'sf-recipe-row inv-edit-row';
        row.innerHTML =
            '<label class="inv-edit-field">' +
                '<span class="inv-edit-label">Ингредиент</span>' +
                '<select class="js-sf-child">' + ingredientOptionsHtml(presetChildId) + '</select>' +
            '</label>' +
            '<label class="inv-edit-field">' +
                '<span class="inv-edit-label">Кол-во</span>' +
                '<input type="number" class="js-sf-qty" step="0.001" min="0.001" value="' + (presetQty || 1) + '">' +
            '</label>' +
            '<button type="button" class="admin-checkout-btn cancel js-sf-row-remove" title="Удалить">×</button>';
        list.appendChild(row);
    }

    // Recipe edit
    var recipeModal = document.getElementById('sfRecipeModal');
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-sf-edit-recipe') : null;
        if (!btn || !recipeModal) return;
        event.preventDefault();
        var sfId = parseInt(btn.getAttribute('data-sf-id') || '0', 10);
        if (!sfId) return;
        (document.getElementById('sfRecipeParentId') || {}).value = String(sfId);
        var subtitle = document.getElementById('sfRecipeSubtitle');
        var card = btn.closest('.sf-card');
        var title = card ? card.querySelector('.sf-card-title') : null;
        if (subtitle && title) subtitle.textContent = title.textContent;
        var list = document.getElementById('sfRecipeItems');
        if (list) list.innerHTML = '';
        showMsg('sfRecipeMsg', '');
        // Load existing recipe via API
        api({ action: 'get_recipe', parent_ingredient_id: sfId }).then(function (res) {
            if (res.ok && res.data && res.data.success && Array.isArray(res.data.recipe)) {
                res.data.recipe.forEach(function (r) {
                    addRecipeRow(r.child_ingredient_id, r.quantity);
                });
            }
            if (list && list.children.length === 0) addRecipeRow(null, 1);
            openDialog(recipeModal);
        });
    });

    if (recipeModal) {
        recipeModal.addEventListener('click', function (event) {
            var addBtn = event.target.closest('.js-sf-add-item');
            if (addBtn) { event.preventDefault(); addRecipeRow(null, 1); return; }
            var remBtn = event.target.closest('.js-sf-row-remove');
            if (remBtn) {
                event.preventDefault();
                var row = remBtn.closest('.sf-recipe-row');
                if (row) row.remove();
            }
        });
    }

    var recipeSubmit = document.getElementById('sfRecipeSubmit');
    if (recipeSubmit) {
        recipeSubmit.addEventListener('click', function () {
            var parentId = parseInt((document.getElementById('sfRecipeParentId') || {}).value || '0', 10);
            if (!parentId) return;
            var rows = document.querySelectorAll('#sfRecipeItems .sf-recipe-row');
            var items = [];
            rows.forEach(function (row) {
                var sel = row.querySelector('.js-sf-child');
                var qty = row.querySelector('.js-sf-qty');
                var cid = sel ? parseInt(sel.value || '0', 10) : 0;
                var q = qty ? parseFloat(qty.value || '0') : 0;
                if (cid > 0 && q > 0) items.push({ child_ingredient_id: cid, quantity: q });
            });
            recipeSubmit.disabled = true;
            api({ action: 'save_recipe', parent_ingredient_id: parentId, items: items }).then(function (res) {
                recipeSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    showMsg('sfRecipeMsg', 'Сохранено. Обновляем…', false);
                    setTimeout(function () { window.location.reload(); }, 350);
                } else {
                    showMsg('sfRecipeMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            });
        });
    }

    // Cook batch
    var cookModal = document.getElementById('sfCookModal');
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-sf-cook') : null;
        if (!btn || !cookModal) return;
        event.preventDefault();
        if (btn.disabled) return;
        var sfId = parseInt(btn.getAttribute('data-sf-id') || '0', 10);
        if (!sfId) return;
        (document.getElementById('sfCookParentId') || {}).value = String(sfId);
        var subtitle = document.getElementById('sfCookSubtitle');
        var card = btn.closest('.sf-card');
        var title = card ? card.querySelector('.sf-card-title') : null;
        if (subtitle && title) subtitle.textContent = title.textContent;
        var batchInput = document.getElementById('sfCookBatchSize');
        if (batchInput) batchInput.value = '1';
        var notes = document.getElementById('sfCookNotes');
        if (notes) notes.value = '';
        showMsg('sfCookMsg', '');
        openDialog(cookModal);
    });

    var cookSubmit = document.getElementById('sfCookSubmit');
    if (cookSubmit) {
        cookSubmit.addEventListener('click', function () {
            var parentId = parseInt((document.getElementById('sfCookParentId') || {}).value || '0', 10);
            var batchSize = parseFloat((document.getElementById('sfCookBatchSize') || {}).value || '0');
            var notes = ((document.getElementById('sfCookNotes') || {}).value || '').trim();
            if (!parentId || !batchSize || batchSize <= 0) {
                showMsg('sfCookMsg', 'Укажите положительный размер партии.', true);
                return;
            }
            cookSubmit.disabled = true;
            api({ action: 'cook_batch', parent_ingredient_id: parentId, batch_size: batchSize, notes: notes })
                .then(function (res) {
                    cookSubmit.disabled = false;
                    if (res.ok && res.data && res.data.success) {
                        showMsg('sfCookMsg', 'Партия приготовлена. Обновляем…', false);
                        setTimeout(function () { window.location.reload(); }, 350);
                    } else if (res.data && res.data.error === 'insufficient_stock') {
                        showMsg('sfCookMsg', 'Недостаточно ингредиента: ' + (res.data.ingredient || 'unknown'), true);
                    } else {
                        showMsg('sfCookMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                    }
                });
        });
    }
})();
