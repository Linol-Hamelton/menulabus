<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/../config.php';

// ✅ Add API-specific headers:
header('Content-Type: application/json');

// Получаем данные из POST-запроса
$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? '';

// Определяем текст сообщения в зависимости от типа и наличия подписи
if ($type === 'reservation') {
    if (isset($input['signedData']) && $input['isSigned']) {
        // Если данные подписаны, используем текстовое представление
        $message = $input['text'] ?? 'Новая бронь (подписанные данные)';
    } else {
        $message = $input['text'] ?? '';
    }
} else {
    $message = $input['text'] ?? '';
}

if (empty($message)) {
    echo json_encode(['ok' => false, 'error' => 'Empty message']);
    exit;
}

// Форматируем сообщение в зависимости от типа
$text = "";
if ($type === 'order') {
    $text = $message; // Уже отформатировано в /js/cart.js
} elseif ($type === 'reservation') {
    $text = $message; // Уже содержит "Новая бронь:" в тексте
} else {
    $text = $message;
}

// Отправляем сообщение в Telegram
$url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
$data = [
    'chat_id' => TELEGRAM_CHAT_ID,
    'text' => $text,
    'parse_mode' => $type === 'order' ? 'Markdown' : 'HTML'
];

$options = [
    'http' => [
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query($data),
    ],
];

$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === FALSE) {
    echo json_encode(['ok' => false, 'error' => 'Telegram API error']);
} else {
    echo $result; // Возвращаем ответ от Telegram API
}
?>