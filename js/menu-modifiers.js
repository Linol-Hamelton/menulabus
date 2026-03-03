/* menu-modifiers.js — Modifier selection modal for menu items */
(function () {
    'use strict';

    // ── Modal template ───────────────────────────────────────────────────────
    function buildModal() {
        var modal = document.getElementById('modifiersModal');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'modifiersModal';
        modal.className = 'delivery';
        modal.innerHTML =
            '<div class="delivery-content">' +
                '<h3 id="modModalTitle">Настройте заказ</h3>' +
                '<div id="modModalGroups"></div>' +
                '<div id="modModalPrice" class="cart-item-price"></div>' +
                '<div class="delivery-modal-buttons">' +
                    '<button id="modModalConfirm" class="checkout-btn">Добавить</button>' +
                    '<button id="modModalCancel" class="checkout-btn cancel-btn">Отмена</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        document.getElementById('modModalCancel').addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
        return modal;
    }

    function openModal(item, groups) {
        var modal = buildModal();
        document.getElementById('modModalTitle').textContent = item.name;
        renderGroups(groups, item.price);
        modal.classList.add('active');
        document.getElementById('modModalConfirm').onclick = function () {
            var result = collectSelections(groups, item.price);
            if (result === null) return; // validation failed
            closeModal();
            addToCartWithModifiers(item, result.totalPrice, result.selectedNames);
        };
    }

    function closeModal() {
        var modal = document.getElementById('modifiersModal');
        if (modal) modal.classList.remove('active');
    }

    function renderGroups(groups, basePrice) {
        var container = document.getElementById('modModalGroups');
        container.innerHTML = '';
        groups.forEach(function (g, gi) {
            var div = document.createElement('div');
            div.className = 'mod-modal-group';
            div.innerHTML = '<div class="payment-label">' + escHtml(g.name) + (g.required ? ' *' : '') + '</div>';
            var opts = document.createElement('div');
            opts.className = 'tips-options';
            g.options.forEach(function (o, oi) {
                var btn = document.createElement('button');
                btn.className = 'tips-option' + (g.type === 'radio' && oi === 0 ? ' active' : '');
                btn.dataset.groupIdx  = gi;
                btn.dataset.optionIdx = oi;
                btn.dataset.priceDelta = o.price_delta;
                btn.dataset.optName   = o.name;
                btn.textContent = o.name + (o.price_delta ? ' +' + o.price_delta + '₽' : '');
                btn.addEventListener('click', function () {
                    if (g.type === 'radio') {
                        opts.querySelectorAll('.tips-option').forEach(function (b) { b.classList.remove('active'); });
                        btn.classList.add('active');
                    } else {
                        btn.classList.toggle('active');
                    }
                    updatePrice(groups, basePrice);
                });
                opts.appendChild(btn);
            });
            div.appendChild(opts);
            container.appendChild(div);
        });
        updatePrice(groups, basePrice);
    }

    function updatePrice(groups, basePrice) {
        var extra = 0;
        groups.forEach(function (g) {
            document.querySelectorAll('.tips-option[data-group-idx]').forEach(function (btn) {
                if (btn.classList.contains('active')) {
                    extra += parseFloat(btn.dataset.priceDelta) || 0;
                }
            });
        });
        var total = basePrice + extra;
        var el = document.getElementById('modModalPrice');
        if (el) el.textContent = 'Итого: ' + total + ' ₽';
    }

    function collectSelections(groups, basePrice) {
        var extra = 0;
        var names = [];
        var valid = true;

        groups.forEach(function (g, gi) {
            var selected = document.querySelectorAll('.tips-option[data-group-idx="' + gi + '"].active');
            if (g.required && selected.length === 0) {
                alert('Выберите вариант: ' + g.name);
                valid = false;
                return;
            }
            selected.forEach(function (btn) {
                extra += parseFloat(btn.dataset.priceDelta) || 0;
                names.push(btn.dataset.optName);
            });
        });

        if (!valid) return null;
        return { totalPrice: basePrice + extra, selectedNames: names };
    }

    function addToCartWithModifiers(item, totalPrice, modifierNames) {
        if (typeof cart === 'undefined' || typeof cart.addProduct !== 'function') return;
        var displayName = modifierNames.length
            ? item.name + ' (' + modifierNames.join(', ') + ')'
            : item.name;
        cart.addProduct(
            item.id,
            displayName,
            totalPrice,
            item.image,
            item.calories || 0,
            item.protein  || 0,
            item.fat      || 0,
            item.carbs    || 0
        );
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Intercept "+" click on items with modifiers ──────────────────────────
    function attachHandlers() {
        document.addEventListener('click', function (e) {
            var buyText = e.target.closest('.buy-text');
            if (!buyText) return;
            var buy = buyText.closest('.buy');
            if (!buy) return;
            var modData = buy.dataset.modifiers;
            if (!modData) return;

            var groups = [];
            try { groups = JSON.parse(modData); } catch (_) {}
            if (!groups.length) return;

            e.stopImmediatePropagation();
            e.preventDefault();

            var item = {
                id:       buy.dataset.productId,
                name:     buy.dataset.productName,
                price:    parseFloat(buy.dataset.productPrice) || 0,
                image:    buy.dataset.productImage || '',
                calories: parseInt(buy.dataset.calories, 10) || 0,
                protein:  parseInt(buy.dataset.protein, 10)  || 0,
                fat:      parseInt(buy.dataset.fat, 10)       || 0,
                carbs:    parseInt(buy.dataset.carbs, 10)     || 0,
            };

            openModal(item, groups);
        }, true); // use capture to intercept before cart.min.js
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachHandlers);
    } else {
        attachHandlers();
    }
})();
