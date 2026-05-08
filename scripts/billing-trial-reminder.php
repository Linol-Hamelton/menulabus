<?php
/**
 * scripts/billing-trial-reminder.php — Phase 32B trial-end reminder cron.
 *
 * Finds tenants in `subscription_status = trial` whose trial expires in
 * 15 days (D-15 / first reminder) or 5 days (D-5 / urgent reminder) and
 * emails the owner. Runs daily.
 *
 * Recommended cron line:
 *   30 9 * * * /usr/bin/php8.1 /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/billing-trial-reminder.php >> /var/log/cleanmenu/trial-reminder.log 2>&1
 *
 * Idempotent: if the same tenant gets two cron-firings within the same
 * day-window, the dedup check on `subscription_events` (event_type =
 * 'trial_reminder_sent') prevents double-emails. Reminders re-fire if
 * cron was missed for ≥1 day window.
 *
 * Phase 32B scope:
 *   - Reminder emails only — no auto plan-switch, no special-discount
 *     conversion offers (those are founder-led during M1-M3 direct-sales
 *     phase per docs/marketing-strategy-2026.md).
 *   - The actual day-91 trial→past_due transition is owned by the
 *     existing billing-cycle-worker via SubscriptionStore::onWebhook /
 *     getDueForCharge logic (when first auto-charge fails on missing
 *     payment_method).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "billing-trial-reminder must be run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';
require_once __DIR__ . '/../mailer.php';

use Cleanmenu\Billing\SubscriptionStore;

$verbose = in_array('--verbose', $argv ?? [], true) || in_array('-v', $argv ?? [], true);
$dryRun  = in_array('--dry-run', $argv ?? [], true);

function log_line(string $msg, bool $force = false): void
{
    global $verbose;
    if ($force || $verbose) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
    }
}

$pdo = SubscriptionStore::pdo();

// Find tenants on trial with trial_ends_at in window [D-15.5d, D-14.5d) and
// [D-5.5d, D-4.5d). Half-day windows give cron 24h grace if it's flapping.
$now = new DateTimeImmutable('now');
$windows = [
    'D-15' => [
        'lower' => $now->modify('+14 days +12 hours'),
        'upper' => $now->modify('+15 days +12 hours'),
        'urgency' => 'soft',
    ],
    'D-5' => [
        'lower' => $now->modify('+4 days +12 hours'),
        'upper' => $now->modify('+5 days +12 hours'),
        'urgency' => 'urgent',
    ],
];

$totalSent = 0;

foreach ($windows as $label => $w) {
    $lowerStr = $w['lower']->format('Y-m-d H:i:s');
    $upperStr = $w['upper']->format('Y-m-d H:i:s');
    log_line("checking window {$label}: [{$lowerStr} .. {$upperStr})");

    $stmt = $pdo->prepare(
        "SELECT id, brand_slug, brand_name, owner_email, trial_ends_at
           FROM tenants
          WHERE subscription_status = 'trial'
            AND owner_email IS NOT NULL
            AND owner_email != ''
            AND trial_ends_at >= :lo
            AND trial_ends_at <  :hi"
    );
    $stmt->execute([':lo' => $lowerStr, ':hi' => $upperStr]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    log_line("  found " . count($rows) . " candidate tenant(s)");

    foreach ($rows as $row) {
        $tenantId = (int)$row['id'];
        $owner    = (string)$row['owner_email'];
        $brand    = (string)($row['brand_name'] ?: $row['brand_slug']);
        $slug     = (string)$row['brand_slug'];
        $endsAt   = (string)$row['trial_ends_at'];

        // Dedup: skip if reminder for this window was already sent today.
        $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $dupCheck = $pdo->prepare(
            "SELECT id FROM subscription_events
              WHERE tenant_id = :tid
                AND event_type = 'trial_reminder_sent'
                AND JSON_EXTRACT(payload, '$.window') = :window
                AND created_at >= :today
              LIMIT 1"
        );
        try {
            $dupCheck->execute([':tid' => $tenantId, ':window' => $label, ':today' => $todayStart]);
            if ($dupCheck->fetch()) {
                log_line("  tenant #{$tenantId} ({$slug}): already reminded today for {$label}, skipping");
                continue;
            }
        } catch (Throwable $e) {
            // JSON_EXTRACT not supported on older MySQL — fall through (best-effort dedup).
            log_line("  tenant #{$tenantId}: dedup check failed: " . $e->getMessage());
        }

        $subject = $w['urgency'] === 'urgent'
            ? "⏰ Trial завершается через 5 дней — выберите тариф для {$brand}"
            : "Через 2 недели trial CleanMenu заканчивается — {$brand}";
        $body = build_email_body($brand, $slug, $endsAt, $w['urgency']);

        if ($dryRun) {
            log_line("  [DRY-RUN] would email {$owner} subject={$subject}", true);
            continue;
        }

        try {
            $sent = mailer_send($owner, $subject, $body, $brand);
            if ($sent) {
                SubscriptionStore::logEvent($tenantId, 'trial_reminder_sent', [
                    'window'    => $label,
                    'owner'     => $owner,
                    'trial_end' => $endsAt,
                ]);
                $totalSent++;
                log_line("  ✓ tenant #{$tenantId} ({$slug}): emailed {$owner} ({$label})", true);
            } else {
                log_line("  ✗ tenant #{$tenantId}: mailer returned false");
            }
        } catch (Throwable $e) {
            log_line("  ✗ tenant #{$tenantId}: email failed: " . $e->getMessage(), true);
        }
    }
}

log_line("done. total reminders sent: {$totalSent}", true);
exit(0);

// ────────────────────────────────────────────────────────────────────────────

function build_email_body(string $brand, string $slug, string $trialEndsAt, string $urgency): string
{
    $billingUrl = "https://{$slug}.menu.labus.pro/owner.php?tab=billing";
    $endsHuman = date('d.m.Y', strtotime($trialEndsAt) ?: time());

    if ($urgency === 'urgent') {
        return <<<TEXT
Здравствуйте!

Trial-период вашего ресторана «{$brand}» на CleanMenu заканчивается {$endsHuman} — это через 5 дней.

Чтобы продолжить пользоваться платформой без перерыва:

1. Откройте {$billingUrl}
2. Привяжите карту, если ещё не привязана
3. Выберите тариф:

   • Pro    — 6 990 ₽/месяц
   • Pro Annual — 69 900 ₽/год (2 месяца бесплатно, ~16% скидка)
   • Enterprise — 19 990 ₽/месяц (white-label, dev API, priority support; в цену включён Express onboarding и 4 ч обучения)

Если выберете годовой план до окончания trial — напишите нам на hello@labus.pro,
обсудим спецусловия для перехода (lock-in цены 2026 на 12 месяцев).

Если вопросов нет, ничего делать не нужно — карта (если привязана) автоматически
спишется на день 91 в соответствии с выбранным тарифом.

Хорошего дня!
Команда CleanMenu

— — —
hello@labus.pro · https://menu.labus.pro · Telegram: @labus_support
TEXT;
    }

    // Soft 15-day reminder.
    return <<<TEXT
Здравствуйте!

Trial-период вашего ресторана «{$brand}» на CleanMenu заканчивается {$endsHuman} — осталось около двух недель.

Что важно успеть:

• Если ещё не привязали карту — сделайте это в {$billingUrl}.
  Без карты на день 91 trial автоматически перейдёт в read-only режим.

• Выберите тариф (можно поменять в любой момент):

   — Pro 6 990 ₽/мес — основной тариф для мейнстрим-операции.
   — Pro Annual 69 900 ₽/год — те же фичи, 2 месяца бесплатно,
     lock-in цены 2026 на 12 месяцев.
   — Enterprise 19 990 ₽/мес — для сетей до 10 локаций; white-label
     на своём домене, dev API, priority support. В цену включён
     Express onboarding (3 ч) и 4 ч обучения персонала
     (~24 900 ₽ value).

• Если хотите помощь с миграцией / настройкой — пишите hello@labus.pro,
  обсудим Express onboarding (9 900 ₽), Full onboarding (24 900 ₽)
  или полную миграцию с iiko/R-Keeper/Quick Resto (49 900 ₽).

Никаких автодействий с вашей стороны не требуется — это просто напоминание.

Хорошего дня!
Команда CleanMenu

— — —
hello@labus.pro · https://menu.labus.pro · Telegram: @labus_support
TEXT;
}

/**
 * Thin wrapper around the project's existing mailer. Returns true on
 * successful send. mailer.php exposes sendBrandEmail() in some setups
 * and sendVerificationEmail in others; we try a graceful fallback to
 * mail() if neither exists yet (the dev environment may lack SMTP).
 */
function mailer_send(string $to, string $subject, string $body, string $brand): bool
{
    if (function_exists('cleanmenu_send_plain_email')) {
        return cleanmenu_send_plain_email($to, $subject, $body, $brand);
    }
    if (class_exists('Mailer')) {
        try {
            $m = new Mailer();
            if (method_exists($m, 'sendPlain')) {
                return (bool)$m->sendPlain($to, $subject, $body, $brand);
            }
        } catch (Throwable $e) {
            log_line("Mailer::sendPlain failed: " . $e->getMessage());
        }
    }
    // Fallback to PHP mail() — works on most prod hosts with sendmail/postfix.
    $headers = "From: hello@labus.pro\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "MIME-Version: 1.0\r\n";
    return @mail($to, $subject, $body, $headers);
}
