<?php

ob_start();

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

$response = ['success' => false];

try {
    // Получаем входные данные
    $input = json_decode(file_get_contents('php://input'), true);

    // 1. Проверка CSRF токена (только для авторизованных пользователей)
    if (isset($_SESSION['user_id']) && isset($_SESSION['csrf_token'])) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
        if (!$csrfToken || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            $response['error'] = 'Ошибка безопасности (CSRF)';
            http_response_code(403);
            echo json_encode($response);
            exit;
        }
    }

    // 2. Проверяем наличие товаров и телефона
    if (!isset($input['items']) || !isset($input['total']) || !isset($input['phone'])) {
        $response['error'] = 'Неверные параметры заказа';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    $items = $input['items'];
    $total = $input['total'];
    $phone = $input['phone'];
    $deliveryType = $input['delivery_type'] ?? 'bar';
    $deliveryDetail = $input['delivery_details'] ?? '';

    // Валидация телефона (минимум 10 цифр)
    $cleanPhone = preg_replace('/\D/', '', $phone);
    if (strlen($cleanPhone) < 10) {
        $response['error'] = 'Укажите корректный номер телефона';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Форматируем номер для сохранения
    $formattedPhone = '+7' . substr($cleanPhone, -10);

    // Добавляем телефон в delivery_details, если нужно
    if (!empty($deliveryDetail)) {
        $deliveryDetail .= '; Телефон: ' . $formattedPhone;
    } else {
        $deliveryDetail = 'Телефон: ' . $formattedPhone;
    }

    $db = Database::getInstance();

    // Создаем гостевой заказ
    $orderId = $db->createGuestOrder($items, $total, $deliveryType, $deliveryDetail);
    if ($orderId) {
        $response['success'] = true;
        $response['orderId'] = $orderId;
        http_response_code(200);
    } else {
        $response['error'] = 'Ошибка при создании заказа';
        http_response_code(500);
    }

} catch (Exception $e) {
    error_log("Guest order processing error: " . $e->getMessage());
    error_log("Input data: " . print_r($input, true));
    $response['error'] = 'Внутренняя ошибка сервера';
    http_response_code(500);
}

ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;