<?php
/**
 * api/save/menu-order.php — persist a drag-n-drop reorder for menu items.
 *
 * POST JSON {
 *   category: "Пицца",
 *   order:    [{ id: 42, position: 0 }, { id: 17, position: 1 }, ...],
 *   csrf_token: "..."
 * }
 *
 * Admin/owner only. CSRF via lib/Csrf.php. The `category` field scopes the
 * reorder: the endpoint refuses to move rows across categories here — that
 * belongs to a separate "move to category" bulk action (track 5.2).
 *
 * Returns:
 *   200 { success, updated }
 *   400 validation
 *   403 csrf / role
 *   500 db_failure
 */

$required_role = 'admin';
require_once __DIR__ . '/../../session_init.php';
require_once __DIR__ . '/../../require_auth.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/Csrf.php';

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

$category = trim((string)($input['category'] ?? ''));
$order    = $input['order'] ?? null;

if ($category === '' || !is_array($order) || empty($order)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_params']);
    exit;
}

$idToPosition = [];
$submittedIds = [];
foreach ($order as $entry) {
    if (!is_array($entry)) continue;
    $id       = (int)($entry['id'] ?? 0);
    $position = (int)($entry['position'] ?? 0);
    if ($id <= 0) continue;
    $idToPosition[$id] = $position;
    $submittedIds[] = $id;
}

if (empty($idToPosition)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'no_valid_entries']);
    exit;
}

// Safety: refuse to move rows that do not belong to the submitted category.
// Prevents a malicious client from globally rearranging the menu with one
// request; the drag UI is per-category, so all submitted ids must match.
$db = Database::getInstance();
try {
    $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
    $stmt = $db->getConnection()->prepare(
        "SELECT id, category FROM menu_items WHERE id IN ({$placeholders})"
    );
    $stmt->execute($submittedIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('save-menu-order lookup error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_failure']);
    exit;
}

foreach ($rows as $row) {
    if ((string)$row['category'] !== $category) {
        http_response_code(400);
        echo json_encode([
            'success'           => false,
            'error'             => 'cross_category_reorder_refused',
            'offending_item_id' => (int)$row['id'],
            'item_category'     => (string)$row['category'],
        ]);
        exit;
    }
}

if (!$db->updateMenuItemsOrder($idToPosition)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_failure']);
    exit;
}

echo json_encode([
    'success' => true,
    'updated' => count($idToPosition),
]);
