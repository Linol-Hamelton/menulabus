// Reusable focus-trap helper for modal dialogs (Polish 12.6, 2026-04-27).
//
// Exposes window.FocusTrap with three operations:
//   FocusTrap.activate(modalEl, { onEscape })
//     - Stores the currently-focused element so it can be restored later.
//     - Moves focus to the first focusable inside the modal.
//     - Listens for Tab/Shift+Tab and wraps focus around the first/last
//       focusable so keyboard users cannot tab outside the open modal.
//     - If onEscape is supplied, calls it on Escape (consumer typically
//       hides the modal, then calls deactivate).
//   FocusTrap.deactivate(modalEl)
//     - Removes the listener and restores focus to whatever element had
//       it before activate() was called.
//   FocusTrap.isActive(modalEl)
//     - Returns true if the trap is currently engaged on this modal.
//
// The helper is intentionally tiny — no third-party dep, ~80 LoC after
// minification. Plays nicely with strict CSP (no inline JS, no eval).
//
// Selector for "focusable" follows the de-facto standard: anchors with
// href, buttons, input/select/textarea (not disabled), tabindex>=0,
// audio/video with controls, contenteditable. Elements with `disabled`,
// `inert`, or `hidden` are skipped.
//
// Per-modal state is kept in a WeakMap keyed by the modal element, so
// repeated activate/deactivate cycles don't leak handlers and multiple
// modals can coexist (the topmost is the active one).

(function () {
    'use strict';

    var FOCUSABLE = [
        'a[href]',
        'area[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        'audio[controls]',
        'video[controls]',
        '[contenteditable]:not([contenteditable="false"])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    var state = new WeakMap();

    function visibleFocusables(root) {
        var nodes = root.querySelectorAll(FOCUSABLE);
        return Array.prototype.filter.call(nodes, function (n) {
            if (n.hasAttribute('inert')) return false;
            if (n.hasAttribute('hidden')) return false;
            // Skip elements rendered inside hidden ancestors. offsetParent is
            // null for display:none subtrees, but is also null for fixed-pos
            // elements — handle that by checking computed visibility.
            if (n.offsetParent === null) {
                var style = window.getComputedStyle(n);
                if (style.display === 'none' || style.visibility === 'hidden') {
                    return false;
                }
            }
            return true;
        });
    }

    function activate(modal, opts) {
        if (!modal || state.has(modal)) return;
        opts = opts || {};

        var previouslyFocused = document.activeElement;

        function onKeydown(e) {
            if (e.key === 'Escape' && typeof opts.onEscape === 'function') {
                opts.onEscape(e);
                return;
            }
            if (e.key !== 'Tab') return;
            var items = visibleFocusables(modal);
            if (items.length === 0) {
                e.preventDefault();
                return;
            }
            var first = items[0];
            var last  = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }

        modal.addEventListener('keydown', onKeydown);
        state.set(modal, { previouslyFocused: previouslyFocused, onKeydown: onKeydown });

        // Move focus into the modal after it has rendered.
        var items = visibleFocusables(modal);
        if (items.length > 0) {
            items[0].focus();
        } else {
            // Fallback: make the modal itself focusable so screen readers
            // announce its content. Restored on deactivate.
            if (!modal.hasAttribute('tabindex')) {
                modal.setAttribute('tabindex', '-1');
                state.get(modal).addedTabindex = true;
            }
            modal.focus();
        }
    }

    function deactivate(modal) {
        var s = state.get(modal);
        if (!s) return;
        modal.removeEventListener('keydown', s.onKeydown);
        if (s.addedTabindex) {
            modal.removeAttribute('tabindex');
        }
        state.delete(modal);
        if (s.previouslyFocused && typeof s.previouslyFocused.focus === 'function') {
            try { s.previouslyFocused.focus(); } catch (_) { /* element gone */ }
        }
    }

    function isActive(modal) {
        return state.has(modal);
    }

    window.FocusTrap = { activate: activate, deactivate: deactivate, isActive: isActive };
})();
