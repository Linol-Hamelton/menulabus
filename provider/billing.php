<?php
/**
 * provider/billing.php — list of all tenants with billing status (Phase 14.7).
 *
 * Provider-only admin surface. Aggregates: brand, plan, status, MRR
 * contribution, trial/period end, owner email, last charge result.
 */

require_once __DIR__ . '/../require_provider_admin.php';
require_once __DIR__ . '/../lib/Billing/PlanRegistry.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';

use Cleanmenu\Billing\PlanRegistry;
use Cleanmenu\Billing\SubscriptionStore;

$pdo = SubscriptionStore::pdo();
$tenants = $pdo->query(
    "SELECT id, brand_slug, mode, plan_id, subscription_status,
            trial_ends_at, current_period_end, owner_email, is_active, created_at
     FROM tenants
     ORDER BY id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Compute MRR (sum of active tenants' plan prices).
$mrrKop = 0;
$counts = ['trial' => 0, 'active' => 0, 'past_due' => 0, 'suspended' => 0, 'cancelled' => 0];
foreach ($tenants as $t) {
    $counts[$t['subscription_status']] = ($counts[$t['subscription_status']] ?? 0) + 1;
    if ($t['subscription_status'] === 'active') {
        $mrrKop += PlanRegistry::priceKop((string)$t['plan_id']);
    }
}

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
    <title>Provider · Billing · <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/owner-billing.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/provider-billing.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="provider-page">
<?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/../header.php'; ?>

<div class="account-container provider-billing">
    <section class="account-section">
        <div class="section-header-menu">
            <h2>Provider Billing</h2>
            <a href="/owner.php" class="back-to-menu-btn">К /owner.php</a>
        </div>

        <div class="provider-billing-stats">
            <div class="provider-billing-stat">
                <div class="provider-billing-stat-num"><?= number_format($mrrKop / 100, 0, '.', ' ') ?> ₽</div>
                <div class="provider-billing-stat-label">MRR (active)</div>
            </div>
            <div class="provider-billing-stat">
                <div class="provider-billing-stat-num"><?= count($tenants) ?></div>
                <div class="provider-billing-stat-label">Всего тенантов</div>
            </div>
            <div class="provider-billing-stat">
                <div class="provider-billing-stat-num"><?= (int)$counts['active'] ?></div>
                <div class="provider-billing-stat-label">Активные</div>
            </div>
            <div class="provider-billing-stat">
                <div class="provider-billing-stat-num"><?= (int)$counts['trial'] ?></div>
                <div class="provider-billing-stat-label">Trial</div>
            </div>
            <div class="provider-billing-stat">
                <div class="provider-billing-stat-num"><?= (int)$counts['past_due'] ?></div>
                <div class="provider-billing-stat-label">Past due</div>
            </div>
            <div class="provider-billing-stat">
                <div class="provider-billing-stat-num"><?= (int)$counts['suspended'] ?></div>
                <div class="provider-billing-stat-label">Suspended</div>
            </div>
        </div>
    </section>

    <section class="account-section">
        <h3>Тенанты</h3>
        <table class="provider-tenants-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Бренд / домен</th>
                    <th>Mode</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Trial / Period end</th>
                    <th>Owner</th>
                    <th>MRR</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $t):
                    $kop = $t['subscription_status'] === 'active' ? PlanRegistry::priceKop((string)$t['plan_id']) : 0;
                    $endLabel = '';
                    if ($t['subscription_status'] === 'trial' && $t['trial_ends_at']) {
                        $endLabel = 'до ' . date('d.m.Y', strtotime((string)$t['trial_ends_at'])) . ' (trial)';
                    } elseif ($t['current_period_end']) {
                        $endLabel = 'до ' . date('d.m.Y', strtotime((string)$t['current_period_end']));
                    }
                ?>
                    <tr>
                        <td>#<?= (int)$t['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars((string)$t['brand_slug']) ?></strong><br>
                            <small><?= htmlspecialchars((string)$t['brand_slug']) ?>.menu.labus.pro</small>
                        </td>
                        <td><?= htmlspecialchars((string)$t['mode']) ?></td>
                        <td><?= htmlspecialchars((string)$t['plan_id']) ?></td>
                        <td>
                            <span class="billing-status-badge billing-status-<?= htmlspecialchars((string)$t['subscription_status']) ?>">
                                <?= htmlspecialchars((string)$t['subscription_status']) ?>
                            </span>
                        </td>
                        <td><small><?= htmlspecialchars($endLabel) ?></small></td>
                        <td><small><?= htmlspecialchars((string)($t['owner_email'] ?? '—')) ?></small></td>
                        <td><?= $kop > 0 ? number_format($kop / 100, 0, '.', ' ') . ' ₽' : '—' ?></td>
                        <td><a href="/provider/tenant.php?id=<?= (int)$t['id'] ?>" class="admin-checkout-btn">Открыть</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
