(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-stocktake.php', {
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

    document.addEventListener('click', function (event) {
        var t = event.target;
        if (t && t.matches && t.matches('[data-stocktake-modal-close]')) {
            var dlg = t.closest('dialog');
            if (dlg) { event.preventDefault(); closeDialog(dlg); }
        }
    });

    // Start session
    var startBtn = document.querySelector('.js-stocktake-start');
    var startModal = document.getElementById('stocktakeStartModal');
    if (startBtn && startModal) {
        startBtn.addEventListener('click', function () {
            showMsg('stocktakeStartMsg', '');
            openDialog(startModal);
        });
    }
    var startSubmit = document.getElementById('stocktakeStartSubmit');
    if (startSubmit) {
        startSubmit.addEventListener('click', function () {
            var name = ((document.getElementById('stocktakeStartName') || {}).value || '').trim();
            var notes = ((document.getElementById('stocktakeStartNotes') || {}).value || '').trim();
            startSubmit.disabled = true;
            api({ action: 'start', name: name, notes: notes }).then(function (res) {
                startSubmit.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    showMsg('stocktakeStartMsg', 'Сессия создана. Обновляем…', false);
                    setTimeout(function () { window.location.reload(); }, 350);
                } else {
                    showMsg('stocktakeStartMsg', 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            });
        });
    }

    // Inline count input — auto-save on blur or Enter, update variance display
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input || !input.matches || !input.matches('.js-stocktake-counted')) return;
        var row = input.closest('tr[data-item-id]');
        if (!row) return;
        var sessionId = parseInt(row.getAttribute('data-session-id') || '0', 10);
        var ingredientId = parseInt(row.getAttribute('data-ingredient-id') || '0', 10);
        var expected = parseFloat(row.getAttribute('data-expected') || '0');
        var counted = parseFloat(input.value || '');
        if (!sessionId || !ingredientId || !isFinite(counted) || counted < 0) return;
        input.disabled = true;
        api({ action: 'count', session_id: sessionId, ingredient_id: ingredientId, counted_qty: counted }).then(function (res) {
            input.disabled = false;
            if (res.ok && res.data && res.data.success) {
                var variance = counted - expected;
                var vCell = row.querySelector('.js-stocktake-variance');
                if (vCell) {
                    vCell.textContent = (variance > 0 ? '+' : '') + variance.toFixed(3).replace(/\.?0+$/, '');
                    vCell.classList.remove('st-variance-zero', 'st-variance-pos', 'st-variance-neg');
                    if (Math.abs(variance) < 0.0005) vCell.classList.add('st-variance-zero');
                    else if (variance > 0) vCell.classList.add('st-variance-pos');
                    else vCell.classList.add('st-variance-neg');
                }
                input.classList.add('st-saved-flash');
                setTimeout(function () { input.classList.remove('st-saved-flash'); }, 600);
            } else {
                input.classList.add('st-error-flash');
                setTimeout(function () { input.classList.remove('st-error-flash'); }, 1500);
            }
        });
    });

    // Close session
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-stocktake-close') : null;
        if (!btn) return;
        event.preventDefault();
        var sid = parseInt(btn.getAttribute('data-session-id') || '0', 10);
        if (!sid) return;
        if (!window.confirm('Закрыть сессию и применить расхождения к остаткам?')) return;
        btn.disabled = true;
        api({ action: 'close', session_id: sid }).then(function (res) {
            if (res.ok && res.data && res.data.success) {
                alert('Применено: ' + (res.data.applied || 0) + ' | Пропущено: ' + (res.data.skipped || 0));
                window.location.reload();
            } else {
                alert('Ошибка: ' + ((res.data && res.data.error) || 'unknown'));
                btn.disabled = false;
            }
        });
    });

    // Cancel session
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.js-stocktake-cancel') : null;
        if (!btn) return;
        event.preventDefault();
        var sid = parseInt(btn.getAttribute('data-session-id') || '0', 10);
        if (!sid) return;
        if (!window.confirm('Отменить сессию? Все введённые counted_qty будут потеряны, остатки НЕ изменятся.')) return;
        btn.disabled = true;
        api({ action: 'cancel', session_id: sid }).then(function (res) {
            if (res.ok && res.data && res.data.success) {
                window.location.reload();
            } else {
                alert('Ошибка: ' + ((res.data && res.data.error) || 'unknown'));
                btn.disabled = false;
            }
        });
    });
})();
