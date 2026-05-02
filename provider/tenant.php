<?php
/**
 * provider/tenant.php?id=N — drill-down for one tenant (Phase 14.7).
 *
 * Provider-only. Shows: tenant metadata, current billing state, all
 * payment methods, full invoice history, audit log of subscription_events.
 * Action buttons: extend trial, force-status (active/past_due/suspended),
 * manual charge of pending invoice, comp credit (write a paid invoice
 * with amount=0 + reason).
 */

require_once __DIR__ . '/../require_provider_admin.php';
require_once __DIR__ . '/../lib/Billing/PlanRegistry.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';

use Cleanmenu\Billing\PlanRegistry;
use Cleanmenu\Billing\SubscriptionStore;

$tenantId = (int)($_GET['id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(400);
    echo 'Bad tenant id';
    exit;
}

$tenant = SubscriptionStore::getTenantBilling($tenantId);
if (!$tenant) {
    http_response_code(404);
    echo 'Tenant not found';
    exit;
}

$pdo = SubscriptionStore::pdo();

$paymentMethods = $pdo->prepare(
    'SELECT id, provider, yk_payment_method_id, last4, brand,
            expires_month, expires_year, is_default, created_at
     FROM payment_methods WHERE tenant_id = :id ORDER BY id DESC'
);
$paymentMethods->execute([':id' => $tenantId]);
$paymentMethods = $paymentMethods->fetchAll(PDO::FETCH_ASSOC);

$invoices = SubscriptionStore::getInvoicesByTenant($tenantId, 100);

$events = $pdo->prepare(
    'SELECT id, event_type, payload, created_at FROM subscription_events
     WHERE tenant_id = :id ORDER BY id DESC LIMIT 50'
);
$events->execute([':id' => $tenantId]);
$events = $events->fetchAll(PDO::FETCH_ASSOC);

$plan = PlanRegistry::byId((string)$tenant['plan_id']);

$siteName    = $GLOBALS['siteName'] ?? 'CleanMenu';
$appVersion  = (string)($_SESSION['app_version'] ?? '1.0.0');
$scriptNonce = $GLOBALS['scriptNonce'] ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Tenant #<?= (int)$tenantId ?> · Provider · <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/owner-billing.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/provider-billing.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="provider-page" data-tenant-id="<?= (int)$tenantId ?>">
<?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/../header.php'; ?>

<div class="account-container provider-tenant" data-tenant-id="<?= (int)$tenantId ?>">
    <section class="account-section">
        <div class="section-header-menu">
            <h2>#<?= (int)$tenantId ?> · <?= htmlspecialchars((string)$tenant['brand_slug']) ?></h2>
            <a href="/provider/billing.php" class="back-to-menu-btn">← К списку</a>
        </div>
        <p>
            <strong>Plan:</strong> <?= htmlspecialchars((string)$tenant['plan_id']) ?>
            (<?= htmlspecialchars(PlanRegistry::priceLabel((string)$tenant['plan_id'])) ?>) ·
            <strong>Status:</strong>
            <span class="billing-status-badge billing-status-<?= htmlspecialchars((string)$tenant['subscription_status']) ?>">
                <?= htmlspecialchars((string)$tenant['subscription_status']) ?>
            </span> ·
            <strong>Owner:</strong> <?= htmlspecialchars((string)($tenant['owner_email'] ?? '—')) ?>
        </p>
        <p>
            <small>
                Trial ends: <?= htmlspecialchars((string)($tenant['trial_ends_at'] ?? '—')) ?> ·
                Period end: <?= htmlspecialchars((string)($tenant['current_period_end'] ?? '—')) ?>
            </small>
        </p>
    </section>

    <section class="account-section">
        <h3>Действия</h3>
        <div class="provider-actions-grid">
            <button type="button" class="admin-checkout-btn" data-prov-action="extend_trial">+7 дней trial</button>
            <button type="button" class="admin-checkout-btn" data-prov-action="force_active">Force active</button>
            <button type="button" class="admin-checkout-btn cancel" data-prov-action="force_past_due">Force past_due</button>
            <button type="button" class="admin-checkout-btn cancel" data-prov-action="force_suspended">Suspend</button>
            <button type="button" class="admin-checkout-btn" data-prov-action="comp">Comp / зачислить</button>
        </div>
        <span class="billing-action-feedback" id="provFeedback" hidden></span>
    </section>

    <section class="account-section">
        <h3>Способы оплаты</h3>
        <?php if (empty($paymentMethods)): ?>
            <p class="billing-empty">Карта не привязана.</p>
        <?php else: ?>
            <table class="provider-tenants-table">
                <thead><tr><th>#</th><th>Provider</th><th>Card</th><th>Default</th><th>Создан</th></tr></thead>
                <tbody>
                    <?php foreach ($paymentMethods as $pm): ?>
                        <tr>
                            <td>#<?= (int)$pm['id'] ?></td>
                            <td><?= htmlspecialchars((string)$pm['provider']) ?></td>
                            <td>
                                <?= htmlspecialchars(strtoupper((string)($pm['brand'] ?? '?'))) ?>
                                •••• <?= htmlspecialchars((string)($pm['last4'] ?? '????')) ?>
                                <?php if ($pm['expires_month']): ?>
                                    <small>· до <?= sprintf('%02d/%02d', (int)$pm['expires_month'], (int)$pm['expires_year'] % 100) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= $pm['is_default'] ? '✓' : '' ?></td>
                            <td><small><?= htmlspecialchars((string)$pm['created_at']) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="account-section">
        <h3>Списания</h3>
        <?php if (empty($invoices)): ?>
            <p class="billing-empty">Списаний ещё не было.</p>
        <?php else: ?>
            <table class="provider-tenants-table">
                <thead><tr><th>#</th><th>Period</th><th>Plan</th><th>Amount</th><th>Status</th><th>YK ID</th><th>Created</th></tr></thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td>#<?= (int)$inv['id'] ?></td>
                            <td><small><?= htmlspecialchars((string)$inv['period_start']) ?> — <?= htmlspecialchars((string)$inv['period_end']) ?></small></td>
                            <td><?= htmlspecialchars((string)$inv['plan_id']) ?></td>
                            <td><?= number_format((int)$inv['amount_kop'] / 100, 0, '.', ' ') ?> ₽</td>
                            <td>
                                <span class="billing-invoice-status billing-invoice-<?= htmlspecialchars((string)$inv['status']) ?>">
                                    <?= htmlspecialchars((string)$inv['status']) ?>
                                </span>
                                <?php if ((int)$inv['retry_count'] > 0): ?>
                                    <small>(попыток: <?= (int)$inv['retry_count'] ?>)</small>
                                <?php endif; ?>
                            </td>
                            <td><small><?= htmlspecialchars((string)($inv['yk_payment_id'] ?? '—')) ?></small></td>
                            <td><small><?= htmlspecialchars((string)$inv['created_at']) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="account-section">
        <h3>Audit log</h3>
        <?php if (empty($events)): ?>
            <p class="billing-empty">Событий нет.</p>
        <?php else: ?>
            <table class="provider-tenants-table">
                <thead><tr><th>#</th><th>Type</th><th>Payload</th><th>Created</th></tr></thead>
                <tbody>
                    <?php foreach ($events as $ev): ?>
                        <tr>
                            <td>#<?= (int)$ev['id'] ?></td>
                            <td><?= htmlspecialchars((string)$ev['event_type']) ?></td>
                            <td><small><code><?= htmlspecialchars((string)($ev['payload'] ?? '')) ?></code></small></td>
                            <td><small><?= htmlspecialchars((string)$ev['created_at']) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
<script src="/js/provider-tenant.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
