<?php
/**
 * apply-promo.php — validate a promo code against a cart total.
 *
 * POST JSON {
 *   code:        string,
 *   order_total: number,
 *   csrf_token:  string
 * }
 *
 * Public-ish: works for both logged-in users and guests. CSRF still
 * required (cart.php already issues a session CSRF token). This is a
 * PURE read — it does NOT increment used_count. The counter is bumped
 * when the order is actually created and the code is re-resolved by
 * id (see Database::incrementPromoCodeUsage). That keeps abandoned
 * carts from locking a limited-use code against them.
 *
 * Response:
 *   200 { success: true, code, promo_id, discount, new_total }
 *   400 { success: false, error: <slug>, meta? }
 *   403 csrf
 */

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Csrf.php';

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

$code = trim((string)($input['code'] ?? ''));
$orderTotal = isset($input['order_total']) ? (float)$input['order_total'] : 0.0;

if ($code === '' || mb_strlen($code) > 64) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_code']);
    exit;
}
if ($orderTotal <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_order_total']);
    exit;
}

$db = Database::getInstance();
$result = $db->evaluatePromoCode($code, $orderTotal);

if (empty($result['ok'])) {
    http_response_code(400);
    $payload = ['success' => false, 'error' => $result['error'] ?? 'invalid'];
    if (isset($result['min'])) $payload['min'] = $result['min'];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success'   => true,
    'promo_id'  => (int)$result['promo_id'],
    'code'      => (string)$result['code'],
    'discount'  => (float)$result['discount'],
    'new_total' => (float)$result['new_total'],
], JSON_UNESCAPED_UNICODE);
