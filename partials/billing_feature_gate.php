<?php
/**
 * partials/billing_feature_gate.php — guards an admin page behind a plan
 * feature flag (Phase 14.8, 2026-05-03).
 *
 * Usage at the top of an admin page:
 *   $gate_feature = 'kds';
 *   $gate_label   = 'Kitchen Display System';
 *   require __DIR__ . '/../partials/billing_feature_gate.php';
 *
 * If the current tenant's plan doesn't include the feature, this partial
 * renders a styled paywall page and calls exit. Otherwise execution
 * continues normally.
 *
 * Requires session_init.php + auth (caller already includes those).
 */

require_once __DIR__ . '/../lib/Billing/PlanRegistry.php';
require_once __DIR__ . '/../lib/Billing/FeatureGate.php';

$_gate_feature = $gate_feature ?? null;
$_gate_label   = $gate_label   ?? $_gate_feature;
if (!$_gate_feature) return;

if (\Cleanmenu\Billing\FeatureGate::isAllowed($_gate_feature)) {
    return; // pass through
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
    <title>Тариф недоступен · <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/owner-billing.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page">
<?php $GLOBALS['header_css_in_head'] = true; require __DIR__ . '/../header.php'; ?>
<?php require __DIR__ . '/../account-header.php'; ?>
<div class="account-container">
    <section class="account-section">
        <?= \Cleanmenu\Billing\FeatureGate::renderPaywall((string)$_gate_feature, (string)$_gate_label) ?>
    </section>
</div>
</body>
</html>
<?php
exit;
