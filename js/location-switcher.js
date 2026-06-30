/* Phase L103.2 — auto-submit location switcher form on <select> change.
 * Falls back to the <noscript> submit button when JS is disabled.
 * Scoped to #locationSwitcherForm only.
 */
(function () {
    'use strict';
    var form = document.getElementById('locationSwitcherForm');
    if (!form) return;
    var select = document.getElementById('locationSwitcherSelect');
    if (!select) return;
    var lastValue = select.value;
    select.addEventListener('change', function () {
        if (select.value === lastValue) return;
        lastValue = select.value;
        try {
            form.submit();
        } catch (_) {
            /* ignore — noscript button is the fallback */
        }
    });
})();
