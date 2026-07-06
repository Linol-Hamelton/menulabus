<?php
/**
 * api/provider/tenant-action.php — provider-side ops on one tenant (Phase 14.7).
 *
 * Actions:
 *   extend_trial(tenant_id, days=7)            — push trial_ends_at forward
 *   force_active(tenant_id)                    — manually flip to active
 *   force_past_due(tenant_id)                  — manually flip to past_due
 *   force_suspended(tenant_id)                 — manually flip to suspended
 *   comp(tenant_id, amount_kop, reason)        — write a paid-zero invoice
 *   change_plan(tenant_id, tariff_code, location_id=0, days=30)
 *       — Phase L103.9: set an L103 tariff on one location without payment
 *         (QA / support / comp use). Writes BOTH the legacy tenants.plan_id
 *         and the per-location subscriptions row so every read path agrees.
 */

require_once __DIR__ . '/../../require_provider_admin.php';
require_once __DIR__ . '/../../lib/Csrf.php';
require_once __DIR__ . '/../../lib/Billing/SubscriptionStore.php';

use Cleanmenu\Billing\SubscriptionStore;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

Csrf::requireValid();

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$action   = (string)($input['action'] ?? '');
$tenantId = (int)($input['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'bad_tenant_id']);
    exit;
}

$tenant = SubscriptionStore::getTenantBilling($tenantId);
if (!$tenant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'tenant_not_found']);
    exit;
}

try {
    switch ($action) {
        case 'extend_trial':
            $days = max(1, min(365, (int)($input['days'] ?? 7)));
            $newTrial = date('Y-m-d H:i:s', strtotime('+' . $days . ' days', strtotime((string)($tenant['trial_ends_at'] ?? 'now'))));
            $pdo = SubscriptionStore::pdo();
            $pdo->prepare('UPDATE tenants SET trial_ends_at = :t, subscription_status = "trial" WHERE id = :id')
                ->execute([':t' => $newTrial, ':id' => $tenantId]);
            SubscriptionStore::logEvent($tenantId, 'trial_extended', ['days' => $days, 'new_trial_ends_at' => $newTrial]);
            echo json_encode(['success' => true, 'trial_ends_at' => $newTrial]);
            break;

        case 'force_active':
            SubscriptionStore::updateTenantStatus($tenantId, 'active', date('Y-m-d H:i:s', strtotime('+1 month')));
            echo json_encode(['success' => true]);
            break;

        case 'force_past_due':
            SubscriptionStore::updateTenantStatus($tenantId, 'past_due');
            echo json_encode(['success' => true]);
            break;

        case 'force_suspended':
            SubscriptionStore::updateTenantStatus($tenantId, 'suspended');
            echo json_encode(['success' => true]);
            break;

        case 'comp':
            $amountKop = max(0, (int)($input['amount_kop'] ?? 0));
            $reason    = (string)($input['reason'] ?? 'manual_comp');
            $now = date('Y-m-d H:i:s');
            $end = date('Y-m-d H:i:s', strtotime('+1 month'));
            $invoiceId = SubscriptionStore::createInvoice($tenantId, (string)$tenant['plan_id'], $now, $end, $amountKop);
            SubscriptionStore::updateInvoiceByYk('comp_' . $invoiceId, 'paid'); // synthetic yk_payment_id
            // Mark paid directly (we wrote a synthetic id above so updateInvoiceByYk targets it).
            $pdo = SubscriptionStore::pdo();
            $pdo->prepare('UPDATE subscription_invoices SET yk_payment_id = :yk, status = "paid", paid_at = NOW() WHERE id = :id')
                ->execute([':yk' => 'comp_' . $invoiceId, ':id' => $invoiceId]);
            SubscriptionStore::logEvent($tenantId, 'comp', ['amount_kop' => $amountKop, 'reason' => $reason, 'invoice_id' => $invoiceId]);
            // Also extend period if currently past_due/suspended.
            if (in_array((string)$tenant['subscription_status'], ['past_due', 'suspended'], true)) {
                SubscriptionStore::updateTenantStatus($tenantId, 'active', $end);
            }
            echo json_encode(['success' => true, 'invoice_id' => $invoiceId]);
            break;

        case 'change_plan':
            // Phase L103.9 — provider-side tariff switch without payment.
            // Validates the code against the live tariffs catalog, then writes
            // both billing sources in lockstep:
            //   1. subscriptions(tenant_id, location_id) — the L103 read path
            //      (Database::getActiveTariff / hasFeature)
            //   2. tenants.plan_id — the legacy FeatureGate / PlanRegistry path
            $tariffCode = trim((string)($input['tariff_code'] ?? ''));
            $locationId = max(0, (int)($input['location_id'] ?? 0));
            $days       = max(1, min(36500, (int)($input['days'] ?? 30)));
            if ($tariffCode === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'missing_tariff_code']);
                break;
            }
            $pdo = SubscriptionStore::pdo();
            $chk = $pdo->prepare('SELECT code, tier_rank FROM tariffs WHERE code = :c AND is_active = 1 LIMIT 1');
            $chk->execute([':c' => $tariffCode]);
            $tariffRow = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$tariffRow) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'unknown_tariff_code']);
                break;
            }
            $periodEnd = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));

            // Ensure the FK target exists, then upsert the subscription row
            // (updateLocationSubscription is UPDATE-only and would no-op on
            // locations that never had a subscription).
            $pdo->prepare('INSERT INTO tenant_locations (tenant_id, location_id, name, is_active)
                           VALUES (:tid, :lid, :name, 1)
                           ON DUPLICATE KEY UPDATE is_active = 1')
                ->execute([':tid' => $tenantId, ':lid' => $locationId, ':name' => 'Основная']);
            $pdo->prepare('INSERT INTO subscriptions
                             (tenant_id, location_id, tariff_code, status, current_period_end)
                           VALUES (:tid, :lid, :code, "active", :pe)
                           ON DUPLICATE KEY UPDATE
                             tariff_code = VALUES(tariff_code),
                             status = "active",
                             current_period_end = VALUES(current_period_end),
                             cancelled_at = NULL')
                ->execute([':tid' => $tenantId, ':lid' => $locationId, ':code' => $tariffCode, ':pe' => $periodEnd]);

            // Legacy column kept in sync so FeatureGate/PlanRegistry surfaces
            // (owner billing tab, suspended-gate) don't contradict L103 state.
            SubscriptionStore::updateTenantStatus($tenantId, 'active', $periodEnd, $tariffCode);

            SubscriptionStore::logEventForLocation($tenantId, $locationId, 'provider_change_plan', [
                'tariff_code' => $tariffCode,
                'tier_rank'   => $tariffRow['tier_rank'] !== null ? (int)$tariffRow['tier_rank'] : null,
                'period_end'  => $periodEnd,
                'days'        => $days,
            ]);
            echo json_encode([
                'success'     => true,
                'tariff_code' => $tariffCode,
                'location_id' => $locationId,
                'period_end'  => $periodEnd,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unknown_action']);
    }
} catch (Throwable $e) {
    error_log('provider/tenant-action ' . $action . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'action_failed', 'message' => $e->getMessage()]);
}
