<?php
/**
 * shift-swap-action.php — single endpoint for the four shift-swap
 * lifecycle transitions (Phase 7.4 v2, 2026-04-28).
 *
 * Input (JSON body):
 *   action  string  request | offer | approve | deny | cancel
 *   shift_id  int   required for action=request
 *   swap_id   int   required for action in {offer, approve, deny, cancel}
 *   note      string optional, only for action=request
 *   csrf_token string  required (or X-CSRF-Token header)
 *
 * Auth: any authenticated employee/admin/owner. approve and deny are
 *   restricted to admin/owner.
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

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'auth_required']);
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById((int)$_SESSION['user_id']);
if (!$user || empty($user['is_active']) || !in_array($user['role'], ['employee', 'admin', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');
$userId = (int)$user['id'];
$isManager = in_array($user['role'], ['admin', 'owner'], true);

switch ($action) {
    case 'request':
        $shiftId = (int)($input['shift_id'] ?? 0);
        $note    = isset($input['note']) ? (string)$input['note'] : null;
        if ($shiftId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'shift_id_required']);
            exit;
        }
        $id = $db->createShiftSwapRequest($shiftId, $userId, $note);
        if (!$id) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'create_failed']);
            exit;
        }
        echo json_encode(['success' => true, 'swap_id' => $id]);
        break;

    case 'offer':
        $swapId = (int)($input['swap_id'] ?? 0);
        if (!$db->offerToTakeShift($swapId, $userId)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'cannot_offer']);
            exit;
        }
        echo json_encode(['success' => true]);
        break;

    case 'approve':
        if (!$isManager) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'manager_required']);
            exit;
        }
        $swapId = (int)($input['swap_id'] ?? 0);
        if (!$db->approveShiftSwap($swapId, $userId)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'cannot_approve']);
            exit;
        }
        echo json_encode(['success' => true]);
        break;

    case 'deny':
        if (!$isManager) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'manager_required']);
            exit;
        }
        $swapId = (int)($input['swap_id'] ?? 0);
        if (!$db->denyShiftSwap($swapId, $userId)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'cannot_deny']);
            exit;
        }
        echo json_encode(['success' => true]);
        break;

    case 'cancel':
        $swapId = (int)($input['swap_id'] ?? 0);
        if (!$db->cancelShiftSwap($swapId, $userId)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'cannot_cancel']);
            exit;
        }
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
