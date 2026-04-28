<?php
/**
 * admin/kitchen.php — admin UI for Kitchen Display System.
 *
 * Two panels on one page:
 *   1. Kitchen stations CRUD (create / rename / activate / sort / delete).
 *   2. Menu-item routing: for each menu item pick which stations it plates on.
 *
 * Writes go through api/save-kitchen-station.php. Any action on the routing
 * panel rewrites the full station set for the item — matches the setMenuItemStations
 * contract on the server side.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';

$db = Database::getInstance();
$stations = $db->listKitchenStations(false);
$menuItems = $db->getMenuItems(null, false);

// Pre-fetch current routing for all items so the UI renders in one pass.
$routingMap = [];
foreach ($menuItems as $mi) {
    $routingMap[(int)$mi['id']] = array_map(static fn($s) => (int)$s['id'], $db->getMenuItemStations((int)$mi['id']));
}

$siteName = $GLOBALS['siteName'] ?? 'labus';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Станции кухни | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-kitchen.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/../account-header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <div class="section-header-menu">
                <h2>Станции кухни</h2>
                <div>
                    <a href="/kds.php" class="checkout-btn" target="_blank">Открыть KDS</a>
                    <a href="/admin/menu.php" class="back-to-menu-btn">К админке</a>
                </div>
            </div>
            <p class="kitchen-intro">
                Создайте станции (например, «Горячий цех», «Холодный», «Бар», «Пицца») и привяжите к ним блюда ниже.
                Когда блюдо принимает заказ, кухня видит его на своём экране <code>/kds.php</code>.
            </p>

            <h3>Активные и архивные станции</h3>
            <table class="kitchen-stations-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Slug</th>
                        <th>Порядок</th>
                        <th>Активна</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody id="kitchenStationsBody">
                    <?php foreach ($stations as $s): ?>
                        <tr data-station-id="<?= (int)$s['id'] ?>">
                            <td>#<?= (int)$s['id'] ?></td>
                            <td><input type="text" class="st-label" value="<?= htmlspecialchars((string)$s['label']) ?>" maxlength="64"></td>
                            <td><input type="text" class="st-slug" value="<?= htmlspecialchars((string)$s['slug']) ?>" maxlength="32" pattern="[a-z0-9_-]{1,32}"></td>
                            <td><input type="number" class="st-sort" value="<?= (int)$s['sort_order'] ?>" min="0" max="999" data-w="2xs"></td>
                            <td><input type="checkbox" class="st-active" <?= (int)$s['active'] === 1 ? 'checked' : '' ?>></td>
                            <td>
                                <button type="button" class="admin-checkout-btn btn-st-save">Сохранить</button>
                                <button type="button" class="admin-checkout-btn cancel btn-st-delete">Удалить</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="kitchen-new-row" data-station-id="">
                        <td>—</td>
                        <td><input type="text" class="st-label" placeholder="Название" maxlength="64"></td>
                        <td><input type="text" class="st-slug" placeholder="slug (a-z0-9_-)" maxlength="32" pattern="[a-z0-9_-]{1,32}"></td>
                        <td><input type="number" class="st-sort" value="0" min="0" max="999" data-w="2xs"></td>
                        <td><input type="checkbox" class="st-active" checked></td>
                        <td>
                            <button type="button" class="admin-checkout-btn btn-st-save">Создать</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="account-section">
            <div class="section-header-menu">
                <h3>Маршрутизация блюд по станциям</h3>
                <small>Клик по чекбоксу — блюдо отправляется на эту станцию при следующем заказе.</small>
            </div>
            <?php if (empty($menuItems)): ?>
                <p>Меню пока пустое.</p>
            <?php elseif (empty($stations)): ?>
                <div class="kitchen-routing-empty">
                    <p>Сначала создайте станции выше — затем здесь появится матрица маршрутизации блюд.</p>
                    <p class="kitchen-routing-empty-hint">После добавления первой станции таблица покажет, какие блюда отправлять на какую станцию.</p>
                </div>
            <?php else: ?>
                <div class="routing-table-wrapper">
                    <table class="kitchen-routing-table" id="kitchenRoutingTable">
                        <thead>
                            <tr>
                                <th class="routing-item-head">Блюдо</th>
                                <?php foreach ($stations as $s): ?>
                                    <th class="routing-station-head" data-station-id="<?= (int)$s['id'] ?>">
                                        <div class="routing-station-label">
                                            <?= htmlspecialchars((string)$s['label']) ?>
                                        </div>
                                        <div class="routing-station-slug"><?= htmlspecialchars((string)$s['slug']) ?></div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menuItems as $mi): ?>
                                <?php $itemId = (int)$mi['id']; $itemRouting = $routingMap[$itemId] ?? []; ?>
                                <tr data-item-id="<?= $itemId ?>">
                                    <td class="routing-item-cell">
                                        <div class="routing-item-name"><?= htmlspecialchars((string)$mi['name']) ?></div>
                                        <div class="routing-item-cat"><?= htmlspecialchars((string)$mi['category']) ?></div>
                                    </td>
                                    <?php foreach ($stations as $s): ?>
                                        <td class="routing-cell">
                                            <input type="checkbox"
                                                   class="routing-toggle"
                                                   data-item-id="<?= $itemId ?>"
                                                   data-station-id="<?= (int)$s['id'] ?>"
                                                   <?= in_array((int)$s['id'], $itemRouting, true) ? 'checked' : '' ?>>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-kitchen.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
