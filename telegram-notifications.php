<?php
/**
 * telegram-notifications.php — Telegram notification helpers.
 *
 * Requires TELEGRAM_BOT_TOKEN constant (from config.php).
 * Functions:
 *   sendOrderToTelegram(int $orderId, array $order, Database $db): void
 *   sendReservationToTelegram(int $reservationId, array $reservation, $db): void
 *   sendTelegramMessage(string $chatId, string $text, array $keyboard = []): void
 */

if (!function_exists('sendTelegramMessage')) {

    function tgAnswerCallback(string $callbackId, string $text, bool $alert = false): void
    {
        if (!defined('TELEGRAM_BOT_TOKEN') || !TELEGRAM_BOT_TOKEN) return;

        @file_get_contents(
            'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/answerCallbackQuery',
            false,
            stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/json\r\n",
                    'content'       => json_encode([
                        'callback_query_id' => $callbackId,
                        'text'              => $text,
                        'show_alert'        => $alert,
                    ], JSON_UNESCAPED_UNICODE),
                    'timeout'       => 5,
                    'ignore_errors' => true,
                ],
            ])
        );
    }

    function tgEditMessageText(string $chatId, int $messageId, string $text): void
    {
        if (!defined('TELEGRAM_BOT_TOKEN') || !TELEGRAM_BOT_TOKEN) return;
        if ($chatId === '' || $messageId <= 0) return;

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
                        'text'         => $text,
                        'parse_mode'   => 'HTML',
                        'reply_markup' => json_encode(['inline_keyboard' => []]),
                    ], JSON_UNESCAPED_UNICODE),
                    'timeout'       => 5,
                    'ignore_errors' => true,
                ],
            ])
        );
    }

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

    function sendReservationToTelegram(int $reservationId, array $reservation, $db): void
    {
        if (!defined('TELEGRAM_BOT_TOKEN') || !TELEGRAM_BOT_TOKEN) return;

        $tgChatId = json_decode($db->getSetting('telegram_chat_id') ?? 'null', true);
        if (!$tgChatId) return;

        $tableLabel  = (string)($reservation['table_label'] ?? '');
        $guestsCount = (int)($reservation['guests_count'] ?? 0);
        $startsAt    = (string)($reservation['starts_at'] ?? '');
        $endsAt      = (string)($reservation['ends_at'] ?? '');
        $guestName   = trim((string)($reservation['guest_name'] ?? ''));
        $guestPhone  = trim((string)($reservation['guest_phone'] ?? ''));
        $note        = trim((string)($reservation['note'] ?? ''));

        $startsTs = strtotime($startsAt);
        $endsTs   = strtotime($endsAt);
        $whenLine = $startsTs && $endsTs
            ? date('d.m.Y H:i', $startsTs) . '–' . date('H:i', $endsTs)
            : ($startsAt . ($endsAt !== '' ? ' – ' . $endsAt : ''));

        $lines = [
            '🪑 <b>Новая бронь #' . $reservationId . '</b>',
            '🍽 Стол: <b>' . htmlspecialchars($tableLabel) . '</b>',
            '🕒 ' . htmlspecialchars($whenLine),
            '👥 Гостей: ' . $guestsCount,
        ];
        if ($guestName !== '')  { $lines[] = '👤 ' . htmlspecialchars($guestName); }
        if ($guestPhone !== '') { $lines[] = '📞 ' . htmlspecialchars($guestPhone); }
        if ($note !== '')       { $lines[] = '📝 ' . htmlspecialchars($note); }

        $text = implode("\n", $lines);

        $keyboard = [[
            ['text' => '✅ Подтвердить', 'callback_data' => 'reserve_confirm_' . $reservationId],
            ['text' => '❌ Отклонить',  'callback_data' => 'reserve_reject_'  . $reservationId],
        ]];

        sendTelegramMessage((string)$tgChatId, $text, $keyboard);
    }

}
