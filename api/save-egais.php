<?php
/**
 * api/save-egais.php — Phase 39 (ЕГАИС / 171-ФЗ алкоголь).
 *
 * POST JSON {
 *   action: 'create_invoice' | 'accept_invoice' | 'reject_invoice' | 'delete_invoice'
 *         | 'open_bottle' | 'toggle_alcohol',
 *   ...payload,
 *   csrf_token: string
 * }
 *
 * Все actions кроме open_bottle требуют роль admin/owner.
 * open_bottle доступен employee+ (вскрывает бутылку из shift dock).
 */

$required_role = 'employee';
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
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['user_role'] ?? '');
if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}
$isManager = in_array($role, ['admin', 'owner'], true);
$action = (string)($input['action'] ?? '');

function egais_fail(int $code, string $err): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

function egais_require_manager(bool $isManager): void {
    if (!$isManager) { egais_fail(403, 'manager_only'); }
}

switch ($action) {
    case 'create_invoice': {
        egais_require_manager($isManager);
        $id = isset($input['id']) ? (int)$input['id'] : null;
        $ttnNumber   = (string)($input['ttn_number'] ?? '');
        $ttnDate     = (string)($input['ttn_date']   ?? '');
        $supplierInn = (string)($input['supplier_inn'] ?? '');
        $supplierName = isset($input['supplier_name']) ? (string)$input['supplier_name'] : null;
        $totalAmount = (float)($input['total_amount'] ?? 0);
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        $notes = isset($input['notes']) ? (string)$input['notes'] : null;

        if ($ttnNumber === '' || $ttnDate === '' || $supplierInn === '') {
            egais_fail(400, 'missing_required');
        }
        if (!preg_match('/^(\d{10}|\d{12})$/', $supplierInn)) {
            egais_fail(400, 'invalid_inn');
        }
        // Auto-compute total if not provided.
        if ($totalAmount <= 0) {
            $totalAmount = 0;
            foreach ($items as $it) {
                $totalAmount += max(0.0, (float)($it['quantity'] ?? 0)) * max(0.0, (float)($it['price_per_unit'] ?? 0));
            }
        }
        $savedId = $db->saveAlcInvoice(
            $id,
            $ttnNumber,
            $ttnDate,
            $supplierInn,
            $supplierName,
            $totalAmount,
            $items,
            $notes
        );
        if (!$savedId) { egais_fail(500, 'save_failed'); }
        echo json_encode(['success' => true, 'id' => $savedId]);
        break;
    }

    case 'accept_invoice': {
        egais_require_manager($isManager);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { egais_fail(400, 'invalid_id'); }
        $applyToStock = !isset($input['apply_to_stock']) || (bool)$input['apply_to_stock'];
        if (!$db->acceptAlcInvoice($id, $userId, $applyToStock)) {
            egais_fail(409, 'cannot_accept');
        }
        echo json_encode(['success' => true]);
        break;
    }

    case 'reject_invoice': {
        egais_require_manager($isManager);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { egais_fail(400, 'invalid_id'); }
        $reason = isset($input['reason']) ? trim((string)$input['reason']) : null;
        if ($reason === '') $reason = null;
        if (!$db->rejectAlcInvoice($id, $userId, $reason)) {
            egais_fail(409, 'cannot_reject');
        }
        echo json_encode(['success' => true]);
        break;
    }

    case 'delete_invoice': {
        egais_require_manager($isManager);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { egais_fail(400, 'invalid_id'); }
        if (!$db->deleteAlcInvoice($id)) {
            egais_fail(409, 'cannot_delete');
        }
        echo json_encode(['success' => true]);
        break;
    }

    case 'open_bottle': {
        // employee+ может вскрывать бутылку. Привязка к открытой смене делается тут.
        $ingredientId = (int)($input['ingredient_id'] ?? 0);
        $volume = isset($input['bottle_volume_ml']) ? (int)$input['bottle_volume_ml'] : 750;
        $notes = isset($input['notes']) ? trim((string)$input['notes']) : null;
        if ($ingredientId <= 0) { egais_fail(400, 'invalid_ingredient_id'); }
        // Determine shift_id: user's own open shift, else any open shift.
        $shift = $db->getOpenShift($userId) ?: $db->getAnyOpenShift();
        $shiftId = $shift ? (int)$shift['id'] : null;
        $opId = $db->saveAlcOpening($ingredientId, $volume, $userId, $shiftId, $notes !== '' ? $notes : null);
        if (!$opId) { egais_fail(500, 'save_failed'); }
        echo json_encode(['success' => true, 'id' => $opId, 'shift_id' => $shiftId]);
        break;
    }

    case 'toggle_alcohol': {
        egais_require_manager($isManager);
        $ingredientId = (int)($input['ingredient_id'] ?? 0);
        $isAlcohol = (bool)($input['is_alcohol'] ?? false);
        $alcCode = isset($input['alc_code']) ? trim((string)$input['alc_code']) : null;
        if ($alcCode === '') $alcCode = null;
        if ($ingredientId <= 0) { egais_fail(400, 'invalid_ingredient_id'); }
        if (!$db->setIngredientAlcohol($ingredientId, $isAlcohol, $alcCode)) {
            egais_fail(500, 'toggle_failed');
        }
        echo json_encode(['success' => true]);
        break;
    }

    default:
        egais_fail(400, 'unknown_action');
}
