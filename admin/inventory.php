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
require_once __DIR__ . '/../lib/Inventory/UnitCatalog.php';

// Phase 14.8 — gate behind 'inventory' plan feature.
$gate_feature = 'inventory';
$gate_label   = 'Управление складом';
require __DIR__ . '/../partials/billing_feature_gate.php';

$db = Database::getInstance();

// Phase 34 — view toggle: ?view=active (default) shows non-archived, ?view=archived shows archived only.
$invView = (($_GET['view'] ?? 'active') === 'archived') ? 'archived' : 'active';

// Optional URL-driven prefilters; the rest of the filter bar applies client-side.
$invSearch   = trim((string)($_GET['q'] ?? ''));
$invSupplier = isset($_GET['supplier']) && $_GET['supplier'] !== '' ? (int)$_GET['supplier'] : null;
$invStock    = (string)($_GET['stock'] ?? '');
if (!in_array($invStock, ['low', 'out', 'ok'], true)) {
    $invStock = '';
}

if ($invView === 'archived') {
    // Archived view: only show archived rows. Pass includeArchived=true and
    // post-filter to archived-only in the loop below (listIngredients doesn't
    // have an "archived only" mode by design).
    $allRows     = $db->listIngredients(true, $invSearch ?: null, $invSupplier, $invStock ?: null);
    $ingredients = array_values(array_filter($allRows, static fn($r) => !empty($r['archived_at'])));
} else {
    $ingredients = $db->listIngredients(false, $invSearch ?: null, $invSupplier, $invStock ?: null);
}
$suppliers     = $db->listSuppliers(false);
$lowStock      = $db->listLowStockIngredients();
$summary       = $db->getInventoryValueSummary();

