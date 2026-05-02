<?php
/**
 * billing-cycle-worker.php — SaaS subscription charge worker
 * (Phase 14.4, 2026-05-03).
 *
 * Cron (every 6 hours):
 *   0 *\/6 * * * cd /var/www/.../menu.labus.pro && php scripts/billing-cycle-worker.php >> data/logs/billing-cycle-worker.log 2>&1
 *
 * Logic per pass:
 *   1. Connect to control-plane DB (must be configured).
 *   2. Find tenants where:
 *      - status='active' AND current_period_end <= NOW() + 1 day  → bill due
 *      - status in ('active','past_due') AND has a failed invoice with
 *        next_retry_at <= NOW()                                  → retry
 *      - status='trial' AND trial_ends_at < NOW() AND no payment_method
 *                                                                → flip to past_due
 *   3. For each due tenant:
 *      a. If retryable invoice exists, charge that.
 *      b. Else create a new invoice (period = next 1 month) and charge.
 *   4. Charge via YookassaRecurring::chargeStored() using stored
 *      payment_method_id. Result is saved synchronously (YK returns the
 *      final state on chargeStored — no async webhook required for the
 *      decision, but the webhook still fires and is idempotent).
 *
 * Failures are non-fatal — best-effort, errors logged. The webhook handler
 * (lib/Billing/SubscriptionStore::onWebhook) is the canonical state mutator
 * for retries / status transitions; this worker only initiates charges.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Billing/PlanRegistry.php';
require_once __DIR__ . '/../lib/Billing/FeatureGate.php';
require_once __DIR__ . '/../lib/Billing/YookassaRecurring.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';

use Cleanmenu\Billing\PlanRegistry;
use Cleanmenu\Billing\YookassaRecurring;
use Cleanmenu\Billing\SubscriptionStore;

// Bootstrap tenant_runtime to populate $GLOBALS['cleanmenuTenantRuntime']['control'].
$bootFile = __DIR__ . '/../tenant_runtime.php';
if (is_file($bootFile)) {
    require_once $bootFile;
    if (function_exists('tenant_bootstrap_runtime')) {
        try { tenant_bootstrap_runtime(); } catch (Throwable $_) {}
    }
}

try {
    $pdo = SubscriptionStore::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "[billing-cycle] no control-plane PDO: " . $e->getMessage() . "\n");
    exit(0);
}

$ts = date('c');
fwrite(STDOUT, "[billing-cycle] {$ts} pass start\n");

// Step 1: trial-expired tenants without payment_method → past_due.
$expiredTrials = $pdo->query(
    "SELECT id, brand_slug FROM tenants
     WHERE subscription_status = 'trial'
       AND trial_ends_at IS NOT NULL
       AND trial_ends_at <= NOW()
       AND id NOT IN (SELECT tenant_id FROM payment_methods WHERE is_default = 1)"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($expiredTrials as $t) {
    SubscriptionStore::updateTenantStatus((int)$t['id'], 'past_due');
    fwrite(STDOUT, "[billing-cycle] trial expired tenant #{$t['id']} ({$t['brand_slug']}) → past_due\n");
}

// Step 2: trial-expired tenants WITH payment_method → first auto-charge to convert to active.
$convertibleTrials = $pdo->query(
    "SELECT t.id, t.plan_id FROM tenants t
     INNER JOIN payment_methods pm ON pm.tenant_id = t.id AND pm.is_default = 1
     WHERE t.subscription_status = 'trial'
       AND t.trial_ends_at IS NOT NULL
       AND t.trial_ends_at <= NOW()"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($convertibleTrials as $t) {
    chargeTenantNewCycle((int)$t['id'], (string)$t['plan_id']);
}

// Step 3: active tenants whose period_end is within 1 day → new cycle charge.
$dueTenants = SubscriptionStore::getDueForCharge(50);
$processed = 0;
$charged   = 0;
$failed    = 0;
foreach ($dueTenants as $t) {
    $tenantId = (int)$t['id'];
    $planId   = (string)$t['plan_id'];
    $status   = (string)$t['subscription_status'];
    $processed++;

    // Skip plans without recurring billing.
    if (in_array($planId, ['trial', 'enterprise'], true)) {
        // Enterprise is invoiced manually (custom contracts).
        continue;
    }

    try {
        if ($status === 'past_due') {
            // Retry the most recent failed invoice.
            $existing = SubscriptionStore::getRetryableInvoice($tenantId);
            if ($existing) {
                if (chargeTenantInvoice($tenantId, (int)$existing['id'], $planId, (int)$existing['amount_kop'])) {
                    $charged++;
                } else {
                    $failed++;
                }
                continue;
            }
        }
        // Create new cycle invoice.
        if (chargeTenantNewCycle($tenantId, $planId)) {
            $charged++;
        } else {
            $failed++;
        }
    } catch (Throwable $e) {
        error_log("[billing-cycle] tenant #{$tenantId}: " . $e->getMessage());
        $failed++;
    }
}

fwrite(STDOUT, "[billing-cycle] {$ts} done"
    . " trial_expired_to_past_due=" . count($expiredTrials)
    . " trial_to_active_charge=" . count($convertibleTrials)
    . " due_processed={$processed} charged={$charged} failed={$failed}\n");
exit(0);

// ───────────────────────────────────────────────────────────────────────

function chargeTenantNewCycle(int $tenantId, string $planId): bool
{
    $plan = PlanRegistry::byId($planId);
    if (!$plan) {
        fwrite(STDERR, "[billing-cycle] tenant #{$tenantId} unknown plan {$planId}\n");
        return false;
    }
    $amountKop = (int)$plan['price_kop'];
    if ($amountKop <= 0) return false; // trial/free — nothing to charge

    $now = date('Y-m-d H:i:s');
    $end = date('Y-m-d H:i:s', strtotime('+1 month'));
    $invoiceId = SubscriptionStore::createInvoice($tenantId, $planId, $now, $end, $amountKop);
    return chargeTenantInvoice($tenantId, $invoiceId, $planId, $amountKop);
}

function chargeTenantInvoice(int $tenantId, int $invoiceId, string $planId, int $amountKop): bool
{
    $pm = SubscriptionStore::getDefaultPaymentMethod($tenantId);
    if (!$pm) {
        fwrite(STDERR, "[billing-cycle] tenant #{$tenantId} has no payment_method\n");
        return false;
    }
    $idemKey = 'sub_inv_' . $invoiceId . '_' . substr(md5(microtime(true) . random_int(0, PHP_INT_MAX)), 0, 12);
    try {
        $resp = YookassaRecurring::chargeStored(
            $tenantId,
            $invoiceId,
            (string)$pm['yk_payment_method_id'],
            $amountKop,
            "Подписка {$planId} #{$invoiceId}",
            $idemKey
        );
        SubscriptionStore::attachYkPaymentToInvoice($invoiceId, $resp['id']);
        // chargeStored returns synchronous status; webhook will also fire and
        // is idempotent (SubscriptionStore::onWebhook re-applies same state).
        SubscriptionStore::logEvent($tenantId, 'charge_attempt', [
            'invoice_id' => $invoiceId, 'amount_kop' => $amountKop, 'yk_payment_id' => $resp['id'], 'sync_status' => $resp['status'],
        ]);
        return $resp['status'] === 'succeeded';
    } catch (Throwable $e) {
        SubscriptionStore::updateInvoiceByYk($idemKey, 'failed', $e->getMessage());
        SubscriptionStore::logEvent($tenantId, 'charge_failed', [
            'invoice_id' => $invoiceId, 'reason' => $e->getMessage(),
        ]);
        return false;
    }
}
