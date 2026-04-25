(function () {
    'use strict';

    var block = document.querySelector('.account-2fa-block');
    if (!block) return;
    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token'))
        || (document.querySelector('meta[name="csrf-token"]') || {}).content
        || '';

    function api(body) {
        return fetch('/api/save-2fa.php', {
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

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function showBackup(codes) {
        var box = document.getElementById('twofaBackupCodes') || document.getElementById('twofaResult');
        if (!box) return;
        box.hidden = false;
        var html = '<div class="account-2fa-backup-title">Резервные коды (сохраните!)</div><ul class="account-2fa-backup-list">';
        codes.forEach(function (c) { html += '<li><code>' + c + '</code></li>'; });
        html += '</ul><p class="account-2fa-backup-warn">Эти коды показываются только сейчас. Каждый можно использовать один раз.</p>';
        box.innerHTML = html;
    }

    var setupBtn = document.getElementById('twofaSetupBtn');
    if (setupBtn) {
        setupBtn.addEventListener('click', function () {
            setupBtn.disabled = true;
            api({ action: 'setup' }).then(function (r) {
                setupBtn.disabled = false;
                if (!r.ok || !r.data || !r.data.success) {
                    window.alert('Не получилось: ' + ((r.data && r.data.error) || 'unknown'));
                    return;
                }
                document.getElementById('twofaSetupBox').hidden = false;
                setText('twofaSecret', r.data.secret || '—');
                var hint = document.getElementById('twofaUriHint');
                if (hint && r.data.uri) hint.textContent = r.data.uri;
                setupBtn.style.display = 'none';
            });
        });
    }

    var enableBtn = document.getElementById('twofaEnableBtn');
    if (enableBtn) {
        enableBtn.addEventListener('click', function () {
            var code = (document.getElementById('twofaCode') || {}).value || '';
            if (!/^\d{6}$/.test(code)) { window.alert('Код — 6 цифр.'); return; }
            enableBtn.disabled = true;
            api({ action: 'enable', code: code }).then(function (r) {
                if (!r.ok || !r.data || !r.data.success) {
                    enableBtn.disabled = false;
                    window.alert('Не получилось: ' + ((r.data && r.data.error) || 'unknown'));
                    return;
                }
                showBackup(r.data.backup_codes || []);
                enableBtn.disabled = true;
                enableBtn.textContent = 'Включено';
            });
        });
    }

    var disableBtn = document.getElementById('twofaDisableBtn');
    if (disableBtn) {
        disableBtn.addEventListener('click', function () {
            var code = (document.getElementById('twofaDisableCode') || {}).value || '';
            if (!code) { window.alert('Введите код для подтверждения.'); return; }
            if (!window.confirm('Отключить двухфакторную защиту?')) return;
            disableBtn.disabled = true;
            api({ action: 'disable', code: code }).then(function (r) {
                disableBtn.disabled = false;
                if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); return; }
                window.location.reload();
            });
        });
    }

    var regenBtn = document.getElementById('twofaRegenerateBtn');
    if (regenBtn) {
        regenBtn.addEventListener('click', function () {
            var code = (document.getElementById('twofaDisableCode') || {}).value || '';
            if (!code) { window.alert('Введите текущий код для подтверждения.'); return; }
            regenBtn.disabled = true;
            api({ action: 'regenerate_backup', code: code }).then(function (r) {
                regenBtn.disabled = false;
                if (!r.ok || !r.data || !r.data.success) { window.alert('Не получилось'); return; }
                showBackup(r.data.backup_codes || []);
            });
        });
    }
})();
