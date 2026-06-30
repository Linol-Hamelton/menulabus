<?php
/**
 * partials/tier_paywall.php — Phase L103 per-location tariff gate.
 *
 * USAGE at the top of a route file (after session_init + require_auth):
 *   $l103_feature = \Cleanmenu\Billing\Features::CART_BASIC;
 *   $l103_label   = 'Корзина и онлайн-заказы';
 *   require __DIR__ . '/../partials/tier_paywall.php';
 *
 * If the current location's active tariff DOES include the feature, this
 * partial returns silently and execution continues. Otherwise it renders a
 * styled paywall page and calls exit.
 *
 * Reads via Database::hasFeature(featureKey, activeLocationId). In legacy
 * single-DB mode (no control-plane configured), hasFeature returns true →
 * this gate is a no-op for dev / CLI / test environments — same fail-open
 * policy as the existing partials/billing_feature_gate.php.
 *
 * Notes:
 *   - Does NOT replace partials/billing_feature_gate.php. The old gate
 *     stays for legacy PlanRegistry features ('inventory', 'kds', etc.)
 *     until Phase L103.8 deprecation. A page can apply both gates back-to-
 *     back; the more restrictive one wins.
 *   - For API endpoints prefer the 402 hard-stop pattern (see e.g.
 *     api/save-courier.php) instead of this HTML paywall.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Billing/Features.php';
require_once __DIR__ . '/../lib/Billing/TariffRegistry.php';

$_l103_feature = isset($l103_feature) ? (string)$l103_feature : '';
$_l103_label   = isset($l103_label)   ? (string)$l103_label   : $_l103_feature;
if ($_l103_feature === '') {
    return;
}

$_l103_db = Database::getInstance();
$_l103_locId = $_l103_db->activeLocationId();

if ($_l103_db->hasFeature($_l103_feature, $_l103_locId)) {
    return; // pass through
}

// Build paywall context.
$_l103_tariff   = $_l103_db->getActiveTariff($_l103_locId);
$_l103_required = \Cleanmenu\Billing\Features::minTierRank($_l103_feature);
$_l103_currentName = $_l103_tariff['display_name'] ?? 'без подписки';
$_l103_currentRank = isset($_l103_tariff['tier_rank']) ? (int)$_l103_tariff['tier_rank'] : 0;

$_l103_needed = null;
foreach (\Cleanmenu\Billing\TariffRegistry::publicTiers() as $_t) {
    if ((int)($_t['tier_rank'] ?? 0) >= $_l103_required) {
        $_l103_needed = $_t;
        break;
    }
}

$_l103_role    = (string)($_SESSION['user']['role'] ?? '');
$_l103_isOwner = ($_l103_role === 'owner');

$_l103_site    = $GLOBALS['siteName'] ?? 'CleanMenu';
$_l103_appVer  = (string)($_SESSION['app_version'] ?? '1.0.0');

http_response_code(402);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Функция недоступна на тарифе · <?= htmlspecialchars((string)$_l103_site) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_l103_appVer) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_l103_appVer) ?>">
    <link rel="stylesheet" href="/css/owner-billing.css?v=<?= htmlspecialchars($_l103_appVer) ?>">
    <link rel="stylesheet" href="/css/tier-paywall.css?v=<?= htmlspecialchars($_l103_appVer) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($_l103_appVer) ?>">
</head>
<body class="admin-page account-page tier-paywall-page">
<?php $GLOBALS['header_css_in_head'] = true; require __DIR__ . '/../header.php'; ?>
<?php require __DIR__ . '/../account-header.php'; ?>
<main class="tier-paywall">
    <div class="tier-paywall-card">
        <p class="tier-paywall-kicker">
            «<?= htmlspecialchars($_l103_label) ?>» не входит в тариф
            <strong><?= htmlspecialchars((string)$_l103_currentName) ?></strong>
        </p>
        <h1 class="tier-paywall-title">
            <?php if ($_l103_needed): ?>
                Подключите тариф «<?= htmlspecialchars((string)$_l103_needed['display_name']) ?>»
            <?php else: ?>
                Эта функция временно недоступна
            <?php endif; ?>
        </h1>
        <p class="tier-paywall-lead">
            Минимальный уровень: <strong><?= (int)$_l103_required ?></strong>. Текущий: <strong><?= (int)$_l103_currentRank ?></strong>.
        </p>
        <?php if ($_l103_needed): ?>
            <p class="tier-paywall-price">
                <?= htmlspecialchars(\Cleanmenu\Billing\TariffRegistry::priceLabel((string)$_l103_needed['code'])) ?>
            </p>
            <?php if (!empty($_l103_needed['description'])): ?>
                <p class="tier-paywall-desc"><?= htmlspecialchars((string)$_l103_needed['description']) ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($_l103_isOwner): ?>
            <a class="tier-paywall-cta" href="/owner.php?tab=billing">Сменить тариф</a>
        <?php else: ?>
            <p class="tier-paywall-owner-only">
                Доступ к этому разделу зависит от выбранного тарифа.
                Обратитесь к владельцу заведения, чтобы изменить подписку.
            </p>
        <?php endif; ?>
        <a class="tier-paywall-link" href="/account.php">← Вернуться в кабинет</a>
    </div>
</main>
</body>
</html>
<?php
exit;
