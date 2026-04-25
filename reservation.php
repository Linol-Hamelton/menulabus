<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$siteName = $GLOBALS['siteName'] ?? 'labus';
$appVersion = $_SESSION['app_version'] ?? '1.0.0';

$prefilledTable = '';
if (!empty($_GET['table'])) {
    $prefilledTable = trim((string)$_GET['table']);
} elseif (!empty($_SESSION['qr_table'])) {
    $prefilledTable = (string)$_SESSION['qr_table'];
}

$isLoggedIn = !empty($_SESSION['user_id']);
$prefilledName  = '';
$prefilledPhone = '';
if ($isLoggedIn) {
    $user = $db->getUserById((int)$_SESSION['user_id']);
    if ($user) {
        $prefilledName  = (string)($user['name']  ?? '');
        $prefilledPhone = (string)($user['phone'] ?? '');
    }
}

$myUpcoming = [];
if ($isLoggedIn) {
    $myUpcoming = $db->getUpcomingReservationsByUser((int)$_SESSION['user_id'], 5);
}

// Phase 7.3 — labels go through the i18n bundle so EN/KK builds don't have
// to fork this template.
$statusLabels = [
    'pending'   => t('reservation.status_pending'),
    'confirmed' => t('reservation.status_confirmed'),
    'seated'    => t('reservation.status_seated'),
];

$minDateTime = date('Y-m-d\TH:i', time() + 30 * 60);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title><?= htmlspecialchars($siteName) ?> | Бронь стола</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/reservation-page.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="reservation-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <div class="section-header-menu">
                <h2>Бронь стола</h2>
                <a href="/menu.php" class="back-to-menu-btn">В меню</a>
            </div>

            <form id="reservationForm" class="reservation-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

                <label class="form-group">
                    <span class="form-label">Стол</span>
                    <input type="text" name="table_label" required maxlength="64"
                           placeholder="Например, 5"
                           value="<?= htmlspecialchars($prefilledTable, ENT_QUOTES) ?>">
                </label>

                <div class="form-row">
                    <label class="form-group">
                        <span class="form-label">Начало</span>
                        <input type="datetime-local" name="starts_at" required
                               min="<?= htmlspecialchars($minDateTime) ?>">
                    </label>
                    <label class="form-group">
                        <span class="form-label">Окончание</span>
                        <input type="datetime-local" name="ends_at" required
                               min="<?= htmlspecialchars($minDateTime) ?>">
                    </label>
                </div>

                <label class="form-group">
                    <span class="form-label">Сколько гостей</span>
                    <input type="number" name="guests_count" required min="1" max="50" value="2">
                </label>

                <?php if (!$isLoggedIn): ?>
                    <label class="form-group">
                        <span class="form-label">Ваше имя</span>
                        <input type="text" name="guest_name" required maxlength="100" value="<?= htmlspecialchars($prefilledName, ENT_QUOTES) ?>">
                    </label>
                    <label class="form-group">
                        <span class="form-label">Телефон для связи</span>
                        <input type="tel" name="guest_phone" required maxlength="32" placeholder="+7 ___ ___ __ __" value="<?= htmlspecialchars($prefilledPhone, ENT_QUOTES) ?>">
                    </label>
                <?php endif; ?>

                <label class="form-group">
                    <span class="form-label">Комментарий (необязательно)</span>
                    <textarea name="note" rows="2" maxlength="500" placeholder="У окна, день рождения и т.п."></textarea>
                </label>

                <div class="form-actions">
                    <button type="submit" class="checkout-btn" id="reservationSubmit">Забронировать</button>
                </div>

                <div id="reservationStatus" class="reservation-status" hidden></div>
            </form>
        </section>

        <?php if (!empty($myUpcoming)): ?>
            <section class="account-section">
                <h3>Мои ближайшие брони</h3>
                <ul class="my-reservations-list">
                    <?php foreach ($myUpcoming as $r): ?>
                        <?php
                        $startsTs = strtotime((string)$r['starts_at']);
                        $endsTs   = strtotime((string)$r['ends_at']);
                        $statusKey = (string)$r['status'];
                        ?>
                        <li class="my-reservation-item">
                            <div class="my-reservation-time">
                                <strong><?= date('d.m.Y', $startsTs) ?></strong>
                                <?= date('H:i', $startsTs) ?>–<?= date('H:i', $endsTs) ?>
                            </div>
                            <div class="my-reservation-meta">
                                Стол <?= htmlspecialchars((string)$r['table_label']) ?> · <?= (int)$r['guests_count'] ?> гост.
                            </div>
                            <span class="my-reservation-status status-<?= htmlspecialchars($statusKey) ?>">
                                <?= htmlspecialchars($statusLabels[$statusKey] ?? $statusKey) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/reservation-form.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
