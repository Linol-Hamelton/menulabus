<?php
/**
 * admin/egais.php — Phase 39 (ЕГАИС / 171-ФЗ алкоголь, manual MVP).
 *
 * Three tabs:
 *   1. Накладные (ТТН) — list of alc_invoices + create/accept/reject
 *   2. Акты вскрытия — list of alc_openings (по дате/смене)
 *   3. Отчёт об остатках — за период (default: текущий месяц)
 *
 * Role: admin/owner. Behind `inventory` plan feature (same as склад).
 * Writes go through api/save-egais.php.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';

$gate_feature = 'inventory';
$gate_label   = 'ЕГАИС / алкоголь';
require __DIR__ . '/../partials/billing_feature_gate.php';

$db = Database::getInstance();

$tab = (string)($_GET['tab'] ?? 'invoices');
if (!in_array($tab, ['invoices', 'openings', 'stock_report'], true)) $tab = 'invoices';

$filterStatus = (string)($_GET['status'] ?? '');
if (!in_array($filterStatus, ['pending', 'accepted', 'rejected'], true)) $filterStatus = '';

$invoices  = ($tab === 'invoices')
    ? $db->listAlcInvoices($filterStatus !== '' ? $filterStatus : null)
    : [];
$openings  = ($tab === 'openings')
    ? $db->listAlcOpenings()
    : [];

$reportFrom = (string)($_GET['from'] ?? date('Y-m-01'));
$reportTo   = (string)($_GET['to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportFrom)) $reportFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportTo))   $reportTo   = date('Y-m-d');
$stockReport = ($tab === 'stock_report')
    ? $db->getEgaisStockReport($reportFrom, $reportTo)
    : [];

$alcIngredients = $db->listAlcoholIngredients();

$siteName    = $GLOBALS['siteName'] ?? 'labus';
$appVersion  = (string)($_SESSION['app_version'] ?? '1.0.0');
$scriptNonce = $GLOBALS['scriptNonce'] ?? '';

$statusLabel = ['pending' => 'Ожидает', 'accepted' => 'Принята', 'rejected' => 'Отклонена'];

$invCounts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0];
foreach ($invoices as $r) {
    $st = (string)($r['status'] ?? '');
    if (isset($invCounts[$st])) $invCounts[$st]++;
}

$fmtQty = static fn($v): string => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
$fmtMoney = static fn($v): string => number_format((float)$v, 2, '.', ' ') . ' ₽';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>ЕГАИС (алкоголь) | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-design-modals.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-inventory.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-vsd.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-egais.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/../account-header.php'; ?>

    <div class="account-container">
        <section class="account-section admin-section-card">
            <div class="admin-pane-header">
                <div class="admin-pane-header-copy">
                    <p class="admin-pane-kicker">ЕГАИС</p>
                    <h2 class="admin-pane-title">Алкоголь (171-ФЗ)</h2>
                    <p class="admin-pane-caption">
                        Manual-режим: ТТН-накладные с поставщиков + акты вскрытия тары + отчёт об остатках.
                        Авто-интеграция через УТМ / Контур.ЕГАИС API — следующий шаг.
                    </p>
                </div>
                <a href="/admin/inventory.php" class="back-to-menu-btn">К складу</a>
            </div>

            <div class="egais-tabs" role="tablist">
                <a href="?tab=invoices" role="tab" class="egais-tab <?= $tab === 'invoices' ? 'is-active' : '' ?>">Накладные</a>
                <a href="?tab=openings" role="tab" class="egais-tab <?= $tab === 'openings' ? 'is-active' : '' ?>">Акты вскрытия</a>
                <a href="?tab=stock_report" role="tab" class="egais-tab <?= $tab === 'stock_report' ? 'is-active' : '' ?>">Отчёт об остатках</a>
            </div>

            <?php if ($tab === 'invoices'): ?>
                <div class="inv-summary-row" role="status">
                    <div class="inv-summary-card<?= $invCounts['pending'] > 0 ? ' inv-summary-card--warn' : '' ?>">
                        <span class="inv-summary-label">Ожидает принятия</span>
                        <span class="inv-summary-value"><?= (int)$invCounts['pending'] ?></span>
                    </div>
                    <div class="inv-summary-card">
                        <span class="inv-summary-label">Принято (в выборке)</span>
                        <span class="inv-summary-value"><?= (int)$invCounts['accepted'] ?></span>
                    </div>
                    <div class="inv-summary-card">
                        <span class="inv-summary-label">Отклонено</span>
                        <span class="inv-summary-value"><?= (int)$invCounts['rejected'] ?></span>
                    </div>
                </div>

                <div class="inv-toolbar">
                    <form method="get" class="vsd-filter-bar">
                        <input type="hidden" name="tab" value="invoices">
                        <label class="vsd-filter-field">
                            <span class="vsd-filter-label">Статус</span>
                            <select name="status">
                                <option value="">— все —</option>
                                <?php foreach ($statusLabel as $k => $v): ?>
                                    <option value="<?= htmlspecialchars($k) ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="vsd-filter-actions">
                            <button type="submit" class="admin-checkout-btn">Применить</button>
                            <a href="/admin/egais.php?tab=invoices" class="admin-checkout-btn cancel">Сбросить</a>
                        </div>
                    </form>
                    <div class="inv-toolbar-end">
                        <button type="button" class="checkout-btn" id="egaisCreateInvoiceOpen">+ Принять ТТН</button>
                    </div>
                </div>

                <?php if (empty($invoices)): ?>
                    <p class="vsd-empty">Накладных по фильтру нет. Нажмите «+ Принять ТТН».</p>
                <?php else: ?>
                    <div class="inv-table-wrapper desktop-table vsd-table-wrapper">
                        <table class="inv-table">
                            <thead>
                                <tr>
                                    <th>Дата</th>
                                    <th>ТТН №</th>
                                    <th>Поставщик</th>
                                    <th>Позиций</th>
                                    <th>Сумма</th>
                                    <th>Статус</th>
                                    <th class="last-col">Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoices as $inv): ?>
                                    <tr data-invoice-id="<?= (int)$inv['id'] ?>">
                                        <td><?= htmlspecialchars((string)$inv['ttn_date']) ?></td>
                                        <td class="vsd-num"><?= htmlspecialchars((string)$inv['ttn_number']) ?></td>
                                        <td>
                                            <?php if (!empty($inv['supplier_name'])): ?>
                                                <?= htmlspecialchars((string)$inv['supplier_name']) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($inv['supplier_inn'])): ?>
                                                <small class="egais-inn">ИНН <?= htmlspecialchars((string)$inv['supplier_inn']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int)($inv['items_count'] ?? 0) ?></td>
                                        <td><?= $fmtMoney($inv['total_amount']) ?></td>
                                        <td>
                                            <span class="vsd-status-badge vsd-status-<?= htmlspecialchars((string)$inv['status']) ?>">
                                                <?= htmlspecialchars($statusLabel[$inv['status']] ?? $inv['status']) ?>
                                            </span>
                                        </td>
                                        <td class="vsd-actions-cell">
                                            <?php if ($inv['status'] === 'pending'): ?>
                                                <button type="button" class="checkout-btn js-egais-accept" data-invoice-id="<?= (int)$inv['id'] ?>">Принять</button>
                                                <button type="button" class="admin-checkout-btn cancel js-egais-reject" data-invoice-id="<?= (int)$inv['id'] ?>">Отклонить</button>
                                            <?php elseif (!empty($inv['accepted_at'])): ?>
                                                <span class="vsd-meta">
                                                    <?= date('d.m.Y H:i', strtotime((string)$inv['accepted_at'])) ?>
                                                    <?php if (!empty($inv['accepter_name'])): ?>
                                                        · <?= htmlspecialchars((string)$inv['accepter_name']) ?>
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
            <?php elseif ($tab === 'openings'): ?>
                <?php if (empty($openings)): ?>
                    <p class="vsd-empty">Актов вскрытия нет. Кассир может вскрыть бутылку из dock на /employee.php (если смена открыта).</p>
                <?php else: ?>
                    <div class="inv-table-wrapper desktop-table vsd-table-wrapper">
                        <table class="inv-table">
                            <thead>
                                <tr>
                                    <th>Когда</th>
                                    <th>Продукт</th>
                                    <th>АСНА</th>
                                    <th>Объём</th>
                                    <th>Смена</th>
                                    <th class="last-col">Кто вскрыл</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($openings as $o): ?>
                                    <tr data-opening-id="<?= (int)$o['id'] ?>">
                                        <td><?= date('d.m.Y H:i', strtotime((string)$o['opened_at'])) ?></td>
                                        <td><?= htmlspecialchars((string)($o['ingredient_name'] ?? '—')) ?></td>
                                        <td class="vsd-num"><?= htmlspecialchars((string)($o['alc_code'] ?? '—')) ?></td>
                                        <td><?= (int)$o['bottle_volume_ml'] ?> мл</td>
                                        <td><?= $o['shift_id'] ? '#' . (int)$o['shift_id'] : '—' ?></td>
                                        <td><?= htmlspecialchars((string)($o['opener_name'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php else: /* stock_report */ ?>
                <div class="inv-toolbar">
                    <form method="get" class="vsd-filter-bar">
                        <input type="hidden" name="tab" value="stock_report">
                        <label class="vsd-filter-field">
                            <span class="vsd-filter-label">С даты</span>
                            <input type="date" name="from" value="<?= htmlspecialchars($reportFrom) ?>" required>
                        </label>
                        <label class="vsd-filter-field">
                            <span class="vsd-filter-label">По дату</span>
                            <input type="date" name="to" value="<?= htmlspecialchars($reportTo) ?>" required>
                        </label>
                        <div class="vsd-filter-actions">
                            <button type="submit" class="admin-checkout-btn">Применить</button>
                        </div>
                    </form>
                </div>
                <?php if (empty($stockReport)): ?>
                    <p class="vsd-empty">
                        Алкогольных ингредиентов нет. Включите «Алкоголь (ЕГАИС)» на ингредиенте в /admin/inventory.php → edit.
                    </p>
                <?php else: ?>
                    <div class="inv-table-wrapper desktop-table vsd-table-wrapper">
                        <table class="inv-table">
                            <thead>
                                <tr>
                                    <th>Продукт</th>
                                    <th>АСНА</th>
                                    <th>Принято (по ТТН)</th>
                                    <th>Вскрыто (бут.)</th>
                                    <th class="last-col">Текущий остаток</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stockReport as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)$r['name']) ?></td>
                                        <td class="vsd-num"><?= htmlspecialchars((string)($r['alc_code'] ?? '—')) ?></td>
                                        <td><?= $fmtQty($r['received_qty']) ?> <?= htmlspecialchars((string)$r['unit']) ?></td>
                                        <td><?= (int)$r['opened_count'] ?></td>
                                        <td><?= $fmtQty($r['stock_qty']) ?> <?= htmlspecialchars((string)$r['unit']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <!-- Create invoice modal -->
    <dialog id="egaisInvoiceModal" class="design-modal" aria-labelledby="egaisInvoiceTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="egaisInvoiceTitle" class="modal-title">Принять ТТН</h2>
                    <p class="modal-subtitle">Регистрируем накладную поставщика. После приёмки бутылки добавятся в склад.</p>
                </div>
                <button type="button" class="modal-close" data-egais-modal-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-edit-form" id="egaisInvoiceForm">
                <div class="inv-edit-row">
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">ТТН №</span>
                        <input type="text" id="egaisTtnNumber" maxlength="64" required placeholder="напр. ТТН-2026-0042">
                    </label>
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Дата ТТН</span>
                        <input type="date" id="egaisTtnDate" required value="<?= date('Y-m-d') ?>">
                    </label>
                </div>
                <div class="inv-edit-row">
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">Поставщик (название)</span>
                        <input type="text" id="egaisSupplierName" maxlength="255" placeholder="напр. ООО Алкоопт">
                    </label>
                    <label class="inv-edit-field">
                        <span class="inv-edit-label">ИНН поставщика</span>
                        <input type="text" id="egaisSupplierInn" maxlength="16" required placeholder="10 или 12 цифр" pattern="\d{10}|\d{12}">
                    </label>
                </div>
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Заметки</span>
                    <input type="text" id="egaisNotes" maxlength="500">
                </label>

                <div class="egais-items">
                    <div class="egais-items-head">
                        <span class="inv-edit-label">Позиции (минимум 1)</span>
                        <button type="button" class="admin-checkout-btn js-egais-add-item">+ Добавить позицию</button>
                    </div>
                    <div id="egaisItemsList"></div>
                </div>

                <div id="egaisInvoiceMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-egais-modal-close>Отмена</button>
                <button type="button" class="checkout-btn" id="egaisInvoiceSubmit">Сохранить</button>
            </footer>
        </div>
    </dialog>

    <!-- Reject modal -->
    <dialog id="egaisRejectModal" class="design-modal" aria-labelledby="egaisRejectTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="egaisRejectTitle" class="modal-title">Отклонить ТТН</h2>
                    <p class="modal-subtitle">Запись останется в истории. На склад товар не попадёт.</p>
                </div>
                <button type="button" class="modal-close" data-egais-modal-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-edit-form" id="egaisRejectForm">
                <input type="hidden" id="egaisRejectInvoiceId" value="">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Причина</span>
                    <input type="text" id="egaisRejectReason" maxlength="255" placeholder="напр. товар не соответствует ТТН">
                </label>
                <div id="egaisRejectMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-egais-modal-close>Отмена</button>
                <button type="button" class="checkout-btn" id="egaisRejectSubmit">Отклонить</button>
            </footer>
        </div>
    </dialog>

    <!-- Hidden data for JS (ingredient list with alc) -->
    <script type="application/json" id="egaisAlcIngredients" nonce="<?= $scriptNonce ?>">
        <?= json_encode(array_map(static fn($i) => [
            'id'   => (int)$i['id'],
            'name' => (string)$i['name'],
            'unit' => (string)$i['unit'],
            'code' => (string)($i['alc_code'] ?? ''),
        ], $alcIngredients), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>
    </script>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-egais.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
