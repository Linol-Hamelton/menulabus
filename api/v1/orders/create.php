<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('POST');
$user = api_v1_auth_user_from_bearer();
$input = ApiResponse::readJsonBody();

$items = $input['items'] ?? null;
$total = $input['total'] ?? null;
$deliveryType = (string)($input['delivery_type'] ?? 'bar');
$deliveryDetail = (string)($input['delivery_details'] ?? '');

if (!is_array($items) || empty($items) || !is_numeric($total)) {
    ApiResponse::error('Invalid order payload', 400);
}
if ($deliveryType === 'delivery' && trim($deliveryDetail) === '') {
    ApiResponse::error('delivery_details is required for delivery', 400);
}
if ($deliveryType === 'table' && trim($deliveryDetail) === '') {
    ApiResponse::error('delivery_details is required for table', 400);
}

$idempotencyKey = Idempotency::getHeaderKey();
$requestHash = Idempotency::hashPayload([
    'user_id' => (int)$user['id'],
    'items' => $items,
    'total' => (float)$total,
    'delivery_type' => $deliveryType,
    'delivery_details' => $deliveryDetail,
]);

$db = Database::getInstance();
$pdo = $db->getConnection();

if ($idempotencyKey !== null) {
    $hit = Idempotency::find($pdo, 'api_v1_order_create', $idempotencyKey, $requestHash);
    if ($hit && !empty($hit['conflict'])) {
        ApiResponse::error('Idempotency-Key was already used with a different payload', 409);
    }
    if ($hit && is_array($hit['response'])) {
        ApiResponse::success($hit['response']);
    }
}

$orderId = $db->createOrder((int)$user['id'], $items, (float)$total, $deliveryType, $deliveryDetail);
if (!$orderId) {
    ApiResponse::error('Failed to create order', 500);
}

$createdOrder = $db->getOrderById((int)$orderId);
$createdStatus = (string)($createdOrder['status'] ?? '');

$data = [
    'order_id' => (int)$orderId,
    'status' => $createdStatus,
];

if ($idempotencyKey !== null) {
    Idempotency::store($pdo, 'api_v1_order_create', $idempotencyKey, $requestHash, $data);
}

ApiResponse::success($data, 201);

