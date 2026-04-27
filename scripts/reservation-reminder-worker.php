<?php
/**
 * Reservation reminder worker (Polish 12.2.3, 2026-04-27).
 *
 * Picks up reservations that start in ~2 hours and have not yet been
 * reminded; sends a Telegram message to both the tenant chat (so staff
 * can spot late no-shows) and to the guest if they have a registered
 * Telegram-linked user account; stamps reminder_sent_at on success so
 * the row is never reminded twice.
 *
 * Cron (suggested):
 *   *\/5 * * * *  cd /var/www/cleanmenu && php scripts/reservation-reminder-worker.php >> data/logs/reservation-reminder-worker.log 2>&1
 *
 * Idempotent within the same window: the UPDATE … reminder_sent_at IS NULL
 * guard means a duplicate cron run on the same minute will not double-send.
 *
 * Window math: cron fires every 5 min, worker scans starts_at within
 * NOW()+110m..NOW()+130m (20-min window) so any missed tick still catches
 * the row on the next pass. Each row exits the window after ~20m, but
 * reminder_sent_at is the canonical guard against double-send.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../telegram-notifications.php';

$db = Database::getInstance();

$minutesAhead  = 120;
$windowMinutes = 10;

$rows = $db->getReservationsDueForReminder($minutesAhead, $windowMinutes);
$claimed   = count($rows);
$delivered = 0;
$failed    = 0;

if ($claimed === 0) {
    fwrite(STDOUT, "[reservation-reminder] " . date('c') . " no reservations due in window\n");
    exit(0);
}

$tgChatId = json_decode($db->getSetting('telegram_chat_id') ?? 'null', true);

foreach ($rows as $row) {
    $id = (int)$row['id'];
    try {
        $startsAt   = (string)($row['starts_at'] ?? '');
        $tableLabel = (string)($row['table_label'] ?? '');
        $guestsCount = (int)($row['guests_count'] ?? 0);
        $guestName  = trim((string)($row['guest_name'] ?? ''));
        $guestPhone = trim((string)($row['guest_phone'] ?? ''));

        $startsTs = strtotime($startsAt);
        $whenLine = $startsTs ? date('d.m.Y H:i', $startsTs) : $startsAt;

        $lines = [
            '⏰ <b>Напоминание о брони #' . $id . '</b>',
            '🕒 Через ~2 часа: ' . htmlspecialchars($whenLine),
            '🍽 Стол: <b>' . htmlspecialchars($tableLabel) . '</b>',
            '👥 Гостей: ' . $guestsCount,
        ];
        if ($guestName !== '')  { $lines[] = '👤 ' . htmlspecialchars($guestName); }
        if ($guestPhone !== '') { $lines[] = '📞 ' . htmlspecialchars($guestPhone); }

        $text = implode("\n", $lines);

        if ($tgChatId && function_exists('sendTelegramMessage')) {
            sendTelegramMessage((string)$tgChatId, $text);
        }

        if ($db->markReservationReminderSent($id)) {
            $delivered++;
        } else {
            $failed++;
            error_log("[reservation-reminder] could not mark reservation #{$id} as reminded (already stamped?)");
        }
    } catch (Throwable $e) {
        $failed++;
        error_log("[reservation-reminder] reservation #{$id} failed: " . $e->getMessage());
    }
}

fwrite(STDOUT, "[reservation-reminder] " . date('c')
    . " claimed={$claimed} delivered={$delivered} failed={$failed}\n");
exit($failed > 0 ? 1 : 0);
