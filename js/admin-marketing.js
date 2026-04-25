(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(body) {
        return fetch('/api/save-campaign.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(Object.assign({ csrf_token: csrfToken }, body)),
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }

    function setMsg(text, kind) {
        var el = document.getElementById('mkMsg');
        if (!el) return;
        el.hidden = false;
        el.textContent = text;
        el.className = 'mk-msg mk-msg-' + (kind || 'info');
    }

    function buildSegment() {
        var picked = document.querySelector('input[name="mkSeg"]:checked');
        var type = picked ? picked.value : 'all';
        if (type === 'min_orders') {
            return { type: 'min_orders', threshold: parseInt((document.getElementById('mkSegThreshold') || {}).value || '3', 10) };
        }
        if (type === 'loyalty_tier') {
            var tid = parseInt((document.getElementById('mkSegTier') || {}).value || '0', 10);
            return { type: 'loyalty_tier', tier_id: tid };
        }
        return { type: type };
    }

    var saveBtn = document.getElementById('mkSaveBtn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var name = ((document.getElementById('mkName') || {}).value || '').trim();
            var ch   = ((document.getElementById('mkChannel') || {}).value || 'email');
            var subj = ((document.getElementById('mkSubject') || {}).value || '').trim();
            var bt   = ((document.getElementById('mkBodyText') || {}).value || '').trim();
            var bh   = ((document.getElementById('mkBodyHtml') || {}).value || '').trim();
            if (!name || !bt) { window.alert('Заполните название и текст.'); return; }
            saveBtn.disabled = true;
            api({
                action: 'save', name: name, channel: ch,
                subject: subj || null,
                body_text: bt, body_html: bh || null,
                segment: buildSegment(),
            }).then(function (r) {
                saveBtn.disabled = false;
                if (!r.ok || !r.data || !r.data.success) { setMsg('Не сохранилось.', 'error'); return; }
                setMsg('Сохранено как черновик. Поставьте в очередь в таблице ниже.', 'success');
                setTimeout(function () { window.location.reload(); }, 1000);
            }).catch(function () { saveBtn.disabled = false; setMsg('Сетевая ошибка', 'error'); });
        });
    }

    var table = document.getElementById('mkTable');
    if (table) {
        table.addEventListener('click', function (event) {
            var queue = event.target.closest('.btn-mk-queue');
            var cancel = event.target.closest('.btn-mk-cancel');
            if (!queue && !cancel) return;
            var tr = event.target.closest('tr');
            var id = parseInt(tr.getAttribute('data-campaign-id') || '0', 10);
            if (!id) return;
            var act = queue ? 'queue' : 'cancel';
            var msg = queue ? 'Поставить кампанию #' + id + ' в очередь?' : 'Отменить кампанию #' + id + '?';
            if (!window.confirm(msg)) return;
            (queue || cancel).disabled = true;
            api({ action: act, id: id }).then(function (r) {
                if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); (queue || cancel).disabled = false; return; }
                window.location.reload();
            });
        });
    }
})();
