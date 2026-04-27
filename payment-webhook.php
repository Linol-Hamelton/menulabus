<?php
/**
 * payment-webhook.php — ЮKassa webhook receiver
 *
 * ЮKassa calls this endpoint when payment status changes.
 * We verify the payment by fetching it from the API (more secure than IP check).
 * Docs: https://yookassa.ru/developers/using-api/webhooks
 */

define('LABUS_CTX', 'webhook');
require_once __DIR__ . '/db.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');

// ── Detect T-Bank webhook (form-encoded or has TerminalKey field) ───────────
$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($contentType, 'application/x-www-form-urlencoded') !== false
    || !empty($_POST['TerminalKey'])
    || (isset($raw) && strpos($raw, 'TerminalKey=') !== false)) {
    handleTBankWebhook();
    exit;
}

$payload = json_decode($raw, true);

if (!$payload || !isset($payload['event'], $payload['object']['id'])) {
    http_response_code(400);
    exit;
}

$event     = $payload['event'];         // e.g. "payment.succeeded"
$paymentId = $payload['object']['id']; // ЮKassa payment UUID

// We only care about these events
$validEvents = ['payment.succeeded', 'payment.canceled'];
if (!in_array($event, $validEvents, true)) {
    http_response_code(200); // ACK, but do nothing
    exit;
}

$db = Database::getInstance();

// Read ЮKassa credentials
$shopId    = json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true) ?? '';
$secretKey = json_decode($db->getSetting('yookassa_secret_key') ?? '""', true) ?? '';

if ($shopId === '' || $secretKey === '') {
    error_log("payment-webhook: ЮKassa credentials not configured");
    http_response_code(200);
    exit;
}

// Verify payment by fetching it from ЮKassa API
$verified = yookassaGetPayment($paymentId, $shopId, $secretKey);
if (!$verified) {
    error_log("payment-webhook: failed to verify payment $paymentId");
    http_response_code(200);
    exit;
}

$apiStatus = $verified['status'] ?? ''; // pending | waiting_for_capture | succeeded | canceled

// ── Phase 7.5: group split-bill payment branch ───────────────────────────
// If the YK payment carries metadata.kind = 'group_intent' it's a share
// payment, not a regular order payment. Mark the intent paid; if the
// aggregate covers the group total, fire cleanmenu_on_order_paid for
// every underlying order created at submitGroupOrder time.
$metadata = $verified['metadata'] ?? [];
if (($metadata['kind'] ?? '') === 'group_intent') {
    if ($apiStatus === 'succeeded') {
        $intent = $db->markGroupPaymentIntentPaid($paymentId);
        if ($intent) {
            $groupId = (int)$intent['group_order_id'];
            $total   = $db->getGroupOrderTotal($groupId);
            $paid    = $db->getGroupPaidTotal($groupId);
            if ($paid + 0.005 >= $total && $total > 0) {
                $db->markGroupOrderPaid($groupId);
                // Each per-seat order created at submit time is in the
                // orders table; fire the paid hook for those that are
                // not yet paid. We pull them by group reference (kept
                // in their delivery_details / external_id by submit).
                if (method_exists($db, 'getOrdersByGroupOrderId')) {
                    foreach ($db->getOrdersByGroupOrderId($groupId) as $row) {
                        $oid = (int)$row['id'];
                        $db->updateOrderPayment($oid, $paymentId, 'paid');
                        cleanmenu_on_order_paid($db, $oid);
                    }
                }
                error_log("payment-webhook: group #{$groupId} fully paid (total={$total} paid={$paid})");
            }
        }
        http_response_code(200);
        echo 'ok';
        exit;
    } elseif ($apiStatus === 'canceled') {
        // Best-effort: mark intent failed so the user can retry. Other
        // intents on the same group keep their state.
        try {
            $r = new ReflectionClass($db);
            $p = $r->getProperty('connection');
            $p->setAccessible(true);
            $pdo = $p->getValue($db);
            $pdo->prepare("UPDATE group_payment_intents SET status='failed' WHERE yk_payment_id = :y AND status='pending'")
                ->execute([':y' => $paymentId]);
        } catch (Throwable $_) {}
        http_response_code(200);
        echo 'ok';
        exit;
    }
}

