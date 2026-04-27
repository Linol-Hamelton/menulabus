<?php
/**
 * Fiscal receipt status worker (Phase 7.2, 2026-04-27).
 *
 * Polls АТОЛ Онлайн for receipts that have a uuid but no URL yet. When
 * provider returns status='done' with the OFD link, the worker stamps
 * orders.fiscal_receipt_url. Failed (status='fail') receipts get the
 * uuid cleared so the next paid-webhook firing can retry from scratch.
 *
 * Cron (suggested):
 *   *\/2 * * * * cd /var/www/.../menu.labus.pro && php scripts/fiscal-receipt-worker.php >> data/logs/fiscal-receipt-worker.log 2>&1
 *
 * Two-minute cadence is enough — АТОЛ usually responds in 15-30s, and
 * legal receipt-display deadline is "as soon as practical" (no seconds-
 * level SLA).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Fiscal/AtolOnline.php';

$db = Database::getInstance();

$provider = (string)json_decode($db->getSetting('fiscal_provider') ?? '""', true);
if ($provider !== 'atol') {
    fwrite(STDOUT, "[fiscal-worker] " . date('c') . " no provider configured — exit\n");
    exit(0);
}

$cfg = [
    'login'           => (string)json_decode($db->getSetting('fiscal_atol_login') ?? '""', true),
    'password'        => (string)json_decode($db->getSetting('fiscal_atol_password') ?? '""', true),
    'group_code'      => (string)json_decode($db->getSetting('fiscal_atol_group_code') ?? '""', true),
    'inn'             => (string)json_decode($db->getSetting('fiscal_atol_inn') ?? '""', true),
    'payment_address' => (string)json_decode($db->getSetting('fiscal_atol_payment_address') ?? '""', true),
    'sno'             => (string)json_decode($db->getSetting('fiscal_atol_sno') ?? '"usn_income"', true),
    'sandbox'         => (string)json_decode($db->getSetting('fiscal_atol_sandbox') ?? '"0"', true) === '1',
];
foreach (['login', 'password', 'group_code', 'inn', 'payment_address'] as $req) {
    if ($cfg[$req] === '') {
        fwrite(STDOUT, "[fiscal-worker] " . date('c') . " missing config '{$req}' — exit\n");
        exit(0);
    }
}

$atol = new \Cleanmenu\Fiscal\AtolOnline($cfg);

$r = new ReflectionClass($db);
$p = $r->getProperty('connection');
$p->setAccessible(true);
$pdo = $p->getValue($db);

// Pull pending: uuid set, url not set, capped at 50/run to avoid burst.
$rows = $pdo->query(
    "SELECT id, fiscal_receipt_uuid
     FROM orders
     WHERE fiscal_receipt_uuid IS NOT NULL
       AND fiscal_receipt_uuid <> ''
       AND (fiscal_receipt_url IS NULL OR fiscal_receipt_url = '')
     ORDER BY id DESC
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$failed  = 0;
$still_pending = 0;
foreach ($rows as $row) {
    $orderId = (int)$row['id'];
    $uuid    = (string)$row['fiscal_receipt_uuid'];
    try {
        $st = $atol->fetchReceiptUrl($uuid);
        if ($st['status'] === 'done' && !empty($st['url'])) {
            $up = $pdo->prepare('UPDATE orders SET fiscal_receipt_url = :u WHERE id = :id');
            $up->execute([':u' => (string)$st['url'], ':id' => $orderId]);
            $updated++;
        } elseif ($st['status'] === 'fail') {
            // Clear uuid so the next paid hook can retry. Keep url empty.
            $up = $pdo->prepare('UPDATE orders SET fiscal_receipt_uuid = NULL WHERE id = :id');
            $up->execute([':id' => $orderId]);
            $failed++;
        } else {
            $still_pending++;
        }
    } catch (Throwable $e) {
        error_log("[fiscal-worker] order {$orderId}: " . $e->getMessage());
        $failed++;
    }
}

fwrite(STDOUT, "[fiscal-worker] " . date('c')
    . " checked=" . count($rows)
    . " updated={$updated} failed={$failed} pending={$still_pending}\n");
exit(0);
