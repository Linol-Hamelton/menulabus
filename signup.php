<?php
/**
 * signup.php — self-service tenant signup (Phase 32, 2026-05-08).
 *
 * Public, no auth. Creates a Pro-trial tenant for 90 days (Phase 32 v3
 * pricing model). After successful provisioning, redirects to the new
 * tenant's domain with auto-login.
 *
 * Card collection (full Phase 32B): card pre-auth via YooKassa SaveMethod
 * required at signup; not charged until day 91 unless user explicitly
 * upgrades earlier. The card-collection flow lives in /js/signup.js
 * (Phase 32B). For Phase 32A this page only updates the messaging and
 * tier picker; backend signup endpoint still creates trial without card
 * (existing behaviour) — to be tightened in 32B.
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
        <h1>90 дней Pro бесплатно — запустите ресторан за 5 минут</h1>
        <p class="signup-lead">
            Полный доступ ко всему: меню, заказы, KDS, склад, лояльность, маркетинг,
            54-ФЗ, group split-bill, white-label на вашем домене (Enterprise).
            Карта при регистрации — без списания до 91-го дня, отмена в любой момент.
        </p>
    </header>

    <section class="signup-plans-row signup-plans-row--single">
        <?php
        $trialPlan = PlanRegistry::byId('trial');
        if ($trialPlan):
            $trialDays = PlanRegistry::trialDays('trial');
        ?>
            <div class="signup-plan-card is-default is-featured" data-plan-id="trial">
                <span class="signup-plan-badge">Старт здесь</span>
                <h3><?= htmlspecialchars($trialPlan['name']) ?></h3>
                <p class="signup-plan-price">
                    Бесплатно <small>· <?= (int)$trialDays ?> дней</small>
                </p>
                <p class="signup-plan-desc"><?= htmlspecialchars($trialPlan['description']) ?></p>
                <ul class="signup-plan-features">
                    <li>✓ KDS, склад, рецепты, лояльность</li>
                    <li>✓ Маркетинг (email/SMS/Telegram)</li>
                    <li>✓ 54-ФЗ через АТОЛ Онлайн</li>
                    <li>✓ Group orders, split-bill, бронирования</li>
                    <li>✓ До 3 локаций, до 30 сотрудников</li>
                </ul>
                <p class="signup-plan-after-trial">
                    После trial — <strong>Pro 6 990 ₽/мес</strong>
                    или <strong>69 900 ₽/год</strong> (2 мес бесплатно).
                </p>
            </div>
        <?php endif; ?>
    </section>

    <section class="signup-plans-also">
        <h3>Нужно больше — Enterprise или сетевой план</h3>
        <div class="signup-plans-also-row">
            <div class="signup-plan-card-mini">
                <h4>Enterprise · 19 990 ₽/мес</h4>
                <p>До 10 локаций, white-label на своём домене, dev API, priority support, SLA.
                    <strong>В цену включён Express onboarding (3 ч) + 4 ч обучения персонала</strong> (~24 900 ₽ value).</p>
            </div>
            <div class="signup-plan-card-mini">
                <h4>Enterprise+ / Сеть · договорная</h4>
                <p>Сети 10+ локаций, SSO/SAML, dedicated DB, кастомная SLA, выделенный success-manager.
                    <a href="mailto:sales@labus.pro">sales@labus.pro</a></p>
            </div>
        </div>
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
            <strong>90 дней Pro бесплатно</strong>. На <strong>91-й день</strong> trial автоматически переходит в Pro
            (6 990 ₽/мес) — карта на этом шаге понадобится. Отмена в любой момент в личном кабинете.
        </p>

        <button type="submit" class="checkout-btn signup-submit">Запустить ресторан · 90 дней Pro бесплатно</button>
        <span class="signup-feedback" id="signupFeedback" hidden></span>
    </form>
</div>

<script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
<script src="/js/signup.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