$unitOptions = \Cleanmenu\Inventory\UnitCatalog::CANONICAL;

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
    <link rel="stylesheet" href="/css/mobile-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-inventory.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/../account-header.php'; ?>

    <div class="account-container">
        <section class="account-section admin-section-card">
            <div class="admin-pane-header">
                <div class="admin-pane-header-copy">
                    <p class="admin-pane-kicker">Склад</p>
                    <h2 class="admin-pane-title">Ингредиенты</h2>
                    <p class="admin-pane-caption">
                        Остаток списывается автоматически при каждом заказе (по рецептам из карточки блюда).
                        Порог — когда уведомлять в Telegram и по webhook-у <code>inventory.stock_low</code>.
                    </p>
                </div>
                <a href="/admin/menu.php" class="back-to-menu-btn">К админке</a>
            </div>

            <!-- Phase 34: summary cards (active count, low-stock count, total stock value) -->
            <div class="inv-summary-row" role="status" aria-label="Сводка по складу">
                <div class="inv-summary-card">
                    <span class="inv-summary-label">Активных позиций</span>
                    <span class="inv-summary-value"><?= (int)$summary['active'] ?></span>
                </div>
                <div class="inv-summary-card<?= (int)$summary['low'] > 0 ? ' inv-summary-card--warn' : '' ?>">
                    <span class="inv-summary-label">Низкий остаток</span>
                    <span class="inv-summary-value"><?= (int)$summary['low'] ?></span>
                </div>
                <div class="inv-summary-card">
                    <span class="inv-summary-label">Общая стоимость склада</span>
                    <span class="inv-summary-value">
                        <?= number_format((float)$summary['total_value'], 2, '.', ' ') ?> ₽
                    </span>
                </div>
            </div>

            <?php if (!empty($lowStock) && $invView !== 'archived'): ?>
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

            <!-- Phase 34: view toggle (active / archived) -->
            <div class="form-actions inv-view-switch">
                <a href="/admin/inventory.php?view=active" class="admin-checkout-btn<?= $invView !== 'archived' ? ' cancel' : '' ?>">Активные</a>
                <a href="/admin/inventory.php?view=archived" class="admin-checkout-btn<?= $invView === 'archived' ? ' cancel' : '' ?>">Архив</a>
            </div>

            <!-- Phase 34: filter bar (client-side filtering, applied to rendered rows) -->
            <div class="inv-filter-bar" id="invFilterBar">
                <label class="inv-filter-group inv-filter-search">
                    <span class="inv-filter-label">Поиск</span>
                    <input type="search" id="invFilterSearch" placeholder="По названию…" autocomplete="off" value="<?= htmlspecialchars($invSearch, ENT_QUOTES) ?>">
                </label>
                <label class="inv-filter-group">
                    <span class="inv-filter-label">Поставщик</span>
                    <select id="invFilterSupplier">
                        <option value="">Все</option>
                        <option value="0" <?= $invSupplier === 0 ? 'selected' : '' ?>>Без поставщика</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= (int)$sup['id'] ?>" <?= $invSupplier === (int)$sup['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$sup['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="inv-filter-group">
                    <span class="inv-filter-label">Статус остатка</span>
                    <select id="invFilterStock">
                        <option value="">Любой</option>
                        <option value="low" <?= $invStock === 'low' ? 'selected' : '' ?>>Низкий</option>
                        <option value="out" <?= $invStock === 'out' ? 'selected' : '' ?>>Закончился</option>
                        <option value="ok"  <?= $invStock === 'ok'  ? 'selected' : '' ?>>В норме</option>
                    </select>
                </label>
                <button type="button" class="admin-checkout-btn cancel" id="invFilterReset">Сбросить</button>
            </div>

            <!-- Phase 34: bulk-action bar (hidden until rows are checked) -->
            <div class="inv-bulk-bar" id="invBulkBar" hidden>
                <span class="inv-bulk-count">Выбрано: <strong id="invBulkCount">0</strong></span>
                <button type="button" class="admin-checkout-btn cancel" id="invBulkArchive">Архивировать выбранные</button>
                <button type="button" class="admin-checkout-btn" id="invBulkClear">Снять выделение</button>
            </div>

            <div class="inv-table-wrapper">
                <?php if (empty($ingredients)): ?>
                    <div class="inv-empty-state">
                        <?php if ($invView === 'archived'): ?>
                            В архиве пусто. Архивные позиции сюда попадают по кнопке «Архив» у активного ингредиента.
                        <?php elseif ($invSearch !== '' || $invSupplier !== null || $invStock !== ''): ?>
                            По текущим фильтрам ничего не найдено. <a href="/admin/inventory.php?view=active">Сбросить фильтры</a>.
                        <?php else: ?>
                            Пока нет ни одного ингредиента — создайте первый в строке снизу.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <table class="inv-ingredients-table" id="invIngredientsTable">
                    <thead>
                        <tr>
                            <th class="inv-col-check"><input type="checkbox" id="invSelectAll" aria-label="Выбрать все"></th>
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
                                data-supplier-id="<?= $i['supplier_id'] !== null ? (int)$i['supplier_id'] : '' ?>"
                                data-stock-status="<?= $isLow ? 'low' : ((float)$i['stock_qty'] <= 0 ? 'out' : 'ok') ?>"
                                class="<?= $isArchived ? 'inv-row-archived' : '' ?> <?= $isLow ? 'inv-row-low' : '' ?>">
                                <td class="inv-col-check"><input type="checkbox" class="inv-row-check" aria-label="Выбрать строку"></td>
                                <td>#<?= (int)$i['id'] ?></td>
                                <td><input type="text" class="inv-name" value="<?= htmlspecialchars((string)$i['name']) ?>" maxlength="255"></td>
                                <td class="inv-unit-cell">
                                    <?php
                                    $currentUnit = (string)$i['unit'];
                                    $isCanonical = \Cleanmenu\Inventory\UnitCatalog::isCanonical($currentUnit);
                                    ?>
                                    <select class="inv-unit-select" data-w="sm">
                                        <?php foreach ($unitOptions as $u): ?>
                                            <option value="<?= htmlspecialchars($u) ?>" <?= ($isCanonical && $u === $currentUnit) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($u) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="__other__" <?= $isCanonical ? '' : 'selected' ?>>Другое…</option>
                                    </select>
                                    <input type="text" class="inv-unit-other" maxlength="16" data-w="3xs"
                                           value="<?= $isCanonical ? '' : htmlspecialchars($currentUnit) ?>"
                                           placeholder="напр. гр"
                                           <?= $isCanonical ? 'hidden' : '' ?>>
                                </td>
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
                        <?php if ($invView !== 'archived'): ?>
                        <tr class="inv-new-row" data-ingredient-id="">
                            <td class="inv-col-check"></td>
                            <td>—</td>
                            <td><input type="text" class="inv-name" placeholder="Название" maxlength="255"></td>
                            <td class="inv-unit-cell">
                                <select class="inv-unit-select" data-w="sm">
                                    <?php foreach ($unitOptions as $u): ?>
                                        <option value="<?= htmlspecialchars($u) ?>" <?= $u === 'шт' ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="__other__">Другое…</option>
                                </select>
                                <input type="text" class="inv-unit-other" maxlength="16" data-w="3xs" placeholder="напр. гр" hidden>
                            </td>
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
                        <?php endif; ?>
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

        <section class="account-section admin-section-card">
            <div class="admin-pane-header">
                <div class="admin-pane-header-copy">
                    <p class="admin-pane-kicker">Контакт-книга</p>
                    <h2 class="admin-pane-title">Поставщики</h2>
                    <p class="admin-pane-caption">Soft-reference на карточке ингредиента. Цены приходов фиксируются в стоимости ингредиента — отдельных закупочных накладных пока нет.</p>
                </div>
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
