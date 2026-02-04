<?php
// Включить логирование
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

// ✅ Add API-specific headers:
header('Content-Type: application/json');

/**
 * Отправляет push-уведомления всем подписчикам заказа
 */
function sendPushNotificationsForOrder($orderId, $newStatus, $updaterName) {
    // Загружаем VAPID ключи
    $vapidKeysPath = __DIR__ . '/data/vapid-keys.json';
    if (!file_exists($vapidKeysPath)) {
        error_log("VAPID keys file not found: $vapidKeysPath");
        return;
    }
    $vapidKeys = json_decode(file_get_contents($vapidKeysPath), true);
    if (!$vapidKeys) {
        error_log("Invalid VAPID keys JSON");
        return;
    }

    // Получаем подписки из базы данных
    $db = Database::getInstance();
    $stmt = $db->prepare("
        SELECT endpoint, p256dh, auth
        FROM push_subscriptions
        WHERE order_id = ? OR user_id IN (SELECT user_id FROM orders WHERE id = ?)
    ");
    $stmt->execute([$orderId, $orderId]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($subscriptions)) {
        error_log("No push subscriptions found for order $orderId");
        return;
    }

    // Загружаем библиотеку WebPush
    require_once __DIR__ . '/vendor/autoload.php';

    $auth = [
        'VAPID' => [
            'subject' => $vapidKeys['subject'],
            'publicKey' => $vapidKeys['publicKey'],
            'privateKey' => $vapidKeys['privateKey'],
        ],
    ];

    $webPush = new Minishlink\WebPush\WebPush($auth);

    $title = "Статус заказа #$orderId обновлён";
    $body = "Новый статус: $newStatus (изменил: $updaterName)";
    $icon = '/icons/icon-192x192.png';
    $data = [
        'orderId' => $orderId,
        'status' => $newStatus,
        'url' => '/customer_orders.php?order=' . $orderId
    ];

    foreach ($subscriptions as $sub) {
        try {
            $subscription = Minishlink\WebPush\Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh'],
                'authToken' => $sub['auth'],
            ]);
            $webPush->queueNotification(
                $subscription,
                json_encode([
                    'title' => $title,
                    'body' => $body,
                    'icon' => $icon,
                    'data' => $data
                ])
            );
        } catch (Exception $e) {
            error_log("Error creating subscription for endpoint {$sub['endpoint']}: " . $e->getMessage());
        }
    }

    // Отправляем все уведомления
    foreach ($webPush->flush() as $report) {
        if (!$report->isSuccess()) {
            error_log("Push notification failed: " . $report->getReason());
        }
    }
}

$response = ['success' => false];

try {
    error_log("========== NEW REQUEST ==========");
    error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
    error_log("POST data: " . print_r($_POST, true));

    // Проверка метода
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed', 405);
    }

    // Проверка CSRF
    if (empty($_POST['csrf_token'])) {
        throw new Exception('CSRF token is missing', 403);
    }
    
    if (!isset($_SESSION['csrf_token'])) {
        throw new Exception('Session CSRF token not set', 403);
    }
    
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        throw new Exception('CSRF token validation failed', 403);
    }

    // Проверка авторизации
    if (empty($_SESSION['user_id'])) {
        throw new Exception('Authentication required', 401);
    }

    // Проверка order_id
    if (empty($_POST['order_id'])) {
        throw new Exception('Order ID is required', 400);
    }

    $db = Database::getInstance();
    $orderId = (int)$_POST['order_id'];
    error_log("Order ID: $orderId");

    // Получаем статус через метод класса
    $currentStatus = $db->getOrderStatus($orderId);
    error_log("Current status: " . ($currentStatus ?: 'NULL'));

    if (!$currentStatus) {
        throw new Exception('Order not found', 404);
    }

    // Определяем следующий статус
    $statusFlow = ['Приём', 'готовим', 'доставляем', 'завершён'];
    $currentIndex = array_search($currentStatus, $statusFlow);

    // Обработка отказа
    if (isset($_POST['action']) && $_POST['action'] === 'reject') {
        $newStatus = 'отказ';
    } else {
        if ($currentIndex === false) {
            throw new Exception("Invalid current status: $currentStatus", 400);
        }
        
        if ($currentIndex >= count($statusFlow) - 1) {
            throw new Exception('Cannot change status further', 400);
        }

        $newStatus = $statusFlow[$currentIndex + 1];
    }
    
    // Обновляем статус через метод класса
    $success = $db->updateOrderStatus($orderId, $newStatus, $_SESSION['user_id']);

    if (!$success) {
        throw new Exception('Failed to update order status', 500);
    }

    // После успешного обновления статуса заказа
    // Получаем информацию о сотруднике
    $updater = $db->getUserById($_SESSION['user_id']);
    
    $response = [
        'success' => true,
        'new_status' => $newStatus,
        'order_id' => $orderId,
        'updater_name' => $updater['name'] ?? null,
        'message' => 'Status updated successfully'
    ];

    // Отправляем push-уведомления подписчикам заказа (в фоне, не блокируем ответ)
    try {
        sendPushNotificationsForOrder($orderId, $newStatus, $updater['name'] ?? 'Сотрудник');
    } catch (Exception $e) {
        error_log("Push notification error: " . $e->getMessage());
    }

} catch (Throwable $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $code
    ];
    
    error_log("ERROR: " . $e->getMessage());
    error_log("FILE: " . $e->getFile() . ":" . $e->getLine());
    error_log("TRACE:\n" . $e->getTraceAsString());
}

echo json_encode($response);
?>