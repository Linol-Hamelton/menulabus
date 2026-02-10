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

$projectName = 'labus';

$scriptNonce = base64_encode(random_bytes(16));
$styleNonce = base64_encode(random_bytes(16));

$GLOBALS['scriptNonce'] = $scriptNonce;
$GLOBALS['styleNonce'] = $styleNonce;
$GLOBALS['appVersion'] = $appVersion;
$GLOBALS['projectName'] = $projectName;

$db = Database::getInstance();
$categories = $db->getUniqueCategories();
$activeCategory = $_COOKIE['activeMenuCategory'] ?? ($categories[0]['category'] ?? '');
$menuView = 'default';
$csrfToken = bin2hex(random_bytes(16));
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>labus | Меню</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/menu-alt.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/menu-content-info.min.css?v=<?= htmlspecialchars($appVersion) ?>">
</head>

<body id="body">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
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

    <div class="footer-bottom">
        <p>&copy; 2023 "labus". Все права защищены.</p>
    </div>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>

