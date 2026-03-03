<?php
/**
 * telegram-webhook.php — Handles Telegram Bot webhook callbacks.
 *
 * Register this URL in Telegram:
 *   https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://yourdomain/telegram-webhook.php
 *
 * Handles callback_query from inline keyboard buttons:
 *   accept_{orderId} → sets order status to 'готовим'
 *   reject_{orderId} → sets order status to 'отказ'
 */

define('LABUS_CTX', 'web');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram-notifications.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body = file_get_contents('php://input');
$update = json_decode($body, true);

if (!$update) {
    echo json_encode(['ok' => false]);
    exit;
}

// Handle callback_query (button press)
if (!empty($update['callback_query'])) {
    $query      = $update['callback_query'];
    $callbackId = $query['id'];
    $data       = $query['data'] ?? '';
    $chatId     = (string)($query['message']['chat']['id'] ?? '');
    $messageId  = (int)($query['message']['message_id'] ?? 0);

    if (preg_match('/^(accept|reject)_(\d+)$/', $data, $m)) {
        $action  = $m[1]; // 'accept' or 'reject'
        $orderId = (int)$m[2];

        $db        = Database::getInstance();
        $newStatus = ($action === 'accept') ? 'готовим' : 'отказ';

        $currentStatus = $db->getOrderStatus($orderId);
        if ($currentStatus && !in_array($currentStatus, ['завершён', 'отказ'], true)) {
            $db->updateOrderStatus($orderId, $newStatus);

            $statusLabel = ($action === 'accept')
                ? '✅ Принят — отправлен на кухню'
                : '❌ Отказано';

            // Answer the callback (removes spinner from button)
            @file_get_contents(
                'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/answerCallbackQuery',
                false,
                stream_context_create([
                    'http' => [
                        'method'        => 'POST',
                        'header'        => "Content-Type: application/json\r\n",
                        'content'       => json_encode([
                            'callback_query_id' => $callbackId,
                            'text'              => $statusLabel,
                            'show_alert'        => false,
                        ], JSON_UNESCAPED_UNICODE),
                        'timeout'       => 5,
                        'ignore_errors' => true,
                    ],
                ])
            );

            // Edit the original message to remove buttons
            if ($chatId && $messageId) {
                $editText = ($action === 'accept')
                    ? "✅ Заказ #$orderId принят — готовим"
                    : "❌ Заказ #$orderId отклонён";

                @file_get_contents(
                    'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/editMessageText',
                    false,
                    stream_context_create([
                        'http' => [
                            'method'        => 'POST',
                            'header'        => "Content-Type: application/json\r\n",
                            'content'       => json_encode([
                                'chat_id'      => $chatId,
                                'message_id'   => $messageId,
                                'text'         => $editText,
                                'reply_markup' => json_encode(['inline_keyboard' => []]),
                            ], JSON_UNESCAPED_UNICODE),
                            'timeout'       => 5,
                            'ignore_errors' => true,
                        ],
                    ])
                );
            }
        } else {
            // Already processed
            @file_get_contents(
                'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/answerCallbackQuery',
                false,
                stream_context_create([
                    'http' => [
                        'method'        => 'POST',
                        'header'        => "Content-Type: application/json\r\n",
                        'content'       => json_encode([
                            'callback_query_id' => $callbackId,
                            'text'              => 'Заказ уже обработан',
                            'show_alert'        => true,
                        ]),
                        'timeout'       => 5,
                        'ignore_errors' => true,
                    ],
                ])
            );
        }
    }
}

echo json_encode(['ok' => true]);
