<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('GET');
$user = api_v1_auth_user_from_bearer();

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    ApiResponse::error('order_id is required', 400);
}

$db = Database::getInstance();
$order = $db->getOrderById($orderId);
if (!$order) {
    ApiResponse::error('Order not found', 404);
}

$role = (string)($user['role'] ?? '');
$isPrivileged = in_array($role, ['employee', 'owner', 'admin'], true);
if (!$isPrivileged && (int)$order['user_id'] !== (int)$user['id']) {
    ApiResponse::error('Forbidden', 403);
}

ApiResponse::success([
    'order_id' => (int)$order['id'],
    'status' => (string)$order['status'],
    'total' => (float)$order['total'],
    'delivery_type' => (string)($order['delivery_type'] ?? ''),
    'delivery_details' => (string)($order['delivery_details'] ?? ''),
    'updated_at' => (string)($order['updated_at'] ?? ''),
]);

