<?php
/**
 * telegram-notifications.php — Telegram notification helpers.
 *
 * Requires TELEGRAM_BOT_TOKEN constant (from config.php).
 * Functions:
 *   sendOrderToTelegram(int $orderId, array $order, Database $db): void
 *   sendTelegramMessage(string $chatId, string $text, array $keyboard = []): void
 */

if (!function_exists('sendTelegramMessage')) {

    function sendTelegramMessage(string $chatId, string $text, array $inlineKeyboard = []): void
    {
        if (!defined('TELEGRAM_BOT_TOKEN') || !TELEGRAM_BOT_TOKEN) return;

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if (!empty($inlineKeyboard)) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        @file_get_contents(
            'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage',
            false,
            stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/json\r\n",
                    'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'timeout'       => 5,
                    'ignore_errors' => true,
                ],
            ])
        );
    }

    function sendOrderToTelegram(int $orderId, array $order, $db): void
    {
        if (!defined('TELEGRAM_BOT_TOKEN') || !TELEGRAM_BOT_TOKEN) return;

        $tgChatId = json_decode($db->getSetting('telegram_chat_id') ?? 'null', true);
        if (!$tgChatId) return;

        $deliveryLabels = [
            'takeaway' => '🚶 Самовывоз',
            'delivery' => '🛵 Доставка',
            'table'    => '🪑 Стол',
            'bar'      => '🍸 Бар',
        ];

        $deliveryType   = $order['delivery_type'] ?? 'bar';
        $deliveryDetail = $order['delivery_details'] ?? '';
        $paymentMethod  = $order['payment_method'] ?? 'cash';
        $total          = (float)($order['total'] ?? 0);
        $tips           = (float)($order['tips'] ?? 0);

        $deliveryText = $deliveryLabels[$deliveryType] ?? $deliveryType;
        if ($deliveryDetail !== '') {
            $deliveryText .= ': ' . $deliveryDetail;
        }

        $paymentLabels = ['cash' => '💵 Наличные', 'online' => '💳 Карта', 'sbp' => '⚡ СБП'];
        $paymentText   = $paymentLabels[$paymentMethod] ?? $paymentMethod;

        // Build items list
        $items = [];
        if (!empty($order['items'])) {
            $rawItems = is_string($order['items']) ? json_decode($order['items'], true) : $order['items'];
            foreach ((array)$rawItems as $item) {
                $items[] = htmlspecialchars($item['name'] ?? '?') . ' × ' . ($item['quantity'] ?? 1)
                    . ' = ' . number_format(($item['price'] ?? 0) * ($item['quantity'] ?? 1), 0, '.', ' ') . ' ₽';
            }
        }

        $tipsLine = $tips > 0 ? "\n💝 Чаевые: " . number_format($tips, 0, '.', ' ') . ' ₽' : '';

        $text = "🆕 <b>Новый заказ #" . $orderId . "</b>\n"
              . implode("\n", array_map(fn($l) => "  • $l", $items)) . "\n"
              . "──────────────────\n"
              . "💰 Итого: " . number_format($total, 0, '.', ' ') . " ₽" . $tipsLine . "\n"
              . "🚚 " . $deliveryText . "\n"
              . "💳 " . $paymentText;

        $keyboard = [[
            ['text' => '✅ Принять', 'callback_data' => 'accept_' . $orderId],
            ['text' => '❌ Отказать', 'callback_data' => 'reject_' . $orderId],
        ]];

        sendTelegramMessage((string)$tgChatId, $text, $keyboard);
    }

}
