<?php
/**
 * api/save-inventory.php — CRUD + stock ops + recipes for Inventory (Phase 6.2).
 *
 * POST body (JSON): {
 *   action: 'list_ingredients' | 'list_suppliers' | 'save_ingredient' |
 *           'archive_ingredient' | 'restore_ingredient' | 'adjust_stock' |
 *           'list_movements' | 'save_supplier' |
 *           'get_recipe' | 'set_recipe',
 *   ...payload per action,
 *   csrf_token: string
 * }
 *
 * Admin/owner only. See docs/inventory.md.
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

$db  = Database::getInstance();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$action = (string)($input['action'] ?? '');

switch ($action) {
    case 'list_ingredients':
        $includeArchived = !empty($input['include_archived']);
        echo json_encode([
            'success'     => true,
            'ingredients' => $db->listIngredients($includeArchived),
            'low_stock'   => $db->listLowStockIngredients(),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'list_suppliers':
        echo json_encode([
            'success'   => true,
            'suppliers' => $db->listSuppliers(!empty($input['include_archived'])),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save_ingredient':
        $id                = isset($input['id']) ? (int)$input['id'] : null;
        $name              = (string)($input['name'] ?? '');
        $unit              = (string)($input['unit'] ?? 'шт');
        $stockQty          = (float)($input['stock_qty'] ?? 0);
        $reorderThreshold  = (float)($input['reorder_threshold'] ?? 0);
        $costPerUnit       = (float)($input['cost_per_unit'] ?? 0);
        $supplierId        = isset($input['supplier_id']) && $input['supplier_id'] !== '' ? (int)$input['supplier_id'] : null;

        $savedId = $db->saveIngredient($id, $name, $unit, $stockQty, $reorderThreshold, $costPerUnit, $supplierId);
        if ($savedId === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        echo json_encode(['success' => true, 'id' => $savedId]);
        break;

    case 'archive_ingredient':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        echo json_encode(['success' => $db->archiveIngredient($id)]);
        break;

    case 'restore_ingredient':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        echo json_encode(['success' => $db->restoreIngredient($id)]);
        break;

    case 'adjust_stock':
        $id     = (int)($input['id'] ?? 0);
        $delta  = isset($input['delta']) ? (float)$input['delta'] : 0.0;
        $reason = (string)($input['reason'] ?? '');
        $note   = isset($input['note']) ? (string)$input['note'] : null;
        if ($id <= 0 || $delta === 0.0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        $ok = $db->adjustIngredientStock($id, $delta, $reason, $note, $uid);
        if (!$ok) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'adjust_failed']);
            exit;
        }
        $ing = $db->getIngredientById($id);
        echo json_encode(['success' => true, 'ingredient' => $ing], JSON_UNESCAPED_UNICODE);
        break;

    case 'list_movements':
        $id    = (int)($input['id'] ?? 0);
        $limit = (int)($input['limit'] ?? 50);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        echo json_encode([
            'success'   => true,
            'movements' => $db->getStockMovementsForIngredient($id, $limit),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save_supplier':
        $id      = isset($input['id']) ? (int)$input['id'] : null;
        $name    = (string)($input['name'] ?? '');
        $contact = isset($input['contact']) ? (string)$input['contact'] : null;
        $notes   = isset($input['notes']) ? (string)$input['notes'] : null;

        $savedId = $db->saveSupplier($id, $name, $contact, $notes);
        if ($savedId === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        echo json_encode(['success' => true, 'id' => $savedId]);
        break;

    case 'get_recipe':
        $menuItemId = (int)($input['menu_item_id'] ?? 0);
        if ($menuItemId <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_menu_item_id']); exit; }
        echo json_encode([
            'success' => true,
            'recipe'  => $db->getRecipeForMenuItem($menuItemId),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'set_recipe':
        $menuItemId = (int)($input['menu_item_id'] ?? 0);
        $rows       = $input['rows'] ?? [];
        if ($menuItemId <= 0 || !is_array($rows)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $iid = (int)($row['ingredient_id'] ?? 0);
            $qty = (float)($row['quantity'] ?? 0);
            if ($iid > 0 && $qty > 0) {
                $map[$iid] = $qty;
            }
        }
        $ok = $db->setRecipeForMenuItem($menuItemId, $map);
        echo json_encode(['success' => $ok]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
