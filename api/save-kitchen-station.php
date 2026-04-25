<?php
/**
 * api/save-kitchen-station.php — CRUD for kitchen_stations + menu_item_stations.
 *
 * POST body (JSON): {
 *   action: 'list' | 'save' | 'delete' | 'set_item_stations' | 'get_item_stations',
 *   id?: int,
 *   label?: string,
 *   slug?: string,
 *   active?: bool,
 *   sort_order?: int,
 *   item_id?: int,          // for set_item_stations / get_item_stations
 *   station_ids?: int[],    // for set_item_stations
 *   csrf_token: string
 * }
 *
 * Admin/owner only. See docs/kds.md for the data model.
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
            'success'  => true,
            'stations' => $db->listKitchenStations(false),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save':
        $id         = isset($input['id']) ? (int)$input['id'] : null;
        $label      = (string)($input['label'] ?? '');
        $slug       = (string)($input['slug'] ?? '');
        $active     = !isset($input['active']) || (bool)$input['active'];
        $sortOrder  = (int)($input['sort_order'] ?? 0);

        $savedId = $db->saveKitchenStation($id, $label, $slug, $active, $sortOrder);
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
        echo json_encode(['success' => $db->deleteKitchenStation($id)]);
        break;

    case 'get_item_stations':
        $itemId = (int)($input['item_id'] ?? 0);
        if ($itemId <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_item_id']); exit; }
        echo json_encode([
            'success'  => true,
            'stations' => $db->getMenuItemStations($itemId),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'set_item_stations':
        $itemId = (int)($input['item_id'] ?? 0);
        $stationIds = $input['station_ids'] ?? [];
        if ($itemId <= 0 || !is_array($stationIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        $ok = $db->setMenuItemStations($itemId, $stationIds);
        echo json_encode(['success' => $ok]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
