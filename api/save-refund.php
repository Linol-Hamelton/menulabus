<?php
/**
 * api/save-refund.php — Phase 35 (Refund / чек коррекции).
 *
 * POST JSON {
 *   action: 'create',
 *   order_id: int,
 *   mode: 'full' | 'partial',
 *   amount?: float       // required when mode=partial
 *   reason?: string,
 *   csrf_token: string
 * }
 *
 * Side effects:
 *   - Creates an order_refunds row (linked to currently-open shift if any).
 *   - Best-effort: calls АТОЛ Онлайн `sendCorrectionReceipt` and stores uuid.
 *   - Updates order payment_status → 'refunded' for full refund.
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
if (!in_array($role, ['employee', 'admin', 'owner'], true) || $userId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$action = (string)($input['action'] ?? '');
if ($action !== 'create') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unknown_action']);
    exit;
}

$orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_order_id']);
    exit;
}

$order = $db->getOrderById($orderId);
if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'order_not_found']);
    exit;
}

$paymentStatus = (string)($order['payment_status'] ?? '');
if (!in_array($paymentStatus, ['paid'], true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'order_not_paid', 'payment_status' => $paymentStatus]);
    exit;
}

$mode = (string)($input['mode'] ?? 'full');
$total = (float)($order['total'] ?? 0);
$alreadyRefunded = $db->sumRefundsForOrder($orderId);
$available = max(0.0, $total - $alreadyRefunded);
if ($available <= 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'fully_refunded']);
    exit;
}

if ($mode === 'partial') {
    $amount = isset($input['amount']) ? round((float)$input['amount'], 2) : 0.0;
    if ($amount <= 0 || $amount > $available + 0.001) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_amount', 'available' => $available]);
        exit;
    }
    $isPartial = true;
} else {
    $amount = $available;
    $isPartial = false;
}

$reason = isset($input['reason']) ? trim((string)$input['reason']) : '';
if ($reason === '') { $reason = null; }

$openShift = $db->getAnyOpenShift();
$shiftId = $openShift ? (int)$openShift['id'] : null;

$refundId = $db->createOrderRefund($orderId, $userId, $amount, $isPartial, $reason, $shiftId);
if (!$refundId) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'create_failed']);
    exit;
}

// If a full refund (after this call) leaves nothing remaining → mark order refunded.
if (!$isPartial || abs(($alreadyRefunded + $amount) - $total) < 0.01) {
    try {
        $db->setOrderPaymentStatus($orderId, 'refunded');
    } catch (Throwable $e) {
        error_log('save-refund setOrderPaymentStatus: ' . $e->getMessage());
    }
}

// Best-effort fiscal correction receipt (АТОЛ).
$fiscal = ['uuid' => null, 'status' => null];
try {
    $provider = (string)json_decode($db->getSetting('fiscal_provider') ?? '""', true);
    if ($provider === 'atol') {
        $cfg = [
            'login'           => (string)json_decode($db->getSetting('fiscal_atol_login') ?? '""', true),
            'password'        => (string)json_decode($db->getSetting('fiscal_atol_password') ?? '""', true),
            'group_code'      => (string)json_decode($db->getSetting('fiscal_atol_group_code') ?? '""', true),
            'inn'             => (string)json_decode($db->getSetting('fiscal_atol_inn') ?? '""', true),
            'payment_address' => (string)json_decode($db->getSetting('fiscal_atol_payment_address') ?? '""', true),
            'sno'             => (string)json_decode($db->getSetting('fiscal_atol_sno') ?? '"usn_income"', true),
            'sandbox'         => (string)json_decode($db->getSetting('fiscal_atol_sandbox') ?? '"0"', true) === '1',
        ];
        $hasAllCreds = true;
        foreach (['login', 'password', 'group_code', 'inn', 'payment_address'] as $req) {
            if ($cfg[$req] === '') { $hasAllCreds = false; break; }
        }
        if ($hasAllCreds) {
            require_once __DIR__ . '/../lib/Fiscal/AtolOnline.php';
            $atol = new \Cleanmenu\Fiscal\AtolOnline($cfg);

            $items = $order['items'] ?? [];
            if (is_string($items)) {
                $items = json_decode($items, true) ?: [];
            }
            $email = '';
            $custId = (int)($order['user_id'] ?? 0);
            if ($custId > 0) {
                $u = $db->getUserById($custId);
                if ($u && !empty($u['email'])) $email = (string)$u['email'];
            }
            $idem = 'refund_' . $refundId . '_' . substr(md5((string)microtime(true)), 0, 12);
            $resp = $atol->sendCorrectionReceipt(
                array_merge($order, ['items' => $items]),
                $amount,
                $isPartial,
                $email,
                $idem
            );
            $fiscal['uuid']   = $resp['uuid'] ?? null;
            $fiscal['status'] = $resp['status'] ?? 'wait';
            $db->updateRefundFiscal($refundId, $fiscal['uuid'], $fiscal['status'], null);
        }
    }
} catch (Throwable $e) {
    error_log('save-refund fiscal correction error: ' . $e->getMessage());
    $db->updateRefundFiscal($refundId, null, 'fail', null);
    $fiscal['status'] = 'fail';
}

echo json_encode([
    'success'  => true,
    'refund_id'=> $refundId,
    'amount'   => $amount,
    'is_partial' => $isPartial,
    'remaining'=> max(0.0, $total - $alreadyRefunded - $amount),
    'fiscal'   => $fiscal,
]);
