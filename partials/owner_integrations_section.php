<?php
// partials/owner_integrations_section.php — Phase 37 (1С OData).
//
// Read-only view of OData credentials + buttons to rotate / enable.
// Plaintext API key is shown only once (immediately after rotation) via
// data attribute that JS reads from the response and surfaces in a banner.

if (!isset($db)) return;
// Note: $user is clobbered by `foreach ($users as $user)` in the Users tab
// above. Read role from session instead — owner.php already gated the page
// with $required_role='owner', so this is a defence-in-depth check.
if (($_SESSION['user_role'] ?? '') !== 'owner') {
    echo '<p class="vsd-empty">Доступ только для владельца.</p>';
    return;
}

$creds = $db->getOdataCreds();
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
         . ($_SERVER['HTTP_HOST'] ?? '');
?>
<div class="owner-workspace-stack" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <div class="owner-workspace-header">
        <div>
            <p class="owner-workspace-kicker">Интеграции</p>
            <h2>1С OData</h2>
        </div>
        <p class="owner-workspace-copy">
            Read-only OData v3 endpoint для подключения 1С Конфигуратора как «Внешний источник данных».
            Экспонируем три сущности: Orders, MenuItems, Customers. Аутентификация — HTTP Basic.
        </p>
    </div>

    <section class="account-section admin-section-card integrations-card">
        <div class="integrations-status-row">
            <span class="integrations-label">Статус:</span>
            <?php if ($creds && (int)$creds['enabled'] === 1): ?>
                <span class="integrations-badge integrations-badge--on">включено</span>
            <?php elseif ($creds): ?>
                <span class="integrations-badge integrations-badge--off">выключено</span>
            <?php else: ?>
                <span class="integrations-badge integrations-badge--missing">не настроено</span>
            <?php endif; ?>
            <?php if ($creds && !empty($creds['last_used_at'])): ?>
                <span class="integrations-meta">последний запрос: <?= htmlspecialchars((string)$creds['last_used_at']) ?></span>
            <?php endif; ?>
        </div>

        <div class="integrations-row">
            <span class="integrations-label">Endpoint:</span>
            <code class="integrations-endpoint"><?= htmlspecialchars($baseUrl) ?>/api/v1/odata/</code>
        </div>
        <div class="integrations-row">
            <span class="integrations-label">Логин:</span>
            <code class="integrations-endpoint"><?= $creds ? htmlspecialchars((string)$creds['username']) : '—' ?></code>
        </div>
        <div class="integrations-row">
            <span class="integrations-label">API ключ:</span>
            <span class="integrations-meta">хранится только в виде hash. Чтобы получить — нажмите «Сгенерировать новый ключ» (старый сразу перестанет работать).</span>
        </div>

        <div id="integrationsKeyBanner" class="integrations-key-banner" hidden>
            <p class="integrations-key-label">Новый API ключ (показывается один раз — скопируйте сейчас):</p>
            <div class="integrations-key-row">
                <input type="text" id="integrationsKeyValue" readonly>
                <button type="button" class="admin-checkout-btn" id="integrationsKeyCopy">Копировать</button>
            </div>
        </div>

        <div class="integrations-actions">
            <button type="button" class="checkout-btn" id="integrationsRotate">
                <?= $creds ? 'Сгенерировать новый ключ' : 'Создать ключ' ?>
            </button>
            <?php if ($creds): ?>
                <button type="button" class="admin-checkout-btn" id="integrationsToggleEnable" data-enabled="<?= (int)$creds['enabled'] ?>">
                    <?= (int)$creds['enabled'] === 1 ? 'Выключить интеграцию' : 'Включить интеграцию' ?>
                </button>
            <?php endif; ?>
            <div id="integrationsMsg" class="recipe-save-msg" hidden></div>
        </div>

        <details class="integrations-details">
            <summary>Как подключить 1С Конфигуратор</summary>
            <ol class="integrations-howto">
                <li>В 1С Конфигуратор → «Общие» → «Внешние источники данных» → «Добавить».</li>
                <li>Тип источника — «OData».</li>
                <li>URL: <code><?= htmlspecialchars($baseUrl) ?>/api/v1/odata/</code></li>
                <li>Аутентификация — HTTP Basic. Имя пользователя = логин выше, пароль = API ключ.</li>
                <li>Сущности: <code>orders.php</code>, <code>menu_items.php</code>, <code>customers.php</code>.</li>
                <li>Поддерживаемые query options: <code>$top</code>, <code>$skip</code>, <code>$count=true</code>, <code>$select=A,B</code>, <code>$filter=A eq 1 and B gt 0</code>, <code>substringof('x', Name)</code>.</li>
            </ol>
        </details>
    </section>
</div>
