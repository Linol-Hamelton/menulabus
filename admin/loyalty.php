<?php
/**
 * admin/loyalty.php — admin UI for loyalty tiers + promo codes (Phase 6.3).
 *
 * Panels:
 *   1. Loyalty tiers — name / min_spent / cashback_pct / sort_order.
 *   2. Promo codes — code / discount (pct OR amount) / window / usage cap.
 *
 * Both panels CRUD through api/save-loyalty.php.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';

// Phase 14.8 — gate behind 'loyalty' plan feature.
$gate_feature = 'loyalty';
$gate_label   = 'Программа лояльности';
require __DIR__ . '/../partials/billing_feature_gate.php';

$db     = Database::getInstance();
$tiers  = $db->listLoyaltyTiers(false);
$promos = $db->listPromoCodes(false);

$siteName   = $GLOBALS['siteName'] ?? 'labus';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Лояльность | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-loyalty.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/../account-header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <div class="section-header-menu">
                <h2>Программа лояльности</h2>
                <a href="/admin/menu.php" class="back-to-menu-btn">К админке</a>
            </div>
            <p class="loyalty-intro">
                Уровни (тиры) — кто и сколько баллов получает за заказ. Тир назначается
                автоматически, когда суммарные траты клиента превышают <code>min_spent</code>.
                Баллы = <code>order_total × cashback_pct / 100</code>, списываются 1 к 1 при оплате.
            </p>

            <h3>Тиры</h3>
            <table class="loyalty-tiers-table" id="loyaltyTiersTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th class="num-col">Порог (₽)</th>
                        <th class="num-col">Cashback, %</th>
                        <th class="num-col">Порядок</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tiers as $t): ?>
                        <tr data-tier-id="<?= (int)$t['id'] ?>">
                            <td>#<?= (int)$t['id'] ?></td>
                            <td><input type="text" class="t-name" value="<?= htmlspecialchars((string)$t['name']) ?>" maxlength="64"></td>
                            <td class="num-col"><input type="number" class="t-min" step="0.01" min="0" value="<?= htmlspecialchars((string)$t['min_spent']) ?>"></td>
                            <td class="num-col"><input type="number" class="t-cb" step="0.01" min="0" max="100" value="<?= htmlspecialchars((string)$t['cashback_pct']) ?>"></td>
                            <td class="num-col"><input type="number" class="t-sort" step="1" min="0" value="<?= (int)$t['sort_order'] ?>" data-w="2xs"></td>
                            <td>
                                <button type="button" class="admin-checkout-btn btn-t-save">Сохранить</button>
                                <button type="button" class="admin-checkout-btn cancel btn-t-archive">Архив</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="loyalty-new-row" data-tier-id="">
                        <td>—</td>
                        <td><input type="text" class="t-name" placeholder="Silver" maxlength="64"></td>
                        <td class="num-col"><input type="number" class="t-min" step="0.01" min="0" value="0"></td>
                        <td class="num-col"><input type="number" class="t-cb" step="0.01" min="0" max="100" value="0"></td>
                        <td class="num-col"><input type="number" class="t-sort" step="1" min="0" value="0" data-w="2xs"></td>
                        <td><button type="button" class="admin-checkout-btn btn-t-save">Создать</button></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="account-section">
            <div class="section-header-menu">
                <h3>Промо-коды</h3>
                <small>Либо процент, либо фиксированная сумма — не одновременно.</small>
            </div>

            <?php if (empty($promos)): ?>
                <div class="loyalty-promos-empty">
                    <p>Промо-кодов ещё нет. Заполните строку ниже, чтобы создать первый.</p>
                </div>
            <?php endif; ?>

            <table class="loyalty-promos-table<?= empty($promos) ? ' loyalty-promos-table--new-only' : '' ?>" id="loyaltyPromosTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Код</th>
                        <th class="num-col">%</th>
                        <th class="num-col">₽</th>
                        <th class="num-col">Мин. чек</th>
                        <th>С</th>
                        <th>По</th>
                        <th class="num-col">Лимит</th>
                        <th class="num-col">Использ.</th>
                        <th>Описание</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promos as $p): ?>
                        <tr data-promo-id="<?= (int)$p['id'] ?>">
                            <td>#<?= (int)$p['id'] ?></td>
                            <td><input type="text" class="p-code" value="<?= htmlspecialchars((string)$p['code']) ?>" maxlength="64" data-w="xl" data-uppercase=""></td>
                            <td class="num-col"><input type="number" step="0.01" min="0" max="100" class="p-pct" value="<?= $p['discount_pct'] !== null ? htmlspecialchars((string)$p['discount_pct']) : '' ?>" data-w="xs"></td>
                            <td class="num-col"><input type="number" step="0.01" min="0" class="p-amt" value="<?= $p['discount_amount'] !== null ? htmlspecialchars((string)$p['discount_amount']) : '' ?>" data-w="sm"></td>
                            <td class="num-col"><input type="number" step="0.01" min="0" class="p-min-total" value="<?= htmlspecialchars((string)$p['min_order_total']) ?>" data-w="sm"></td>
                            <td><input type="datetime-local" class="p-from" value="<?= $p['valid_from'] ? htmlspecialchars(str_replace(' ', 'T', (string)$p['valid_from'])) : '' ?>"></td>
                            <td><input type="datetime-local" class="p-to" value="<?= $p['valid_to'] ? htmlspecialchars(str_replace(' ', 'T', (string)$p['valid_to'])) : '' ?>"></td>
                            <td class="num-col"><input type="number" step="1" min="0" class="p-limit" value="<?= (int)$p['usage_limit'] ?>" data-w="xs"></td>
                            <td class="num-col"><?= (int)$p['used_count'] ?></td>
                            <td><input type="text" class="p-desc" value="<?= htmlspecialchars((string)($p['description'] ?? '')) ?>" maxlength="255"></td>
                            <td>
                                <button type="button" class="admin-checkout-btn btn-p-save">Сохранить</button>
                                <button type="button" class="admin-checkout-btn cancel btn-p-archive">Архив</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="loyalty-new-row" data-promo-id="">
                        <td>—</td>
                        <td><input type="text" class="p-code" placeholder="NEWCODE" maxlength="64" data-w="xl" data-uppercase=""></td>
                        <td class="num-col"><input type="number" step="0.01" min="0" max="100" class="p-pct" placeholder="10" data-w="xs"></td>
                        <td class="num-col"><input type="number" step="0.01" min="0" class="p-amt" placeholder="" data-w="sm"></td>
                        <td class="num-col"><input type="number" step="0.01" min="0" class="p-min-total" value="0" data-w="sm"></td>
                        <td><input type="datetime-local" class="p-from"></td>
                        <td><input type="datetime-local" class="p-to"></td>
                        <td class="num-col"><input type="number" step="1" min="0" class="p-limit" value="0" data-w="xs"></td>
                        <td class="num-col">0</td>
                        <td><input type="text" class="p-desc" placeholder="Промо-кампания" maxlength="255"></td>
                        <td><button type="button" class="admin-checkout-btn btn-p-save">Создать</button></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-loyalty.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
