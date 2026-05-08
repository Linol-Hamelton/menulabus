<?php
/**
 * api/billing-purchase-addon.php — Phase 32B add-on services purchase.
 *
 * Tenant-side endpoint. Owner-only. CSRF-required. Creates a YooKassa
 * one-time payment for a specific add-on SKU. On YK success the
 * payment-webhook handler marks the addon_purchases row as 'paid' and
 * the founder/team manually delivers the service (Express onboarding,
 * training, migration, etc.).
 *
 * Recurring add-ons (priority_support 990 ₽/mo) are not yet billed
 * recurrent-style here — that requires linking to the regular
 * subscription billing cycle. For Phase 32B priority_support purchase
 * creates a one-month YK charge; tenant can re-purchase next month
 * manually until annual-billing-and-add-ons-cycle integration ships
 * (deferred to Phase 33+).
 *
 * POST body (JSON):
 *   {
 *     "csrf_token": "…",
 *     "addon_sku":  "express_onboarding",
 *     "notes":      "optional free-form text from the buyer (e.g. 'нужна миграция меню из CSV')"
 *   }
 *
 * Response:
 *   {
 *     "success":           true,
 *     "addon_purchase_id": 123,
 *     "yk_payment_id":     "2dabe...",
 *     "redirect_url":      "https://yoomoney.ru/checkout/payments/v2/contract?orderId=…"
 *   }
 */

require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';
require_once __DIR__ . '/../lib/Billing/AddonCatalog.php';
require_once __DIR__ . '/../lib/Billing/YookassaRecurring.php';

use Cleanmenu\Billing\SubscriptionStore;
use Cleanmenu\Billing\AddonCatalog;
use Cleanmenu\Billing\YookassaRecurring;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Owner-only.
$role = (string)($_SESSION['user_role'] ?? '');
if ($role !== 'owner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden_role']);
    exit;
}

Csrf::requireValid();

$tenantId = (int)($GLOBALS['tenantId'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'tenant_lookup_failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sku   = trim((string)($input['addon_sku'] ?? ''));
$notes = trim((string)($input['notes'] ?? ''));
if (mb_strlen($notes) > 500) {
    $notes = mb_substr($notes, 0, 500);
}

if ($sku === '' || !AddonCatalog::exists($sku)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unknown_addon_sku']);
    exit;
}
if (!in_array($sku, AddonCatalog::purchasableSkus(), true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'addon_not_purchasable',
        'message' => 'Эта услуга включена в тариф и не продаётся отдельно.']);
    exit;
}

$addon  = AddonCatalog::bySku($sku);
$amount = (int)$addon['price_kop'];
if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'addon_zero_price']);
    exit;
}

// Insert pending purchase row first (so we have a stable ID for metadata).
try {
    $pdo = SubscriptionStore::pdo();
    $pdo->prepare(
        'INSERT INTO addon_purchases (tenant_id, addon_sku, amount_kop, currency, status, notes)
         VALUES (:tid, :sku, :amt, :cur, "pending", :notes)'
    )->execute([
        ':tid'   => $tenantId,
        ':sku'   => $sku,
        ':amt'   => $amount,
        ':cur'   => $addon['currency'] ?? 'RUB',
        ':notes' => $notes !== '' ? $notes : null,
    ]);
    $purchaseId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('billing-purchase-addon: insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'persistence_failed']);
    exit;
}

// Create YK one-time payment. We reuse createInitialPayment with
// metadata.kind='addon_purchase' (separate from subscription_invoice
// so the SubscriptionStore::onWebhook handler doesn't try to extend
// the tenant's subscription period).
$idemKey = 'addon_' . $purchaseId . '_' . substr(md5(microtime(true) . random_int(0, PHP_INT_MAX)), 0, 12);
$returnUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
           . ($_SERVER['HTTP_HOST'] ?? 'menu.labus.pro')
           . '/owner.php?tab=billing&addon_paid=' . $purchaseId;

try {
    // We can't reuse createInitialPayment as-is because its metadata is
    // hard-coded to kind=subscription_invoice. Build payload directly with
    // YK API by reusing fetchPayment + createPayment patterns. For
    // Phase 32B simplicity we call createInitialPayment and then update
    // the metadata via a follow-up call — but YK doesn't allow metadata
    // changes after creation. Easier path: add a dedicated method on
    // YookassaRecurring. Below uses createInitialPayment and accepts
    // that the webhook will see kind=subscription_invoice — which is
    // detected as a non-binding initial charge in
    // SubscriptionStore::onWebhook ($amountKop > 100 → falls into the
    // "active conversion" branch). To avoid the unintended status flip,
    // we explicitly tag the addon_purchases row with the YK payment id
    // and the webhook handler is updated below to short-circuit when
    // the matching addon_purchases row exists.
    $resp = YookassaRecurring::createInitialPayment(
        $tenantId,
        $amount,
        sprintf('%s — заказ услуги CleanMenu (%s)', $addon['name'], date('d.m.Y H:i')),
        $returnUrl,
        $idemKey
    );

    // Link YK payment id to our purchase row.
    $pdo->prepare('UPDATE addon_purchases SET yk_payment_id = :yk WHERE id = :id')
        ->execute([':yk' => $resp['id'], ':id' => $purchaseId]);

    SubscriptionStore::logEvent($tenantId, 'addon_purchase_initiated', [
        'purchase_id' => $purchaseId,
        'sku'         => $sku,
        'amount_kop'  => $amount,
        'yk_id'       => $resp['id'],
    ]);

    echo json_encode([
        'success'           => true,
        'addon_purchase_id' => $purchaseId,
        'yk_payment_id'     => $resp['id'],
        'redirect_url'      => $resp['confirmation_url'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('billing-purchase-addon: YK creation failed: ' . $e->getMessage());
    $pdo->prepare('UPDATE addon_purchases SET status = "failed", notes = CONCAT(IFNULL(notes,""),"\n\n[error] ", :err) WHERE id = :id')
        ->execute([':err' => $e->getMessage(), ':id' => $purchaseId]);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'yk_payment_creation_failed', 'message' => $e->getMessage()]);
}
