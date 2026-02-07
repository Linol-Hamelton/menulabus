<?php
header('Cache-Control: public, max-age=600, s-maxage=600');
require_once __DIR__ . '/session_init.php';

$appVersion = $_SESSION['app_version'] ?? '1.0.0';
$db = Database::getInstance();
$categories = $db->getUniqueCategories();
$activeCategory = $_COOKIE['activeMenuCategory'] ?? $categories[0]['category'];
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
    // РџРѕР»СѓС‡Р°РµРј Р°РєС‚СѓР°Р»СЊРЅС‹Рµ РґР°РЅРЅС‹Рµ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РёР· Р‘Р”
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
    } ?>

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
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
