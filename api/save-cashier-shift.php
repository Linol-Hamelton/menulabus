<?php
/**
 * api/save-cashier-shift.php — Phase 35 (Кассовая смена).
 *
 * POST JSON {
 *   action: 'open' | 'close' | 'encash' | 'status',
 *   ...payload,
 *   csrf_token: string
 * }
 *
 * Distinct from api/save-staff.php (which manages scheduled work shifts).
 * This endpoint owns cash-register open/close/encashment lifecycle.
 */

$required_role = 'employee';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../lib/Billing/TierGate.php';

header('Content-Type: application/json; charset=utf-8');

// Phase L103.5e — gate behind staff.cashier_shift feature (tier 5+ «Смена+»).
\Cleanmenu\Billing\TierGate::requireFeature(
    \Cleanmenu\Billing\Features::STAFF_CASHIER_SHIFT,
    'Кассовая смена с Z-отчётом'
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
$role = (string)($_SESSION['user_role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$isManager = in_array($role, ['admin', 'owner'], true);
$action = (string)($input['action'] ?? '');

function shift_fail(int $code, string $err): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

switch ($action) {
    case 'open': {
        if ($userId <= 0) { shift_fail(401, 'unauthorized'); }
        $existing = $db->getOpenShift($userId);
        if ($existing) { shift_fail(409, 'shift_already_open'); }
        $openingCash = isset($input['opening_cash']) ? (float)$input['opening_cash'] : 0.0;
        $notes = isset($input['notes']) && $input['notes'] !== '' ? (string)$input['notes'] : null;
        $newId = $db->openCashierShift($userId, $openingCash, null, $notes);
        if (!$newId) { shift_fail(500, 'open_failed'); }
        AuditLog::record('cashier_shift.open', 'cashier_shift', (string)$newId, ['opening_cash' => $openingCash]);
        echo json_encode(['success' => true, 'shift_id' => $newId]);
        break;
    }

    case 'close': {
        $shiftId = isset($input['shift_id']) ? (int)$input['shift_id'] : 0;
        if ($shiftId <= 0) { shift_fail(400, 'invalid_shift_id'); }
        $shift = $db->getShiftById($shiftId);
        if (!$shift) { shift_fail(404, 'shift_not_found'); }
        if (!empty($shift['closed_at'])) { shift_fail(409, 'shift_already_closed'); }
        if (!$isManager && (int)$shift['cashier_id'] !== $userId) {
            shift_fail(403, 'not_your_shift');
        }
        $closingCash = isset($input['closing_cash']) ? (float)$input['closing_cash'] : 0.0;
        $notes = isset($input['notes']) && $input['notes'] !== '' ? (string)$input['notes'] : null;
        $ok = $db->closeCashierShift($shiftId, $closingCash, $notes);
        if (!$ok) { shift_fail(500, 'close_failed'); }
        AuditLog::record('cashier_shift.close', 'cashier_shift', (string)$shiftId, ['closing_cash' => $closingCash]);
        echo json_encode([
            'success' => true,
            'report' => $db->getShiftReport($shiftId),
        ]);
        break;
    }

    case 'encash': {
        $shiftId = isset($input['shift_id']) ? (int)$input['shift_id'] : 0;
        if ($shiftId <= 0) { shift_fail(400, 'invalid_shift_id'); }
        $shift = $db->getShiftById($shiftId);
        if (!$shift) { shift_fail(404, 'shift_not_found'); }
        if (!empty($shift['closed_at'])) { shift_fail(409, 'shift_closed'); }
        if (!$isManager && (int)$shift['cashier_id'] !== $userId) {
            shift_fail(403, 'not_your_shift');
        }
        $amount = isset($input['amount']) ? (float)$input['amount'] : 0.0;
        $reason = (string)($input['reason'] ?? 'other');
        $ok = $db->addEncashment($shiftId, $amount, $reason);
        if (!$ok) { shift_fail(400, 'encash_failed'); }
        AuditLog::record('cashier_shift.encash', 'cashier_shift', (string)$shiftId, ['amount' => $amount, 'reason' => $reason]);
        echo json_encode([
            'success' => true,
            'report' => $db->getShiftReport($shiftId),
        ]);
        break;
    }

    case 'status': {
        $shift = $db->getAnyOpenShift();
        if (!$shift) {
            echo json_encode(['success' => true, 'shift' => null]);
            break;
        }
        echo json_encode([
            'success' => true,
            'shift'   => $shift,
            'report'  => $db->getShiftReport((int)$shift['id']),
        ]);
        break;
    }

    default:
        shift_fail(400, 'unknown_action');
}
