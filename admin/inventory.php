<?php
/**
 * admin/inventory.php — admin UI for Inventory (Phase 6.2).
 *
 * Panels:
 *   1. Low-stock banner (if any ingredient ≤ threshold).
 *   2. Ingredients table — inline editable + per-row "+N / -N" adjust + movements drawer.
 *   3. Suppliers mini-table.
 *
 * Writes go through api/save-inventory.php.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';

// Phase 14.8 — gate behind 'inventory' plan feature.
$gate_feature = 'inventory';
$gate_label   = 'Управление складом';
require __DIR__ . '/../partials/billing_feature_gate.php';

$db = Database::getInstance();
$ingredients = $db->listIngredients(true);
$suppliers   = $db->listSuppliers(false);
$lowStock    = $db->listLowStockIngredients();

$siteName   = $GLOBALS['siteName'] ?? 'labus';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Склад | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-inventory.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/../account-header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <div class="section-header-menu">
                <h2>Склад ингредиентов</h2>
                <a href="/admin/menu.php" class="back-to-menu-btn">К админке</a>
            </div>

            <?php if (!empty($lowStock)): ?>
                <div class="inv-low-banner" role="status">
                    <strong>Низкий остаток (<?= count($lowStock) ?>):</strong>
                    <?php foreach ($lowStock as $ls): ?>
                        <span class="inv-low-chip">
                            <?= htmlspecialchars((string)$ls['name']) ?>
                            — <?= rtrim(rtrim(number_format((float)$ls['stock_qty'], 3, '.', ''), '0'), '.') ?> <?= htmlspecialchars((string)$ls['unit']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3>Ингредиенты</h3>
            <p class="inv-intro">
                Остаток списывается автоматически при каждом заказе (по рецептам из
                карточки блюда в админке меню). Порог — когда уведомлять в Telegram
                и по webhook-у <code>inventory.stock_low</code>.
            </p>

            <div class="inv-table-wrapper">
                <table class="inv-ingredients-table" id="invIngredientsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Ед.</th>
                            <th class="num-col">Остаток</th>
                            <th class="num-col">Порог</th>
                            <th class="num-col">Цена/ед.</th>
                            <th>Поставщик</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ingredients as $i): ?>
                            <?php
                            $isArchived = !empty($i['archived_at']);
                            $isLow = !$isArchived && (float)$i['reorder_threshold'] > 0 && (float)$i['stock_qty'] <= (float)$i['reorder_threshold'];
                            ?>
                            <tr data-ingredient-id="<?= (int)$i['id'] ?>"
                                class="<?= $isArchived ? 'inv-row-archived' : '' ?> <?= $isLow ? 'inv-row-low' : '' ?>">
                                <td>#<?= (int)$i['id'] ?></td>
                                <td><input type="text" class="inv-name" value="<?= htmlspecialchars((string)$i['name']) ?>" maxlength="255"></td>
                                <td><input type="text" class="inv-unit" value="<?= htmlspecialchars((string)$i['unit']) ?>" maxlength="16" data-w="3xs"></td>
                                <td class="num-col">
                                    <span class="inv-stock-cell"><?= rtrim(rtrim(number_format((float)$i['stock_qty'], 3, '.', ''), '0'), '.') ?></span>
                                    <input type="number" step="0.001" class="inv-adjust-delta" placeholder="±" data-w="xs">
                                    <button type="button" class="admin-checkout-btn btn-inv-apply" data-adjust-action="apply">Применить</button>
                                </td>
                                <td class="num-col"><input type="number" step="0.001" class="inv-threshold" value="<?= rtrim(rtrim(number_format((float)$i['reorder_threshold'], 3, '.', ''), '0'), '.') ?>" min="0" data-w="sm"></td>
                                <td class="num-col"><input type="number" step="0.0001" class="inv-cost" value="<?= rtrim(rtrim(number_format((float)$i['cost_per_unit'], 4, '.', ''), '0'), '.') ?>" min="0" data-w="md"></td>
                                <td>
                                    <select class="inv-supplier">
                                        <option value="">—</option>
                                        <?php foreach ($suppliers as $sup): ?>
                                            <option value="<?= (int)$sup['id'] ?>"
                                                <?= (int)($i['supplier_id'] ?? 0) === (int)$sup['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)$sup['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="inv-actions-cell">
                                    <button type="button" class="admin-checkout-btn btn-inv-save">Сохранить</button>
                                    <button type="button" class="admin-checkout-btn btn-inv-history">История</button>
                                    <?php if ($isArchived): ?>
                                        <button type="button" class="admin-checkout-btn btn-inv-restore">Вернуть</button>
                                    <?php else: ?>
                                        <button type="button" class="admin-checkout-btn cancel btn-inv-archive">Архив</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="inv-new-row" data-ingredient-id="">
                            <td>—</td>
                            <td><input type="text" class="inv-name" placeholder="Название" maxlength="255"></td>
                            <td><input type="text" class="inv-unit" placeholder="шт" value="шт" maxlength="16" data-w="3xs"></td>
                            <td class="num-col"><input type="number" step="0.001" class="inv-new-stock" value="0" min="0" data-w="md"></td>
                            <td class="num-col"><input type="number" step="0.001" class="inv-threshold" value="0" min="0" data-w="sm"></td>
                            <td class="num-col"><input type="number" step="0.0001" class="inv-cost" value="0" min="0" data-w="md"></td>
                            <td>
                                <select class="inv-supplier">
                                    <option value="">—</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars((string)$sup['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <button type="button" class="admin-checkout-btn btn-inv-save">Создать</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="invHistoryPanel" class="inv-history-panel" hidden>
                <h3>История движения</h3>
                <div class="inv-history-meta"></div>
                <table class="inv-history-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Дельта</th>
                            <th>Причина</th>
                            <th>Заказ / Блюдо</th>
                            <th>Примечание</th>
                            <th>Когда</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <section class="account-section">
            <div class="section-header-menu">
                <h3>Поставщики</h3>
                <small>Контакт-книга. Используется как soft-reference на карточке ингредиента.</small>
            </div>
            <table class="inv-suppliers-table" id="invSuppliersTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Контакт</th>
                        <th>Заметки</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suppliers as $sup): ?>
                        <tr data-supplier-id="<?= (int)$sup['id'] ?>">
                            <td>#<?= (int)$sup['id'] ?></td>
                            <td><input type="text" class="sup-name" value="<?= htmlspecialchars((string)$sup['name']) ?>" maxlength="255"></td>
                            <td><input type="text" class="sup-contact" value="<?= htmlspecialchars((string)($sup['contact'] ?? '')) ?>" maxlength="255"></td>
                            <td><input type="text" class="sup-notes" value="<?= htmlspecialchars((string)($sup['notes'] ?? '')) ?>" maxlength="500"></td>
                            <td><button type="button" class="admin-checkout-btn btn-sup-save">Сохранить</button></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="inv-new-row" data-supplier-id="">
                        <td>—</td>
                        <td><input type="text" class="sup-name" placeholder="Новый поставщик" maxlength="255"></td>
                        <td><input type="text" class="sup-contact" placeholder="телефон / email" maxlength="255"></td>
                        <td><input type="text" class="sup-notes" placeholder="" maxlength="500"></td>
                        <td><button type="button" class="admin-checkout-btn btn-sup-save">Создать</button></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-inventory.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
