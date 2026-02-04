<?php
require_once __DIR__ . '/session_init.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// время, от которого ищем изменения
$lastKnown = (int)($_GET['t'] ?? 0);

$db = Database::getInstance();
$response = ['timestamp' => time()];

/* ---------- 1. Корзина текущего пользователя ---------- */
$userId = $_SESSION['user_id'] ?? 0;
$response['cartTotal'] = (int) $db->scalar(
    "SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE user_id = ?",
    [$userId]
);

/* ---------- 2. Проверка изменений заказов ---------- */
$userRole = $_SESSION['user_role'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

// Получаем время последнего изменения любого заказа
$lastOrderUpdate = (int) $db->scalar(
    "SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM orders WHERE 1"
);

// Для клиентов: дополнительно проверяем, изменились ли ИХ заказы
if ($userRole === 'customer' && $userId) {
    $lastUserOrderUpdate = (int) $db->scalar(
        "SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM orders WHERE user_id = ?",
        [$userId]
    );
    
    // Используем большее из двух значений
    $lastOrderUpdate = max($lastOrderUpdate, $lastUserOrderUpdate);
}

// Проверяем, были ли изменения с момента lastKnown
if ($lastOrderUpdate > $lastKnown) {
    $response['orderUpdated'] = true;
    
    // Добавляем информацию о том, какие заказы изменились
    $changedOrders = $db->getOrderUpdatesSince($lastKnown);
    
    $response['changedOrderIds'] = array_column($changedOrders, 'id');
    $response['changedOrderStatuses'] = array_column($changedOrders, 'status');
}

/* ---------- 3. (Опционально) количество активных заказов ---------- */
$response['activeOrders'] = (int) $db->scalar(
    "SELECT COUNT(*) FROM orders WHERE status IN ('Приём','готовим','доставляем')"
);

echo json_encode($response);