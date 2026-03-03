/* cart-tips.js — Tips selection logic for cart page */
(function () {
    'use strict';

    function getCartTotal() {
        if (window.cart && typeof window.cart.getTotalSum === 'function') {
            return window.cart.getTotalSum();
        }
        // Fallback: read from DOM
        var el = document.getElementById('cart-total');
        if (el) {
            var m = el.textContent.match(/[\d.,]+/);
            if (m) return parseFloat(m[0].replace(',', '.')) || 0;
        }
        return 0;
    }

    function updateTipsDisplay(tipAmount) {
        var display = document.getElementById('tipsTotalDisplay');
        if (!display) return;
        if (tipAmount > 0) {
            display.textContent = 'Чаевые: ' + tipAmount + ' ₽';
        } else {
            display.textContent = '';
        }
    }

    function setTip(amount) {
        var hidden = document.getElementById('selectedTip');
        if (hidden) hidden.value = amount;
        updateTipsDisplay(amount);
    }

    function init() {
        var optionsRow = document.querySelector('.tips-options');
        if (!optionsRow) return;

        var customWrap  = document.getElementById('tipsCustomWrap');
        var customInput = document.getElementById('tipsCustomInput');

        optionsRow.addEventListener('click', function (e) {
            var btn = e.target.closest('.tips-option');
            if (!btn) return;

            // Toggle active state
            optionsRow.querySelectorAll('.tips-option').forEach(function (b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');

            var pct = btn.getAttribute('data-pct');

            if (pct === 'custom') {
                if (customWrap) customWrap.classList.add('visible');
                if (customInput) {
                    customInput.focus();
                    var val = parseFloat(customInput.value) || 0;
                    setTip(val);
                }
            } else {
                if (customWrap) customWrap.classList.remove('visible');
                var pctNum = parseFloat(pct) || 0;
                if (pctNum === 0) {
                    setTip(0);
                } else {
                    var total = getCartTotal();
                    var tip   = Math.round(total * pctNum / 100);
                    setTip(tip);
                }
            }
        });

        if (customInput) {
            customInput.addEventListener('input', function () {
                var val = Math.max(0, Math.min(9999, parseFloat(customInput.value) || 0));
                setTip(val);
            });
        }

        // Recalculate percentage tips when cart changes
        document.addEventListener('cartUpdated', function () {
            var activeBtn = optionsRow.querySelector('.tips-option.active');
            if (!activeBtn) return;
            var pct = activeBtn.getAttribute('data-pct');
            if (!pct || pct === '0' || pct === 'custom') return;
            var pctNum = parseFloat(pct) || 0;
            var total  = getCartTotal();
            var tip    = Math.round(total * pctNum / 100);
            setTip(tip);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
