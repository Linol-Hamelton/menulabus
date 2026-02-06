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
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои заказы | labus</title>

    <!-- Preloader - мгновенная загрузка -->
    
</head>

<body class="customer_orders-page">
    <?php require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="account-sections">
            <section class="account-section">
                <h3>Ваши заказы</h3>
                <?php
                $orders   = $db->getUserOrders($_SESSION['user_id']);
                $statuses = [
                                ['status' => 'Приём'],
                                ['status' => 'готовим'],
                                ['status' => 'доставляем'],
                                ['status' => 'завершён'],
                                ['status' => 'отказ']
                            ];

                if (empty($orders)): ?>
                    <p>Нет активных заказов</p>
                <?php else: ?>
                    <?php foreach ($statuses as $s): ?>
                        <div class="orders-list tab-content <?= $s['status'] === $activeTab ? 'active' : 'Приём' ?>"
                            id="<?= htmlspecialchars($s['status']) ?>">
                            <?php
                            $filtered = array_values(array_filter($orders, fn($o) => $o['status'] === $s['status']));
                            if (empty($filtered)): ?>
                                <p>Нет заказов со статусом «<?= htmlspecialchars($s['status']) ?>»</p>
                            <?php else: ?>
                                <?php foreach ($filtered as $o): ?>
                                    <div class="order-item" data-order-id="<?= $o['id'] ?>" data-status="<?= htmlspecialchars($o['status']) ?>">
                                        <div class="order-header" data-toggle-order>
                                            <span class="order-id">#<?= $o['id'] ?></span>
                                            <span class="order-date"><?= date('d.m H:i', strtotime($o['created_at'])) ?></span>
                                            <span class="order-total"><?= number_format($o['total'], 0, '.', ' ') ?> ₽</span>
                                            <i class="fas fa-chevron-down employee-toggle-icon"></i>
                                            <span class="employee-toggle-icon">Состав</span>
                                        </div>
                                        <hr class="divider">

                                        <div class="order-customer-info">
                                            <span><i class="fas fa-user"></i> <?= htmlspecialchars($o['user_name']) ?></span>
                                            <?php if ($o['user_phone']): ?>
                                                <span><i class="fas fa-phone"></i> <?= htmlspecialchars($o['user_phone']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <hr class="divider">
                                        <div class="order-customer-info">
                                            <div class="delivery-meta">
                                                <span class="delivery-icon-text">
                                                    <?php
                                                    $iconMap = [
                                                        'takeaway' => 'fa-walking',
                                                        'delivery' => 'fa-motorcycle',
                                                        'table'    => 'fa-store',
                                                        'bar'      => 'fa-glass-cheers'
                                                    ];
                                                    $type   = $o['delivery_type'] ?? 'Не указано';
                                                    $icon   = $iconMap[$type] ?? 'fa-question-circle';
                                                    ?>
                                                    <i class="fas <?= $icon ?>"></i>
                                                    <?= htmlspecialchars($type) ?>
                                                </span>

                                                <?php if (!empty($o['delivery_details'])): ?>
                                                    <span class="delivery-details">
                                                        <?= htmlspecialchars($o['delivery_details']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($o['updater_name']): ?>
                                                <span><i class="fas fa-user-edit"></i> Обновил: <?= htmlspecialchars($o['updater_name']) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="order-items">
                                            <hr class="divider">
                                            <?php foreach ($o['items'] as $item): ?>
                                                <div class="order-product">
                                                    <span class="product-name"><?= htmlspecialchars($item['name']) ?></span>
                                                    <span class="product-quantity"><?= $item['quantity'] ?> × <?= $item['price'] ?> ₽</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="order-actions">
                                            <form method="POST" class="update-order-form" data-order-id="<?= $o['id'] ?>">
                                                <span class="order-status <?= strtolower($o['status']) ?>"><?= $o['status'] ?></span>
                                                <?php if ($o['status'] === 'завершён' || $o['status'] === 'отказ'): ?>
                                                        <form class="repeat-order-form" data-order-id="<?= $o['id'] ?>">
                                                            <input type="hidden" name="action" value="repeat_order">
                                                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                            <button type="submit" class="checkout-btn"
                                                                title="Добавить все товары из этого заказа в корзину">
                                                                <i class="fas fa-redo"></i>  Повторить</button>
                                                        </form>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>

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
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/account.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/ws-orders.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>