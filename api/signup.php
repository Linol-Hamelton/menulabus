<?php
/**
 * api/signup.php — self-service tenant provisioning endpoint (Phase 14.6).
 *
 * Validates input → checks slug/email collision → calls provision_run() →
 * stamps plan_id, trial_ends_at, owner_email on tenants row → returns
 * { success, tenant_url, owner_email }.
 *
 * Public, no auth. CSRF-required to prevent drive-by tenant creation.
 * Rate-limited at the nginx layer (auth zone covers /api/signup.php).
 */

require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/Billing/PlanRegistry.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';
require_once __DIR__ . '/../lib/Billing/FeatureGate.php';

use Cleanmenu\Billing\PlanRegistry;
use Cleanmenu\Billing\SubscriptionStore;

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

echo json_encode([
    'success'     => true,
    'tenant_url'  => 'https://' . $domain,
    'owner_email' => $ownerEmail,
    'trial_ends'  => $trialEnds ?? null,
]);
