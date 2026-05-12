<?php
/**
 * api/save-courier.php — Phase 42 (своя курьерская доставка).
 *
 * Actions:
 *   - assign / unassign — admin/owner only (назначает курьера на заказ)
 *   - pick_up / deliver — назначенный курьер сам отмечает событие
 */

$required_role = 'employee';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}
Csrf::requireValid();

$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) { $input = $_POST; }

$db = Database::getInstance();
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['user_role'] ?? '');
$isManager = in_array($role, ['admin', 'owner'], true);

function courier_fail(int $code, string $err): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

$action = (string)($input['action'] ?? '');

switch ($action) {
    case 'assign': {
        if (!$isManager) courier_fail(403, 'manager_only');
        $orderId = (int)($input['order_id'] ?? 0);
        $courierUserId = isset($input['courier_id']) && $input['courier_id'] !== '' ? (int)$input['courier_id'] : null;
        if ($orderId <= 0) courier_fail(400, 'invalid_order_id');
        if (!$db->assignOrderCourier($orderId, $courierUserId)) courier_fail(500, 'assign_failed');
        AuditLog::record('order.courier.assign', 'order', (string)$orderId, ['courier_id' => $courierUserId]);
        echo json_encode(['success' => true]);
        break;
    }

    case 'unassign': {
        if (!$isManager) courier_fail(403, 'manager_only');
        $orderId = (int)($input['order_id'] ?? 0);
        if ($orderId <= 0) courier_fail(400, 'invalid_order_id');
        if (!$db->assignOrderCourier($orderId, null)) courier_fail(500, 'unassign_failed');
        AuditLog::record('order.courier.unassign', 'order', (string)$orderId, []);
        echo json_encode(['success' => true]);
        break;
    }

    case 'pick_up': {
        $orderId = (int)($input['order_id'] ?? 0);
        if ($orderId <= 0) courier_fail(400, 'invalid_order_id');
        if (!$db->markOrderPickedUp($orderId, $userId)) {
            courier_fail(409, 'not_assigned_or_already_picked_up');
        }
        AuditLog::record('order.courier.pick_up', 'order', (string)$orderId, []);
        echo json_encode(['success' => true]);
        break;
    }

    case 'deliver': {
        $orderId = (int)($input['order_id'] ?? 0);
        if ($orderId <= 0) courier_fail(400, 'invalid_order_id');
        if (!$db->markOrderDelivered($orderId, $userId)) {
            courier_fail(409, 'not_assigned_or_already_delivered');
        }
        AuditLog::record('order.courier.deliver', 'order', (string)$orderId, []);
        echo json_encode(['success' => true]);
        break;
    }

    default:
        courier_fail(400, 'unknown_action');
}
