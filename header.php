<?php
require_once __DIR__ . '/session_init.php';
?>

<header class="header">
  <div class="header-inner">
    <div class="logo">
      <a href="index.php" data-project-name><?= htmlspecialchars($_SESSION['project_name'] ?? 'labus') ?></a>
    </div>
    <div class="mobile-menu-btn">
      <span class="burger-line"></span>
      <span class="burger-line"></span>
      <span class="burger-line"></span>
    </div>
    <nav class="nav">
      <ul>
        <li><a href="tel:+79640020200">Позвонить</a></li>
        <li>
          <a href="https://yandex.ru/maps/org/labus/1013468037/?ll=47.510386%2C42.955485&z=16" target="_blank">Приехать</a>
        </li>
        <li><a href="menu.php">Меню</a></li>
        <li class="cart-menu-item">
          <a href="cart.php">
            Заказ
            <span id="cart-total-count" class="cart-counter">0</span>
          </a>
        </li>
        <li class="account-menu-item">
          <a href="<?= isset($_SESSION['user_id']) ? 'account.php' : 'auth.php' ?>">Аккаунт</a>
        </li>
      </ul>
    </nav>
  </div>
  <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
  <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
</header>