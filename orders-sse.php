<?php
require_once __DIR__ . '/session_init.php';

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Accel-Buffering: no');

ignore_user_abort(true);
@set_time_limit(35);

$db = Database::getInstance();
$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['user_role'] ?? '');

// IMPORTANT: release PHP session lock before long-lived SSE loop.
// Otherwise this stream blocks other same-user requests (page navigation) until it finishes.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($userId <= 0) {
    http_response_code(401);
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'auth_required'], JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
    exit;
}

$lastKnown = (int)($_GET['t'] ?? 0);
$startedAt = time();
$sentUpdate = false;
$lastPingAt = 0;

while (!connection_aborted() && (time() - $startedAt) < 25) {
    $lastOrderUpdate = (int)$db->getOrdersLastUpdateTs();
    if ($lastOrderUpdate > $lastKnown) {
        if ($userRole === 'customer' && $userId > 0) {
            $changedOrders = $db->getUserOrderUpdatesSince($userId, $lastKnown);
        } else {
            $changedOrders = $db->getOrderUpdatesSince($lastKnown);
        }

        $payload = [
            'timestamp' => time(),
            'orderUpdated' => true,
            'changedOrderIds' => array_column($changedOrders, 'id'),
            'changedOrderStatuses' => array_column($changedOrders, 'status'),
            'cartTotal' => (int)$db->getCartTotalCountForUser($userId),
            'activeOrders' => (int)$db->scalar(
                "SELECT COUNT(*) FROM orders WHERE status IN (1,2,3)"
            ),
        ];

        echo "event: update\n";
        echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        @flush();
        $sentUpdate = true;
        break;
    }

    if ((time() - $lastPingAt) >= 10) {
        $ping = [
            'timestamp' => time(),
            'orderUpdated' => false,
            'cartTotal' => (int)$db->getCartTotalCountForUser($userId),
        ];
        echo "event: ping\n";
        echo "data: " . json_encode($ping, JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        @flush();
        $lastPingAt = time();
    }

    sleep(2);
}

if (!$sentUpdate && !connection_aborted()) {
    $final = [
        'timestamp' => time(),
        'orderUpdated' => false,
    ];
    echo "event: ping\n";
    echo "data: " . json_encode($final, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
}
