<?php
header('Cache-Control: public, max-age=600, s-maxage=600');
require_once __DIR__ . '/session_init.php';

$tenantContext = tenant_runtime_require_resolved();
$isProvider = !empty($tenantContext['is_provider']);
$isTenant = !$isProvider;
$isLoggedIn = !empty($_SESSION['user_id']);
$publicEntryMode = cleanmenu_normalize_tenant_public_entry_mode(
    (string)($GLOBALS['publicEntryMode'] ?? ''),
    $isProvider
);

if ($isTenant && $publicEntryMode === 'menu') {
    header('Location: /menu.php', true, 302);
    exit;
}

$rawSiteName = trim((string) html_entity_decode($GLOBALS['siteName'] ?? 'labus', ENT_QUOTES, 'UTF-8'), "\"'");
$rawSiteDesc = trim((string) html_entity_decode($GLOBALS['siteDesc'] ?? '', ENT_QUOTES, 'UTF-8'), "\"'");
$rawTagline = trim((string) html_entity_decode($GLOBALS['siteTagline'] ?? '', ENT_QUOTES, 'UTF-8'), "\"'");

if ($isProvider && $rawTagline === '') {
    $rawTagline = 'Цифровое меню и управление заказами';
}

$siteName = htmlspecialchars($rawSiteName !== '' ? $rawSiteName : 'labus', ENT_QUOTES, 'UTF-8');
$siteDesc = htmlspecialchars($rawSiteDesc, ENT_QUOTES, 'UTF-8');
$tagline = htmlspecialchars($rawTagline, ENT_QUOTES, 'UTF-8');
$tenantLeadText = $tagline !== '' ? $tagline : $siteDesc;

$faviconUrl = htmlspecialchars((string)($GLOBALS['faviconUrl'] ?? '/icons/favicon.ico'), ENT_QUOTES, 'UTF-8');
$appVer = htmlspecialchars($_SESSION['app_version'] ?? '1.0.0', ENT_QUOTES, 'UTF-8');
$contactPhone = trim((string)($GLOBALS['contactPhone'] ?? ''));
$contactAddress = trim((string)($GLOBALS['contactAddress'] ?? ''));
$contactMapUrl = trim((string)($GLOBALS['contactMapUrl'] ?? ''));
$socialTg = trim((string)($GLOBALS['socialTg'] ?? ''));
$socialVk = trim((string)($GLOBALS['socialVk'] ?? ''));
$footerContactLines = array_values(array_filter([$contactAddress, $contactPhone], static fn($value) => $value !== ''));
$hasTenantContacts = $contactPhone !== '' || $contactAddress !== '' || $contactMapUrl !== '' || $socialTg !== '' || $socialVk !== '';
$hasTenantAbout = $rawSiteDesc !== '' || $rawTagline !== '';
$tenantMetaTitle = $isTenant && $tenantLeadText !== ''
    ? $siteName . ' | ' . $tenantLeadText
    : $siteName . ($siteDesc !== '' ? ' | ' . $siteDesc : ($tagline !== '' ? ' | ' . $tagline : ''));
$tenantQuickPoints = array_values(array_filter([
    $contactAddress !== '' ? $contactAddress : null,
    $contactPhone !== '' ? $contactPhone : null,
    $contactMapUrl !== '' ? 'Карта' : null,
    $socialTg !== '' ? 'Telegram' : null,
    $socialVk !== '' ? 'VK' : null,
]));
$tenantSecondaryHref = $hasTenantContacts
    ? '#contact'
    : ($isLoggedIn ? '/account.php' : '/auth.php');
