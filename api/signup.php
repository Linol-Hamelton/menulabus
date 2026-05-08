<?php
/**
 * api/signup.php — self-service tenant provisioning endpoint (Phase 32B).
 *
 * Phase 14.6 → Phase 32B flow:
 *   1. Validate input → check slug/email collision → provision_run() →
 *      stamp plan_id='trial' / 90-day trial_ends_at on tenants row.
 *   2. Phase 32B addition: immediately create a 1 ₽ binding payment via
 *      YooKassa (save_payment_method=true). Returns the YK confirmation
 *      URL as `redirect_url` so the frontend can redirect the customer
 *      to YK to enter card details. After successful binding, the
 *      payment-webhook handler:
 *        - saves the resulting payment_method_id to payment_methods
 *        - auto-refunds the 1 ₽ binding charge so the customer pays nothing
 *        - leaves trial active for 90 days
 *      On day 91 the billing-cycle-worker autocharges Pro 6 990 ₽ unless
 *      the owner cancelled or downgraded mid-trial.
 *
 * If the YK binding payment creation fails (network blip, invalid creds),
 * the tenant is still created in trial; signup returns {success: true,
 * tenant_url, card_required: true, redirect_url: null} and the owner can
 * add a card later via /owner.php?tab=billing.
 *
 * Public, no auth. CSRF-required to prevent drive-by tenant creation.
 * Rate-limited at the nginx layer (auth zone covers /api/signup.php).
 */

require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/Billing/PlanRegistry.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';
require_once __DIR__ . '/../lib/Billing/FeatureGate.php';
require_once __DIR__ . '/../lib/Billing/YookassaRecurring.php';

use Cleanmenu\Billing\PlanRegistry;
use Cleanmenu\Billing\SubscriptionStore;
use Cleanmenu\Billing\YookassaRecurring;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (empty($GLOBALS['isProviderMode'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$brandName    = trim((string)($input['brand_name'] ?? ''));
$brandSlug    = strtolower(trim((string)($input['brand_slug'] ?? '')));
$ownerEmail   = strtolower(trim((string)($input['owner_email'] ?? '')));
$ownerPass    = (string)($input['owner_password'] ?? '');
$planId       = (string)($input['plan_id'] ?? 'trial');

// Validation.
if ($brandName === '' || mb_strlen($brandName) > 80) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_brand_name']);
    exit;
}
if (!preg_match('/^[a-z0-9-]{3,32}$/', $brandSlug)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_brand_slug', 'message' => 'Адрес может содержать только латинские буквы, цифры и дефис, 3-32 символа']);
    exit;
}
if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_email']);
    exit;
}
if (strlen($ownerPass) < 8 || strlen($ownerPass) > 80) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_password', 'message' => 'Пароль 8-80 символов']);
    exit;
}
if (!in_array($planId, PlanRegistry::selfServiceIds(), true)) {
    $planId = 'trial';
}

// Collision checks against control plane.
try {
    $pdo = SubscriptionStore::pdo();
    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE brand_slug = :s OR owner_email = :e LIMIT 1');
    $stmt->execute([':s' => $brandSlug, ':e' => $ownerEmail]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'slug_or_email_taken', 'message' => 'Этот адрес или email уже занят']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'control_plane_unavailable']);
    exit;
}

// Generate tenant DB credentials (operator uses provision.php convention).
$tenantDbUser = 'tn_' . substr($brandSlug, 0, 16) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
$tenantDbPass = bin2hex(random_bytes(16));

require_once __DIR__ . '/../scripts/tenant/provision.php';

