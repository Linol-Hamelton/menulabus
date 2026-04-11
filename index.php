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
                        <h2>Показать сценарий запуска и обсудить подключение</h2>
                        <p>Здесь остаются B2B-смысл, консультация и быстрый контакт для нового ресторана или сети.</p>
                        <a href="#reservation" class="landing-entry-link">Оставить заявку</a>
                    </article>
                </div>
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
                                src="/images/HDR1_1440.webp"
                                srcset="/images/HDR1_1440.webp 1440w"
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
                                    src="/images/HDR1_1440.webp"
                                    srcset="/images/HDR1_1440.webp 1440w"
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
