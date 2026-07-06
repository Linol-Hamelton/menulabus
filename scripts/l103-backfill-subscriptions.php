<?php
/**
 * scripts/l103-backfill-subscriptions.php — one-shot CLI backfill
 * (Phase L103.4).
 *
 * Migrates per-tenant billing (control-plane.tenants.plan_id) into the new
 * per-location subscriptions model:
 *
 *   For each control-plane tenant (is_active=1):
 *     1. UPSERT tenant_locations(tenant_id, 1, 'Основная', 1) — placeholder.
 *        Real per-location rows are populated by Database::syncLocationToControlPlane
 *        the next time the owner opens admin/locations.php (saveLocation hook).
 *     2. INSERT IGNORE INTO subscriptions one row per tenant mapping legacy
 *        plan_id → new tariff_code per the L103 plan §4.3:
 *
 *          OLD plan_id              → NEW tariff_code  (legacy_grandfather)
 *          trial                    → menu_trial       (0)
 *          pro / pro_annual         → order            (1)
 *          enterprise / _annual     → kitchen          (1)
 *          enterprise_plus          → chain            (1)
 *          anything / NULL          → menu             (0)
 *
 *        status / trial_ends_at / current_period_end are preserved verbatim
 *        from the tenants row so dunning/period state is not lost.
 *     3. Emit a `legacy_migrated_to_l103` event into subscription_events.
 *
 * After Phase L103.6 ships, tenants whose real location count > 1 can buy
 * per-location subscriptions through /owner.php?tab=billing — those extra
 * rows are NOT auto-created here (we don't want to silently bill a chain
 * customer N× their current rate).
 *
 * Flags:
 *   --dry-run    print intended writes only, no DB mutation
 *   --tenant=ID  only process a single tenant id (else: all is_active=1)
 *
 * Idempotent: INSERT IGNORE on subscriptions + ON DUPLICATE KEY UPDATE on
 * tenant_locations. Safe to re-run.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../tenant_runtime.php';
require_once __DIR__ . '/../lib/Billing/FeatureGate.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';

use Cleanmenu\Billing\SubscriptionStore;

$opts = getopt('', ['dry-run', 'tenant::']);
$dryRun     = array_key_exists('dry-run', $opts);
$onlyTenant = isset($opts['tenant']) ? (int)$opts['tenant'] : 0;

if (!tenant_control_configured()) {
    fwrite(STDERR, "[l103-backfill] control plane not configured — aborting\n");
    exit(1);
}

try {
    $pdo = tenant_control_pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "[l103-backfill] cannot open control-plane PDO: " . $e->getMessage() . "\n");
    exit(1);
}

// Sanity: tariffs reference table must be seeded (Phase L103.1 migration ran).
$tariffCount = (int)$pdo->query("SELECT COUNT(*) FROM tariffs WHERE is_active=1")->fetchColumn();
if ($tariffCount === 0) {
    fwrite(STDERR, "[l103-backfill] tariffs table empty — run sql/l103-six-tier-migration.sql first\n");
    exit(1);
}
fwrite(STDOUT, "[l103-backfill] " . date('c') . " starting (dry-run=" . ($dryRun ? 'yes' : 'no') . ", filter=" . ($onlyTenant ?: 'all') . ")\n");
fwrite(STDOUT, "[l103-backfill] tariffs catalog: {$tariffCount} active rows\n");

/** Map legacy plan_id → [new tariff_code, legacy_grandfather flag]. */
function l103_map_plan(?string $planId): array
{
    $p = (string)($planId ?? '');
    switch ($p) {
        case 'trial':
            return ['menu_trial', 0];
        case 'pro':
        case 'pro_annual':
            return ['order', 1];
        case 'enterprise':
        case 'enterprise_annual':
            return ['kitchen', 1];
        case 'enterprise_plus':
            return ['chain', 1];
        default:
            return ['menu', 0];
    }
}

