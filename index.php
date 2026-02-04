<?php
header('Cache-Control: public, max-age=600, s-maxage=600');
require_once __DIR__ . '/session_init.php';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <?php include 'preloader-universal.php'; ?>
    
    <meta charset="UTF-8">
    <!-- Существующие теги -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#000000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="labus">
    <link rel="apple-touch-icon" href="/icons/icon-192x192.png">
    <meta name="msapplication-TileImage" content="/icons/icon-128x128.png">
    <meta name="msapplication-TileColor" content="#000000">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/version.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
  <title>
    labus | Компания labus — это место, где можно получить профессиональную помощь в продвижении и развитии бизнеса.
  </title>

    <!-- Preloader - мгновенная загрузка -->
</head>

<body>
  <?php require_once __DIR__ . '/header.php'; ?>

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
          alt="Меню для ресторанов от labus.pro"
          loading="eager"
          decoding="async">
    </picture>
  </div>
    <div class="hero-content">
      <h1>labus</h1>
      <p>цифровое меню и управление заказами</p>
      <a href="#reservation" class="btn">Забронировать</a>
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
                alt="Меню для ресторанов от labus.pro"
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
          <h3>labus</h3>
          <p>
            Интуитивные меню, которые повышают аппетит и средний чек.
          </p>
        </div>
        <div class="footer-col">
          <h3>Контакты</h3>
          <p>Махачкала,<br>Олега Кошевого,<br>46 а<br>+7‒964‒002‒02‒00</p>
        </div>
        <div class="footer-col">
          <h3>Часы работы</h3>
          <p>Пн-Пт: 09:00–18:00</p>
        </div>
        <div class="footer-col">
          <h3>Мы в соцсетях</h3>
          <div class="social-links">
            <a href="https://instagram.com/kultura.bar" aria-label="Instagram"><i class="fab fa-instagram" aria-hidden="true"></i></a>
            <a href="https://facebook.com/kultura.bar" aria-label="Facebook"><i class="fab fa-facebook-f" aria-hidden="true"></i></a>
            <a href="https://vk.com/kultura.bar" aria-label="ВКонтакте"><i class="fab fa-vk" aria-hidden="true"></i></a>
            <a href="https://t.me/kultura_bar" aria-label="Telegram"><i class="fab fa-telegram-plane" aria-hidden="true"></i></a>
          </div>
        </div>
      </div>
      <div class="footer-bottom">
        <p>&copy; 2025 "labus". Все права защищены.</p>
      </div>
    </div>
  </footer>
  <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
  <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
  <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
  <script src="/js/pwa-install.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
  <script src="/js/version-checker.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>