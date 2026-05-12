<?php
/**
 * api/save-semi-finished.php — Phase 40 (полуфабрикаты).
 *
 * POST JSON {
 *   action: 'toggle' | 'save_recipe' | 'cook_batch' | 'get_recipe',
 *   ...payload,
 *   csrf_token
 * }
 *
 * admin/owner only. cook_batch ставит audit_log + decrement child ingredients
 * по recipe и increment parent stock_qty по yield_per_batch.
 */

$required_role = 'admin';
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
if (!in_array($role, ['admin', 'owner'], true) || $userId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$action = (string)($input['action'] ?? '');

switch ($action) {
    case 'toggle': {
        $ingredientId = (int)($input['ingredient_id'] ?? 0);
        $isSf = (bool)($input['is_semi_finished'] ?? false);
        $yield = (float)($input['yield_per_batch'] ?? 0);
        if ($ingredientId <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        if (!$db->setIngredientSemiFinished($ingredientId, $isSf, $yield)) {
            http_response_code(500); echo json_encode(['success' => false, 'error' => 'toggle_failed']); exit;
        }
        AuditLog::record('ingredient.semi_finished_toggle', 'ingredient', (string)$ingredientId, [
            'is_semi_finished' => $isSf,
            'yield_per_batch'  => $yield,
        ]);
        echo json_encode(['success' => true]);
        break;
    }

    case 'save_recipe': {
        $parentId = (int)($input['parent_ingredient_id'] ?? 0);
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        if ($parentId <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_parent']); exit; }
        if (!$db->saveSemiFinishedRecipe($parentId, $items)) {
            http_response_code(500); echo json_encode(['success' => false, 'error' => 'save_failed']); exit;
        }
        AuditLog::record('semi_finished.recipe.save', 'ingredient', (string)$parentId, ['items_count' => count($items)]);
        echo json_encode(['success' => true]);
        break;
    }

    case 'get_recipe': {
        $parentId = (int)($input['parent_ingredient_id'] ?? 0);
        if ($parentId <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_parent']); exit; }
        echo json_encode(['success' => true, 'recipe' => $db->getSemiFinishedRecipe($parentId)]);
        break;
    }

    case 'cook_batch': {
        $parentId = (int)($input['parent_ingredient_id'] ?? 0);
        $batchSize = (float)($input['batch_size'] ?? 0);
        $notes = isset($input['notes']) ? trim((string)$input['notes']) : null;
        if ($parentId <= 0 || $batchSize <= 0) {
            http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_params']); exit;
        }
        $result = $db->cookSemiFinishedBatch($parentId, $batchSize, $userId, $notes !== '' ? $notes : null);
        if (is_array($result) && isset($result['error'])) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => $result['error'], 'ingredient' => $result['ingredient'] ?? null]);
            exit;
        }
        if (!$result) {
            http_response_code(500); echo json_encode(['success' => false, 'error' => 'cook_failed']); exit;
        }
        AuditLog::record('semi_finished.cook', 'ingredient', (string)$parentId, [
            'batch_id'   => $result,
            'batch_size' => $batchSize,
        ]);
        echo json_encode(['success' => true, 'batch_id' => $result]);
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
