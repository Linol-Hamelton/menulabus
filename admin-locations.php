<?php
/**
 * admin-locations.php — admin CRUD for restaurant locations (Phase 6.5).
 *
 * Minimal interface: one inline-editable table. Deactivating a location is
 * soft (flips `active=0`); history is preserved.
 */

$required_role = 'admin';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$locations = $db->listLocations(false);
$summary   = $db->getOrdersByLocationSummary(
    date('Y-m-d 00:00:00', strtotime('-30 days')),
    date('Y-m-d 00:00:00', strtotime('+1 day'))
);

$summaryById = [];
foreach ($summary as $s) { $summaryById[(int)$s['location_id']] = $s; }

$siteName   = $GLOBALS['siteName'] ?? 'labus';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Локации | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-locations.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <div class="section-header-menu">
                <h2>Локации (сеть ресторанов)</h2>
                <a href="/admin-menu.php" class="back-to-menu-btn">К админке</a>
            </div>
            <p class="loc-intro">
                Добавьте точки сети. Заказы и меню-позиции могут быть привязаны к конкретной локации
                (необязательно: блюда без <code>location_id</code> отображаются во всех локациях).
                Модель: <em>1 клиент = 1 база данных</em> — сеть живёт внутри одной тенант-БД.
            </p>

            <h3>Локации</h3>
            <table class="locations-table" id="locationsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Адрес</th>
                        <th>Телефон</th>
                        <th>Часовой пояс</th>
                        <th class="num-col">Заказов (30д)</th>
                        <th class="num-col">Выручка</th>
                        <th>Порядок</th>
                        <th>Активна</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($locations as $l): ?>
                        <?php $s = $summaryById[(int)$l['id']] ?? ['orders_count' => 0, 'revenue' => 0]; ?>
                        <tr data-location-id="<?= (int)$l['id'] ?>">
                            <td>#<?= (int)$l['id'] ?></td>
                            <td><input type="text" class="l-name" value="<?= htmlspecialchars((string)$l['name']) ?>" maxlength="255"></td>
                            <td><input type="text" class="l-address" value="<?= htmlspecialchars((string)($l['address'] ?? '')) ?>" maxlength="500"></td>
                            <td><input type="text" class="l-phone" value="<?= htmlspecialchars((string)($l['phone'] ?? '')) ?>" maxlength="32"></td>
                            <td><input type="text" class="l-tz" value="<?= htmlspecialchars((string)$l['timezone']) ?>" maxlength="64" data-w="xl"></td>
                            <td class="num-col"><?= (int)$s['orders_count'] ?></td>
                            <td class="num-col"><?= number_format((float)$s['revenue'], 0, '.', ' ') ?> ₽</td>
                            <td><input type="number" class="l-sort" value="<?= (int)$l['sort_order'] ?>" min="0" max="999" data-w="2xs"></td>
                            <td><input type="checkbox" class="l-active" <?= (int)$l['active'] === 1 ? 'checked' : '' ?>></td>
                            <td>
                                <button type="button" class="admin-checkout-btn btn-l-save">Сохранить</button>
                                <button type="button" class="admin-checkout-btn cancel btn-l-delete">Деактивировать</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!empty($summaryById[0])): ?>
                        <tr class="loc-legacy">
                            <td>—</td>
                            <td colspan="4"><em>Без локации (legacy-заказы)</em></td>
                            <td class="num-col"><?= (int)$summaryById[0]['orders_count'] ?></td>
                            <td class="num-col"><?= number_format((float)$summaryById[0]['revenue'], 0, '.', ' ') ?> ₽</td>
                            <td colspan="3">—</td>
                        </tr>
                    <?php endif; ?>
                    <tr class="loc-new-row" data-location-id="">
                        <td>—</td>
                        <td><input type="text" class="l-name" placeholder="Центр" maxlength="255"></td>
                        <td><input type="text" class="l-address" placeholder="улица, дом" maxlength="500"></td>
                        <td><input type="text" class="l-phone" placeholder="+7..." maxlength="32"></td>
                        <td><input type="text" class="l-tz" value="Europe/Moscow" maxlength="64" data-w="xl"></td>
                        <td class="num-col">—</td>
                        <td class="num-col">—</td>
                        <td><input type="number" class="l-sort" value="0" min="0" max="999" data-w="2xs"></td>
                        <td><input type="checkbox" class="l-active" checked></td>
                        <td><button type="button" class="admin-checkout-btn btn-l-save">Создать</button></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-locations.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
