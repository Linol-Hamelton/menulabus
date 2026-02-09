<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

// Проверка авторизации и роли
if (!isset($_SESSION['user_id'])) {
    $_SESSION['auth_error'] = "Для доступа необходимо авторизоваться";
    header("Location: auth.php");
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);

// Проверка существования пользователя и активности
if (!$user || !$user['is_active']) {
    session_destroy();
    $_SESSION['auth_error'] = $user ? "Аккаунт деактивирован" : "Пользователь не найден";
    header("Location: auth.php");
    exit;
}

if (!in_array($user['role'], ['owner', 'customer', 'employee', 'admin'])) {
    $_SESSION['auth_error'] = "У вас нет доступа к этой странице";
    header("Location: account.php");
    exit;
}

// Generate/refresh CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['csrf_token_created'] = time();
}

// ---------- AJAX-обработчик «Повторить заказ» ----------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' &&
    ($_POST['action'] ?? '') === 'repeat_order'
) {
    header('Content-Type: application/json; charset=utf-8');
    
    error_log('Repeat order request received: ' . print_r($_POST, true));

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        error_log('CSRF token mismatch');
        echo json_encode(['success' => false, 'error' => 'CSRF']);
        exit;
    }

    require_once __DIR__ . '/db.php';
    $db = Database::getInstance();

    $orderId = (int)($_POST['order_id'] ?? 0);
    error_log('Processing order ID: ' . $orderId);
    
    $order = $db->getOrderById($orderId);

    if (!$order || empty($order['items'])) {
        error_log('Order not found or empty: ' . $orderId);
        echo json_encode(['success' => false, 'error' => 'Empty order']);
        exit;
    }

    // превращаем items в products + cart
    $products = [];
    $cart     = [];
    foreach ($order['items'] as $item) {
        $products[$item['id']] = [
            'id'       => $item['id'],
            'name'     => $item['name'],
            'price'    => (float)$item['price'],
            'image'    => $item['image'] ?? '',
            'calories' => (int)($item['calories'] ?? 0),
            'protein'  => (int)($item['protein'] ?? 0),
            'fat'      => (int)($item['fat'] ?? 0),
            'carbs'    => (int)($item['carbs'] ?? 0),
        ];
        $cart[$item['id']] = (int)$item['quantity'];
    }

    error_log('Returning products: ' . count($products) . ', cart items: ' . count($cart));
    
    echo json_encode([
        'success' => true,
        'products' => $products,
        'cart' => $cart
    ]);
    exit;
}

// Получаем заказы пользователя
$orders = $db->getUserOrders($_SESSION['user_id']);
$activeTab = $_COOKIE['activeOrderTab'] ?? 'all';

// Partial fetch for SSE refresh (reduces HTML size and prevents UI freezes on low-end devices).
if (($_GET['partial'] ?? '') === 'account-sections') {
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/partials/customer_orders_account_sections.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.webmanifest?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <title>Мои заказы | labus</title>

    <!-- Preloader - мгновенная загрузка -->
    
</head>

<body class="customer_orders-page">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php require __DIR__ . '/partials/customer_orders_account_sections.php'; ?>

        <div class="menu-tabs-container">
            <div class="menu-tabs">
                <button class="tab-btn <?= $activeTab === 'Приём' ? 'active' : '' ?>" data-tab="Приём">Приём</button>
                <button class="tab-btn <?= $activeTab === 'готовим' ? 'active' : '' ?>" data-tab="готовим">Готовим</button>
                <button class="tab-btn <?= $activeTab === 'доставляем' ? 'active' : '' ?>" data-tab="доставляем">Доставляем</button>
                <button class="tab-btn <?= $activeTab === 'завершён' ? 'active' : '' ?>" data-tab="завершён">Завершено</button>
                <button class="tab-btn <?= $activeTab === 'отказ' ? 'active' : '' ?>" data-tab="отказ">Отказ</button>
            </div>
        </div>
    </div>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/account.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/ws-orders.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
