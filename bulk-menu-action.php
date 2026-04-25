<?php
/**
 * bulk-menu-action.php — multi-select actions for the admin menu table.
 *
 * POST JSON {
 *   action:   'hide' | 'show' | 'archive' | 'move',
 *   ids:      [12, 17, 42, ...],
 *   category: "Пицца",   // required only when action === 'move'
 *   csrf_token: "..."
 * }
 *
 * Admin/owner only. CSRF via lib/Csrf.php. All actions run inside a
 * single DB transaction; the response reports how many rows were
 * actually touched (`affected`), which can be less than `ids.length`
 * if some rows were already in the target state or are archived.
 *
 * Responses:
 *   200 { success, affected }
 *   400 invalid_params / empty_ids / missing_category
 *   403 csrf / role
 *   500 db_failure
 */

$required_role = 'admin';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Csrf.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

Csrf::requireValid();

$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_body']);
    exit;
}

$action   = (string)($input['action'] ?? '');
$ids      = $input['ids'] ?? null;
$category = isset($input['category']) ? trim((string)$input['category']) : '';

$allowedActions = ['hide', 'show', 'archive', 'move'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_action']);
    exit;
}
if (!is_array($ids) || empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'empty_ids']);
    exit;
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'no_valid_ids']);
    exit;
}
if (count($ids) > 500) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'too_many_ids']);
    exit;
}

$db = Database::getInstance();
$affected = 0;

switch ($action) {
    case 'hide':
        $affected = $db->bulkSetMenuItemsAvailable($ids, false);
        break;
    case 'show':
        $affected = $db->bulkSetMenuItemsAvailable($ids, true);
        break;
    case 'archive':
        $affected = $db->bulkArchiveMenuItems($ids);
        break;
    case 'move':
        if ($category === '' || strlen($category) > 50) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'missing_or_invalid_category']);
            exit;
        }
        $affected = $db->bulkMoveMenuItemsToCategory($ids, $category);
        break;
}

echo json_encode([
    'success'  => true,
    'action'   => $action,
    'affected' => $affected,
]);
