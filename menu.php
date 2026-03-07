<?php
header('Cache-Control: public, max-age=600, s-maxage=600');
require_once __DIR__ . '/session_init.php';

$appVersion = $_SESSION['app_version'] ?? '1.0.0';
$db = Database::getInstance();
$categories = $db->getUniqueCategories();
$activeCategory = $_COOKIE['activeMenuCategory'] ?? ($categories[0]['category'] ?? '');

if (isset($_GET['table']) && ctype_digit((string)$_GET['table']) && (int)$_GET['table'] > 0) {
    $_SESSION['qr_table'] = min((int)$_GET['table'], 999);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.php?v=<?= htmlspecialchars($appVersion) ?>">
    <title><?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?> | Меню</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/menu-alt.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/menu-content-info.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/menu-discovery.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body id="body" class="menu-catalog-page">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php
    $now = time();
    $user = $_SESSION['user'] ?? null;
    $userSyncInterval = 300;
    if (isset($_SESSION['user_id'])) {
        $lastSync = $_SESSION['user_last_sync'] ?? 0;
        if (!$user || ($now - $lastSync) >= $userSyncInterval) {
            $user = $db->getUserById($_SESSION['user_id']);
            if ($user) {
                $_SESSION['user'] = $user;
                $_SESSION['user_last_sync'] = $now;
            }
        }
    }

    $menuView = $user['menu_view'] ?? 'default';
    $csrfToken = $_SESSION['csrf_token'] ?? ($GLOBALS['csrfToken'] ?? '');
    $GLOBALS['menu_request_ts'] = $now;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $GLOBALS['menu_css_in_head'] = true;
    $quickCategories = $categories;
    ?>

    <section class="menu-discovery-strip">
        <div class="container">
            <div class="menu-discovery-head">
                <div>
                    <p class="menu-discovery-kicker">Быстрый старт</p>
                    <h1>Откройте нужную категорию и сразу добавляйте блюда в заказ</h1>
                    <p class="menu-discovery-copy">Каталог остаётся привычным. Мы усиливаем навигацию, поиск и быстрый переход к нужным позициям, не ломая сам сценарий заказа.</p>
                </div>
                <a href="/cart.php" class="menu-discovery-cart-link">Перейти к заказу</a>
            </div>

            <div class="menu-discovery-toolbar">
                <label class="menu-discovery-search" for="menuQuickSearch">
                    <span class="menu-discovery-search-label">Поиск по всему меню</span>
                    <input
                        id="menuQuickSearch"
                        class="menu-discovery-search-input"
                        type="search"
                        placeholder="Например, пицца, кофе или ролл"
                        autocomplete="off">
                </label>
                <div class="menu-discovery-meta">
                    <span class="menu-discovery-current" id="menuActiveCategoryLabel"><?= htmlspecialchars((string)$activeCategory) ?></span>
                    <span class="menu-discovery-count" id="menuActiveCategoryMeta">Ищем по всем разделам...</span>
                </div>
            </div>

            <?php if ($quickCategories): ?>
                <div class="menu-discovery-quickcats" aria-label="Быстрый переход по разделам">
                    <?php foreach ($quickCategories as $category): ?>
                        <button
                            type="button"
                            class="menu-quickcat-btn"
                            data-tab-target="<?= htmlspecialchars($category['category']) ?>">
                            <?= htmlspecialchars($category['category']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="menu-discovery-global-empty" id="menuGlobalNoResults" hidden>
                По этому запросу ничего не найдено ни в одной категории. Попробуйте другое название блюда или раздела.
            </div>
        </div>
    </section>

    <div class="menu-tabs-container">
        <div class="menu-tabs">
            <?php foreach ($categories as $category): ?>
                <button class="tab-btn <?= $category['category'] === $activeCategory ? 'active' : '' ?>"
                    data-tab="<?= htmlspecialchars($category['category']) ?>">
                    <?= htmlspecialchars($category['category']) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    switch ($menuView) {
        case 'alt':
            require_once __DIR__ . '/menu-content.php';
            break;
        case 'info':
            require_once __DIR__ . '/menu-content-info.php';
            break;
        default:
            require_once __DIR__ . '/menu-alt.php';
            break;
    }
    ?>

    <div class="footer-bottom">
        <p>&copy; <?= date('Y') ?> «<?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?>». Все права защищены.</p>
    </div>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/menu-modifiers.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/menu-discovery.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
