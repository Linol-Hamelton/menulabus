<?php
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
$response = ['success' => false];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON');
    }

    $subscription = $input['subscription'] ?? null;
    $phone = $input['phone'] ?? null;
    $orderId = $input['order_id'] ?? null;

    if (!$subscription || !isset($subscription['endpoint'], $subscription['keys']['p256dh'], $subscription['keys']['auth'])) {
        throw new Exception('Invalid subscription data');
    }

    $db = Database::getInstance();

    // Определяем user_id (если пользователь авторизован)
    $userId = $_SESSION['user_id'] ?? null;

    // Для гостей: phone и order_id обязательны
    if (!$userId && (!$phone || !$orderId)) {
        throw new Exception('Для гостей требуется номер телефона и ID заказа');
    }

    // Проверяем, существует ли уже такая подписка
    $stmt = $db->prepareCached("
        SELECT id FROM push_subscriptions 
        WHERE endpoint = ? AND p256dh = ? AND auth = ?
    ");
    $stmt->execute([
        $subscription['endpoint'],
        $subscription['keys']['p256dh'],
        $subscription['keys']['auth']
    ]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        // Обновляем существующую запись
        $stmt = $db->prepareCached("
            UPDATE push_subscriptions 
            SET user_id = ?, phone = ?, order_id = ?, created_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId, $phone, $orderId, $existing]);
    } else {
        // Создаём новую запись
        $stmt = $db->prepareCached("
            INSERT INTO push_subscriptions 
            (user_id, phone, order_id, endpoint, p256dh, auth, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $phone,
            $orderId,
            $subscription['endpoint'],
            $subscription['keys']['p256dh'],
            $subscription['keys']['auth']
        ]);
    }

    $response['success'] = true;
    $response['message'] = 'Подписка сохранена';

} catch (Exception $e) {
    error_log("save-push-subscription error: " . $e->getMessage());
    $response['error'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);