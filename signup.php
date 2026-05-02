<?php
/**
 * signup.php — self-service tenant signup (Phase 14.6, 2026-05-03).
 *
 * Public, no auth. Creates a trial tenant in 14 days. After successful
 * provisioning, redirects to the new tenant's domain with auto-login.
 *
 * Available only on provider mode (menu.labus.pro itself); 404 on
 * tenant subdomains to avoid confusion.
 */

require_once __DIR__ . '/session_init.php';

if (empty($GLOBALS['isProviderMode'])) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once __DIR__ . '/lib/Billing/PlanRegistry.php';
use Cleanmenu\Billing\PlanRegistry;

$siteName   = $GLOBALS['siteName'] ?? 'CleanMenu';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
$scriptNonce = $GLOBALS['scriptNonce'] ?? '';
$styleNonce  = $GLOBALS['styleNonce']  ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Создать новый ресторан · <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/signup.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="signup-page">
<?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>

<div class="signup-container">
    <header class="signup-hero">
        <p class="signup-kicker">SaaS для ресторанов</p>
        <h1>Запустите своё меню за 5 минут</h1>
        <p class="signup-lead">
            14 дней бесплатно. Без карты. Полный доступ ко всем функциям —
            меню, заказы, KDS, лояльность, аналитика, фискальные чеки.
        </p>
    </header>

    <section class="signup-plans-row">
        <?php foreach (PlanRegistry::selfServiceIds() as $pid):
            $plan = PlanRegistry::byId($pid);
            if (!$plan) continue;
        ?>
            <div class="signup-plan-card<?= $pid === 'trial' ? ' is-default' : '' ?>" data-plan-id="<?= htmlspecialchars($pid) ?>">
                <h3><?= htmlspecialchars($plan['name']) ?></h3>
                <p class="signup-plan-price"><?= htmlspecialchars(PlanRegistry::priceLabel($pid)) ?>
                    <?php if ((int)$plan['price_kop'] > 0): ?><small> / мес</small><?php endif; ?>
                </p>
                <p class="signup-plan-desc"><?= htmlspecialchars($plan['description']) ?></p>
            </div>
        <?php endforeach; ?>
    </section>

    <form id="signupForm" class="signup-form" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
        <input type="hidden" name="plan_id" id="signupPlanId" value="trial">

        <div class="signup-grid">
            <label class="signup-field">
                <span>Название ресторана</span>
                <input type="text" name="brand_name" required maxlength="80" placeholder="Например, Кофейня «Утро»">
            </label>
            <label class="signup-field">
                <span>Адрес сайта</span>
                <span class="signup-slug-row">
                    <input type="text" name="brand_slug" required pattern="[a-z0-9-]{3,32}" maxlength="32" placeholder="utro" id="signupSlug">
                    <span class="signup-slug-suffix">.menu.labus.pro</span>
                </span>
            </label>
            <label class="signup-field">
                <span>Email владельца</span>
                <input type="email" name="owner_email" required maxlength="255" placeholder="you@example.com">
            </label>
            <label class="signup-field">
                <span>Пароль</span>
                <input type="password" name="owner_password" required minlength="8" maxlength="80" placeholder="минимум 8 символов">
            </label>
        </div>

        <p class="signup-terms">
            Создавая аккаунт, вы соглашаетесь с условиями использования и политикой конфиденциальности.
            Через 14 дней trial автоматически переходит в режим past_due, пока вы не выберете тариф и не введёте карту.
        </p>

        <button type="submit" class="checkout-btn signup-submit">Создать ресторан</button>
        <span class="signup-feedback" id="signupFeedback" hidden></span>
    </form>
</div>

<script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
<script src="/js/signup.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
