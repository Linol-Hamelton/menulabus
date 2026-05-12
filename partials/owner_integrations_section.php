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
$aggregators = method_exists($db, 'listAggregatorSettings') ? $db->listAggregatorSettings() : [];
$aggregatorLabels = [
    'yandex_eda'    => 'Яндекс.Еда',
    'delivery_club' => 'Delivery Club',
];
$aggregatorSigHeader = [
    'yandex_eda'    => 'X-YandexEda-Signature',
    'delivery_club' => 'X-DC-Signature',
];
$menuItemsForMapping = method_exists($db, 'listMenuItemsWithAggregatorIds')
    ? $db->listMenuItemsWithAggregatorIds()
    : [];
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

    <!-- Phase 36 — aggregator integrations (Yandex.Еда + Delivery Club) -->
    <div class="owner-workspace-header">
        <div>
            <p class="owner-workspace-kicker">Интеграции</p>
            <h2>Агрегаторы доставки</h2>
        </div>
        <p class="owner-workspace-copy">
            Принимаем заказы из Яндекс.Еды и Delivery Club через webhook → автоматически создаём заказы в системе.
            Обратная синхронизация статусов работает через cron-воркер (каждую минуту).
        </p>
    </div>

    <?php foreach (['yandex_eda', 'delivery_club'] as $provider): ?>
        <?php
        $agg = $aggregators[$provider] ?? null;
        $label = $aggregatorLabels[$provider];
        $sigHeader = $aggregatorSigHeader[$provider];
        $webhookUrl = $baseUrl . '/api/aggregator/webhook.php?provider=' . $provider;
        ?>
        <section class="account-section admin-section-card integrations-card" data-aggregator-card="<?= htmlspecialchars($provider) ?>">
            <div class="integrations-status-row">
                <h3 class="integrations-aggregator-title"><?= htmlspecialchars($label) ?></h3>
                <?php if ($agg && (int)$agg['enabled'] === 1): ?>
                    <span class="integrations-badge integrations-badge--on">включено</span>
                <?php elseif ($agg): ?>
                    <span class="integrations-badge integrations-badge--off">выключено</span>
                <?php else: ?>
                    <span class="integrations-badge integrations-badge--missing">не настроено</span>
                <?php endif; ?>
                <?php if ($agg && !empty($agg['last_webhook_at'])): ?>
                    <span class="integrations-meta">последний webhook: <?= htmlspecialchars((string)$agg['last_webhook_at']) ?></span>
                <?php endif; ?>
                <?php if ($agg && !empty($agg['last_push_at'])): ?>
                    <span class="integrations-meta">последний push: <?= htmlspecialchars((string)$agg['last_push_at']) ?></span>
                <?php endif; ?>
            </div>

            <div class="integrations-row">
                <span class="integrations-label">Webhook URL:</span>
                <code class="integrations-endpoint"><?= htmlspecialchars($webhookUrl) ?></code>
            </div>
            <div class="integrations-row">
                <span class="integrations-label">Signature header:</span>
                <code class="integrations-endpoint"><?= htmlspecialchars($sigHeader) ?></code>
                <span class="integrations-meta">HMAC-SHA256(payload, secret), hex-кодировка</span>
            </div>

            <form class="aggregator-form" data-provider="<?= htmlspecialchars($provider) ?>">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">API ключ (Bearer для outbound push)</span>
                    <input type="text" class="js-agg-api-key" maxlength="255"
                           value="<?= htmlspecialchars((string)($agg['api_key'] ?? '')) ?>"
                           placeholder="выдаёт партнёр при подключении">
                </label>
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Webhook secret</span>
                    <input type="text" class="js-agg-secret" maxlength="64"
                           value="<?= htmlspecialchars((string)($agg['webhook_secret'] ?? '')) ?>"
                           placeholder="<?= $agg ? 'оставьте пустым чтобы сохранить текущий' : 'оставьте пустым — сгенерируется автоматически' ?>">
                </label>
                <label class="inv-edit-field inv-edit-field--checkbox">
                    <span class="inv-edit-label">Статус</span>
                    <span class="inv-vsd-toggle-wrap">
                        <input type="checkbox" class="js-agg-enabled" <?= ($agg && (int)$agg['enabled'] === 1) ? 'checked' : '' ?>>
                        <span>Принимать вebhook'и и пушить статусы</span>
                    </span>
                </label>
                <div class="recipe-save-msg js-agg-msg" hidden></div>
                <div class="integrations-actions">
                    <button type="button" class="checkout-btn js-agg-save">Сохранить</button>
                </div>
            </form>

            <details class="integrations-details">
                <summary>Маппинг товаров (<?= count($menuItemsForMapping) ?> блюд)</summary>
                <div class="aggregator-mapping" data-provider="<?= htmlspecialchars($provider) ?>">
                    <p class="integrations-meta">
                        Внешний ID товара партнёра → наш <code>menu_items.id</code>. Используется webhook'ом для матчинга позиций заказа.
                    </p>
                    <table class="agg-mapping-table">
                        <thead>
                            <tr>
                                <th>Блюдо</th>
                                <th>Категория</th>
                                <th>Внешний ID партнёра</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menuItemsForMapping as $mi): ?>
                                <?php $col = $provider === 'yandex_eda' ? 'aggregator_yandex_id' : 'aggregator_dc_id'; ?>
                                <tr data-menu-item-id="<?= (int)$mi['id'] ?>">
                                    <td><?= htmlspecialchars((string)$mi['name']) ?></td>
                                    <td class="integrations-meta"><?= htmlspecialchars((string)($mi['category'] ?? '')) ?></td>
                                    <td>
                                        <input type="text" class="js-agg-mapping" maxlength="64"
                                               value="<?= htmlspecialchars((string)($mi[$col] ?? '')) ?>"
                                               placeholder="—">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="integrations-actions">
                        <button type="button" class="admin-checkout-btn js-agg-mapping-save">Сохранить маппинг</button>
                        <div class="recipe-save-msg js-agg-mapping-msg" hidden></div>
                    </div>
                </div>
            </details>
        </section>
    <?php endforeach; ?>
</div>
