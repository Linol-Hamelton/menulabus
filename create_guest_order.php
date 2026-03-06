<?php

ob_start();

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Idempotency.php';
require_once __DIR__ . '/lib/CheckoutErrorLog.php';

$response = ['success' => false];
$input = [];

function guest_order_fail(string $category, string $reason, int $statusCode, array $context = []): void
{
    CheckoutErrorLog::log('create_guest_order.php', $category, $reason, $statusCode, $context);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (isset($_SESSION['user_id']) && isset($_SESSION['csrf_token'])) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
        if (!$csrfToken || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            guest_order_fail('auth', 'csrf_mismatch', 403, [
                'has_csrf_header' => !empty($_SERVER['HTTP_X_CSRF_TOKEN']),
            ]);
            $response['error'] = 'Ошибка безопасности (CSRF)';
            http_response_code(403);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $items = $input['items'] ?? null;
    $total = isset($input['total']) ? (float)$input['total'] : 0;
    $phone = (string)($input['phone'] ?? '');
    $deliveryType = (string)($input['delivery_type'] ?? 'bar');
    $deliveryDetail = (string)($input['delivery_details'] ?? '');

    if (!is_array($items) || empty($items) || $phone === '') {
        guest_order_fail('validation', 'invalid_order_payload', 400, [
            'items_count' => is_array($items) ? count($items) : 0,
            'has_phone' => $phone !== '',
            'delivery_type' => $deliveryType,
        ]);
        $response['error'] = 'Неверные параметры заказа';
        http_response_code(400);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cleanPhone = preg_replace('/\D/', '', $phone);
    if (strlen($cleanPhone) < 10) {
        guest_order_fail('validation', 'invalid_phone', 400, [
            'digits_count' => strlen($cleanPhone),
        ]);
        $response['error'] = 'Укажите корректный номер телефона';
        http_response_code(400);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $formattedPhone = '+7' . substr($cleanPhone, -10);
    $deliveryDetail = trim($deliveryDetail);
    if ($deliveryDetail !== '') {
        $deliveryDetail .= '; ';
    }
    $deliveryDetail .= 'Телефон: ' . $formattedPhone;

    $db = Database::getInstance();
    $idempotencyKey = Idempotency::getHeaderKey();
    $requestHash = Idempotency::hashPayload([
        'items' => $items,
        'total' => $total,
        'phone' => $formattedPhone,
        'delivery_type' => $deliveryType,
        'delivery_details' => $deliveryDetail,
    ]);

    if ($idempotencyKey !== null) {
        $existing = Idempotency::find($db->getConnection(), 'guest_order_create', $idempotencyKey, $requestHash);
        if ($existing && !empty($existing['conflict'])) {
            guest_order_fail('idempotency', 'key_conflict', 409, [
                'idempotency_key_present' => true,
            ]);
            $response['error'] = 'Idempotency-Key уже использован с другим payload';
            http_response_code(409);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($existing && is_array($existing['response'])) {
            $cached = $existing['response'];
            $cached['idempotent_replay'] = true;
            $response = array_merge(['success' => true], $cached);
            http_response_code(200);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $sanitized = $db->sanitizeOrderItemsForCheckout($items);
    $items = $sanitized['items'] ?? [];
    $removedItems = $sanitized['removed_items'] ?? [];
    $serverTotal = (float)($sanitized['server_total'] ?? 0);
    $cartAdjusted = !empty($sanitized['cart_adjusted']);

    if (empty($items) || $serverTotal <= 0) {
        guest_order_fail('validation', 'cart_empty_after_menu_sync', 409, [
            'removed_items_count' => is_array($removedItems) ? count($removedItems) : 0,
            'server_total' => $serverTotal,
            'cart_adjusted' => $cartAdjusted,
        ]);
        $response['error'] = 'Корзина пуста после актуализации меню';
        $response['removed_items'] = $removedItems;
        $response['server_total'] = $serverTotal;
        $response['cart_adjusted'] = $cartAdjusted;
        http_response_code(409);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $total = $serverTotal;

    $orderId = $db->createGuestOrder($items, $total, $deliveryType, $deliveryDetail);
    if (!$orderId) {
        guest_order_fail('db', 'create_order_failed', 500, [
            'items_count' => is_array($items) ? count($items) : 0,
            'server_total' => $serverTotal,
        ]);
        $response['error'] = 'Ошибка при создании заказа';
        $response['removed_items'] = $removedItems;
        $response['server_total'] = $serverTotal;
        $response['cart_adjusted'] = $cartAdjusted;
        http_response_code(500);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = [
        'orderId' => (int)$orderId,
        'removed_items' => $removedItems,
        'server_total' => $serverTotal,
        'cart_adjusted' => $cartAdjusted,
    ];
    if ($idempotencyKey !== null) {
        Idempotency::store($db->getConnection(), 'guest_order_create', $idempotencyKey, $requestHash, $payload);
    }

    $response['success'] = true;
    $response['orderId'] = (int)$orderId;
    $response['removed_items'] = $removedItems;
    $response['server_total'] = $serverTotal;
    $response['cart_adjusted'] = $cartAdjusted;
    http_response_code(200);
} catch (Throwable $e) {
    guest_order_fail('db', 'unhandled_exception', 500, [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);
    error_log("Guest order processing error: " . $e->getMessage());
    error_log("Input data: " . print_r($input, true));
    $response['error'] = 'Внутренняя ошибка сервера';
    http_response_code(500);
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
