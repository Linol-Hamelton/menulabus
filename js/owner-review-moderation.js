(function () {
    'use strict';

    var pane = document.getElementById('reviews');
    if (!pane) return;

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token'))
        || (document.querySelector('meta[name="csrf-token"]') || {}).content
        || '';

    function api(body) {
        return fetch('/api/moderate-review.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(Object.assign({ csrf_token: csrfToken }, body)),
        }).then(function (r) { return r.json(); });
    }

    pane.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('.owner-review-reply-save') : null;
        if (!btn) return;
        var tr = event.target.closest('[data-review-id]');
        if (!tr) return;
        var id = parseInt(tr.getAttribute('data-review-id') || '0', 10);
        if (!id) return;
        var text = (tr.querySelector('.owner-review-reply-text') || {}).value || '';
        btn.disabled = true;
        api({ action: 'set_reply', review_id: id, reply_text: text }).then(function (d) {
            btn.disabled = false;
            if (!d || !d.success) { window.alert('Не сохранилось'); return; }
            btn.textContent = 'Сохранено';
            setTimeout(function () { btn.textContent = 'Сохранить ответ'; }, 1500);
        }).catch(function () { btn.disabled = false; });
    });

    pane.addEventListener('change', function (event) {
        var cb = event.target;
        if (!cb || !cb.classList || !cb.classList.contains('owner-review-publish-toggle')) return;
        var tr = cb.closest('[data-review-id]');
        if (!tr) return;
        var id = parseInt(tr.getAttribute('data-review-id') || '0', 10);
        if (!id) return;
        cb.disabled = true;
        api({ action: 'toggle_publish', review_id: id, published: cb.checked }).then(function (d) {
            cb.disabled = false;
            if (!d || !d.success) {
                cb.checked = !cb.checked;
                window.alert('Не получилось');
            }
        }).catch(function () { cb.disabled = false; cb.checked = !cb.checked; });
    });
})();
