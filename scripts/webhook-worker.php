<?php

/**
 * Webhook delivery worker.
 *
 * Picks up rows from webhook_deliveries that are in 'queued' status, or
 * in 'failed' status with next_retry_at <= NOW(), and POSTs them through
 * WebhookDispatcher::send(). Atomic claim via SELECT ... FOR UPDATE so
 * multiple workers can run in parallel without racing.
 *
 * Usage (one-shot, e.g. from cron every minute):
 *   php scripts/webhook-worker.php --batch=20
 *
 * Usage (long-running daemon, e.g. supervisord):
 *   php scripts/webhook-worker.php --loop --sleep=2
 *
 * Exits 0 on clean shutdown, non-zero on fatal init error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['batch::', 'loop::', 'sleep::', 'max-attempts::']);
$batch       = max(1, min(200, (int)($opts['batch'] ?? 20)));
$loop        = array_key_exists('loop', $opts);
$sleepSec    = max(1, min(60, (int)($opts['sleep'] ?? 2)));
$maxAttempts = max(1, min(10, (int)($opts['max-attempts'] ?? 5)));

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/WebhookDispatcher.php';

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
    $rows = $db->claimDueWebhookDeliveries($batch, $maxAttempts);
    if (empty($rows)) {
        if (!$loop) {
            break;
        }
        sleep($sleepSec);
        continue;
    }

    foreach ($rows as $row) {
        $processed++;
        $ok = WebhookDispatcher::send($row, $db);
        if ($ok) {
            $delivered++;
        } else {
            $failed++;
        }
        if ($shouldStop) {
            break;
        }
    }
} while ($loop && !$shouldStop);

fwrite(STDOUT, sprintf(
    "webhook-worker done: processed=%d delivered=%d failed=%d\n",
    $processed, $delivered, $failed
));
exit(0);
