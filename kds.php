<?php
/**
 * kds.php — Kitchen Display System surface.
 *
 * Auth: role in {employee, admin, owner}. A station is chosen once per
 * session (or via ?station=<id>) and remembered in $_SESSION['kitchen_station_id'].
 * Passing ?station=0 switches to the "unrouted" tab (items whose menu row
 * has no station mapping yet).
 *
 * See docs/kds.md for the full data model and ops runbook.
 */

$required_role = 'employee';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$role = (string)($_SESSION['user_role'] ?? '');
if (!in_array($role, ['employee', 'admin', 'owner'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Explicit station selection via query string — persists until logout.
if (isset($_GET['station'])) {
    $q = (string)$_GET['station'];
    if ($q === '' || $q === '-') {
        unset($_SESSION['kitchen_station_id']);
    } else {
        $_SESSION['kitchen_station_id'] = (int)$q;
    }
    header('Location: /kds.php');
    exit;
}

$stations = $db->listKitchenStations(true);
$selectedStationId = $_SESSION['kitchen_station_id'] ?? null;
$selectedStation = null;
if ($selectedStationId !== null) {
    if ((int)$selectedStationId === 0) {
        $selectedStation = ['id' => 0, 'label' => 'Без маршрута', 'slug' => 'unrouted'];
    } else {
        $selectedStation = $db->getKitchenStationById((int)$selectedStationId);
        if (!$selectedStation) {
            unset($_SESSION['kitchen_station_id']);
            $selectedStationId = null;
        }
    }
}

$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
$siteName   = $GLOBALS['siteName'] ?? 'labus';
$csrfToken  = $_SESSION['csrf_token'] ?? '';

// Status labels shared with JS via data-* attributes.
$statusLabels = [
    'queued'    => 'В очереди',
    'cooking'   => 'Готовим',
    'ready'     => 'Готово',
    'cancelled' => 'Отменено',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <title>Кухня · <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/kds.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="kds-page" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" data-station-id="<?= $selectedStationId !== null ? (int)$selectedStationId : '' ?>">
    <?php if ($selectedStation === null): ?>
        <section class="kds-station-picker">
            <h1>Выбор станции кухни</h1>
            <p>Один планшет = одна станция. Выбор сохранится до выхода из сессии.</p>
            <?php if (empty($stations)): ?>
                <div class="kds-empty kds-empty--cta">
                    <p class="kds-empty-title">Станции ещё не созданы</p>
                    <p class="kds-empty-hint">Создайте хотя бы одну станцию (например, «Горячий цех», «Холодный», «Бар», «Пицца»), чтобы начать работу с кухонной доской. Каждое блюдо потом привязывается к станции в матрице маршрутизации.</p>
                    <?php if (in_array($role, ['admin', 'owner'], true)): ?>
                        <a class="kds-btn kds-btn-primary kds-empty-cta" href="/admin/kitchen.php">Создать первую станцию</a>
                    <?php else: ?>
                        <p class="kds-empty-note">Попросите администратора создать станции через раздел «Станции кухни».</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="kds-station-grid">
                    <?php foreach ($stations as $s): ?>
                        <a class="kds-station-card" href="/kds.php?station=<?= (int)$s['id'] ?>">
                            <span class="kds-station-label"><?= htmlspecialchars((string)$s['label']) ?></span>
                            <span class="kds-station-slug"><?= htmlspecialchars((string)$s['slug']) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <a class="kds-station-card kds-station-card--unrouted" href="/kds.php?station=0">
                        <span class="kds-station-label">Без маршрута</span>
                        <span class="kds-station-slug">unrouted — блюда без привязки к станции</span>
                    </a>
                </div>
            <?php endif; ?>
            <div class="kds-logout-row">
                <a class="kds-btn kds-btn-link" href="/account.php">В аккаунт</a>
            </div>
        </section>
    <?php else: ?>
        <header class="kds-header">
            <div class="kds-header-title">
                <span class="kds-header-label"><?= htmlspecialchars((string)$selectedStation['label']) ?></span>
                <span class="kds-header-time" id="kdsClock">—</span>
            </div>
            <div class="kds-header-actions">
                <span class="kds-header-counter" id="kdsCounter" aria-label="Позиций на доске">0</span>
                <a class="kds-btn kds-btn-secondary" href="/kds.php?station=-">Сменить станцию</a>
            </div>
        </header>

        <main class="kds-board" id="kdsBoard"
              data-station-id="<?= (int)$selectedStation['id'] ?>"
              data-status-labels="<?= htmlspecialchars(json_encode($statusLabels, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
            <div class="kds-loading">Загрузка очереди…</div>
        </main>
    <?php endif; ?>

    <script src="/js/kds.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
