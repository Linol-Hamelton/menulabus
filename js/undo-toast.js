(function () {
    'use strict';

    // Shared "Undo within N seconds" toast used by soft-delete flows
    // (admin-modifiers delete, bulk archive, future 5.5 sinks).
    //
    // Usage:
    //   CleanmenuUndoToast.show({
    //       text: 'Группа модификаторов удалена',
    //       undoLabel: 'Отменить',
    //       timeoutMs: 5000,
    //       onUndo: function () { ... },
    //       onExpire: function () { ... },
    //   });
    //
    // Only one toast is visible at a time — opening a second dismisses
    // the first without firing its `onExpire` (that slot was consumed
    // by a later action the operator initiated).

    var active = null;

    function show(opts) {
        opts = opts || {};
        dismiss(true);

        var toast = document.createElement('div');
        toast.className = 'undo-toast';
        toast.setAttribute('role', 'status');

        var msg = document.createElement('span');
        msg.className = 'undo-toast-text';
        msg.textContent = opts.text || 'Готово';
        toast.appendChild(msg);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'undo-toast-btn';
        btn.textContent = opts.undoLabel || 'Отменить';
        toast.appendChild(btn);

        var progress = document.createElement('div');
        progress.className = 'undo-toast-progress';
        toast.appendChild(progress);

        document.body.appendChild(toast);
        var timeoutMs = typeof opts.timeoutMs === 'number' ? opts.timeoutMs : 5000;
        progress.style.animationDuration = timeoutMs + 'ms';

        active = {
            el: toast,
            onExpire: typeof opts.onExpire === 'function' ? opts.onExpire : null,
            expireTimer: setTimeout(function () {
                if (active && active.el === toast) {
                    var cb = active.onExpire;
                    active = null;
                    removeEl(toast);
                    if (cb) { try { cb(); } catch (e) { /* noop */ } }
                }
            }, timeoutMs),
        };

        btn.addEventListener('click', function () {
            if (!active || active.el !== toast) return;
            clearTimeout(active.expireTimer);
            active = null;
            removeEl(toast);
            if (typeof opts.onUndo === 'function') {
                try { opts.onUndo(); } catch (e) { /* noop */ }
            }
        });
    }

    function dismiss(silent) {
        if (!active) return;
        clearTimeout(active.expireTimer);
        var toast = active.el;
        var cb = silent ? null : active.onExpire;
        active = null;
        removeEl(toast);
        if (cb) { try { cb(); } catch (e) { /* noop */ } }
    }

    function removeEl(el) {
        if (el && el.parentNode) el.parentNode.removeChild(el);
    }

    window.CleanmenuUndoToast = { show: show, dismiss: dismiss };
})();
