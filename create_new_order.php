<?php

ob_start();

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Idempotency.php';

$response = ['success' => false];
$input = [];

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $response['error'] = 'Ошибка безопасности (CSRF)';
        http_response_code(403);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        $response['error'] = 'Требуется авторизация';
        http_response_code(401);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = Database::getInstance();
    $user = $db->getUserById((int)$_SESSION['user_id']);
    if (!$user) {
        $response['error'] = 'Доступ запрещен';
        http_response_code(403);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (isset($input['items'])) {
        $items = $input['items'];
        $total = (float)($input['total'] ?? 0);
        $deliveryType = (string)($input['delivery_type'] ?? 'bar');
        $deliveryDetail = (string)($input['delivery_details'] ?? '');

        if (!is_array($items) || empty($items) || $total <= 0) {
            $response['error'] = 'Неверные параметры заказа';
            http_response_code(400);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($deliveryType === 'delivery' && trim($deliveryDetail) === '') {
            $response['error'] = 'Укажите адрес доставки';
            http_response_code(400);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($deliveryType === 'table' && trim($deliveryDetail) === '') {
            $response['error'] = 'Укажите номер стола';
            http_response_code(400);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $idempotencyKey = Idempotency::getHeaderKey();
        $requestHash = Idempotency::hashPayload([
            'user_id' => (int)$_SESSION['user_id'],
            'items' => $items,
            'total' => $total,
            'delivery_type' => $deliveryType,
            'delivery_details' => $deliveryDetail,
        ]);

        if ($idempotencyKey !== null) {
            $existing = Idempotency::find($db->getConnection(), 'web_order_create', $idempotencyKey, $requestHash);
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

        $orderId = $db->createOrder((int)$_SESSION['user_id'], $items, $total, $deliveryType, $deliveryDetail);
        if (!$orderId) {
            $response['error'] = 'Ошибка при создании заказа';
            http_response_code(500);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $payload = ['orderId' => (int)$orderId];
        if ($idempotencyKey !== null) {
            Idempotency::store($db->getConnection(), 'web_order_create', $idempotencyKey, $requestHash, $payload);
        }

        $response['success'] = true;
        $response['orderId'] = (int)$orderId;
        http_response_code(200);
    } elseif (isset($input['order_id']) && isset($input['action'])) {
        if (($user['role'] ?? '') !== 'employee') {
            $response['error'] = 'Недостаточно прав для изменения статуса';
            http_response_code(403);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $orderId = (int)$input['order_id'];
        $currentStatus = $db->getOrderStatus($orderId);
        $statusFlow = ['Приём', 'готовим', 'доставляем', 'завершён'];
        $currentIndex = array_search($currentStatus, $statusFlow, true);

        if ($currentStatus === false) {
            $response['error'] = 'Заказ не найден';
            http_response_code(404);
        } elseif ($currentIndex === false) {
            $response['error'] = 'Неизвестный текущий статус заказа';
            http_response_code(400);
        } elseif ($currentIndex >= count($statusFlow) - 1) {
            $response['error'] = 'Заказ уже завершён';
            http_response_code(400);
        } else {
            $newStatus = $statusFlow[$currentIndex + 1];
            $success = $db->updateOrderStatus($orderId, $newStatus, (int)$_SESSION['user_id']);

            if ($success) {
                $response['success'] = true;
                $response['new_status'] = $newStatus;
                $response['order_id'] = $orderId;
                http_response_code(200);
            } else {
                $response['error'] = 'Ошибка обновления статуса';
                http_response_code(500);
            }
        }
    } else {
        $response['error'] = 'Неверные параметры запроса';
        http_response_code(400);
    }
} catch (Throwable $e) {
    error_log("Order processing error: " . $e->getMessage());
    error_log("Input data: " . print_r($input, true));
    error_log("Session data: " . print_r($_SESSION, true));
    $response['error'] = 'Внутренняя ошибка сервера';
    http_response_code(500);
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;

