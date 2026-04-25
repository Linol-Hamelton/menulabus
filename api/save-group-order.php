<?php
/**
 * api/save-group-order.php — shared tab CRUD (Phase 8.3).
 *
 * POST body (JSON): {
 *   action: 'create' | 'add_item' | 'remove_item' | 'submit',
 *   ...payload,
 *   csrf_token: string
 * }
 *
 * `create` / `add_item` / `remove_item` are open to any session; `submit`
 * freezes the group into orders. CSRF required on all.
 */

require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';

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
$action = (string)($input['action'] ?? '');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

switch ($action) {
    case 'create':
        $tableLabel = isset($input['table_label']) ? (string)$input['table_label'] : null;
        $locationId = isset($input['location_id']) && $input['location_id'] !== '' ? (int)$input['location_id'] : null;
        $group = $db->createGroupOrder($userId, $tableLabel, $locationId);
        if (!$group) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'create_failed']);
            exit;
        }
        echo json_encode(['success' => true, 'id' => $group['id'], 'code' => $group['code']], JSON_UNESCAPED_UNICODE);
        break;

    case 'add_item':
        $code = (string)($input['code'] ?? '');
        $seat = (string)($input['seat_label'] ?? '');
        $mid  = (int)($input['menu_item_id'] ?? 0);
        $qty  = (int)($input['quantity'] ?? 1);
        $note = isset($input['note']) ? (string)$input['note'] : null;
        $group = $db->getGroupOrderByCode($code);
        if (!$group || $group['status'] !== 'open') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'group_not_open']);
            exit;
        }
        $id = $db->addGroupOrderItem((int)$group['id'], $seat, $mid, $qty, $note, $userId);
        if ($id === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'add_failed']);
            exit;
        }
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'remove_item':
        $code = (string)($input['code'] ?? '');
        $itemId = (int)($input['item_id'] ?? 0);
        $group = $db->getGroupOrderByCode($code);
        if (!$group || $group['status'] !== 'open') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'group_not_open']);
            exit;
        }
        echo json_encode(['success' => $db->removeGroupOrderItem($itemId, (int)$group['id'])]);
        break;

    case 'submit':
        $code = (string)($input['code'] ?? '');
        $mode = (string)($input['mode'] ?? 'single');
        $group = $db->getGroupOrderByCode($code);
        if (!$group || $group['status'] !== 'open') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'group_not_open']);
            exit;
        }
        // Only host (if logged-in) or staff can submit. Guests without session
        // user_id can submit if the group host_user_id is also NULL (pure guest flow).
        $role = (string)($_SESSION['user_role'] ?? '');
        $isStaff = in_array($role, ['employee', 'admin', 'owner'], true);
        $isHost  = $group['host_user_id'] !== null && $userId !== null && (int)$group['host_user_id'] === $userId;
        $isGuestFlow = $group['host_user_id'] === null;
        if (!$isStaff && !$isHost && !$isGuestFlow) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'not_host']);
            exit;
        }
        $orderIds = $db->submitGroupOrder((int)$group['id'], $mode);
        if ($orderIds === null) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'submit_failed']);
            exit;
        }
        try {
            require_once __DIR__ . '/../lib/WebhookDispatcher.php';
            WebhookDispatcher::dispatch('group_order.submitted', [
                'group_code' => $group['code'],
                'order_ids'  => $orderIds,
                'mode'       => $mode,
                'table_label'=> $group['table_label'],
            ], $db);
        } catch (Throwable $e) { error_log('group_order webhook error: ' . $e->getMessage()); }

        echo json_encode(['success' => true, 'order_ids' => $orderIds, 'mode' => $mode]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
