<?php
/**
 * api/save-modifiers.php — CRUD endpoint for modifier groups and options.
 *
 * POST body (JSON): {
 *   action: 'save_group'|'delete_group'|'save_option'|'delete_option',
 *   item_id: int,        // required for save_group
 *   group_id: int|null,  // null = create new
 *   name: string,
 *   type: 'radio'|'checkbox',
 *   required: bool,
 *   sort_order: int,
 *   option_id: int|null, // null = create new (for option actions)
 *   price_delta: float,  // for option actions
 *   csrf_token: string
 * }
 *
 * Admin/owner only.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF mismatch']);
    exit;
}

$db     = Database::getInstance();
$action = $input['action'] ?? '';

switch ($action) {
    case 'save_group':
        $itemId    = (int)($input['item_id'] ?? 0);
        $groupId   = isset($input['group_id']) ? (int)$input['group_id'] : null;
        $name      = substr(trim($input['name'] ?? ''), 0, 100);
        $type      = in_array($input['type'] ?? '', ['radio', 'checkbox'], true) ? $input['type'] : 'radio';
        $required  = !empty($input['required']);
        $sortOrder = (int)($input['sort_order'] ?? 0);
        if ($itemId <= 0 || $name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid params']);
            exit;
        }
        $id = $db->saveModifierGroup($itemId, $groupId ?: null, $name, $type, $required, $sortOrder);
        echo json_encode(['success' => $id !== false, 'group_id' => $id]);
        break;

    case 'delete_group':
        $groupId = (int)($input['group_id'] ?? 0);
        if ($groupId <= 0) { http_response_code(400); echo json_encode(['success' => false]); exit; }
        echo json_encode(['success' => $db->deleteModifierGroup($groupId)]);
        break;

    case 'save_option':
        $groupId   = (int)($input['group_id'] ?? 0);
        $optionId  = isset($input['option_id']) ? (int)$input['option_id'] : null;
        $name      = substr(trim($input['name'] ?? ''), 0, 100);
        $priceDelta = (float)($input['price_delta'] ?? 0);
        $sortOrder  = (int)($input['sort_order'] ?? 0);
        if ($groupId <= 0 || $name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid params']);
            exit;
        }
        $id = $db->saveModifierOption($groupId, $optionId ?: null, $name, $priceDelta, $sortOrder);
        echo json_encode(['success' => $id !== false, 'option_id' => $id]);
        break;

    case 'delete_option':
        $optionId = (int)($input['option_id'] ?? 0);
        if ($optionId <= 0) { http_response_code(400); echo json_encode(['success' => false]); exit; }
        echo json_encode(['success' => $db->deleteModifierOption($optionId)]);
        break;

    case 'get':
        $itemId = (int)($input['item_id'] ?? 0);
        if ($itemId <= 0) { http_response_code(400); echo json_encode(['success' => false]); exit; }
        echo json_encode(['success' => true, 'groups' => $db->getModifiersByItemId($itemId)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
