<?php
/**
 * admin/stocktake.php — Phase 41 (физическая инвентаризация).
 *
 * Если есть open session — показываем её table с counted_qty inputs.
 * Если нет — список past sessions + кнопка «Начать инвентаризацию».
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';

// Phase L103.5e — gate behind inventory.stocktake feature (tier 6+ «Кухня+»).
$l103_feature = \Cleanmenu\Billing\Features::INVENTORY_STOCKTAKE;
$l103_label   = 'Физическая инвентаризация';
require __DIR__ . '/../partials/tier_paywall.php';

// Legacy gate stays for back-compat with PlanRegistry 'inventory' feature.
$gate_feature = 'inventory';
$gate_label   = 'Инвентаризация';
require __DIR__ . '/../partials/billing_feature_gate.php';

$db = Database::getInstance();
$openSession = $db->getOpenStocktakeSession();
$sessions = $db->listStocktakeSessions(30);
$sessionItems = $openSession ? $db->getStocktakeItems((int)$openSession['id']) : [];

$siteName    = $GLOBALS['siteName'] ?? 'labus';
$appVersion  = (string)($_SESSION['app_version'] ?? '1.0.0');
$scriptNonce = $GLOBALS['scriptNonce'] ?? '';

$statusLabel = [
    'open' => 'Открыта',
    'closed' => 'Закрыта',
    'cancelled' => 'Отменена',
];

$fmtQty = static fn($v): string => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Инвентаризация | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-design-modals.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-inventory.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-vsd.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-stocktake.css?v=<?= htmlspecialchars($appVersion) ?>">
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
                    <h2 class="admin-pane-title">Инвентаризация</h2>
                    <p class="admin-pane-caption">
                        Физический пересчёт остатков по ингредиентам. При закрытии сессии расхождения (counted − expected)
                        применяются к stock_qty через stock_movements с reason=stocktake.
                    </p>
                </div>
                <a href="/admin/inventory.php" class="back-to-menu-btn">К складу</a>
            </div>

            <?php if ($openSession): ?>
                <div class="stocktake-open-banner">
                    <div>
                        <strong>Открытая сессия:</strong> <?= htmlspecialchars((string)$openSession['name']) ?>
                        <span class="integrations-meta">
                            (старт: <?= htmlspecialchars((string)$openSession['started_at']) ?>
                            <?php if (!empty($openSession['started_by_name'])): ?>
                                · <?= htmlspecialchars((string)$openSession['started_by_name']) ?>
                            <?php endif; ?>
                            · посчитано <?= (int)$openSession['items_counted'] ?> / <?= (int)$openSession['items_total'] ?>)
                        </span>
                    </div>
                    <div class="stocktake-actions">
                        <button type="button" class="checkout-btn js-stocktake-close" data-session-id="<?= (int)$openSession['id'] ?>">Закрыть и применить</button>
                        <button type="button" class="admin-checkout-btn cancel js-stocktake-cancel" data-session-id="<?= (int)$openSession['id'] ?>">Отменить сессию</button>
                    </div>
                </div>

                <div class="inv-table-wrapper desktop-table stocktake-table-wrapper">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th>Ингредиент</th>
                                <th>Ед.</th>
                                <th>Ожидаемое</th>
                                <th>Факт</th>
                                <th>Расхождение</th>
                                <th class="last-col">Кто посчитал</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessionItems as $it): ?>
                                <?php
                                $variance = $it['counted_qty'] === null
                                    ? null
                                    : ((float)$it['counted_qty'] - (float)$it['expected_qty']);
                                $varianceClass = '';
                                if ($variance !== null) {
                                    if (abs($variance) < 0.0005) $varianceClass = 'st-variance-zero';
                                    elseif ($variance > 0) $varianceClass = 'st-variance-pos';
                                    else $varianceClass = 'st-variance-neg';
                                }
                                ?>
                                <tr data-item-id="<?= (int)$it['id'] ?>"
                                    data-session-id="<?= (int)$openSession['id'] ?>"
                                    data-ingredient-id="<?= (int)$it['ingredient_id'] ?>"
                                    data-expected="<?= htmlspecialchars((string)$it['expected_qty']) ?>">
                                    <td><?= htmlspecialchars((string)$it['ingredient_name']) ?></td>
                                    <td><?= htmlspecialchars((string)$it['ingredient_unit']) ?></td>
                                    <td><?= $fmtQty($it['expected_qty']) ?></td>
                                    <td>
                                        <input type="number" step="0.001" min="0"
                                               class="js-stocktake-counted st-counted-input"
                                               value="<?= htmlspecialchars((string)($it['counted_qty'] ?? '')) ?>"
                                               placeholder="—">
                                    </td>
                                    <td class="js-stocktake-variance <?= $varianceClass ?>">
                                        <?php if ($variance === null): ?>
                                            —
                                        <?php else: ?>
                                            <?= $variance > 0 ? '+' : '' ?><?= $fmtQty($variance) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="integrations-meta">
                                        <?php if (!empty($it['counted_by_name'])): ?>
                                            <?= htmlspecialchars((string)$it['counted_by_name']) ?>
                                            · <?= htmlspecialchars((string)$it['counted_at']) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="inv-toolbar">
                    <p class="integrations-meta" style="flex:1;">Нет открытой сессии. Начните новую, чтобы зафиксировать текущие остатки и пересчитать их фактически.</p>
                    <div class="inv-toolbar-end">
                        <button type="button" class="checkout-btn js-stocktake-start">+ Начать инвентаризацию</button>
                    </div>
                </div>

                <?php if (!empty($sessions)): ?>
                    <div class="inv-table-wrapper desktop-table stocktake-history">
                        <table class="inv-table">
                            <thead>
                                <tr>
                                    <th>Сессия</th>
                                    <th>Старт</th>
                                    <th>Закрыта</th>
                                    <th>Кто</th>
                                    <th>Позиций (учёт/всего)</th>
                                    <th class="last-col">Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $s): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)$s['name']) ?></td>
                                        <td><?= htmlspecialchars((string)$s['started_at']) ?></td>
                                        <td><?= htmlspecialchars((string)($s['closed_at'] ?? '—')) ?></td>
                                        <td><?= htmlspecialchars((string)($s['started_by_name'] ?? '—')) ?></td>
                                        <td><?= (int)$s['items_counted'] ?> / <?= (int)$s['items_total'] ?></td>
                                        <td>
                                            <span class="vsd-status-badge vsd-status-<?= $s['status'] === 'closed' ? 'accepted' : ($s['status'] === 'open' ? 'pending' : 'rejected') ?>">
                                                <?= htmlspecialchars($statusLabel[$s['status']] ?? $s['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <!-- Start session modal -->
    <dialog id="stocktakeStartModal" class="design-modal" aria-labelledby="stocktakeStartTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="stocktakeStartTitle" class="modal-title">Начать инвентаризацию</h2>
                    <p class="modal-subtitle">Будет создан snapshot текущих остатков всех активных ингредиентов.</p>
                </div>
                <button type="button" class="modal-close" data-stocktake-modal-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-edit-form" id="stocktakeStartForm">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Название сессии</span>
                    <input type="text" id="stocktakeStartName" maxlength="128" value="Инвентаризация <?= date('Y-m-d') ?>">
                </label>
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Заметки</span>
                    <input type="text" id="stocktakeStartNotes" maxlength="500" placeholder="опционально">
                </label>
                <div id="stocktakeStartMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-stocktake-modal-close>Отмена</button>
                <button type="button" class="checkout-btn" id="stocktakeStartSubmit">Создать</button>
            </footer>
        </div>
    </dialog>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-stocktake.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
