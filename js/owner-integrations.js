(function () {
    'use strict';

    var section = document.getElementById('integrations');
    if (!section) return;

    var csrfToken = (document.body && document.body.getAttribute('data-csrf-token')) || '';

    function api(action) {
        return fetch('/api/save-odata-creds.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ action: action, csrf_token: csrfToken }),
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; }, function () {
                return { ok: r.ok, data: { success: false, error: 'invalid_json' } };
            });
        });
    }

    function showMsg(text, isError) {
        var el = document.getElementById('integrationsMsg');
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
        el.classList.toggle('recipe-save-msg-success', !isError);
        el.classList.toggle('recipe-save-msg-error', !!isError);
    }

    var rotateBtn = document.getElementById('integrationsRotate');
    var toggleBtn = document.getElementById('integrationsToggleEnable');
    var copyBtn = document.getElementById('integrationsKeyCopy');
    var keyBanner = document.getElementById('integrationsKeyBanner');
    var keyInput = document.getElementById('integrationsKeyValue');

    if (rotateBtn) {
        rotateBtn.addEventListener('click', function () {
            if (!window.confirm('Сгенерировать новый API ключ? Старый сразу перестанет работать.')) return;
            rotateBtn.disabled = true;
            api('rotate').then(function (res) {
                rotateBtn.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    if (keyBanner && keyInput) {
                        keyInput.value = res.data.api_key;
                        keyBanner.hidden = false;
                        keyInput.focus();
                        keyInput.select();
                    }
                    showMsg('Новый ключ сгенерирован. Скопируйте сейчас — больше показан не будет.', false);
                } else {
                    showMsg('Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            }).catch(function (e) {
                rotateBtn.disabled = false;
                showMsg('Сеть: ' + (e && e.message || ''), true);
            });
        });
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var currentlyOn = toggleBtn.getAttribute('data-enabled') === '1';
            var action = currentlyOn ? 'disable' : 'enable';
            toggleBtn.disabled = true;
            api(action).then(function (res) {
                toggleBtn.disabled = false;
                if (res.ok && res.data && res.data.success) {
                    showMsg(currentlyOn ? 'Интеграция выключена.' : 'Интеграция включена.', false);
                    setTimeout(function () { window.location.reload(); }, 600);
                } else {
                    showMsg('Ошибка: ' + ((res.data && res.data.error) || 'unknown'), true);
                }
            }).catch(function (e) {
                toggleBtn.disabled = false;
                showMsg('Сеть: ' + (e && e.message || ''), true);
            });
        });
    }

    if (copyBtn && keyInput) {
        copyBtn.addEventListener('click', function () {
            keyInput.focus();
            keyInput.select();
            try {
                document.execCommand('copy');
                copyBtn.textContent = 'Скопировано!';
                setTimeout(function () { copyBtn.textContent = 'Копировать'; }, 1500);
            } catch (_) {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(keyInput.value).then(function () {
                        copyBtn.textContent = 'Скопировано!';
                        setTimeout(function () { copyBtn.textContent = 'Копировать'; }, 1500);
                    });
                }
            }
        });
    }
})();
