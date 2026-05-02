<?php
/**
 * api/billing-action.php — tenant-side billing actions (Phase 14.5).
 *
 * Owner-gated, CSRF-protected. Actions:
 *   change_plan          — switch plan_id; if upgrading and a card exists,
 *                          immediate prorate-charge (v1: full month at new
 *                          price applied at next cycle; no proration math
 *                          to keep launch simple).
 *   update_payment_method — initiate YK confirmation_url with
 *                          save_payment_method=true; on return, webhook
 *                          stores the new payment_method_id.
 *   cancel_subscription  — flips status to 'cancelled' at end of period;
 *                          tenant continues working until current_period_end.
 *   reactivate           — restore from 'cancelled' if still within period.
 */

require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/Billing/PlanRegistry.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';
require_once __DIR__ . '/../lib/Billing/YookassaRecurring.php';
require_once __DIR__ . '/../lib/Billing/FeatureGate.php';

use Cleanmenu\Billing\PlanRegistry;
use Cleanmenu\Billing\SubscriptionStore;
use Cleanmenu\Billing\YookassaRecurring;
use Cleanmenu\Billing\FeatureGate;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

Csrf::requireValid();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'auth_required']);
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById((int)$_SESSION['user_id']);
if (!$user || $user['role'] !== 'owner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'owner_only']);
    exit;
}

$tenantId = (int)($GLOBALS['tenantId'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'tenant_not_resolved']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');

switch ($action) {
    case 'change_plan':
        $newPlan = (string)($input['plan_id'] ?? '');
        if (!PlanRegistry::exists($newPlan) || $newPlan === 'trial') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_plan']);
            break;
        }
        if ($newPlan === 'enterprise') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'enterprise_via_sales']);
            break;
        }
        $billing = SubscriptionStore::getTenantBilling($tenantId);
        if (!$billing) { http_response_code(500); echo json_encode(['success' => false, 'error' => 'tenant_lookup_failed']); break; }

        $pm = SubscriptionStore::getDefaultPaymentMethod($tenantId);
        if (!$pm) {
            // Need a card first.
            echo json_encode(['success' => false, 'error' => 'card_required', 'message' => 'Сначала добавьте карту']);
            break;
        }
        // Switch the plan; the next billing-cycle worker pass charges new price.
        SubscriptionStore::updateTenantStatus($tenantId, 'active', $billing['current_period_end'] ?? null, $newPlan);
        SubscriptionStore::logEvent($tenantId, 'plan_changed', ['from' => $billing['plan_id'], 'to' => $newPlan]);
        FeatureGate::clearCache();
        echo json_encode(['success' => true, 'plan_id' => $newPlan]);
        break;

    case 'update_payment_method':
        $billing = SubscriptionStore::getTenantBilling($tenantId);
        if (!$billing) { http_response_code(500); echo json_encode(['success' => false, 'error' => 'tenant_lookup_failed']); break; }
        $planId    = (string)($billing['plan_id'] ?? 'trial');
        $amountKop = max(100, PlanRegistry::priceKop($planId)); // 1 ₽ minimum for card-add (YK rejects 0)
        $idemKey = 'sub_pm_' . $tenantId . '_' . substr(md5(microtime(true) . random_int(0, PHP_INT_MAX)), 0, 12);
        $returnUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'menu.labus.pro') . '/owner.php?tab=billing&card_added=1';
        try {
            $resp = YookassaRecurring::createInitialPayment(
                $tenantId,
                $amountKop,
                "Привязка карты для подписки {$planId}",
                $returnUrl,
                $idemKey
            );
            // Create an invoice row to capture this charge (it will be marked
            // paid via webhook + payment_method saved at the same time).
            $now = date('Y-m-d H:i:s');
            $end = date('Y-m-d H:i:s', strtotime('+1 month'));
            $invoiceId = SubscriptionStore::createInvoice($tenantId, $planId, $now, $end, $amountKop, $resp['id']);
            SubscriptionStore::logEvent($tenantId, 'card_add_initiated', ['invoice_id' => $invoiceId, 'yk_payment_id' => $resp['id']]);
            echo json_encode(['success' => true, 'paymentUrl' => $resp['confirmation_url']]);
        } catch (Throwable $e) {
            http_response_code(502);
            echo json_encode(['success' => false, 'error' => 'yookassa_failed', 'message' => $e->getMessage()]);
        }
        break;

    case 'cancel_subscription':
        SubscriptionStore::updateTenantStatus($tenantId, 'cancelled');
        SubscriptionStore::logEvent($tenantId, 'subscription_cancelled', ['by_user_id' => (int)$user['id']]);
        FeatureGate::clearCache();
        echo json_encode(['success' => true]);
        break;

    case 'reactivate':
        $billing = SubscriptionStore::getTenantBilling($tenantId);
        if ($billing && $billing['subscription_status'] === 'cancelled') {
            SubscriptionStore::updateTenantStatus($tenantId, 'active');
            SubscriptionStore::logEvent($tenantId, 'subscription_reactivated', ['by_user_id' => (int)$user['id']]);
            FeatureGate::clearCache();
        }
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