// Find order by payment_id
$order = $db->getOrderByPaymentId($paymentId);
if (!$order) {
    error_log("payment-webhook: no order found for payment_id=$paymentId");
    http_response_code(200);
    exit;
}

$orderId = (int)$order['id'];

if ($apiStatus === 'succeeded') {
    $db->updateOrderPayment($orderId, $paymentId, 'paid');
    error_log("payment-webhook: order #$orderId payment SUCCEEDED");
    cleanmenu_on_order_paid($db, $orderId);
} elseif ($apiStatus === 'canceled') {
    $db->updateOrderPayment($orderId, $paymentId, 'cancelled');
    // Also mark order as rejected if it hasn't been processed yet
    if (in_array(mb_strtolower($order['status'] ?? ''), ['принят', 'приём'], true)) {
        $db->updateOrderStatus($orderId, 'отказ', null);
    }
    error_log("payment-webhook: order #$orderId payment CANCELLED");
}

http_response_code(200);
echo 'ok';

// ── T-Bank Webhook Handler ──────────────────────────────────────────────────

function handleTBankWebhook(): void
{
    // T-Bank can send form-encoded or raw body; parse both
    $data = $_POST;
    if (empty($data)) {
        global $raw;
        parse_str($raw ?? '', $data);
    }

    if (empty($data['TerminalKey']) || empty($data['PaymentId'])) {
        http_response_code(400);
        echo 'Bad Request';
        return;
    }

    $db     = Database::getInstance();
    $tbPass = json_decode($db->getSetting('tbank_password') ?? '""', true) ?? '';

    if ($tbPass === '') {
        error_log("T-Bank webhook: password not configured");
        http_response_code(200);
        echo 'OK';
        return;
    }

    // Verify token
    require_once __DIR__ . '/lib/TBank.php';
    $expected = tBankToken($data, $tbPass);
    if (!hash_equals($expected, (string)($data['Token'] ?? ''))) {
        error_log("T-Bank webhook: invalid token");
        http_response_code(400);
        echo 'Invalid token';
        return;
    }

    $orderId   = (int)($data['OrderId'] ?? 0);
    $paymentId = (string)($data['PaymentId'] ?? '');
    $status    = (string)($data['Status'] ?? '');

    if (!$orderId) {
        http_response_code(200);
        echo 'OK';
        return;
    }

    if ($status === 'CONFIRMED') {
        $db->updateOrderPayment($orderId, $paymentId, 'paid', 'tbank_sbp');
        error_log("T-Bank webhook: order #$orderId payment CONFIRMED");
        cleanmenu_on_order_paid($db, $orderId);
    } elseif (in_array($status, ['REJECTED', 'AUTH_FAIL', 'REVERSED'], true)) {
        $db->updateOrderPayment($orderId, $paymentId, 'cancelled', 'tbank_sbp');
        $order = $db->getOrderById($orderId);
        if ($order && in_array(mb_strtolower($order['status'] ?? ''), ['принят', 'приём'], true)) {
            $db->updateOrderStatus($orderId, 'отказ', null);
        }
        error_log("T-Bank webhook: order #$orderId payment $status");
    }

    http_response_code(200);
    echo 'OK';
}

// ── Helpers ────────────────────────────────────────────────────────────────

// cleanmenu_on_order_paid() lives in lib/OrderPaidHook.php so both
// payment-webhook.php (YooKassa + T-Bank branches) and confirm-cash-payment.php
// can require it without risking "Cannot redeclare" fatals.
require_once __DIR__ . '/lib/OrderPaidHook.php';

/**
 * Fetch a payment object from the ЮKassa API to verify it.
 */
function yookassaGetPayment(string $paymentId, string $shopId, string $secretKey): ?array
{
    $ch = curl_init("https://api.yookassa.ru/v3/payments/" . urlencode($paymentId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$shopId:$secretKey",
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$result) {
        return null;
    }
    return json_decode($result, true) ?: null;
}
