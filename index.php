<?php
header('Cache-Control: public, max-age=600, s-maxage=600');
require_once __DIR__ . '/session_init.php';
$tenantContext = tenant_runtime_require_resolved();
if (empty($tenantContext['is_provider'])) {
    header('Location: /menu.php', true, 302);
    exit;
}
$siteName  = htmlspecialchars(trim(html_entity_decode($GLOBALS['siteName']    ?? 'labus',                             ENT_QUOTES, 'UTF-8'), '"\''));
$siteDesc  = htmlspecialchars(trim(html_entity_decode($GLOBALS['siteDesc']    ?? '',                                   ENT_QUOTES, 'UTF-8'), '"\''));
$tagline   = htmlspecialchars(trim(html_entity_decode($GLOBALS['siteTagline'] ?? 'цифровое меню и управление заказами', ENT_QUOTES, 'UTF-8'), '"\''));
$faviconUrl = htmlspecialchars($GLOBALS['faviconUrl'] ?? '/icons/favicon.ico');
$appVer    = htmlspecialchars($_SESSION['app_version'] ?? '1.0.0');
$contactPhone = trim((string)($GLOBALS['contactPhone'] ?? ''));
$contactAddress = trim((string)($GLOBALS['contactAddress'] ?? ''));
$socialTg = trim((string)($GLOBALS['socialTg'] ?? ''));
$socialVk = trim((string)($GLOBALS['socialVk'] ?? ''));
$footerContactLines = array_values(array_filter([$contactAddress, $contactPhone], static fn ($value) => $value !== ''));
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
    <?php if ($siteDesc !== ''): ?><meta name="description" content="<?= $siteDesc ?>"><?php endif; ?>
    <link rel="apple-touch-icon" href="/icons/icon-192x192.png">
    <meta name="msapplication-TileImage" content="/icons/icon-128x128.png">
    <meta name="msapplication-TileColor" content="#000000">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= $appVer ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= $appVer ?>">
    <link rel="stylesheet" href="/css/index-landing.css?v=<?= $appVer ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= $appVer ?>">
    <title><?= $siteName ?> | <?= $siteDesc !== '' ? $siteDesc : $tagline ?></title>

    <!-- Preloader - мгновенная загрузка -->
</head>

<body class="index-landing-page">
  <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>

  <section class="hero">
  <!-- Адаптивная картинка, которая будет фоном -->
  <div class="hero__bg">
    <picture>
      <source 
          srcset="/images/HDR_320.webp 320w, /images/HDR_640.webp 640w" 
          media="(max-width: 768px)" 
          type="image/webp">
      <source 
          srcset="/images/HDR_1024.webp 1024w" 
          media="(max-width: 1280px)" 
          type="image/webp">
      <img
          src="/images/HDR_1440.webp"
          srcset="/images/HDR_1440.webp 1440w"
          alt="Меню от <?= $siteName ?>"
          loading="eager"
          decoding="async">
    </picture>
  </div>
    <div class="hero-content">
      <span class="hero-eyebrow">Digital dining flow</span>
      <h1><?= $siteName ?></h1>
      <p><?= $tagline ?></p>
      <div class="hero-actions">
        <a href="/menu.php" class="btn hero-btn-primary">Открыть меню</a>
        <a href="#reservation" class="btn hero-btn-secondary">Забронировать</a>
      </div>
      <div class="hero-quick-points" aria-label="Быстрые преимущества">
        <span>QR-меню без лишних шагов</span>
        <span>Быстрый переход к заказу</span>
        <span>Бронирование и консультация</span>
      </div>
    </div>
  </section>

  <section class="landing-entry-strip" aria-label="Основные сценарии">
    <div class="container">
      <div class="landing-entry-grid">
        <article class="landing-entry-card">
          <p class="landing-entry-kicker">Основной сценарий</p>
          <h2>Открыть меню и сразу перейти к выбору блюд</h2>
          <p>Традиционный каталог остаётся на отдельной странице, без лишнего маркетингового шума.</p>
          <a href="/menu.php" class="landing-entry-link">Перейти в меню</a>
        </article>
        <article class="landing-entry-card landing-entry-card-muted">
          <p class="landing-entry-kicker">До заказа</p>
          <h2>Узнать о сервисе, форматах работы и оставить заявку</h2>
          <p>Лендинг помогает быстро понять продукт, а не подменяет собой сам каталог.</p>
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
          <p>Электронное меню от Labus — это цифровое искусство подачи вашей кухни. Оно превращает выбор блюд в увлекательное путешествие для гостей с полным погружением в атмосферу заведения.</p>
          <p>Это витрина вашего бренда: сочные фотографии, изящные описания и бесшовная навигация пробуждают аппетит и повышают средний чек. Заказ становится интуитивным и приятным ритуалом. </p>
          <p>Сцена для вашего гастрономического театра. Современный дизайн и передовая аналитика раскрывают предпочтения гостей, повышая их лояльность и позволяя вам творить и вдохновляться.</p>
        </div>
        <div class="about-image">
          <picture>
            <!-- Порядок важен: от самого узкого (мобильные) до самого широкого (десктоп) -->
            <source 
                srcset="/images/HDR1_320.webp 320w, /images/HDR1_640.webp 640w" 
                media="(max-width: 768px)" 
                type="image/webp">
            <source 
                srcset="/images/HDR1_1024.webp 1024w" 
                media="(max-width: 1280px)" 
                type="image/webp">
            <!-- Основной источник для больших экранов и фолбэк -->
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
              <input
                type="text"
                name="name"
                placeholder="Ваше имя"
                required />
            </div>
            <div class="form-group">
              <input type="tel" name="phone" placeholder="Телефон" required />
            </div>
            <div class="form-group">
              <input type="date" name="date" placeholder="Дата" required />
            </div>
            <div class="form-group">
              <input type="time" name="time" placeholder="Время" required />
            </div>
            <div class="form-group">
              <input
                type="number"
                name="guests"
                placeholder="Количество ЛПР"
                min="1"
                required />
            </div>
            <button type="submit" class="btn-form">Записаться</button>
          </form>
          <div id="formMessage"></div>
        </div>
      </div>
    </div>
  </section>

  <footer class="footer">
    <div class="container">
      <div class="footer-inner">
        <div class="footer-col">
          <h3><?= $siteName ?></h3>
          <p>Интуитивные меню, которые повышают аппетит и средний чек.</p>
        </div>
        <?php if ($footerContactLines !== []): ?>
        <div class="footer-col">
          <h3>Контакты</h3>
          <p><?= nl2br(htmlspecialchars(implode(PHP_EOL, $footerContactLines))) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($socialTg !== '' || $socialVk !== ''): ?>
        <div class="footer-col">
          <h3>Мы в соцсетях</h3>
          <div class="social-links">
            <?php if ($socialVk !== ''): ?>
            <a href="<?= htmlspecialchars($socialVk) ?>" aria-label="ВКонтакте" target="_blank" rel="noopener"><i class="fab fa-vk" aria-hidden="true"></i></a>
            <?php endif; ?>
            <?php if ($socialTg !== ''): ?>
            <a href="<?= htmlspecialchars($socialTg) ?>" aria-label="Telegram" target="_blank" rel="noopener"><i class="fab fa-telegram-plane" aria-hidden="true"></i></a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="footer-bottom">
        <p>&copy; <?= date('Y') ?> «<?= $siteName ?>». Все права защищены.</p>
      </div>
    </div>
  </footer>
  <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
  <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
  <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
  <script src="/js/pwa-install.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
