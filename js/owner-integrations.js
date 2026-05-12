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

    // ---- Phase 36: aggregator settings + mapping ----
    function aggregatorApi(body) {
        var payload = Object.assign({ csrf_token: csrfToken }, body || {});
        return fetch('/api/save-aggregator.php', {
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

    function aggregatorShowMsg(card, isError, text, selector) {
        var el = card.querySelector(selector || '.js-agg-msg');
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
        el.classList.toggle('recipe-save-msg-success', !isError);
        el.classList.toggle('recipe-save-msg-error', !!isError);
    }

    document.querySelectorAll('[data-aggregator-card]').forEach(function (card) {
        var provider = card.getAttribute('data-aggregator-card');

        // Save settings
        var saveBtn = card.querySelector('.js-agg-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var apiKey  = (card.querySelector('.js-agg-api-key') || {}).value || '';
                var secret  = (card.querySelector('.js-agg-secret') || {}).value || '';
                var enabled = !!(card.querySelector('.js-agg-enabled') && card.querySelector('.js-agg-enabled').checked);
                saveBtn.disabled = true;
                aggregatorApi({
                    action: 'save_settings',
                    provider: provider,
                    api_key: apiKey,
                    webhook_secret: secret,
                    enabled: enabled,
                }).then(function (res) {
                    saveBtn.disabled = false;
                    if (res.ok && res.data && res.data.success) {
                        aggregatorShowMsg(card, false, 'Сохранено. Обновляем…');
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        aggregatorShowMsg(card, true, 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'));
                    }
                }).catch(function (e) {
                    saveBtn.disabled = false;
                    aggregatorShowMsg(card, true, 'Сеть: ' + (e && e.message || ''));
                });
            });
        }

        // Save mapping
        var saveMappingBtn = card.querySelector('.js-agg-mapping-save');
        if (saveMappingBtn) {
            saveMappingBtn.addEventListener('click', function () {
                var rows = card.querySelectorAll('.aggregator-mapping tbody tr[data-menu-item-id]');
                var mappings = [];
                rows.forEach(function (tr) {
                    var miId = parseInt(tr.getAttribute('data-menu-item-id') || '0', 10);
                    if (!miId) return;
                    var input = tr.querySelector('.js-agg-mapping');
                    mappings.push({
                        menu_item_id: miId,
                        external_id: input ? (input.value || '').trim() : '',
                    });
                });
                saveMappingBtn.disabled = true;
                aggregatorApi({ action: 'save_mapping', provider: provider, mappings: mappings })
                    .then(function (res) {
                        saveMappingBtn.disabled = false;
                        if (res.ok && res.data && res.data.success) {
                            aggregatorShowMsg(card, false, 'Сохранено: ' + res.data.saved + ' позиций.', '.js-agg-mapping-msg');
                        } else {
                            aggregatorShowMsg(card, true, 'Ошибка: ' + ((res.data && res.data.error) || 'unknown'), '.js-agg-mapping-msg');
                        }
                    }).catch(function (e) {
                        saveMappingBtn.disabled = false;
                        aggregatorShowMsg(card, true, 'Сеть: ' + (e && e.message || ''), '.js-agg-mapping-msg');
                    });
            });
        }
    });
})();
