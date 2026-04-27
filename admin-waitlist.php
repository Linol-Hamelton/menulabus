<?php
/**
 * admin-waitlist.php — staff view for the waitlist queue (Phase 8.4).
 *
 * Minimal table: phone + guests + requested window + actions. Staff can
 * flip a row to 'notified' (after calling the guest) or 'seated' / 'cancelled'.
 */

$required_role = 'employee';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';

$role = (string)($_SESSION['user_role'] ?? '');
if (!in_array($role, ['employee', 'admin', 'owner'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$db = Database::getInstance();
$filterDate = (string)($_GET['date'] ?? '');
$entries = $db->listActiveWaitlist($filterDate !== '' ? $filterDate : null, null);

$siteName   = $GLOBALS['siteName'] ?? 'labus';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');

$statusLabels = [
    'waiting'  => 'Ожидает',
    'notified' => 'Уведомлён',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Очередь | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-waitlist.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <div class="section-header-menu">
                <h2>Очередь (waitlist)</h2>
                <a href="/employee.php" class="back-to-menu-btn">Заказы</a>
            </div>
            <form method="GET" class="waitlist-filter">
                <label>Дата
                    <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
                </label>
                <button type="submit" class="admin-checkout-btn">Показать</button>
                <a href="/admin-waitlist.php" class="admin-checkout-btn cancel">Сбросить</a>
            </form>

            <?php if (empty($entries)): ?>
                <p class="waitlist-empty">Очередь пуста.</p>
            <?php else: ?>
                <table class="waitlist-table" id="waitlistTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Имя</th>
                            <th>Телефон</th>
                            <th class="num-col">Гостей</th>
                            <th>Дата</th>
                            <th>Время</th>
                            <th>Комментарий</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $e): ?>
                            <tr data-waitlist-id="<?= (int)$e['id'] ?>" class="waitlist-status-<?= htmlspecialchars((string)$e['status']) ?>">
                                <td>#<?= (int)$e['id'] ?></td>
                                <td><?= htmlspecialchars((string)($e['guest_name'] ?? '—')) ?></td>
                                <td><a href="tel:<?= htmlspecialchars((string)$e['guest_phone']) ?>"><?= htmlspecialchars((string)$e['guest_phone']) ?></a></td>
                                <td class="num-col"><?= (int)$e['guests_count'] ?></td>
                                <td><?= htmlspecialchars((string)$e['preferred_date']) ?></td>
                                <td><?= htmlspecialchars((string)($e['preferred_time'] ?? '—')) ?></td>
                                <td><?= htmlspecialchars((string)($e['note'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($statusLabels[(string)$e['status']] ?? (string)$e['status']) ?></td>
                                <td class="waitlist-actions">
                                    <?php if ($e['status'] === 'waiting'): ?>
                                        <button type="button" class="admin-checkout-btn btn-wl" data-status="notified">Позвонили</button>
                                    <?php endif; ?>
                                    <button type="button" class="admin-checkout-btn btn-wl" data-status="seated">Рассадили</button>
                                    <button type="button" class="admin-checkout-btn cancel btn-wl" data-status="cancelled">Отказ</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-waitlist.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
