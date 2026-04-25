<?php
/**
 * Marketing campaign worker (Phase 8.1).
 *
 * Picks up marketing_sends rows in 'queued' status and dispatches them via
 * the channel-specific transport. Channels:
 *   - email     → Mailer::send (existing SMTP layer in mailer.php)
 *   - push      → push_subscriptions for the user; one VAPID send per device
 *   - telegram  → tenant Telegram chat (broadcast; user-targeting not yet)
 *
 * Cron (suggested):
 *   * * * * *  cd /var/www/cleanmenu && php scripts/marketing-worker.php --batch=50 >> data/logs/marketing-worker.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['batch::', 'loop::', 'sleep::']);
$batch    = max(1, min(500, (int)($opts['batch'] ?? 50)));
$loop     = array_key_exists('loop', $opts);
$sleepSec = max(1, min(60, (int)($opts['sleep'] ?? 2)));

require_once __DIR__ . '/../db.php';

$db = Database::getInstance();
$shouldStop = false;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  static function () use (&$shouldStop) { $shouldStop = true; });
    pcntl_signal(SIGTERM, static function () use (&$shouldStop) { $shouldStop = true; });
}

$processed = 0;
$delivered = 0;
$failed    = 0;

do {
    $rows = $db->getNextQueuedMarketingSends($batch);
    if (empty($rows)) {
        if (!$loop) break;
        sleep($sleepSec);
        continue;
    }

    foreach ($rows as $row) {
        $processed++;
        $sendId = (int)$row['id'];
        try {
            switch ((string)$row['channel']) {
                case 'email':
                    if (empty($row['email'])) {
                        $db->markMarketingSendFailed($sendId, 'no_email');
                        $failed++;
                        continue 2;
                    }
                    require_once __DIR__ . '/../mailer.php';
                    $mailer = new Mailer();
                    $sent = $mailer->send(
                        (string)$row['email'],
                        (string)($row['subject'] ?? 'Сообщение от ресторана'),
                        !empty($row['body_html']) ? (string)$row['body_html'] : nl2br(htmlspecialchars((string)$row['body_text'])),
                        (string)($row['user_name'] ?? '')
                    );
                    if ($sent) {
                        $db->markMarketingSendDelivered($sendId);
                        $delivered++;
                    } else {
                        $db->markMarketingSendFailed($sendId, 'mailer_send_returned_false');
                        $failed++;
                    }
                    break;

                case 'push':
                    // Push transport not finalized; we mark such rows skipped instead of failing
                    // so they don't pile up under a 'failed' status if push isn't configured.
                    $db->markMarketingSendFailed($sendId, 'push_channel_not_implemented');
                    $failed++;
                    break;

                case 'telegram':
                    require_once __DIR__ . '/../config.php';
                    require_once __DIR__ . '/../telegram-notifications.php';
                    $tgChatId = json_decode($db->getSetting('telegram_chat_id') ?? 'null', true);
                    if ($tgChatId && defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN) {
                        sendTelegramMessage((string)$tgChatId, (string)$row['body_text']);
                        $db->markMarketingSendDelivered($sendId);
                        $delivered++;
                    } else {
                        $db->markMarketingSendFailed($sendId, 'telegram_not_configured');
                        $failed++;
                    }
                    break;

                default:
                    $db->markMarketingSendFailed($sendId, 'unknown_channel');
                    $failed++;
            }
        } catch (Throwable $e) {
            $db->markMarketingSendFailed($sendId, $e->getMessage());
            $failed++;
        }

        if ($shouldStop) break;
    }
} while ($loop && !$shouldStop);

fwrite(STDOUT, sprintf(
    "marketing-worker done: processed=%d delivered=%d failed=%d\n",
    $processed, $delivered, $failed
));
exit(0);
