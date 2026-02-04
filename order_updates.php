<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

// Создаём объект базы данных
$db = Database::getInstance();

// Отдаём первое «приветственное» событие
sendSse(['type' => 'hello', 'time' => time()]);

// Время последнего обновления
$lastUpdate = time();

// ==== ОСНОВНОЙ ЦИКЛ (изменён согласно заданию) ====
$timeout = 25; // Уменьшаем общее время
while ($timeout > 0 && time() - $lastUpdate < 30) {
    if (connection_aborted()) break;

    // Увеличиваем интервал проверки — смотрим заказы за последние 10 секунд
    $orders = $db->getOrderUpdatesSince(time() - 10);

    if (!empty($orders)) {
        foreach ($orders as $order) {
            sendSse([
                'type'  => 'order_update',
                'id'    => $order['id'],
                'status'=> $order['status'],
                'time'  => $order['updated_at']
            ]);
        }
    }

    sleep(3); // Увеличиваем интервал
    $timeout--;

    // Периодически «пингуем» клиента, чтобы соединение не обрывалось
    sendSse(['type' => 'ping']);
}
// =====================================================

// Завершаем выходным событием
sendSse(['type' => 'bye']);

/**
 * Утилита: отправка SSE-сообщения
 *
 * @param mixed $data Ассоциативный массив с данными
 */
function sendSse($data)
{
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    ob_flush();
    flush();
}