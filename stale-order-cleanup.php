<?php
$required_role = 'employee';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/orders/lifecycle.php';

$role = (string)($_SESSION['user_role'] ?? ($user['role'] ?? ''));
if (!in_array($role, ['owner', 'admin'], true)) {
    http_response_code(403);
    $_SESSION['auth_error'] = 'Недостаточно прав для очистки просроченных заказов.';
    header('Location: employee.php');
    exit;
}

$returnTo = basename((string)($_POST['return_to'] ?? 'employee.php'));
if (!in_array($returnTo, ['employee.php', 'owner.php'], true)) {
    $returnTo = 'employee.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnTo);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['error'] = 'Ошибка безопасности при очистке просроченных заказов.';
    header('Location: ' . $returnTo);
    exit;
}

$db = Database::getInstance();
$result = $db->cleanupStaleOrders(cleanmenu_order_stale_threshold_minutes(), (int)($_SESSION['user_id'] ?? 0));

if (!empty($result['error'])) {
    $_SESSION['error'] = 'Не удалось закрыть просроченные заказы.';
} elseif ((int)($result['updated'] ?? 0) > 0) {
    $_SESSION['success'] = sprintf(
        'Закрыто просроченных заказов: %d.',
        (int)$result['updated']
    );
} else {
    $_SESSION['success'] = 'Просроченных заказов для очистки не найдено.';
}

header('Location: ' . $returnTo);
exit;
