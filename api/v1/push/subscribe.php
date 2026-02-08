<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('POST');
$input = ApiResponse::readJsonBody();

$subscription = $input['subscription'] ?? null;
$phone = $input['phone'] ?? null;
$orderId = $input['order_id'] ?? null;

if (!$subscription || !isset($subscription['endpoint'], $subscription['keys']['p256dh'], $subscription['keys']['auth'])) {
    ApiResponse::error('Invalid subscription payload', 400);
}

$userId = null;
$bearer = MobileTokenAuth::extractBearerToken();
if ($bearer) {
    $payload = MobileTokenAuth::verifyToken($bearer, 'access');
    if ($payload) {
        $userId = (int)$payload['sub'];
    }
}
if ($userId === null && isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
}

if (!$userId && (!$phone || !$orderId)) {
    ApiResponse::error('Guest subscription requires phone and order_id', 400);
}

$db = Database::getInstance();
$stmt = $db->prepare("
    SELECT id FROM push_subscriptions
    WHERE endpoint = ? AND p256dh = ? AND auth = ?
");
$stmt->execute([
    $subscription['endpoint'],
    $subscription['keys']['p256dh'],
    $subscription['keys']['auth'],
]);
$existing = $stmt->fetchColumn();

if ($existing) {
    $update = $db->prepare("
        UPDATE push_subscriptions
        SET user_id = ?, phone = ?, order_id = ?, created_at = NOW()
        WHERE id = ?
    ");
    $update->execute([$userId, $phone, $orderId, $existing]);
} else {
    $insert = $db->prepare("
        INSERT INTO push_subscriptions
        (user_id, phone, order_id, endpoint, p256dh, auth, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $insert->execute([
        $userId,
        $phone,
        $orderId,
        $subscription['endpoint'],
        $subscription['keys']['p256dh'],
        $subscription['keys']['auth'],
    ]);
}

ApiResponse::success(['saved' => true]);
