<?php
/**
 * api/save-location.php — CRUD for restaurant locations (Phase 6.5).
 *
 * POST body (JSON): {
 *   action: 'list' | 'save' | 'delete',
 *   id?: int, name?, address?, phone?, timezone?, active?, sort_order?,
 *   csrf_token: string
 * }
 *
 * Admin/owner only. See docs/multi-location.md.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
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

switch ($action) {
    case 'list':
        echo json_encode([
            'success'   => true,
            'locations' => $db->listLocations(false),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save':
        $id        = isset($input['id']) ? (int)$input['id'] : null;
        $name      = (string)($input['name'] ?? '');
        $address   = isset($input['address']) ? (string)$input['address'] : null;
        $phone     = isset($input['phone']) ? (string)$input['phone'] : null;
        $timezone  = (string)($input['timezone'] ?? 'Europe/Moscow');
        $active    = !isset($input['active']) || (bool)$input['active'];
        $sortOrder = (int)($input['sort_order'] ?? 0);

        $savedId = $db->saveLocation($id, $name, $address, $phone, $timezone, $active, $sortOrder);
        if ($savedId === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        echo json_encode(['success' => true, 'id' => $savedId]);
        break;

    case 'delete':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        echo json_encode(['success' => $db->deleteLocation($id)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
