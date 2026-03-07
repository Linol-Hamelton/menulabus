<?php
header('Cache-Control: public, max-age=600, s-maxage=600');
header('Content-Type: text/html; charset=utf-8');

define('PUBLIC_MENU', true);

require_once __DIR__ . '/db.php';

$appVersion = '1.0.0';
$versionFile = __DIR__ . '/version.json';
if (is_file($versionFile)) {
    $versionData = json_decode(file_get_contents($versionFile), true);
    if (!empty($versionData['version'])) {
        $appVersion = $versionData['version'];
    }
}

$db = Database::getInstance();
$projectName = $db->getSetting('app_name') ?: 'labus';

$scriptNonce = base64_encode(random_bytes(16));
$styleNonce = base64_encode(random_bytes(16));

$GLOBALS['scriptNonce'] = $scriptNonce;
$GLOBALS['styleNonce'] = $styleNonce;
$GLOBALS['appVersion'] = $appVersion;
$GLOBALS['projectName'] = $projectName;
$categories = $db->getUniqueCategories();
$activeCategory = $_COOKIE['activeMenuCategory'] ?? ($categories[0]['category'] ?? '');
$menuView = 'default';
$csrfToken = bin2hex(random_bytes(16));
$quickCategories = array_slice($categories, 0, 4);
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($projectName) ?> | Меню</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/menu-alt.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/menu-content-info.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/menu-discovery.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>

<body id="body" class="menu-catalog-page">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>

    <section class="menu-discovery-strip">
        <div class="container">
            <div class="menu-discovery-head">
                <div>
                    <p class="menu-discovery-kicker">Быстрый старт</p>
                    <h1>Откройте нужную категорию и сразу добавляйте блюда в заказ</h1>
                    <p class="menu-discovery-copy">Каталог остается привычным. Мы усиливаем только навигацию по разделам и быстрый переход к нужным позициям.</p>
                </div>
                <a href="/cart.php" class="menu-discovery-cart-link">Перейти к заказу</a>
            </div>

            <div class="menu-discovery-toolbar">
                <label class="menu-discovery-search" for="menuQuickSearch">
                    <span class="menu-discovery-search-label">Поиск по активной категории</span>
                    <input
                        id="menuQuickSearch"
                        class="menu-discovery-search-input"
                        type="search"
                        placeholder="Например, пицца, кофе или ролл"
                        autocomplete="off">
                </label>
                <div class="menu-discovery-meta">
                    <span class="menu-discovery-current" id="menuActiveCategoryLabel"><?= htmlspecialchars((string)$activeCategory) ?></span>
                    <span class="menu-discovery-count" id="menuActiveCategoryMeta">Подбираем позиции...</span>
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
        </div>
    </section>

    <div class="menu-tabs-container">
        <div class="menu-tabs">
            <?php foreach ($categories as $index => $category): ?>
                <button class="tab-btn <?= $category['category'] === $activeCategory ? 'active' : '' ?>"
                    data-tab="<?= htmlspecialchars($category['category']) ?>">
                    <?= htmlspecialchars($category['category']) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    $GLOBALS['menu_css_in_head'] = true;
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
        <p>&copy; <?= date('Y') ?> «<?= htmlspecialchars($projectName) ?>». Все права защищены.</p>
    </div>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/menu-discovery.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
