<?php
/**
 * admin/vsd.php — Phase 38 (Меркурий / ВСД, manual MVP).
 *
 * Layout:
 *   1. Section header (kicker / title / caption).
 *   2. Filter bar (status / ingredient / date range).
 *   3. Records table — id, дата, ВСД №, ингредиент, поставщик, кол-во, статус, действия.
 *   4. "+ Принять ВСД" modal (create new pending record).
 *   5. Accept / Reject modals on pending records.
 *
 * Role: admin/owner. Behind `inventory` plan feature (same as склад).
 *
 * Writes go through api/save-vsd.php.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';

// Phase L103.5e — gate behind inventory.vsd_mercury feature (tier 6+ «Кухня+»).
$l103_feature = \Cleanmenu\Billing\Features::INVENTORY_VSD;
$l103_label   = 'ВСД / Меркурий (ветеринарный регулятор)';
require __DIR__ . '/../partials/tier_paywall.php';

// Legacy gate stays for back-compat with PlanRegistry 'inventory' feature.
$gate_feature = 'inventory';
$gate_label   = 'Меркурий / ВСД';
require __DIR__ . '/../partials/billing_feature_gate.php';

$db = Database::getInstance();

$filterStatus     = (string)($_GET['status'] ?? '');
if (!in_array($filterStatus, ['pending', 'accepted', 'rejected'], true)) {
    $filterStatus = '';
}
$filterIngredient = isset($_GET['ingredient']) && $_GET['ingredient'] !== '' ? (int)$_GET['ingredient'] : null;
$filterFrom = (string)($_GET['from'] ?? '');
$filterTo   = (string)($_GET['to']   ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   $filterTo   = '';

$records   = $db->listVsdRecords(
    $filterStatus !== '' ? $filterStatus : null,
    $filterIngredient,
    $filterFrom !== '' ? $filterFrom : null,
    $filterTo !== '' ? $filterTo : null
);
$ingredients = $db->listVsdEligibleIngredients();

$siteName   = $GLOBALS['siteName'] ?? 'labus';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
$scriptNonce = $GLOBALS['scriptNonce'] ?? '';

$statusLabel = [
    'pending'  => 'Ожидает',
    'accepted' => 'Гашён',
    'rejected' => 'Отклонён',
];

$counts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0];
foreach ($records as $r) {
    $st = (string)($r['status'] ?? '');
    if (isset($counts[$st])) $counts[$st]++;
}

$fmtQty = static fn($v): string => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>ВСД (Меркурий) | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-design-modals.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-inventory.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-vsd.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/../account-header.php'; ?>

    <div class="account-container">
        <section class="account-section admin-section-card">
            <div class="admin-pane-header">
                <div class="admin-pane-header-copy">
                    <p class="admin-pane-kicker">Меркурий</p>
                    <h2 class="admin-pane-title">ВСД (ветеринарные документы)</h2>
                    <p class="admin-pane-caption">
                        Manual-режим: вручную регистрируем ВСД от поставщиков мясной/рыбной/молочной продукции, гасим при приёмке.
                        Гашение автоматически добавляет товар на склад (stock_movements: receipt). Авто-интеграция через Vetis API — следующий шаг.
                    </p>
                </div>
                <a href="/admin/inventory.php" class="back-to-menu-btn">К складу</a>
            </div>

            <div class="inv-summary-row" role="status" aria-label="Сводка ВСД">
                <div class="inv-summary-card<?= $counts['pending'] > 0 ? ' inv-summary-card--warn' : '' ?>">
                    <span class="inv-summary-label">Ожидает гашения</span>
                    <span class="inv-summary-value"><?= (int)$counts['pending'] ?></span>
                </div>
                <div class="inv-summary-card">
                    <span class="inv-summary-label">Гашено (в выборке)</span>
                    <span class="inv-summary-value"><?= (int)$counts['accepted'] ?></span>
                </div>
                <div class="inv-summary-card">
                    <span class="inv-summary-label">Отклонено</span>
                    <span class="inv-summary-value"><?= (int)$counts['rejected'] ?></span>
                </div>
            </div>

            <div class="inv-toolbar">
                <form method="get" class="vsd-filter-bar" id="vsdFilterBar">
                    <label class="vsd-filter-field">
                        <span class="vsd-filter-label">Статус</span>
                        <select name="status">
                            <option value="">— все —</option>
                            <?php foreach ($statusLabel as $k => $v): ?>
                                <option value="<?= htmlspecialchars($k) ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="vsd-filter-field">
                        <span class="vsd-filter-label">Ингредиент</span>
                        <select name="ingredient">
                            <option value="">— любой —</option>
                            <?php foreach ($ingredients as $i): ?>
                                <option value="<?= (int)$i['id'] ?>" <?= $filterIngredient === (int)$i['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$i['name']) ?><?= !empty($i['requires_vsd']) ? ' ★' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="vsd-filter-field">
                        <span class="vsd-filter-label">С даты</span>
                        <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
                    </label>
                    <label class="vsd-filter-field">
                        <span class="vsd-filter-label">По дату</span>
                        <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>">
                    </label>
                    <div class="vsd-filter-actions">
                        <button type="submit" class="admin-checkout-btn">Применить</button>
                        <a href="/admin/vsd.php" class="admin-checkout-btn cancel">Сбросить</a>
                    </div>
                </form>
                <div class="inv-toolbar-end">
                    <button type="button" class="checkout-btn" id="vsdCreateOpen">+ Принять ВСД</button>
                </div>
            </div>

            <?php if (empty($records)): ?>
                <p class="vsd-empty">Нет ВСД, удовлетворяющих фильтрам. Нажмите «+ Принять ВСД» чтобы добавить первый документ.</p>
            <?php else: ?>
                <div class="inv-table-wrapper desktop-table vsd-table-wrapper">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>ВСД №</th>
                                <th>Ингредиент</th>
                                <th>Поставщик</th>
                                <th>Кол-во</th>
                                <th>Статус</th>
                                <th class="last-col">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $r): ?>
                                <tr data-vsd-id="<?= (int)$r['id'] ?>"
                                    data-vsd-status="<?= htmlspecialchars((string)$r['status']) ?>">
                                    <td><?= htmlspecialchars((string)$r['vsd_date']) ?></td>
                                    <td class="vsd-num"><?= htmlspecialchars((string)$r['vsd_number']) ?></td>
                                    <td><?= htmlspecialchars((string)($r['ingredient_name'] ?? '—')) ?></td>
                                    <td>
                                        <?php if (!empty($r['supplier_name'])): ?>
                                            <?= htmlspecialchars((string)$r['supplier_name']) ?>
                                        <?php elseif (!empty($r['supplier_inn'])): ?>
                                            ИНН <?= htmlspecialchars((string)$r['supplier_inn']) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $fmtQty($r['quantity']) ?>
                                        <?= htmlspecialchars((string)($r['unit'] ?? $r['ingredient_unit'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <span class="vsd-status-badge vsd-status-<?= htmlspecialchars((string)$r['status']) ?>">
                                            <?= htmlspecialchars($statusLabel[$r['status']] ?? $r['status']) ?>
                                        </span>
                                    </td>
                                    <td class="vsd-actions-cell">
                                        <?php if ($r['status'] === 'pending'): ?>
                                            <button type="button" class="checkout-btn js-vsd-accept" data-vsd-id="<?= (int)$r['id'] ?>">Гашение</button>
                                            <button type="button" class="admin-checkout-btn cancel js-vsd-reject" data-vsd-id="<?= (int)$r['id'] ?>">Отклонить</button>
                                        <?php elseif (!empty($r['accepted_at'])): ?>
                                            <span class="vsd-meta">
                                                <?= date('d.m.Y H:i', strtotime((string)$r['accepted_at'])) ?>
                                                <?php if (!empty($r['accepter_name'])): ?>
                                                    · <?= htmlspecialchars((string)$r['accepter_name']) ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="vsd-meta">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Create VSD modal -->
    <dialog id="vsdCreateModal" class="design-modal" aria-labelledby="vsdCreateTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="vsdCreateTitle" class="modal-title">Принять ВСД</h2>
                    <p class="modal-subtitle">Регистрируем документ от поставщика. Статус → «Ожидает гашения».</p>
                </div>
                <button type="button" class="modal-close" data-vsd-modal-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-edit-form" id="vsdCreateForm">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Ингредиент</span>
                    <select id="vsdCreateIngredient" required>
                        <option value="">— выберите —</option>
                        <?php foreach ($ingredients as $i): ?>
                            <option value="<?= (int)$i['id'] ?>"
                                    data-unit="<?= htmlspecialchars((string)$i['unit']) ?>"
                                    data-requires="<?= !empty($i['requires_vsd']) ? '1' : '0' ?>">
                                <?= htmlspecialchars((string)$i['name']) ?><?= !empty($i['requires_vsd']) ? ' ★ требует ВСД' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="inv-edit-row">
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">ВСД №</span>
                        <input type="text" id="vsdCreateNumber" maxlength="64" required placeholder="напр. 1234-5678">
                    </label>
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Дата выдачи</span>
                        <input type="date" id="vsdCreateDate" required value="<?= date('Y-m-d') ?>">
                    </label>
                </div>
                <div class="inv-edit-row">
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Кол-во</span>
                        <input type="number" step="0.001" min="0" id="vsdCreateQty" value="0">
                    </label>
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Ед. изм. (опционально)</span>
                        <input type="text" id="vsdCreateUnit" maxlength="16" placeholder="из ингредиента">
                    </label>
                </div>
                <div class="inv-edit-row">
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Поставщик (название)</span>
                        <input type="text" id="vsdCreateSupplierName" maxlength="255" placeholder="напр. ООО Мясокомбинат">
                    </label>
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">ИНН поставщика</span>
                        <input type="text" id="vsdCreateSupplierInn" maxlength="16" placeholder="10 или 12 цифр" pattern="\d{10}|\d{12}">
                    </label>
                </div>
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Заметки</span>
                    <input type="text" id="vsdCreateNotes" maxlength="500">
                </label>
                <div id="vsdCreateMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-vsd-modal-close>Отмена</button>
                <button type="button" class="checkout-btn" id="vsdCreateSubmit">Сохранить</button>
            </footer>
        </div>
    </dialog>

    <!-- Reject reason modal -->
    <dialog id="vsdRejectModal" class="design-modal" aria-labelledby="vsdRejectTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="vsdRejectTitle" class="modal-title">Отклонить ВСД</h2>
                    <p class="modal-subtitle">Запись останется в истории со статусом «Отклонён». На склад товар не попадёт.</p>
                </div>
                <button type="button" class="modal-close" data-vsd-modal-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-edit-form" id="vsdRejectForm">
                <input type="hidden" id="vsdRejectId" value="">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Причина</span>
                    <input type="text" id="vsdRejectReason" maxlength="255" placeholder="напр. товар не соответствует партии">
                </label>
                <div id="vsdRejectMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-vsd-modal-close>Отмена</button>
                <button type="button" class="checkout-btn" id="vsdRejectSubmit">Отклонить</button>
            </footer>
        </div>
    </dialog>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-vsd.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
