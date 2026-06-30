<?php
/**
 * api/save-stocktake.php — Phase 41 (физическая инвентаризация).
 *
 * Actions: start / count / close / cancel.
 * count action доступен employee+ (физически считают сотрудники);
 * start/close/cancel — admin/owner only.
 */

$required_role = 'employee';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../lib/Billing/TierGate.php';

header('Content-Type: application/json; charset=utf-8');

// Phase L103.5e — gate behind inventory.stocktake feature (tier 6+ «Кухня+»).
\Cleanmenu\Billing\TierGate::requireFeature(
    \Cleanmenu\Billing\Features::INVENTORY_STOCKTAKE,
    'Физическая инвентаризация'
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
$isManager = in_array($role, ['admin', 'owner'], true);

function st_fail(int $code, string $err, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => false, 'error' => $err], $extra));
    exit;
}
function st_require_manager(bool $isManager): void {
    if (!$isManager) st_fail(403, 'manager_only');
}

$action = (string)($input['action'] ?? '');

switch ($action) {
    case 'start': {
        st_require_manager($isManager);
        $name = (string)($input['name'] ?? '');
        $notes = isset($input['notes']) ? trim((string)$input['notes']) : null;
        $r = $db->startStocktakeSession($name, $userId, $notes !== '' ? $notes : null);
        if (is_array($r) && isset($r['error'])) {
            st_fail(409, $r['error']);
        }
        if (!is_int($r)) st_fail(500, 'create_failed');
        AuditLog::record('stocktake.start', 'stocktake_session', (string)$r, ['name' => $name]);
        echo json_encode(['success' => true, 'session_id' => $r]);
        break;
    }

    case 'count': {
        $sessionId = (int)($input['session_id'] ?? 0);
        $ingredientId = (int)($input['ingredient_id'] ?? 0);
        $counted = isset($input['counted_qty']) ? (float)$input['counted_qty'] : null;
        $notes = isset($input['notes']) ? trim((string)$input['notes']) : null;
        if ($sessionId <= 0 || $ingredientId <= 0 || $counted === null) st_fail(400, 'invalid_params');
        if ($counted < 0) st_fail(400, 'negative_count');
        if (!$db->updateStocktakeItem($sessionId, $ingredientId, $counted, $userId, $notes !== '' ? $notes : null)) {
            st_fail(500, 'update_failed');
        }
        // No audit_log per count — too noisy (could be many per session).
        echo json_encode(['success' => true]);
        break;
    }

    case 'close': {
        st_require_manager($isManager);
        $sessionId = (int)($input['session_id'] ?? 0);
        if ($sessionId <= 0) st_fail(400, 'invalid_session_id');
        $r = $db->closeStocktakeSession($sessionId, $userId);
        if (!is_array($r) || isset($r['error'])) {
            st_fail(409, $r['error'] ?? 'close_failed', ['message' => $r['message'] ?? null]);
        }
        AuditLog::record('stocktake.close', 'stocktake_session', (string)$sessionId, [
            'applied'     => $r['applied'] ?? 0,
            'skipped'     => $r['skipped'] ?? 0,
            'total_delta' => $r['total_delta'] ?? 0,
        ]);
        echo json_encode(['success' => true] + $r);
        break;
    }

    case 'cancel': {
        st_require_manager($isManager);
        $sessionId = (int)($input['session_id'] ?? 0);
        if ($sessionId <= 0) st_fail(400, 'invalid_session_id');
        if (!$db->cancelStocktakeSession($sessionId, $userId)) {
            st_fail(409, 'cancel_failed');
        }
        AuditLog::record('stocktake.cancel', 'stocktake_session', (string)$sessionId, []);
        echo json_encode(['success' => true]);
        break;
    }

    default:
        st_fail(400, 'unknown_action');
}