$sql = "SELECT id, brand_slug, plan_id, subscription_status,
               trial_ends_at, current_period_end, is_active
          FROM tenants
         WHERE is_active = 1";
if ($onlyTenant > 0) {
    $sql .= " AND id = :tid";
}
$stmt = $pdo->prepare($sql);
if ($onlyTenant > 0) {
    $stmt->bindValue(':tid', $onlyTenant, PDO::PARAM_INT);
}
$stmt->execute();
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$tenants) {
    fwrite(STDOUT, "[l103-backfill] no eligible tenants — done\n");
    exit(0);
}

$processed = 0;
$inserted  = 0;
$skipped   = 0;
// location_id=0 = fallback for legacy / single-location tenants; matches
// Database::activeLocationId() when the tenant DB has no `locations` rows.
$placeholderLocId = 0;

foreach ($tenants as $t) {
    $tenantId   = (int)$t['id'];
    $brand      = (string)$t['brand_slug'];
    $oldPlan    = $t['plan_id'] !== null ? (string)$t['plan_id'] : null;
    $oldStatus  = (string)($t['subscription_status'] ?? 'trial');
    $trialEnds  = $t['trial_ends_at'];
    $periodEnd  = $t['current_period_end'];
    [$tariffCode, $grandfather] = l103_map_plan($oldPlan);

    $allowedStatus = ['trial', 'active', 'past_due', 'suspended', 'cancelled'];
    $newStatus = in_array($oldStatus, $allowedStatus, true) ? $oldStatus : 'trial';

    fwrite(STDOUT, sprintf(
        "[l103-backfill] tenant #%d (%s): plan=%s status=%s → tariff=%s grandfather=%d location=%d\n",
        $tenantId, $brand,
        $oldPlan ?? 'NULL', $oldStatus,
        $tariffCode, $grandfather, $placeholderLocId
    ));

    if ($dryRun) {
        $processed++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        // 1. UPSERT placeholder tenant_locations row. Real location names
        //    sync in later via Database::syncLocationToControlPlane.
        $pdo->prepare(
            'INSERT INTO tenant_locations (tenant_id, location_id, name, is_active)
             VALUES (:tid, :lid, :name, 1)
             ON DUPLICATE KEY UPDATE name = IF(name = :name, name, name)'
        )->execute([
            ':tid'  => $tenantId,
            ':lid'  => $placeholderLocId,
            ':name' => 'Основная',
        ]);

        // 2. INSERT IGNORE subscription (one per tenant for now).
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO subscriptions
                (tenant_id, location_id, tariff_code, status,
                 trial_ends_at, current_period_end, legacy_grandfather)
             VALUES (:tid, :lid, :code, :status, :te, :pe, :gf)'
        );
        $ins->execute([
            ':tid'    => $tenantId,
            ':lid'    => $placeholderLocId,
            ':code'   => $tariffCode,
            ':status' => $newStatus,
            ':te'     => $trialEnds,
            ':pe'     => $periodEnd,
            ':gf'     => $grandfather,
        ]);
        $rowsAffected = $ins->rowCount();

        if ($rowsAffected > 0) {
            // 3. Audit event.
            SubscriptionStore::logEvent($tenantId, 'legacy_migrated_to_l103', [
                'location_id'        => $placeholderLocId,
                'legacy_plan_id'     => $oldPlan,
                'new_tariff_code'    => $tariffCode,
                'legacy_grandfather' => (bool)$grandfather,
                'preserved_status'   => $newStatus,
            ]);
            $inserted++;
        } else {
            $skipped++;
        }

        $pdo->commit();
        $processed++;
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $_) {}
        fwrite(STDERR, "[l103-backfill] tenant #{$tenantId} FAILED: " . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, sprintf(
    "[l103-backfill] done — processed=%d inserted=%d skipped=%d (dry-run=%s)\n",
    $processed, $inserted, $skipped, $dryRun ? 'yes' : 'no'
));
exit(0);