try {
    $domain = $brandSlug . '.menu.labus.pro';
    $result = provision_run([
        'brand-name'      => $brandName,
        'brand-slug'      => $brandSlug,
        'domain'          => $domain,
        'mode'            => 'tenant',
        'owner-email'     => $ownerEmail,
        'owner-password'  => $ownerPass,
        'tenant-db-user'  => $tenantDbUser,
        'tenant-db-pass'  => $tenantDbPass,
        'skip-smoke'      => true,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('signup: provision_run failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'provision_failed', 'message' => $e->getMessage()]);
    exit;
}

$tenantId = (int)($result['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'tenant_id_missing']);
    exit;
}

// Stamp billing fields on the new tenant row.
try {
    $trialDays = (int)(PlanRegistry::byId('trial')['trial_days'] ?? 14);
    $trialEnds = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
    $pdo->prepare(
        'UPDATE tenants SET plan_id = :p, subscription_status = "trial",
                            trial_ends_at = :tend, owner_email = :em,
                            owner_user_id = :uid
         WHERE id = :id'
    )->execute([
        ':p'    => $planId,
        ':tend' => $trialEnds,
        ':em'   => $ownerEmail,
        ':uid'  => null, // tenant-side users.id is in their isolated DB; can be backfilled by user later
        ':id'   => $tenantId,
    ]);
    SubscriptionStore::logEvent($tenantId, 'signup', [
        'plan_id'    => $planId,
        'brand_slug' => $brandSlug,
        'domain'     => $domain,
    ]);
} catch (Throwable $e) {
    error_log('signup: failed to stamp billing fields on tenant #' . $tenantId . ': ' . $e->getMessage());
    // Don't fail the response — provisioning succeeded; admin can fix manually.
}

// ─── Phase 32B: card-required signup ───────────────────────────────────────
// Initiate a 1 ₽ binding payment via YooKassa. Card data flows through
// YK-hosted page (PCI-DSS compliant — we never see the PAN). On success,
// payment-webhook.php saves the payment_method_id and refunds the 1 ₽.
//
// If YK is unreachable / misconfigured, signup still succeeds (tenant is
// created in trial); the owner can attach a card later from the billing
// tab. We surface the failure mode via card_required + redirect_url=null.
$cardRedirectUrl = null;
$cardError = null;
try {
    $bindingAmountKop = 100; // 1 ₽ minimum YK accepts
    $idemKey = 'signup_card_' . $tenantId . '_' . substr(md5(microtime(true) . random_int(0, PHP_INT_MAX)), 0, 12);
    $returnUrl = 'https://' . $domain . '/owner.php?tab=billing&card_added=1';
    $resp = YookassaRecurring::createInitialPayment(
        $tenantId,
        $bindingAmountKop,
        'Привязка карты для подписки CleanMenu (1 ₽ возвращается автоматически)',
        $returnUrl,
        $idemKey
    );
    $cardRedirectUrl = $resp['confirmation_url'] ?? null;

    // Record this binding payment as a pending invoice so the webhook
    // handler can correlate it back to the tenant. Reusing the same row
    // pattern as /api/billing-action.php update_payment_method.
    if (!empty($resp['id'])) {
        $pdo->prepare(
            'INSERT INTO subscription_invoices
                (tenant_id, plan_id, period_start, period_end, amount_kop,
                 status, yk_payment_id, retry_count)
             VALUES (:tid, :pid, NOW(), NOW(), :amt, "pending", :ykid, 0)'
        )->execute([
            ':tid'  => $tenantId,
            ':pid'  => 'trial',
            ':amt'  => $bindingAmountKop,
            ':ykid' => (string)$resp['id'],
        ]);
    }

    SubscriptionStore::logEvent($tenantId, 'card_binding_initiated', [
        'yk_payment_id' => $resp['id'] ?? null,
        'amount_kop'    => $bindingAmountKop,
    ]);
} catch (Throwable $e) {
    $cardError = $e->getMessage();
    error_log('signup: YK card binding failed for tenant #' . $tenantId . ': ' . $cardError);
    SubscriptionStore::logEvent($tenantId, 'card_binding_failed', ['error' => $cardError]);
}

echo json_encode([
    'success'      => true,
    'tenant_url'   => 'https://' . $domain,
    'owner_email'  => $ownerEmail,
    'trial_ends'   => $trialEnds ?? null,
    'redirect_url' => $cardRedirectUrl,        // → if non-null, frontend redirects to YK for card input
    'card_required'=> true,
    'card_error'   => $cardError,              // null on success; stringified exception on failure (signup still succeeded)
]);