$tenantSecondaryLabel = $hasTenantContacts
    ? 'Контакты'
    : ($isLoggedIn ? 'Аккаунт' : 'Войти');
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <link rel="manifest" href="/manifest.php?v=<?= $appVer ?>">
    <link rel="icon" href="<?= $faviconUrl ?>">
    <meta name="theme-color" content="#000000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= $siteName ?>">
    <?php if (($isProvider && $siteDesc !== '') || ($isTenant && $tenantLeadText !== '')): ?>
        <meta name="description" content="<?= $isTenant ? $tenantLeadText : $siteDesc ?>">
    <?php endif; ?>
    <link rel="apple-touch-icon" href="/icons/icon-192x192.png">
    <meta name="msapplication-TileImage" content="/icons/icon-128x128.png">
    <meta name="msapplication-TileColor" content="#000000">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= $appVer ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= $appVer ?>">
    <link rel="stylesheet" href="/css/index-landing.css?v=<?= $appVer ?>">
    <link rel="stylesheet" href="/css/index-hero.css?v=<?= $appVer ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= $appVer ?>">
    <title><?= $tenantMetaTitle ?></title>
</head>

<body class="index-landing-page<?= $isTenant ? ' tenant-homepage' : '' ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>

    <?php if ($isProvider): ?>
        <section class="hero">
            <div class="hero__bg">
                <picture>
                    <source
                        srcset="/images/HDR_320.webp 320w, /images/HDR_640.webp 640w"
                        media="(max-width: 768px)"
                        type="image/webp">
                    <img
                        src="/images/HDR_640.webp"
                        srcset="/images/HDR_640.webp 640w"
                        alt="Меню от <?= $siteName ?>"
                        loading="eager"
                        decoding="async">
                </picture>
            </div>
            <div class="hero-content">
                <h1><?= $siteName ?></h1>
                <?php if ($tagline !== ''): ?>
                    <p><?= $tagline ?></p>
                <?php endif; ?>
                <div class="hero-actions">
                    <a href="/menu.php" class="btn hero-btn-primary">Открыть меню</a>
                    <a href="#reservation" class="btn hero-btn-secondary">Оставить заявку</a>
                </div>
            </div>
        </section>

        <section class="landing-entry-strip" aria-label="Основные сценарии">
            <div class="container">
                <div class="landing-entry-grid">
                    <article class="landing-entry-card">
                        <p class="landing-entry-kicker">Demo</p>
                        <h2>Показать каталог и сразу перейти к заказу</h2>
                        <p>Провайдерский домен остаётся витриной продукта и демонстрацией того, как выглядит меню на боевом проекте.</p>
                        <a href="/menu.php" class="landing-entry-link">Перейти в меню</a>
                    </article>
                    <article class="landing-entry-card landing-entry-card-muted">
                        <p class="landing-entry-kicker">Подключение</p>
                        <h2>Запустить ресторан · 90 дней Pro бесплатно</h2>
                        <p>Полная Pro-функциональность на 90 дней без ограничений. Карта при регистрации — без списания до 91-го дня.</p>
                        <a href="/signup.php" class="landing-entry-link">Зарегистрироваться</a>
                    </article>
                </div>
            </div>
        </section>

        <section id="pricing" class="section pricing-section" aria-label="Тарифы и услуги">
            <div class="container">
                <div class="section-header">
                    <h2>Тарифы</h2>
                    <p>6 уровней — от каталога до полного управления заведением. Цена за одну торговую точку.</p>
                </div>

                <?php
                // Phase L103.7 — data-driven cards from control-plane.tariffs.
                // Falls back to legacy 3-card grid if control plane is unconfigured
                // (local dev) or seed migration hasn't run yet.
                require_once __DIR__ . '/lib/Billing/TariffRegistry.php';
                $l103PublicTiers = \Cleanmenu\Billing\TariffRegistry::publicTiers();
                $l103ChainTier   = \Cleanmenu\Billing\TariffRegistry::byCode('chain');
                $l103UseDB       = count($l103PublicTiers) >= 6;
                ?>

                <?php if ($l103UseDB): ?>
                <div class="pricing-grid pricing-grid--six-tiers">
                    <?php foreach ($l103PublicTiers as $l103Tier): ?>
                        <?php
                        $rank = (int)($l103Tier['tier_rank'] ?? 0);
                        $isFeatured = ($rank === 3);  // «Доставка+» — most-likely sweet spot, highlight
                        $price = htmlspecialchars(\Cleanmenu\Billing\TariffRegistry::priceLabel((string)$l103Tier['code']));
                        ?>
                        <article class="pricing-card<?= $isFeatured ? ' pricing-card--featured' : '' ?>">
                            <?php if ($isFeatured): ?>
                                <p class="pricing-card-kicker">Старт работы с клиентами</p>
                            <?php endif; ?>
                            <h3><?= htmlspecialchars($l103Tier['display_name']) ?></h3>
                            <p class="pricing-card-price">
                                <span class="pricing-card-amount"><?= $price ?></span>
                            </p>
                            <?php if (!empty($l103Tier['description'])): ?>
                                <p class="pricing-card-desc"><?= htmlspecialchars($l103Tier['description']) ?></p>
                            <?php endif; ?>
                            <a href="/signup.php?tariff=<?= htmlspecialchars((string)$l103Tier['code']) ?>" class="btn <?= $isFeatured ? 'hero-btn-primary' : 'hero-btn-secondary' ?>">Выбрать</a>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($l103ChainTier): ?>
                    <article class="pricing-card pricing-card--sales pricing-card--chain">
                        <h3><?= htmlspecialchars($l103ChainTier['display_name']) ?></h3>
                        <p class="pricing-card-price">
                            <span class="pricing-card-amount">Договорная</span>
                        </p>
                        <?php if (!empty($l103ChainTier['description'])): ?>
                            <p class="pricing-card-desc"><?= htmlspecialchars($l103ChainTier['description']) ?></p>
                        <?php endif; ?>
                        <a href="mailto:sales@labus.pro?subject=%D0%A1%D0%B5%D1%82%D1%8C%2B%20inquiry" class="btn hero-btn-secondary">Связаться</a>
                    </article>
                <?php endif; ?>

                <?php else: ?>
                <!-- Legacy fallback: 3-card grid when control plane is unconfigured. -->
                <div class="pricing-grid">
                    <article class="pricing-card pricing-card--featured">
                        <p class="pricing-card-kicker">Самый популярный</p>
                        <h3>Pro</h3>
                        <p class="pricing-card-price">
                            <span class="pricing-card-amount">6 990 ₽</span>
                            <span class="pricing-card-period">/ месяц</span>
                        </p>
                        <p class="pricing-card-annual">или 69 900 ₽ в год — 2 месяца бесплатно</p>
                        <ul class="pricing-card-features">
                            <li>До 3 локаций · до 30 сотрудников</li>
                            <li>KDS, склад с рецептами, лояльность</li>
                            <li>Маркетинг (email/SMS/Telegram), сегменты</li>
                            <li>54-ФЗ через АТОЛ Онлайн</li>
                            <li>Group orders · split-bill · бронирования</li>
                            <li>Outgoing webhooks для интеграций</li>
                            <li>Multi-language (ru/en/kk)</li>
                        </ul>
                        <a href="/signup.php" class="btn hero-btn-primary">90 дней Pro бесплатно</a>
                    </article>

                    <article class="pricing-card">
                        <h3>Enterprise</h3>
                        <p class="pricing-card-price">
                            <span class="pricing-card-amount">19 990 ₽</span>
                            <span class="pricing-card-period">/ месяц</span>
                        </p>
                        <p class="pricing-card-annual">или 199 900 ₽ в год — 2 месяца бесплатно</p>
                        <ul class="pricing-card-features">
                            <li>До 10 локаций · без лимитов сотрудников</li>
                            <li><strong>White-label на своём домене</strong></li>
                            <li>Public API + dev-инструменты</li>
                            <li>Priority support · SLA</li>
                            <li>Всё из Pro</li>
                            <li><strong>Включено:</strong> Express onboarding (3 ч) + 4 ч обучения персонала (~24 900 ₽ value)</li>
                        </ul>
                        <a href="/signup.php" class="btn hero-btn-secondary">Начать с 90 дней trial</a>
                    </article>

                    <article class="pricing-card pricing-card--sales">
                        <h3>Enterprise+ / Сеть</h3>
                        <p class="pricing-card-price">
                            <span class="pricing-card-amount">Договорная</span>
                        </p>
                        <p class="pricing-card-annual">от 35 000 ₽/мес для сетей</p>
                        <ul class="pricing-card-features">
                            <li>Сети 10+ локаций</li>
                            <li>SSO / SAML</li>
                            <li>Dedicated DB · кастомная SLA</li>
                            <li>Выделенный success-manager</li>
                            <li>Всё из Enterprise</li>
                            <li><strong>Включено:</strong> Full onboarding + 4 ч обучения + priority support</li>
                        </ul>
                        <a href="mailto:sales@labus.pro?subject=Enterprise%2B%20%2F%20Сеть%20inquiry" class="btn hero-btn-secondary">Связаться</a>
                    </article>
                </div>
                <?php endif; ?>

                <div class="pricing-addons">
                    <h3>Дополнительные услуги</h3>
                    <p class="pricing-addons-hint">Разовая помощь от команды CleanMenu. Цены фиксированные — никаких скрытых сборов или партнёрских комиссий.</p>
                    <ul class="pricing-addons-list">
                        <li><strong>Express onboarding</strong> · 9 900 ₽ — 3 часа: настройка, бренд, импорт меню, QR на 1 локацию</li>
                        <li><strong>Full onboarding</strong> · 24 900 ₽ — 8 часов: всё из Express + категории + рецепты + KDS + 54-ФЗ + Telegram-бот</li>
                        <li><strong>Миграция с iiko/R-Keeper/Quick Resto</strong> · 49 900 ₽ — 12+ часов: экспорт, mapping, parallel-run сопровождение</li>
                        <li><strong>Group training</strong> · 6 900 ₽ — 2 часа онлайн, до 5 человек: приём заказов, KDS, отчётность</li>
                        <li><strong>Individual training</strong> · 4 900 ₽ — 1 час 1-on-1: настройки, аналитика, маркетинг</li>
                        <li><strong>Telegram-bot setup</strong> · 4 900 ₽ — создание бота, webhook, inline-кнопки</li>
                        <li><strong>Custom domain config</strong> · 2 900 ₽ — DNS + Let's Encrypt + nginx (Pro доплата; в Enterprise included)</li>
                        <li><strong>Recorded video course</strong> · 2 900 ₽ — lifetime access</li>
                    </ul>
                </div>

                <p class="pricing-bottom-note">
                    Все цены в рублях, без скрытых комиссий. Месячная оплата = гибкость (отмена в любой момент).
                    Годовая оплата = lock-in цены 2026 на 12 месяцев + 2 месяца бесплатно. Платежи через YooKassa.
                </p>
            </div>
        </section>

        <section id="about" class="section about">
            <div class="container">
                <div class="section-header">
                    <h2>О сервисе</h2>
                    <p>Электронное меню</p>
                </div>
                <div class="about-content">
                    <div class="about-text">
                        <p>Электронное меню от Labus превращает каталог блюд в удобный цифровой слой для гостя и команды ресторана. Заказ собирается быстрее, а навигация по меню остаётся понятной и на телефоне, и за столом.</p>
                        <p>Продукт закрывает публичное меню, заказ, QR-сценарии и внутренние рабочие поверхности: владельца, администратора и команды зала. Это не просто лендинг, а рабочий operational shell для заведения.</p>
                        <p>Провайдерский домен остаётся местом, где показывается продуктовый контур и сценарий подключения. Клиентские домены при этом получают отдельный white-label слой без провайдерской витрины.</p>
                    </div>
                    <div class="about-image">
                        <picture>
                            <source
                                srcset="/images/HDR1_320.webp 320w, /images/HDR1_640.webp 640w"
                                media="(max-width: 768px)"
                                type="image/webp">
                            <source
                                srcset="/images/HDR1_1024.webp 1024w"
                                media="(max-width: 1280px)"
                                type="image/webp">
                            <img
                                src="/images/HDR1_1024.webp"
                                srcset="/images/HDR1_1024.webp 1440w"
                                loading="lazy"
                                decoding="async"
                                alt="Меню от <?= $siteName ?>"
                                sizes="(max-width: 768px) 100vw, (max-width: 1280px) 100vw, 1440px">
                        </picture>
                    </div>
                </div>
            </div>
        </section>

        <section id="reservation" class="reservation-inner">
            <div class="container">
                <div class="form-content">
                    <div class="reservation-form">
                        <h2>Консультация</h2>
                        <form id="reservationForm">
                            <div class="form-group">
                                <input type="text" name="name" placeholder="Ваше имя" required>
                            </div>
                            <div class="form-group">
                                <input type="tel" name="phone" placeholder="Телефон" required>
                            </div>
                            <div class="form-group">
                                <input type="date" name="date" placeholder="Дата" required>
                            </div>
                            <div class="form-group">
                                <input type="time" name="time" placeholder="Время" required>
                            </div>
                            <div class="form-group">
                                <input type="number" name="guests" placeholder="Количество гостей" min="1" required>
                            </div>
                            <button type="submit" class="btn-form">Записаться</button>
                        </form>
                        <div id="formMessage"></div>
                    </div>
                </div>
            </div>
        </section>
    <?php else: ?>
        <section class="hero">
            <div class="hero__bg">
                <picture>
                    <source
                        srcset="/images/HDR_320.webp 320w, /images/HDR_640.webp 640w"
                        media="(max-width: 768px)"
                        type="image/webp">
                    <img
                        src="/images/HDR_640.webp"
                        srcset="/images/HDR_640.webp 640w"
                        alt="<?= $siteName ?>"
                        loading="eager"
                        decoding="async">
                </picture>
            </div>
            <div class="hero-content">
                <h1><?= $siteName ?></h1>
                <?php if ($tenantLeadText !== ''): ?>
                    <p><?= $tenantLeadText ?></p>
                <?php endif; ?>
                <div class="hero-actions">
                    <a href="/menu.php" class="btn hero-btn-primary">Открыть меню</a>
                    <a href="<?= htmlspecialchars($tenantSecondaryHref, ENT_QUOTES, 'UTF-8') ?>" class="btn hero-btn-secondary"><?= htmlspecialchars($tenantSecondaryLabel, ENT_QUOTES, 'UTF-8') ?></a>
                </div>
                <?php if ($tenantQuickPoints !== []): ?>
                    <div class="hero-quick-points" aria-label="Контакты и ориентиры">
                        <?php foreach ($tenantQuickPoints as $point): ?>
                            <span><?= htmlspecialchars($point, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="landing-entry-strip" aria-label="Основные сценарии">
            <div class="container">
                <div class="landing-entry-grid">
                    <article class="landing-entry-card">
                        <p class="landing-entry-kicker">Меню</p>
                        <h2>Открыть меню и сразу перейти к заказу</h2>
                        <p>Каталог блюд остаётся основной transactional поверхностью: категории, карточки блюд, корзина и оформление заказа.</p>
                        <a href="/menu.php" class="landing-entry-link">Перейти в меню</a>
                    </article>
                    <article class="landing-entry-card<?= $hasTenantContacts ? ' landing-entry-card-muted' : '' ?>">
                        <p class="landing-entry-kicker"><?= $hasTenantContacts ? 'Контакты' : 'Аккаунт' ?></p>
                        <h2><?= $hasTenantContacts ? 'Проверить адрес, телефон и каналы связи' : 'Войти в аккаунт и открыть историю заказов' ?></h2>
                        <p>
                            <?php if ($hasTenantContacts): ?>
                                Все публичные контакты и быстрые ссылки собраны на отдельном блоке без провайдерских CTA.
                            <?php else: ?>
                                Если вы уже делали заказ, история и аккаунт остаются на отдельной странице без лишнего маркетингового слоя.
                            <?php endif; ?>
                        </p>
                        <a href="<?= htmlspecialchars($tenantSecondaryHref, ENT_QUOTES, 'UTF-8') ?>" class="landing-entry-link"><?= htmlspecialchars($tenantSecondaryLabel, ENT_QUOTES, 'UTF-8') ?></a>
                    </article>
                </div>
            </div>
        </section>

        <?php if ($hasTenantAbout): ?>
            <section id="about" class="section about">
                <div class="container">
                    <div class="section-header">
                        <h2>О заведении</h2>
                        <?php if ($tagline !== ''): ?>
                            <p><?= $tagline ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="about-content">
                        <div class="about-text">
                            <?php if ($siteDesc !== ''): ?>
                                <p><?= nl2br($siteDesc) ?></p>
                            <?php endif; ?>
                            <?php if ($tagline !== '' && $tagline !== $siteDesc): ?>
                                <p><?= $tagline ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="about-image">
                            <picture>
                                <source
                                    srcset="/images/HDR1_320.webp 320w, /images/HDR1_640.webp 640w"
                                    media="(max-width: 768px)"
                                    type="image/webp">
                                <source
                                    srcset="/images/HDR1_1024.webp 1024w"
                                    media="(max-width: 1280px)"
                                    type="image/webp">
                                <img
                                    src="/images/HDR1_1024.webp"
                                    srcset="/images/HDR1_1024.webp 1440w"
                                    loading="lazy"
                                    decoding="async"
                                    alt="<?= $siteName ?>"
                                    sizes="(max-width: 768px) 100vw, (max-width: 1280px) 100vw, 1440px">
                            </picture>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($hasTenantContacts): ?>
            <section id="contact" class="reservation-inner">
                <div class="container">
                    <div class="form-content">
                        <div class="reservation-form">
                            <h2>Контакты</h2>
                            <?php if ($contactPhone !== ''): ?>
                                <div class="form-group">
                                    <p>Телефон: <?= htmlspecialchars($contactPhone, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if ($contactAddress !== ''): ?>
                                <div class="form-group">
                                    <p>Адрес: <?= htmlspecialchars($contactAddress, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="hero-actions">
                                <?php if ($contactPhone !== ''): ?>
                                    <a href="tel:<?= htmlspecialchars($contactPhone, ENT_QUOTES, 'UTF-8') ?>" class="btn hero-btn-primary">Позвонить</a>
                                <?php endif; ?>
                                <?php if ($contactMapUrl !== ''): ?>
                                    <a href="<?= htmlspecialchars($contactMapUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn hero-btn-secondary" target="_blank" rel="noopener">Приехать</a>
                                <?php endif; ?>
                                <?php if ($socialTg !== ''): ?>
                                    <a href="<?= htmlspecialchars($socialTg, ENT_QUOTES, 'UTF-8') ?>" class="btn hero-btn-secondary" target="_blank" rel="noopener">Telegram</a>
                                <?php endif; ?>
                                <?php if ($socialVk !== ''): ?>
                                    <a href="<?= htmlspecialchars($socialVk, ENT_QUOTES, 'UTF-8') ?>" class="btn hero-btn-secondary" target="_blank" rel="noopener">VK</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <footer class="footer">
        <div class="container">
            <div class="footer-inner">
                <div class="footer-col">
                    <h3><?= $siteName ?></h3>
                    <?php if ($isProvider): ?>
                        <p>Интуитивные меню и рабочие сценарии для ресторана, команды зала и владельца.</p>
                    <?php elseif ($tenantLeadText !== ''): ?>
                        <p><?= $tenantLeadText ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($footerContactLines !== []): ?>
                    <div class="footer-col">
                        <h3>Контакты</h3>
                        <p><?= nl2br(htmlspecialchars(implode(PHP_EOL, $footerContactLines), ENT_QUOTES, 'UTF-8')) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($socialTg !== '' || $socialVk !== ''): ?>
                    <div class="footer-col">
                        <h3>Соцсети</h3>
                        <p>
                            <?php if ($socialVk !== ''): ?>
                                <a href="<?= htmlspecialchars($socialVk, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">VK</a>
                            <?php endif; ?>
                            <?php if ($socialVk !== '' && $socialTg !== ''): ?>
                                <span> · </span>
                            <?php endif; ?>
                            <?php if ($socialTg !== ''): ?>
                                <a href="<?= htmlspecialchars($socialTg, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Telegram</a>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> «<?= $siteName ?>». Все права защищены.</p>
            </div>
        </div>
    </footer>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0', ENT_QUOTES, 'UTF-8') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0', ENT_QUOTES, 'UTF-8') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0', ENT_QUOTES, 'UTF-8') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/pwa-install.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0', ENT_QUOTES, 'UTF-8') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
