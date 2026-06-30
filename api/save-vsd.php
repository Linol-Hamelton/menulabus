<?php
/**
 * api/save-vsd.php — Phase 38 (Меркурий / ВСД).
 *
 * POST JSON {
 *   action: 'create' | 'update' | 'accept' | 'reject' | 'delete' | 'toggle_requires',
 *   ...payload,
 *   csrf_token: string
 * }
 *
 * Все actions требуют роль admin или owner.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../lib/Billing/TierGate.php';

header('Content-Type: application/json; charset=utf-8');

// Phase L103.5e — gate behind inventory.vsd_mercury feature (tier 6+ «Кухня+»).
\Cleanmenu\Billing\TierGate::requireFeature(
    \Cleanmenu\Billing\Features::INVENTORY_VSD,
    'ВСД / Меркурий'
);

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

function vsd_fail(int $code, string $err): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

switch ($action) {
    case 'create':
    case 'update': {
        $id = $action === 'update' && isset($input['id']) ? (int)$input['id'] : null;
        $ingredientId = (int)($input['ingredient_id'] ?? 0);
        $vsdNumber = (string)($input['vsd_number'] ?? '');
        $vsdDate = (string)($input['vsd_date'] ?? '');
        $supplierInn = isset($input['supplier_inn']) ? (string)$input['supplier_inn'] : null;
        $supplierName = isset($input['supplier_name']) ? (string)$input['supplier_name'] : null;
        $quantity = isset($input['quantity']) ? (float)$input['quantity'] : 0.0;
        $unit = isset($input['unit']) ? (string)$input['unit'] : null;
        $notes = isset($input['notes']) ? (string)$input['notes'] : null;

        if ($ingredientId <= 0 || $vsdNumber === '' || $vsdDate === '') {
            vsd_fail(400, 'missing_required');
        }
        $savedId = $db->saveVsdRecord(
            $id,
            $ingredientId,
            $vsdNumber,
            $vsdDate,
            $supplierInn,
            $supplierName,
            $quantity,
            $unit,
            $notes
        );
        if (!$savedId) { vsd_fail(500, 'save_failed'); }
        echo json_encode(['success' => true, 'id' => $savedId]);
        break;
    }

    case 'accept': {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { vsd_fail(400, 'invalid_id'); }
        $applyToStock = !isset($input['apply_to_stock']) || (bool)$input['apply_to_stock'];
        if (!$db->acceptVsd($id, $userId, $applyToStock)) {
            vsd_fail(409, 'cannot_accept');
        }
        AuditLog::record('vsd.accept', 'vsd_record', (string)$id, ['apply_to_stock' => $applyToStock]);
        echo json_encode(['success' => true]);
        break;
    }

    case 'reject': {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { vsd_fail(400, 'invalid_id'); }
        $reason = isset($input['reason']) ? trim((string)$input['reason']) : null;
        if ($reason === '') $reason = null;
        if (!$db->rejectVsd($id, $userId, $reason)) {
            vsd_fail(409, 'cannot_reject');
        }
        AuditLog::record('vsd.reject', 'vsd_record', (string)$id, ['reason' => $reason]);
        echo json_encode(['success' => true]);
        break;
    }

    case 'delete': {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { vsd_fail(400, 'invalid_id'); }
        if (!$db->deleteVsdRecord($id)) {
            vsd_fail(409, 'cannot_delete');
        }
        echo json_encode(['success' => true]);
        break;
    }

    case 'toggle_requires': {
        $ingredientId = (int)($input['ingredient_id'] ?? 0);
        $requires = (bool)($input['requires'] ?? false);
        if ($ingredientId <= 0) { vsd_fail(400, 'invalid_ingredient_id'); }
        if (!$db->setIngredientRequiresVsd($ingredientId, $requires)) {
            vsd_fail(500, 'toggle_failed');
        }
        echo json_encode(['success' => true]);
        break;
    }

    default:
        vsd_fail(400, 'unknown_action');
}
