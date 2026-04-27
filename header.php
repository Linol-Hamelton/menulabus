<?php
if (!defined('PUBLIC_MENU')) {
    require_once __DIR__ . '/session_init.php';
}

$projectName = trim(html_entity_decode($GLOBALS['siteName'] ?? $_SESSION['project_name'] ?? 'labus', ENT_QUOTES, 'UTF-8'), '"\'');
$hideLabusBranding = $GLOBALS['hideLabusBranding'] ?? false;
$contactPhone = trim((string)($GLOBALS['contactPhone'] ?? ''));
$contactAddress = trim((string)($GLOBALS['contactAddress'] ?? ''));
$contactMapUrl = trim((string)($GLOBALS['contactMapUrl'] ?? ''));
$logoUrl = (string)($GLOBALS['logoUrl'] ?? '');
$socialTg = (string)($GLOBALS['socialTg'] ?? '');
$socialVk = (string)($GLOBALS['socialVk'] ?? '');
$appVersion = $_SESSION['app_version'] ?? ($GLOBALS['appVersion'] ?? '1.0.0');
$uiUxPolishVersion = @filemtime(__DIR__ . '/css/ui-ux-polish.css');
$uiUxPolishVersion = $uiUxPolishVersion ? (string)$uiUxPolishVersion : (string)$appVersion;
$isLoggedIn = !empty($_SESSION['user_id']);
$includeHeaderCss = empty($GLOBALS['header_css_in_head']);
?>

<header class="header">
  <div class="header-inner">
    <div class="logo">
      <a href="/" data-project-name>
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
        <?php if ($contactPhone !== ''): ?>
        <li><a href="tel:<?= htmlspecialchars($contactPhone) ?>">Позвонить</a></li>
        <?php endif; ?>
        <?php if ($contactMapUrl !== ''): ?>
        <li>
          <a href="<?= htmlspecialchars($contactMapUrl) ?>" target="_blank" rel="noopener">Приехать</a>
        </li>
        <?php endif; ?>
        <li><a href="/menu.php"><?= htmlspecialchars(t('nav.menu')) ?></a></li>
        <li class="cart-menu-item">
          <a href="/cart.php">
            <?= htmlspecialchars(t('nav.cart')) ?>
            <span id="cart-total-count" class="cart-counter">0</span>
          </a>
        </li>
        <li class="account-menu-item">
          <a href="<?= $isLoggedIn ? '/account.php' : '/auth.php' ?>"><?= htmlspecialchars(t('nav.account')) ?></a>
        </li>
        <li class="nav-more">
          <button type="button" class="nav-more-toggle" aria-haspopup="true" aria-expanded="false" aria-controls="nav-more-menu">
            <?= htmlspecialchars(t('nav.more')) ?>
            <span class="nav-more-caret" aria-hidden="true">▾</span>
          </button>
          <ul class="nav-more-menu" id="nav-more-menu" role="menu">
            <li role="none"><a role="menuitem" href="/reservation.php"><?= htmlspecialchars(t('nav.reservation')) ?></a></li>
            <li role="none"><a role="menuitem" href="/group.php"><?= htmlspecialchars(t('nav.group')) ?></a></li>
            <li role="none" class="lang-picker-item">
              <?php
              $currentLang = I18n::locale();
              $base = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
              ?>
              <?php foreach (I18n::supported() as $code): ?>
                <a role="menuitem"
                   class="lang-picker-link<?= $code === $currentLang ? ' active' : '' ?>"
                   href="<?= htmlspecialchars($base . '?lang=' . $code) ?>"
                   aria-label="<?= htmlspecialchars(t('language.' . $code)) ?>"><?= strtoupper($code) ?></a>
              <?php endforeach; ?>
            </li>
          </ul>
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
  <link rel="stylesheet" href="/css/ui-ux-polish.css?v=<?= htmlspecialchars($uiUxPolishVersion, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="/css/lang-picker.css?v=<?= htmlspecialchars($appVersion) ?>">
  <script src="/js/header-more.js?v=<?= htmlspecialchars($appVersion) ?>" defer<?= !empty($GLOBALS['scriptNonce']) ? ' nonce="' . htmlspecialchars((string)$GLOBALS['scriptNonce']) . '"' : '' ?>></script>
</header>
