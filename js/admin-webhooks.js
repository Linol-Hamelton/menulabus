(function () {
    'use strict';

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(action, body) {
        var payload = Object.assign({ action: action, csrf_token: csrfToken }, body || {});
        return fetch('/api/save-webhook.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
            credentials: 'same-origin',
        }).then(function (resp) {
            return resp.json().then(function (data) { return { ok: resp.ok, data: data }; });
        });
    }

    function showMessage(el, text, kind) {
        if (!el) return;
        el.hidden = false;
        el.textContent = text;
        el.className = 'webhook-msg webhook-msg-' + (kind || 'info');
    }

    var createForm = document.getElementById('webhookCreateForm');
    var createMsg  = document.getElementById('webhookCreateMsg');
    if (createForm) {
        createForm.addEventListener('submit', function (event) {
            event.preventDefault();
            createMsg.hidden = true;

            var data = new FormData(createForm);
            api('create', {
                event_type:  (data.get('event_type')  || '').toString().trim(),
                target_url:  (data.get('target_url')  || '').toString().trim(),
                description: (data.get('description') || '').toString().trim(),
                active:      true,
            }).then(function (result) {
                if (!result.ok || !result.data || !result.data.success) {
                    showMessage(createMsg, 'Ошибка: ' + ((result.data && result.data.error) || 'unknown'), 'error');
                    return;
                }
                var secret = result.data.secret || '';
                showMessage(createMsg,
                    'Подписка #' + result.data.id + ' создана. Скопируйте секрет (он показан только сейчас): ' + secret,
                    'success'
                );
                setTimeout(function () { window.location.reload(); }, 8000);
            }).catch(function () {
                showMessage(createMsg, 'Сетевая ошибка', 'error');
            });
        });
    }

    document.querySelectorAll('.webhooks-table .btn-toggle-active').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('tr');
            if (!row) return;
            var id = parseInt(row.getAttribute('data-webhook-id') || '0', 10);
            var newActive = btn.getAttribute('data-active') === '1' ? false : true;
            btn.disabled = true;
            api('update', { id: id, active: newActive }).then(function (result) {
                btn.disabled = false;
                if (result.ok && result.data && result.data.success) {
                    btn.setAttribute('data-active', newActive ? '1' : '0');
                    btn.textContent = newActive ? 'Да' : 'Нет';
                } else {
                    window.alert('Не удалось обновить статус');
                }
            }).catch(function () {
                btn.disabled = false;
                window.alert('Сетевая ошибка');
            });
        });
    });

    document.querySelectorAll('.webhooks-table .btn-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('tr');
            if (!row) return;
            var id = parseInt(row.getAttribute('data-webhook-id') || '0', 10);
            if (!window.confirm('Удалить подписку #' + id + '? История доставок тоже удалится.')) return;
            btn.disabled = true;
            api('delete', { id: id }).then(function (result) {
                if (result.ok && result.data && result.data.success) {
                    row.remove();
                } else {
                    window.alert('Не удалось удалить');
                    btn.disabled = false;
                }
            }).catch(function () {
                btn.disabled = false;
                window.alert('Сетевая ошибка');
            });
        });
    });

    document.querySelectorAll('.webhooks-table .btn-rotate').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('tr');
            if (!row) return;
            var id = parseInt(row.getAttribute('data-webhook-id') || '0', 10);
            if (!window.confirm('Сменить секрет для подписки #' + id + '? Старая подпись перестанет работать.')) return;
            btn.disabled = true;
            api('rotate_secret', { id: id }).then(function (result) {
                btn.disabled = false;
                if (result.ok && result.data && result.data.success) {
                    window.prompt('Новый секрет (показан только сейчас):', result.data.secret || '');
                } else {
                    window.alert('Не удалось сменить секрет');
                }
            }).catch(function () {
                btn.disabled = false;
                window.alert('Сетевая ошибка');
            });
        });
    });

    var historyPanel = document.getElementById('webhookHistoryPanel');
    var historyTbody = historyPanel ? historyPanel.querySelector('tbody') : null;
    var historyMeta  = historyPanel ? historyPanel.querySelector('.webhook-history-meta') : null;

    document.querySelectorAll('.webhooks-table .btn-history').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('tr');
            if (!row || !historyPanel || !historyTbody) return;
            var id = parseInt(row.getAttribute('data-webhook-id') || '0', 10);

            api('history', { id: id }).then(function (result) {
                if (!result.ok || !result.data || !result.data.success) {
                    window.alert('Не удалось загрузить историю');
                    return;
                }
                historyMeta.textContent = 'Подписка #' + id + ' — последние ' + (result.data.deliveries || []).length + ' доставок';
                historyTbody.innerHTML = '';
                (result.data.deliveries || []).forEach(function (d) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = ''
                        + '<td>#' + (d.id || '') + '</td>'
                        + '<td><code>' + escapeHtml(d.event_type || '') + '</code></td>'
                        + '<td>' + escapeHtml(d.status || '') + '</td>'
                        + '<td>' + (d.response_code != null ? d.response_code : '—') + '</td>'
                        + '<td>' + (d.attempts || 0) + '</td>'
                        + '<td>' + escapeHtml(d.created_at || '') + '</td>'
                        + '<td>' + escapeHtml(d.delivered_at || '—') + '</td>';
                    historyTbody.appendChild(tr);
                });
                historyPanel.hidden = false;
                historyPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }).catch(function () {
                window.alert('Сетевая ошибка');
            });
        });
    });

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
