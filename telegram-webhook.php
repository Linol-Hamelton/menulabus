<?php
/**
 * telegram-webhook.php — Handles Telegram Bot webhook callbacks.
 *
 * Register this URL in Telegram:
 *   https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://yourdomain/telegram-webhook.php
 *
 * Handles callback_query from inline keyboard buttons:
 *   accept_{orderId}            → sets order status to 'готовим'
 *   reject_{orderId}            → sets order status to 'отказ'
 *   reserve_confirm_{resvId}    → sets reservation status to 'confirmed'
 *   reserve_reject_{resvId}     → sets reservation status to 'cancelled'
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

if (!empty($update['callback_query'])) {
    $query      = $update['callback_query'];
    $callbackId = (string)($query['id'] ?? '');
    $data       = (string)($query['data'] ?? '');
    $chatId     = (string)($query['message']['chat']['id'] ?? '');
    $messageId  = (int)($query['message']['message_id'] ?? 0);

    $db = Database::getInstance();

    if (preg_match('/^(accept|reject)_(\d+)$/', $data, $m)) {
        $action  = $m[1];
        $orderId = (int)$m[2];
        $newStatus = ($action === 'accept') ? 'готовим' : 'отказ';

        $currentStatus = $db->getOrderStatus($orderId);
        if ($currentStatus && !in_array($currentStatus, ['завершён', 'отказ'], true)) {
            $db->updateOrderStatus($orderId, $newStatus);
            tgAnswerCallback($callbackId, ($action === 'accept') ? '✅ Принят — отправлен на кухню' : '❌ Отказано');
            tgEditMessageText(
                $chatId,
                $messageId,
                ($action === 'accept') ? "✅ Заказ #$orderId принят — готовим" : "❌ Заказ #$orderId отклонён"
            );
        } else {
            tgAnswerCallback($callbackId, 'Заказ уже обработан', true);
        }
    } elseif (preg_match('/^reserve_(confirm|reject)_(\d+)$/', $data, $m)) {
        $action        = $m[1];
        $reservationId = (int)$m[2];
        $reservation   = $db->getReservationById($reservationId);

        if (!$reservation) {
            tgAnswerCallback($callbackId, 'Бронь не найдена', true);
        } elseif (!in_array((string)$reservation['status'], ['pending', 'confirmed'], true)) {
            tgAnswerCallback($callbackId, 'Бронь уже обработана', true);
        } else {
            $newStatus = ($action === 'confirm') ? 'confirmed' : 'cancelled';
            if ($db->updateReservationStatus($reservationId, $newStatus)) {
                tgAnswerCallback(
                    $callbackId,
                    ($action === 'confirm') ? '✅ Бронь подтверждена' : '❌ Бронь отменена'
                );
                $startsTs = strtotime((string)$reservation['starts_at']);
                $whenLabel = $startsTs ? date('d.m H:i', $startsTs) : (string)$reservation['starts_at'];
                $editText = ($action === 'confirm')
                    ? "✅ Бронь #$reservationId подтверждена — стол {$reservation['table_label']}, {$whenLabel}"
                    : "❌ Бронь #$reservationId отменена";
                tgEditMessageText($chatId, $messageId, $editText);
            } else {
                tgAnswerCallback($callbackId, 'Не удалось обновить бронь', true);
            }
        }
    }
}

echo json_encode(['ok' => true]);
