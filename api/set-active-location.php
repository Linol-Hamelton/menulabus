<?php
/**
 * api/set-active-location.php — Phase L103.2 active-location switcher endpoint.
 *
 * Принимает POST из <form action="/api/set-active-location.php"> в шапке
 * (см. account-header.php :: location-switcher).
 *
 * Валидация:
 *   - role: employee/admin/owner (через require_auth.php + $required_role)
 *   - CSRF: header X-CSRF-Token ИЛИ body csrf_token (Csrf::requireValid)
 *   - location_id: integer > 0, обязан существовать в текущей tenant DB
 *     (Database::getLocationById — implicit ownership через tenant runtime)
 *
 * Успех — 303 redirect на referer (или /account.php fallback) с success-флагом.
 * Ошибка — 303 redirect назад + error-флаг в session.
 *
 * Sessions: пишет $_SESSION['active_location_id']. Все hasFeature-проверки
 * (Phase L103.3) и order-create-path (Phase L103.5) читают этот ключ через
 * Database::activeLocationId().
 */
declare(strict_types=1);

$required_role = 'employee';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';

$backTo = (string)($_SERVER['HTTP_REFERER'] ?? '/account.php');
$refHost = (string)(parse_url($backTo, PHP_URL_HOST) ?: '');
$myHost  = (string)($_SERVER['HTTP_HOST'] ?? '');
if ($refHost !== '' && $refHost !== $myHost) {
    $backTo = '/account.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

Csrf::requireValid();

$locationId = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
if ($locationId <= 0) {
    $_SESSION['error'] = 'Не указана торговая точка';
    header('Location: ' . $backTo, true, 303);
    exit;
}

$db = Database::getInstance();
$location = $db->getLocationById($locationId);
if ($location === null) {
    $_SESSION['error'] = 'Торговая точка не найдена';
    header('Location: ' . $backTo, true, 303);
    exit;
}
if (empty($location['active'])) {
    $_SESSION['error'] = 'Торговая точка деактивирована';
    header('Location: ' . $backTo, true, 303);
    exit;
}

$_SESSION['active_location_id'] = (int)$location['id'];
$_SESSION['success'] = 'Активная точка: ' . $location['name'];

header('Location: ' . $backTo, true, 303);
exit;
