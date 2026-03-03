<?php
if (!defined('PUBLIC_MENU')) {
    require_once __DIR__ . '/session_init.php';
}
$projectName       = trim(html_entity_decode($GLOBALS['siteName'] ?? $_SESSION['project_name'] ?? 'labus', ENT_QUOTES, 'UTF-8'), '"\'');
$hideLabusBranding = $GLOBALS['hideLabusBranding'] ?? false;
$contactPhone   = $GLOBALS['contactPhone']   ?? '+79640020200';
$contactAddress = $GLOBALS['contactAddress'] ?? '';
$logoUrl        = $GLOBALS['logoUrl']        ?? '';
$socialTg       = $GLOBALS['socialTg']       ?? '';
$socialVk       = $GLOBALS['socialVk']       ?? '';
$appVersion     = $_SESSION['app_version']   ?? ($GLOBALS['appVersion'] ?? '1.0.0');
$isLoggedIn     = !empty($_SESSION['user_id']);
$includeHeaderCss = empty($GLOBALS['header_css_in_head']);
?>

<header class="header">
  <div class="header-inner">
    <div class="logo">
      <a href="index.php" data-project-name>
        <?php if ($logoUrl !== ''): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($projectName) ?>" class="site-logo-img">
        <?php else: ?>
          <?= htmlspecialchars($projectName) ?>
        <?php endif; ?>
      </a>
    </div>
    <div class="mobile-menu-btn">
      <span class="burger-line"></span>
      <span class="burger-line"></span>
      <span class="burger-line"></span>
    </div>
    <nav class="nav">
      <ul>
        <li><a href="tel:<?= htmlspecialchars($contactPhone) ?>">Позвонить</a></li>
        <?php if ($contactAddress !== ''): ?>
        <li>
          <a href="<?= htmlspecialchars($contactAddress) ?>" target="_blank" rel="noopener">Приехать</a>
        </li>
        <?php else: ?>
        <li>
          <a href="https://yandex.ru/maps/org/labus/1013468037/?ll=47.510386%2C42.955485&z=16" target="_blank" rel="noopener">Приехать</a>
        </li>
        <?php endif; ?>
        <li><a href="menu.php">Меню</a></li>
        <li class="cart-menu-item">
          <a href="cart.php">
            Заказ
            <span id="cart-total-count" class="cart-counter">0</span>
          </a>
        </li>
        <li class="account-menu-item">
          <a href="<?= $isLoggedIn ? 'account.php' : 'auth.php' ?>">Аккаунт</a>
        </li>
      </ul>
    </nav>
  </div>
  <?php if (!$hideLabusBranding): ?>
  <?php endif; ?>
  <?php if ($includeHeaderCss): ?>
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
  <?php endif; ?>
</header>
