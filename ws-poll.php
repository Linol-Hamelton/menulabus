<?php
require_once __DIR__ . '/session_init.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$lastKnown = (int)($_GET['t'] ?? 0);

$db = Database::getInstance();
$response = ['timestamp' => time()];

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['user_role'] ?? '');

// Release session lock early (polling is frequent; do not block page navigation).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'auth_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$response['cartTotal'] = (int)$db->getCartTotalCountForUser($userId);

// Use Redis-backed marker to avoid heavy aggregate query on every poll.
$lastOrderUpdate = (int)$db->getOrdersLastUpdateTs();

if ($lastOrderUpdate > $lastKnown) {
    $response['orderUpdated'] = true;
    if ($userRole === 'customer' && $userId > 0) {
        $changedOrders = $db->getUserOrderUpdatesSince($userId, $lastKnown);
    } else {
        $changedOrders = $db->getOrderUpdatesSince($lastKnown);
    }
    $response['changedOrderIds'] = array_column($changedOrders, 'id');
    $response['changedOrderStatuses'] = array_column($changedOrders, 'status');
}

$response['activeOrders'] = (int)$db->scalar(
    "SELECT COUNT(*) FROM orders WHERE status IN (1,2,3)"
);

echo json_encode($response, JSON_UNESCAPED_UNICODE);
