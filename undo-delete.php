<?php
/**
 * undo-delete.php — restore a soft-deleted modifier group or option
 * within the 30-second forgiveness window.
 *
 * POST JSON {
 *   table:      "modifier_groups" | "modifier_options",
 *   id:         int,
 *   csrf_token: string
 * }
 *
 * Admin/owner only. See docs/admin-menu-ux.md §5.5 for the full flow.
 *
 * Responses:
 *   200 { success: true, restored: true }
 *   200 { success: true, restored: false } — row exists but past the window
 *   400 invalid_params / unknown_table
 *   403 csrf / role
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
    $input = $_POST;
}

$table = (string)($input['table'] ?? '');
$id    = (int)($input['id'] ?? 0);

if (!in_array($table, ['modifier_groups', 'modifier_options'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unknown_table']);
    exit;
}
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_id']);
    exit;
}

$db = Database::getInstance();
$restored = $db->undoModifierDelete($table, $id);

echo json_encode([
    'success'  => true,
    'restored' => $restored,
]);
