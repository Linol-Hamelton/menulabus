<?php

ob_start();

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

$response = ['success' => false];

try {
    // Получаем входные данные
    error_log("Delivery details received: " . print_r($deliveryDetail, true));
    $input = json_decode(file_get_contents('php://input'), true);

    // 1. Проверка CSRF токена
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    $response['error'] = 'Ошибка безопасности (CSRF)';
    http_response_code(403);
    echo json_encode($response);
    exit;
}

    // 2. Проверка авторизации пользователя
    if (!isset($_SESSION['user_id'])) {
        $response['error'] = 'Требуется авторизация';
        http_response_code(401);
        echo json_encode($response);
        exit;
    }

    $db = Database::getInstance();
    $user = $db->getUserById($_SESSION['user_id']);

    // 3. Проверка роли (разрешаем и customer и employee)
    if (!$user) {
        $response['error'] = 'Доступ запрещен';
        http_response_code(403);
        echo json_encode($response);
        exit;
    }

    // Определяем тип операции (создание заказа или изменение статуса)
    if (isset($input['items'])) {
        // 4A. Создание нового заказа
        $items = $input['items'];
        $total = $input['total'];
        $deliveryType = $input['delivery_type'] ?? 'bar';
        $deliveryDetail = $input['delivery_details'] ?? '';

        // Проверка обязательных полей для определенных типов доставки
        if ($deliveryType === 'delivery' && empty($deliveryDetail)) {
            $response['error'] = 'Укажите адрес доставки';
            http_response_code(400);
            echo json_encode($response);
            exit;
        }

        if ($deliveryType === 'table' && empty($deliveryDetail)) {
            $response['error'] = 'Укажите номер стола';
            http_response_code(400);
            echo json_encode($response);
            exit;
        }

        $itemsJson = json_encode($items);

        if (!$items || !$total) {
            $response['error'] = 'Неверные параметры заказа';
            http_response_code(400);
            echo json_encode($response);
            exit;
        }

        // Создаем новый заказ с типом доставки
        $success = $db->createOrder($_SESSION['user_id'], $items, $total, $deliveryType, $deliveryDetail);
        if ($success) {
            $response['success'] = true;
            $response['orderId'] = $success;
            http_response_code(200);

            // Очищаем корзину только после успешного создания заказа
        } else {
            $response['error'] = 'Ошибка при создании заказа';
            http_response_code(500);
        }
    } elseif (isset($input['order_id']) && isset($input['action'])) {
        // 4B. Изменение статуса существующего заказа (только для employee)
        if ($user['role'] !== 'employee') {
            $response['error'] = 'Недостаточно прав для изменения статуса';
            http_response_code(403);
            echo json_encode($response);
            exit;
        }

        $orderId = $input['order_id'];
        $action = $input['action'];

        // 5. Получаем текущий статус заказа
        $currentStatus = $db->getOrderStatus($orderId);

        if (!$currentStatus) {
            $response['error'] = 'Заказ не найден';
            http_response_code(404);
            echo json_encode($response);
            exit;
        }

        // 6. Определяем следующий статус
        $statusFlow = ['Приём', 'готовим', 'доставляем', 'завершён'];
        $currentIndex = array_search($currentStatus, $statusFlow);

        // 7. Проверяем возможность изменения статуса
        if ($currentIndex === false) {
            $response['error'] = 'Неизвестный текущий статус заказа';
            http_response_code(400);
        } elseif ($currentIndex >= count($statusFlow) - 1) {
            $response['error'] = 'Заказ уже завершён';
            http_response_code(400);
        } else {
            // 8. Обновляем статус
            $newStatus = $statusFlow[$currentIndex + 1];
            $success = $db->updateOrderStatus($orderId, $newStatus, $_SESSION['user_id']);

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
} catch (Exception $e) {
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
