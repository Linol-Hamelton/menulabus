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

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unknown_action']);
    }
} catch (Throwable $e) {
    error_log('provider/tenant-action ' . $action . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'action_failed', 'message' => $e->getMessage()]);
}
