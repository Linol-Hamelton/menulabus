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
require_once __DIR__ . '/../../lib/AuditLog.php';
require_once __DIR__ . '/../../lib/RateLimiter.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$provider = (string)($_GET['provider'] ?? '');

// Rate limit: 60 webhooks per minute per (provider + IP). Real partners
// don't burst — anything above is either misconfigured client or attacker.
$clientIp = RateLimiter::clientIp();
if (!RateLimiter::allow('webhook:' . $provider . ':' . $clientIp, 60, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    AuditLog::record('aggregator.webhook.rate_limited', 'aggregator_settings', $provider, ['ip' => $clientIp]);
    exit;
}
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
        echo json_encode(['ok' => false, 'error' => 'create_failed']);
        exit;
    }
    $db->touchAggregatorWebhookAt($provider);
    AuditLog::record('aggregator.webhook.received', 'order', (string)$orderId, [
        'provider'    => $provider,
        'external_id' => $normalized['external_id'],
        'total'       => $normalized['total'] ?? 0,
    ]);
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
