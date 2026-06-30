<?php
/**
 * admin/inventory.php — admin UI for Inventory (Phase 34.1 polish v2).
 *
 * Layout:
 *   1. Section header (kicker / title / caption) + summary cards.
 *   2. Low-stock chip banner (active view only).
 *   3. Toolbar — view switch + "+ Создать ингредиент" button.
 *   4. Slide-down create panel (hidden by default).
 *   5. Filter bar + bulk-action bar.
 *   6. Desktop table — fewer columns, stock cell with status badge +
 *      kebab toggle, threshold tucked inside stock cell.
 *   7. Mobile card list — compact rows with "Редактировать" → modal.
 *   8. Edit modal — full form for one ingredient.
 *   9. Suppliers section.
 *
 * Writes go through api/save-inventory.php.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Inventory/UnitCatalog.php';

// Phase L103.5e — gate behind inventory.ingredients feature (tier 6+ «Кухня+»).
$l103_feature = \Cleanmenu\Billing\Features::INVENTORY_INGREDIENTS;
$l103_label   = 'Складской учёт ингредиентов';
require __DIR__ . '/../partials/tier_paywall.php';

// Phase 14.8 — legacy gate behind PlanRegistry 'inventory' feature (stays for back-compat).
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

// Helper: status bucket for a row.
$statusBucket = static function (array $i): string {
    if (!empty($i['archived_at'])) return 'archived';
    $stock = (float)$i['stock_qty'];
    $thr   = (float)$i['reorder_threshold'];
    if ($stock <= 0) return 'out';
    if ($thr > 0 && $stock <= $thr) return 'low';
    return 'ok';
};
$statusLabel = ['ok' => 'OK', 'low' => 'Низкий', 'out' => 'Нет', 'archived' => 'Архив'];

// Helper: trim trailing zeros for nice display.
$fmtQty = static function ($v): string {
    return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
};
$fmtCost = static function ($v): string {
    return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
};
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
    <link rel="stylesheet" href="/css/admin-design-modals.css?v=<?= htmlspecialchars($appVersion) ?>">
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

            <!-- Summary cards -->
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
                            — <?= $fmtQty($ls['stock_qty']) ?> <?= htmlspecialchars((string)$ls['unit']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Phase 34.1: toolbar (view switch + create button), echoing admin/menu.php pattern -->
            <div class="inv-toolbar">
                <div class="form-actions inv-view-switch">
                    <a href="/admin/inventory.php?view=active" class="admin-checkout-btn<?= $invView !== 'archived' ? ' cancel' : '' ?>">Активные</a>
                    <a href="/admin/inventory.php?view=archived" class="admin-checkout-btn<?= $invView === 'archived' ? ' cancel' : '' ?>">Архив</a>
                </div>
                <?php if ($invView !== 'archived'): ?>
                    <button type="button" class="checkout-btn" id="invNewToggle" title="Создать новый ингредиент">+ Создать ингредиент</button>
                <?php endif; ?>
            </div>

            <!-- Phase 34.1: slide-down create panel -->
            <?php if ($invView !== 'archived'): ?>
            <div class="inv-create-panel" id="invCreatePanel" hidden>
                <div class="inv-create-grid">
                    <label class="inv-create-field">
                        <span class="inv-create-label">Название</span>
                        <input type="text" id="invNewName" placeholder="Например: Мука В/С" maxlength="255">
                    </label>
                    <label class="inv-create-field">
                        <span class="inv-create-label">Единица</span>
                        <select id="invNewUnit">
                            <?php foreach ($unitOptions as $u): ?>
                                <option value="<?= htmlspecialchars($u) ?>" <?= $u === 'шт' ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__other__">Другое…</option>
                        </select>
                    </label>
                    <label class="inv-create-field inv-create-field--other" id="invNewUnitOtherWrap" hidden>
                        <span class="inv-create-label">Другое</span>
                        <input type="text" id="invNewUnitOther" maxlength="16" placeholder="напр. гр">
                    </label>
                    <label class="inv-create-field">
                        <span class="inv-create-label">Остаток</span>
                        <input type="number" step="0.001" id="invNewStock" value="0" min="0">
                    </label>
                    <label class="inv-create-field">
                        <span class="inv-create-label">Порог</span>
                        <input type="number" step="0.001" id="invNewThreshold" value="0" min="0">
                    </label>
                    <label class="inv-create-field">
                        <span class="inv-create-label">Цена/ед.</span>
                        <input type="number" step="0.0001" id="invNewCost" value="0" min="0">
                    </label>
                    <label class="inv-create-field inv-create-field--wide">
                        <span class="inv-create-label">Поставщик</span>
                        <select id="invNewSupplier">
                            <option value="">—</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars((string)$sup['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="inv-create-actions">
                    <button type="button" class="admin-checkout-btn cancel" id="invCreateCancel">Отмена</button>
                    <button type="button" class="checkout-btn" id="invCreateSubmit">Создать</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter bar -->
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

            <!-- Bulk-action bar -->
            <div class="inv-bulk-bar" id="invBulkBar" hidden>
                <span class="inv-bulk-count">Выбрано: <strong id="invBulkCount">0</strong></span>
                <button type="button" class="admin-checkout-btn cancel" id="invBulkArchive">Архивировать выбранные</button>
                <button type="button" class="admin-checkout-btn" id="invBulkClear">Снять выделение</button>
            </div>

            <?php if (empty($ingredients)): ?>
                <div class="inv-empty-state">
                    <?php if ($invView === 'archived'): ?>
                        В архиве пусто. Архивные позиции сюда попадают по кнопке «Архив» у активного ингредиента.
                    <?php elseif ($invSearch !== '' || $invSupplier !== null || $invStock !== ''): ?>
                        По текущим фильтрам ничего не найдено. <a href="/admin/inventory.php?view=active">Сбросить фильтры</a>.
                    <?php else: ?>
                        Пока нет ни одного ингредиента — нажмите «+ Создать ингредиент» сверху.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- DESKTOP TABLE — fewer columns, stock cell merged with threshold + status badge -->
            <div class="inv-table-wrapper desktop-table">
                <table class="inv-ingredients-table" id="invIngredientsTable">
                    <thead>
                        <tr>
                            <th class="inv-col-check"><input type="checkbox" id="invSelectAll" aria-label="Выбрать все"></th>
                            <th>Название</th>
                            <th>Ед.</th>
                            <th class="num-col">Остаток · порог</th>
                            <th class="num-col">Цена/ед.</th>
                            <th>Поставщик</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ingredients as $i): ?>
                            <?php
                            $isArchived = !empty($i['archived_at']);
                            $status     = $statusBucket($i);
                            $isLow      = $status === 'low';
                            $isOut      = $status === 'out';
                            $currentUnit = (string)$i['unit'];
                            $isCanonical = \Cleanmenu\Inventory\UnitCatalog::isCanonical($currentUnit);
                            ?>
                            <tr data-ingredient-id="<?= (int)$i['id'] ?>"
                                data-supplier-id="<?= $i['supplier_id'] !== null ? (int)$i['supplier_id'] : '' ?>"
                                data-stock-status="<?= htmlspecialchars($status) ?>"
                                data-requires-vsd="<?= !empty($i['requires_vsd']) ? '1' : '0' ?>"
                                data-is-alcohol="<?= !empty($i['is_alcohol']) ? '1' : '0' ?>"
                                data-alc-code="<?= htmlspecialchars((string)($i['alc_code'] ?? '')) ?>"
                                data-is-semi-finished="<?= !empty($i['is_semi_finished']) ? '1' : '0' ?>"
                                data-yield-per-batch="<?= htmlspecialchars((string)($i['yield_per_batch'] ?? '0')) ?>"
                                class="<?= $isArchived ? 'inv-row-archived' : '' ?> <?= $isLow ? 'inv-row-low' : '' ?>">
                                <td class="inv-col-check"><input type="checkbox" class="inv-row-check" aria-label="Выбрать строку"></td>
                                <td>
                                    <input type="text" class="inv-name" value="<?= htmlspecialchars((string)$i['name']) ?>" maxlength="255">
                                    <span class="inv-row-id-hint">#<?= (int)$i['id'] ?></span>
                                </td>
                                <td class="inv-unit-cell">
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
                                <td class="num-col inv-stock-merge">
                                    <div class="inv-stock-display">
                                        <span class="inv-stock-value"><?= $fmtQty($i['stock_qty']) ?></span>
                                        <span class="inv-stock-badge inv-stock-badge--<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($statusLabel[$status] ?? '') ?></span>
                                        <button type="button" class="inv-kebab-btn" aria-label="Действия с остатком" title="Изменить остаток / История">⋯</button>
                                    </div>
                                    <div class="inv-stock-meta">
                                        <span class="inv-stock-meta-label">Порог</span>
                                        <input type="number" step="0.001" class="inv-threshold" value="<?= $fmtQty($i['reorder_threshold']) ?>" min="0" data-w="sm">
                                    </div>
                                    <div class="inv-adjust-controls" hidden>
                                        <input type="number" step="0.001" class="inv-adjust-delta" placeholder="±">
                                        <button type="button" class="admin-checkout-btn btn-inv-apply" data-adjust-action="apply">Применить</button>
                                        <button type="button" class="admin-checkout-btn btn-inv-history">История</button>
                                    </div>
                                </td>
                                <td class="num-col"><input type="number" step="0.0001" class="inv-cost" value="<?= $fmtCost($i['cost_per_unit']) ?>" min="0" data-w="md"></td>
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
                                    <?php if ($isArchived): ?>
                                        <button type="button" class="admin-checkout-btn btn-inv-restore">Вернуть</button>
                                    <?php else: ?>
                                        <button type="button" class="admin-checkout-btn cancel btn-inv-archive">Архив</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS — compact, click "Редактировать" → modal -->
            <div class="inv-mobile-list" id="invMobileList">
                <?php foreach ($ingredients as $i):
                    $isArchived = !empty($i['archived_at']);
                    $status     = $statusBucket($i);
                ?>
                <div class="inv-mcard"
                     data-ingredient-id="<?= (int)$i['id'] ?>"
                     data-supplier-id="<?= $i['supplier_id'] !== null ? (int)$i['supplier_id'] : '' ?>"
                     data-stock-status="<?= htmlspecialchars($status) ?>">
                    <div class="inv-mcard-head">
                        <input type="checkbox" class="inv-row-check" aria-label="Выбрать">
                        <div class="inv-mcard-name-block">
                            <div class="inv-mcard-name"><?= htmlspecialchars((string)$i['name']) ?></div>
                            <div class="inv-mcard-id">#<?= (int)$i['id'] ?></div>
                        </div>
                        <span class="inv-stock-badge inv-stock-badge--<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($statusLabel[$status] ?? '') ?></span>
                    </div>
                    <dl class="inv-mcard-meta">
                        <div class="inv-mcard-row">
                            <dt>Остаток</dt>
                            <dd><?= $fmtQty($i['stock_qty']) ?> <?= htmlspecialchars((string)$i['unit']) ?></dd>
                        </div>
                        <div class="inv-mcard-row">
                            <dt>Порог</dt>
                            <dd><?= $fmtQty($i['reorder_threshold']) ?> <?= htmlspecialchars((string)$i['unit']) ?></dd>
                        </div>
                        <div class="inv-mcard-row">
                            <dt>Цена/ед.</dt>
                            <dd><?= $fmtCost($i['cost_per_unit']) ?> ₽</dd>
                        </div>
                        <?php if (!empty($i['supplier_name'])): ?>
                        <div class="inv-mcard-row">
                            <dt>Поставщик</dt>
                            <dd><?= htmlspecialchars((string)$i['supplier_name']) ?></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                    <div class="inv-mcard-actions">
                        <button type="button" class="admin-checkout-btn js-edit-ing" data-ing-id="<?= (int)$i['id'] ?>">Редактировать</button>
                        <button type="button" class="admin-checkout-btn js-adjust-ing" data-ing-id="<?= (int)$i['id'] ?>">± Остаток</button>
                        <?php if ($isArchived): ?>
                            <button type="button" class="admin-checkout-btn btn-inv-restore">Вернуть</button>
                        <?php else: ?>
                            <button type="button" class="admin-checkout-btn cancel btn-inv-archive">Архив</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
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
            <div class="inv-table-wrapper desktop-table">
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
            </div>
            <!-- Phase 34.5: mobile-only toolbar with "+ Создать поставщика" -->
            <div class="inv-toolbar inv-toolbar--mobile">
                <button type="button" class="checkout-btn" id="invSupNewToggle" title="Создать нового поставщика">+ Создать поставщика</button>
            </div>

            <!-- Phase 34.5: slide-down supplier create panel (mobile-only) -->
            <div class="inv-create-panel inv-create-panel--mobile" id="invSupCreatePanel" hidden>
                <div class="inv-create-grid">
                    <label class="inv-create-field inv-create-field--wide">
                        <span class="inv-create-label">Название</span>
                        <input type="text" id="invSupNewName" placeholder="Напр.: Поставщик муки" maxlength="255">
                    </label>
                    <label class="inv-create-field inv-create-field--wide">
                        <span class="inv-create-label">Контакт</span>
                        <input type="text" id="invSupNewContact" placeholder="телефон / email" maxlength="255">
                    </label>
                    <label class="inv-create-field inv-create-field--wide">
                        <span class="inv-create-label">Заметки</span>
                        <input type="text" id="invSupNewNotes" placeholder="" maxlength="500">
                    </label>
                </div>
                <div class="inv-create-actions">
                    <button type="button" class="admin-checkout-btn cancel" id="invSupCreateCancel">Отмена</button>
                    <button type="button" class="checkout-btn" id="invSupCreateSubmit">Создать</button>
                </div>
            </div>

            <!-- Mobile supplier cards -->
            <div class="inv-mobile-list" id="invSuppliersMobile">
                <?php foreach ($suppliers as $sup): ?>
                    <div class="inv-mcard inv-mcard--supplier"
                         data-supplier-id="<?= (int)$sup['id'] ?>"
                         data-sup-name="<?= htmlspecialchars((string)$sup['name'], ENT_QUOTES) ?>"
                         data-sup-contact="<?= htmlspecialchars((string)($sup['contact'] ?? ''), ENT_QUOTES) ?>"
                         data-sup-notes="<?= htmlspecialchars((string)($sup['notes'] ?? ''), ENT_QUOTES) ?>">
                        <div class="inv-mcard-head">
                            <div class="inv-mcard-name-block">
                                <div class="inv-mcard-name"><?= htmlspecialchars((string)$sup['name']) ?></div>
                                <div class="inv-mcard-id">#<?= (int)$sup['id'] ?></div>
                            </div>
                        </div>
                        <dl class="inv-mcard-meta">
                            <?php if (!empty($sup['contact'])): ?>
                                <div class="inv-mcard-row">
                                    <dt>Контакт</dt>
                                    <dd><?= htmlspecialchars((string)$sup['contact']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($sup['notes'])): ?>
                                <div class="inv-mcard-row">
                                    <dt>Заметки</dt>
                                    <dd><?= htmlspecialchars((string)$sup['notes']) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                        <div class="inv-mcard-actions">
                            <button type="button" class="admin-checkout-btn js-edit-sup" data-sup-id="<?= (int)$sup['id'] ?>">Редактировать</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <!-- Phase 34.5: Edit-supplier modal — opened from mobile supplier cards -->
    <dialog id="invSupEditModal" class="design-modal" aria-labelledby="invSupEditModalTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="invSupEditModalTitle" class="modal-title">Редактировать поставщика</h2>
                    <p class="modal-subtitle" id="invSupEditSubtitle">—</p>
                </div>
                <button type="button" class="modal-close" data-inv-sup-modal-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-edit-form" id="invSupEditForm">
                <input type="hidden" id="invSupEditId" value="">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Название</span>
                    <input type="text" id="invSupEditName" maxlength="255" required>
                </label>
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Контакт</span>
                    <input type="text" id="invSupEditContact" maxlength="255" placeholder="телефон / email">
                </label>
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Заметки</span>
                    <input type="text" id="invSupEditNotes" maxlength="500">
                </label>
                <div id="invSupEditMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-inv-sup-modal-close>Отмена</button>
                <button type="button" class="checkout-btn" id="invSupEditSave">Сохранить</button>
            </footer>
        </div>
    </dialog>

    <!-- Phase 34.1: Edit-ingredient modal — opened from mobile cards (and optionally desktop) -->
    <dialog id="invEditModal" class="design-modal" aria-labelledby="invEditModalTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="invEditModalTitle" class="modal-title">Редактировать ингредиент</h2>
                    <p class="modal-subtitle" id="invEditSubtitle">—</p>
                </div>
                <button type="button" class="modal-close" data-inv-modal-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-edit-form" id="invEditForm">
                <input type="hidden" id="invEditId" value="">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Название</span>
                    <input type="text" id="invEditName" maxlength="255" required>
                </label>
                <div class="inv-edit-row">
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Единица</span>
                        <select id="invEditUnit">
                            <?php foreach ($unitOptions as $u): ?>
                                <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
                            <?php endforeach; ?>
                            <option value="__other__">Другое…</option>
                        </select>
                    </label>
                    <label class="inv-edit-field" id="invEditUnitOtherWrap" hidden>
                        <span class="inv-edit-label">Другое</span>
                        <input type="text" id="invEditUnitOther" maxlength="16" placeholder="напр. гр">
                    </label>
                </div>
                <div class="inv-edit-row">
                    <div class="inv-edit-field">
                        <span class="inv-edit-label">Текущий остаток</span>
                        <div class="inv-edit-readonly" id="invEditStockReadonly">—</div>
                    </div>
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Порог</span>
                        <input type="number" step="0.001" id="invEditThreshold" min="0">
                    </label>
                </div>
                <div class="inv-edit-row">
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Цена/ед., ₽</span>
                        <input type="number" step="0.0001" id="invEditCost" min="0">
                    </label>
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Поставщик</span>
                        <select id="invEditSupplier">
                            <option value="">—</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars((string)$sup['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="inv-edit-row">
                    <label class="inv-edit-field inv-edit-field--checkbox">
                        <span class="inv-edit-label">Меркурий</span>
                        <span class="inv-vsd-toggle-wrap">
                            <input type="checkbox" id="invEditRequiresVsd">
                            <span>Требует ВСД (мясо/рыба/молочка по 243-ФЗ)</span>
                        </span>
                    </label>
                </div>
                <div class="inv-edit-row">
                    <label class="inv-edit-field inv-edit-field--checkbox">
                        <span class="inv-edit-label">ЕГАИС</span>
                        <span class="inv-vsd-toggle-wrap">
                            <input type="checkbox" id="invEditIsAlcohol">
                            <span>Алкоголь (171-ФЗ)</span>
                        </span>
                    </label>
                    <label class="inv-edit-field" id="invEditAlcCodeWrap" hidden>
                        <span class="inv-edit-label">АСНА код</span>
                        <input type="text" id="invEditAlcCode" maxlength="64" placeholder="напр. 0123456789012345678">
                    </label>
                </div>
                <div class="inv-edit-row">
                    <label class="inv-edit-field inv-edit-field--checkbox">
                        <span class="inv-edit-label">Заготовки</span>
                        <span class="inv-vsd-toggle-wrap">
                            <input type="checkbox" id="invEditIsSemiFinished">
                            <span>Полуфабрикат (готовится из других ингредиентов)</span>
                        </span>
                    </label>
                    <label class="inv-edit-field" id="invEditYieldWrap" hidden>
                        <span class="inv-edit-label">Выход партии</span>
                        <input type="number" id="invEditYieldPerBatch" step="0.001" min="0" value="0" placeholder="напр. 5 (для 5 кг соуса)">
                    </label>
                </div>
                <p class="inv-edit-hint">Чтобы изменить остаток — используйте «± Остаток» на карточке ингредиента, тогда движение попадёт в аудит-лог. ВСД-записи редактируются на странице <a href="/admin/vsd.php">ВСД</a>.</p>
                <div id="invEditMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-inv-modal-close>Отмена</button>
                <button type="button" class="checkout-btn" id="invEditSave">Сохранить</button>
            </footer>
        </div>
    </dialog>

    <!-- Phase 34.1: Adjust-stock modal (mobile + as alternative kebab on desktop) -->
    <dialog id="invAdjustModal" class="design-modal" aria-labelledby="invAdjustModalTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="invAdjustModalTitle" class="modal-title">Изменить остаток</h2>
                    <p class="modal-subtitle" id="invAdjustSubtitle">—</p>
                </div>
                <button type="button" class="modal-close" data-inv-adjust-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-adjust-form" id="invAdjustForm">
                <input type="hidden" id="invAdjustId" value="">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Дельта (можно отрицательную)</span>
                    <input type="number" step="0.001" id="invAdjustDelta" placeholder="±N">
                </label>
                <p class="inv-edit-hint">Положительная дельта = приход (receipt), отрицательная = списание (waste). Запись попадает в stock_movements.</p>
                <div id="invAdjustMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-inv-adjust-close>Отмена</button>
                <button type="button" class="checkout-btn" id="invAdjustSave">Применить</button>
            </footer>
        </div>
    </dialog>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-inventory.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
