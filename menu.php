<?php
header('Cache-Control: public, max-age=600, s-maxage=600');
require_once __DIR__ . '/session_init.php';

$db = Database::getInstance();
$categories = $db->getUniqueCategories();
$activeCategory = $_COOKIE['activeMenuCategory'] ?? $categories[0]['category'];
?>

<?php $activeCategory = $_COOKIE['activeMenuCategory'] ?? $categories[0]['category']; ?>

<!DOCTYPE html>
<html lang="ru">

<head>  
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>labus | Меню</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
</head>

<body id="body">
    <?php require_once __DIR__ . '/header.php'; ?>
    <?php
    // Получаем актуальные данные пользователя из БД
    if (isset($_SESSION['user_id'])) {
        $user = $db->getUserById($_SESSION['user_id']);
        $menuView = $user['menu_view'] ?? 'default';
    } else {
        $menuView = 'default';
    }
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    if (empty($csrfToken)) {
        $csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $csrfToken;
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


<?php
// Функция для подписи данных продукта


function signProductData($data, $secretKey)
{
    try {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $jsonData, $secretKey);
        $encodedData = base64_encode($jsonData);

        return $encodedData . '.' . $signature;
    } catch (Exception $e) {
        error_log("Ошибка при подписи данных: " . $e->getMessage());
        return '';
    }
}
