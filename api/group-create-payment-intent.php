<?php
/**
 * group-create-payment-intent.php (Phase 7.5, 2026-04-28).
 *
 * Creates a YooKassa payment for one payer's share of a group_orders row.
 * Returns the YK confirmation_url so the client redirects the user there.
 *
 * Input (JSON body):
 *   group_code   string   required — short code from group_orders.code
 *   payer_label  string   required — "Маша", "Петя", "Я", etc.
 *   seat_label   string   optional — if set, amount = seat total; else amount = (group total - already paid intents) / share_count
 *   share_count  int      optional — for "split equally" mode (default 1)
 *   csrf_token   string   required (or X-CSRF-Token header)
 *
 * Output (JSON):
 *   { success, intent_id, paymentUrl, amount }
 */

ob_start();
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$groupCode  = trim((string)($input['group_code']  ?? ''));
$payerLabel = trim((string)($input['payer_label'] ?? ''));
$seatLabel  = isset($input['seat_label']) ? trim((string)$input['seat_label']) : null;
$shareCount = max(1, min(20, (int)($input['share_count'] ?? 1)));

if ($groupCode === '' || $payerLabel === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_fields']);
    exit;
}

$db = Database::getInstance();
$group = $db->getGroupOrderByCode($groupCode);
if (!$group) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'group_not_found']);
    exit;
}
if (!in_array((string)$group['status'], ['open', 'submitted'], true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'group_not_payable', 'status' => $group['status']]);
    exit;
}

$groupId   = (int)$group['id'];
$total     = $db->getGroupOrderTotal($groupId);
$paid      = $db->getGroupPaidTotal($groupId);
$remaining = max(0, $total - $paid);

if ($seatLabel !== null && $seatLabel !== '') {
    $amount = $db->getGroupSeatTotal($groupId, $seatLabel);
} else {
    $amount = $shareCount > 0 ? round($remaining / $shareCount, 2) : 0;
}

if ($amount <= 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'amount_zero', 'remaining' => $remaining]);
    exit;
}

$intentId = $db->createGroupPaymentIntent(
    $groupId,
    $payerLabel,
    $seatLabel !== '' ? $seatLabel : null,
    $amount,
    'card'
);
if (!$intentId) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'intent_create_failed']);
    exit;
}

// ─── YooKassa payment ─────────────────────────────────────────────
$shopId    = (string)json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true);
$secretKey = (string)json_decode($db->getSetting('yookassa_secret_key') ?? '""', true);

if ($shopId === '' || $secretKey === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'yookassa_not_configured']);
    exit;
}

$siteHost = $_SERVER['HTTP_HOST'] ?? 'menu.labus.pro';
$returnUrl = "https://{$siteHost}/group.php?code=" . urlencode($groupCode);

$idempotenceKey = 'group_' . $groupId . '_' . $intentId . '_' . substr(bin2hex(random_bytes(8)), 0, 12);

$payload = [
    'amount' => [
        'value'    => number_format($amount, 2, '.', ''),
        'currency' => 'RUB',
    ],
    'capture' => true,
    'confirmation' => [
        'type' => 'redirect',
        'return_url' => $returnUrl,
    ],
    'description' => "Доля {$payerLabel} (стол {$group['table_label']}, группа #{$groupId})",
    'metadata' => [
        'kind'         => 'group_intent',
        'group_id'     => (string)$groupId,
        'intent_id'    => (string)$intentId,
        'payer_label'  => $payerLabel,
    ],
];

$ch = curl_init('https://api.yookassa.ru/v3/payments');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_USERPWD        => "{$shopId}:{$secretKey}",
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Idempotence-Key: ' . $idempotenceKey,
    ],
    CURLOPT_TIMEOUT        => 12,
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$body) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'yookassa_failed', 'http' => $code]);
    exit;
}

$resp = json_decode($body, true);
$ykId = (string)($resp['id'] ?? '');
$url  = (string)($resp['confirmation']['confirmation_url'] ?? '');
if ($ykId === '' || $url === '') {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'yookassa_malformed']);
    exit;
}

$db->attachYkPaymentIdToGroupIntent($intentId, $ykId);

echo json_encode([
    'success'    => true,
    'intent_id'  => $intentId,
    'paymentUrl' => $url,
    'amount'     => $amount,
    'remaining'  => max(0, $remaining - $amount),
], JSON_UNESCAPED_UNICODE);
