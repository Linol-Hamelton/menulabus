<?php
/**
 * api/save-staff.php — staff management endpoints (Phase 7.4).
 *
 * POST JSON {
 *   action: 'save_shift' | 'delete_shift' |
 *           'clock_in' | 'clock_out' |
 *           'compute_tips' | 'save_tip_split',
 *   ...payload,
 *   csrf_token: string
 * }
 *
 * - save_shift / delete_shift / compute_tips / save_tip_split: admin/owner.
 * - clock_in / clock_out: self (employee+), the server resolves user_id from session.
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
$role = (string)($_SESSION['user_role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$isManager = in_array($role, ['admin', 'owner'], true);

function staff_require_manager(bool $isManager): void {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'manager_only']);
        exit;
    }
}

$action = (string)($input['action'] ?? '');

switch ($action) {
    case 'save_shift':
        staff_require_manager($isManager);
        $id         = isset($input['id']) ? (int)$input['id'] : null;
        $uid        = isset($input['user_id']) && $input['user_id'] !== '' ? (int)$input['user_id'] : null;
        $roleInput  = (string)($input['role'] ?? '');
        $locId      = isset($input['location_id']) && $input['location_id'] !== '' ? (int)$input['location_id'] : null;
        $startsAt   = (string)($input['starts_at'] ?? '');
        $endsAt     = (string)($input['ends_at'] ?? '');
        $note       = isset($input['note']) ? (string)$input['note'] : null;
        $savedId    = $db->saveShift($id, $uid, $roleInput, $locId, $startsAt, $endsAt, $note);
        if ($savedId === null) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_params']); exit; }
        echo json_encode(['success' => true, 'id' => $savedId]);
        break;

    case 'delete_shift':
        staff_require_manager($isManager);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        echo json_encode(['success' => $db->deleteShift($id)]);
        break;

    case 'clock_in':
        if ($userId <= 0) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'unauthorized']); exit; }
        $shiftId = isset($input['shift_id']) && $input['shift_id'] !== '' ? (int)$input['shift_id'] : null;
        $teId = $db->clockIn($userId, $shiftId);
        echo json_encode(['success' => $teId !== null, 'time_entry_id' => $teId]);
        break;

    case 'clock_out':
        if ($userId <= 0) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'unauthorized']); exit; }
        $note = isset($input['note']) ? (string)$input['note'] : null;
        $ok = $db->clockOut($userId, $note);
        echo json_encode(['success' => $ok]);
        break;

    case 'compute_tips':
        staff_require_manager($isManager);
        $fromDt = (string)($input['period_from'] ?? '');
        $toDt   = (string)($input['period_to'] ?? '');
        if ($fromDt === '' || $toDt === '') {
            http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_period']); exit;
        }
        $pool    = $db->getTipsPoolForPeriod($fromDt, $toDt);
        $minutes = $db->getTimeWorkedByUser($fromDt, $toDt);
        $totalMinutes = array_sum($minutes);
        $allocation = [];
        foreach ($minutes as $uid => $min) {
            $share = $totalMinutes > 0 ? ($min / $totalMinutes) : 0;
            $allocation[] = [
                'user_id' => (int)$uid,
                'minutes' => (int)$min,
                'share'   => round($share, 4),
                'amount'  => round($pool * $share, 2),
            ];
        }
        echo json_encode([
            'success'    => true,
            'pool'       => round($pool, 2),
            'minutes'    => (int)$totalMinutes,
            'allocation' => $allocation,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save_tip_split':
        staff_require_manager($isManager);
        $fromDt = (string)($input['period_from'] ?? '');
        $toDt   = (string)($input['period_to'] ?? '');
        $pool   = (float)($input['pool'] ?? 0);
        $alloc  = is_array($input['allocation'] ?? null) ? $input['allocation'] : [];
        $id = $db->saveTipSplit($fromDt, $toDt, $pool, $alloc, $userId);
        if ($id === null) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_params']); exit; }
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
