<?php

ob_start();

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Idempotency.php';

$response = ['success' => false];
$input = [];

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (isset($_SESSION['user_id']) && isset($_SESSION['csrf_token'])) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
        if (!$csrfToken || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
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

    if (!is_array($items) || empty($items) || $total <= 0 || $phone === '') {
        $response['error'] = 'Неверные параметры заказа';
        http_response_code(400);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cleanPhone = preg_replace('/\D/', '', $phone);
    if (strlen($cleanPhone) < 10) {
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

    $orderId = $db->createGuestOrder($items, $total, $deliveryType, $deliveryDetail);
    if (!$orderId) {
        $response['error'] = 'Ошибка при создании заказа';
        http_response_code(500);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = ['orderId' => (int)$orderId];
    if ($idempotencyKey !== null) {
        Idempotency::store($db->getConnection(), 'guest_order_create', $idempotencyKey, $requestHash, $payload);
    }

    $response['success'] = true;
    $response['orderId'] = (int)$orderId;
    http_response_code(200);
} catch (Throwable $e) {
    error_log("Guest order processing error: " . $e->getMessage());
    error_log("Input data: " . print_r($input, true));
    $response['error'] = 'Внутренняя ошибка сервера';
    http_response_code(500);
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;

