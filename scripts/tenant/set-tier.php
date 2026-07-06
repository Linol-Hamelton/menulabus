<?php
/**
 * scripts/tenant/set-tier.php — Phase L103.9 CLI tariff switch (QA / support).
 *
 * Usage:
 *   php scripts/tenant/set-tier.php --tenant-id=1 --tariff=menu
 *   php scripts/tenant/set-tier.php --tenant-id=1 --tariff=kitchen --location-id=0 --days=36500
 *   php scripts/tenant/set-tier.php --tenant-id=1 --show
 *
 * Writes BOTH billing sources in lockstep (same logic as the provider
 * change_plan action in api/provider/tenant-action.php):
 *   1. control-plane subscriptions(tenant_id, location_id) — L103 read path
 *   2. control-plane tenants.plan_id — legacy FeatureGate path
 *
 * NOTE: per-request tariff cache is session-scoped; after switching, either
 * log out/in in the browser or just reload — Database::$tariffCache is
 * request-scoped, so a fresh page load reads the new tier immediately.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../tenant_runtime.php';
require_once __DIR__ . '/../../lib/Billing/SubscriptionStore.php';
require_once __DIR__ . '/../../lib/Billing/FeatureGate.php';

use Cleanmenu\Billing\SubscriptionStore;

$opts = getopt('', ['tenant-id:', 'tariff::', 'location-id::', 'days::', 'show']);
$tenantId   = (int)($opts['tenant-id'] ?? 0);
$tariffCode = trim((string)($opts['tariff'] ?? ''));
$locationId = max(0, (int)($opts['location-id'] ?? 0));
$days       = max(1, min(36500, (int)($opts['days'] ?? 30)));
$showOnly   = array_key_exists('show', $opts);

if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php set-tier.php --tenant-id=N [--tariff=CODE] [--location-id=N] [--days=N] [--show]\n");
    exit(1);
}

if (!tenant_control_configured()) {
    fwrite(STDERR, "[set-tier] control plane not configured\n");
    exit(1);
}
$pdo = tenant_control_pdo();

if ($showOnly || $tariffCode === '') {
    $t = $pdo->prepare('SELECT id, brand_slug, plan_id, subscription_status, current_period_end FROM tenants WHERE id = :id');
    $t->execute([':id' => $tenantId]);
    $tenant = $t->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) { fwrite(STDERR, "[set-tier] tenant #{$tenantId} not found\n"); exit(1); }
    $s = $pdo->prepare('SELECT location_id, tariff_code, status, current_period_end, legacy_grandfather FROM subscriptions WHERE tenant_id = :id ORDER BY location_id');
    $s->execute([':id' => $tenantId]);
    echo "tenant: " . json_encode($tenant, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "subscription: " . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    exit(0);
}

$chk = $pdo->prepare('SELECT code, display_name, tier_rank FROM tariffs WHERE code = :c AND is_active = 1');
$chk->execute([':c' => $tariffCode]);
$tariff = $chk->fetch(PDO::FETCH_ASSOC);
if (!$tariff) {
    $all = $pdo->query('SELECT code FROM tariffs WHERE is_active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);
    fwrite(STDERR, "[set-tier] unknown tariff '{$tariffCode}'. Valid: " . implode(', ', $all) . "\n");
    exit(1);
}

$periodEnd = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));

$pdo->prepare('INSERT INTO tenant_locations (tenant_id, location_id, name, is_active)
               VALUES (:tid, :lid, "Основная", 1)
               ON DUPLICATE KEY UPDATE is_active = 1')
    ->execute([':tid' => $tenantId, ':lid' => $locationId]);

$pdo->prepare('INSERT INTO subscriptions (tenant_id, location_id, tariff_code, status, current_period_end)
               VALUES (:tid, :lid, :code, "active", :pe)
               ON DUPLICATE KEY UPDATE
                 tariff_code = VALUES(tariff_code), status = "active",
                 current_period_end = VALUES(current_period_end), cancelled_at = NULL')
    ->execute([':tid' => $tenantId, ':lid' => $locationId, ':code' => $tariffCode, ':pe' => $periodEnd]);

SubscriptionStore::updateTenantStatus($tenantId, 'active', $periodEnd, $tariffCode);
SubscriptionStore::logEventForLocation($tenantId, $locationId, 'cli_set_tier', [
    'tariff_code' => $tariffCode,
    'period_end'  => $periodEnd,
]);

echo "[set-tier] tenant #{$tenantId} location {$locationId} → {$tariff['display_name']} ({$tariffCode}, rank " . ($tariff['tier_rank'] ?? 'NULL') . "), active until {$periodEnd}\n";
echo "[set-tier] reload the page (tariff cache is per-request) — no FPM restart needed\n";
exit(0);
