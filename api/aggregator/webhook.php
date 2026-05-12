<?php
/**
 * api/aggregator/webhook.php — Phase 36 (Yandex.Еда + DC inbound webhook).
 *
 * POST /api/aggregator/webhook.php?provider=yandex_eda|delivery_club
 * Headers: X-YandexEda-Signature OR X-DC-Signature: hex(HMAC-SHA256(body, secret))
 * Body: JSON per provider's schema (see adapter docblock).
 *
 * Idempotent: re-posting the same external order id returns the existing
 * order id without inserting a duplicate.
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/Aggregator/YandexEda.php';
require_once __DIR__ . '/../../lib/Aggregator/DeliveryClub.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$provider = (string)($_GET['provider'] ?? '');
$adapters = [
    'yandex_eda'    => \Cleanmenu\Aggregator\YandexEda::class,
    'delivery_club' => \Cleanmenu\Aggregator\DeliveryClub::class,
];
if (!isset($adapters[$provider])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_provider']);
    exit;
}
$adapter = $adapters[$provider];

$db = Database::getInstance();
$settings = $db->getAggregatorSettings($provider);
if (!$settings || (int)$settings['enabled'] !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'provider_disabled']);
    exit;
}
$secret = (string)($settings['webhook_secret'] ?? '');
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'webhook_secret_missing']);
    exit;
}

// Read raw body BEFORE any decoding, so HMAC matches what the sender signed.
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_body']);
    exit;
}

$sigHeader = $provider === 'yandex_eda'
    ? (string)($_SERVER['HTTP_X_YANDEXEDA_SIGNATURE'] ?? '')
    : (string)($_SERVER['HTTP_X_DC_SIGNATURE'] ?? '');

if (!$adapter::verifySignature($rawBody, $sigHeader, $secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

try {
    $normalized = $adapter::normalize($payload, $db);
    if (($normalized['external_id'] ?? '') === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_external_id']);
        exit;
    }
    $orderId = $db->createOrderFromAggregator($provider, $normalized);
    if (!$orderId) {
        http_response_code(500);
        $resp = ['ok' => false, 'error' => 'create_failed'];
        // Surface PDO error message only when ?debug=1 is passed (for diagnostics).
        if (isset($_GET['debug'])) {
            $resp['debug'] = $db->lastAggregatorError ?? null;
        }
        echo json_encode($resp);
        exit;
    }
    $db->touchAggregatorWebhookAt($provider);
    echo json_encode([
        'ok'           => true,
        'order_id'     => $orderId,
        'external_id'  => $normalized['external_id'],
    ]);
} catch (Throwable $e) {
    error_log('aggregator webhook error (' . $provider . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}
