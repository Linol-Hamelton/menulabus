<?php
require_once __DIR__ . '/session_init.php';


// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    $_SESSION['auth_error'] = "Для доступа к личному кабинету необходимо авторизоваться";
    header("Location: auth.php");
    exit;
}

// Получаем экземпляр базы данных (Singleton)
$db = Database::getInstance();

// Получаем данные пользователя с валидацией ID
$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if (!$userId) {
    session_destroy();
    header("Location: auth.php");
    exit;
}

$user = $db->getUserById($userId);

// Проверка роли пользователя
if (!in_array($user['role'], ['owner', 'customer', 'employee', 'admin'])) {
    $_SESSION['auth_error'] = "У вас нет доступа к этой странице";
    header("Location: index.php");
    exit;
}

// Проверка активности и роли
if (!$user || !$user['is_active']) {
    session_destroy();
    $_SESSION['auth_error'] = $user ? "Аккаунт не активирован" : "Пользователь не найден";
    header("Location: auth.php");
    exit;
}

// Получение предпочтений вида меню
$menuView = $user['menu_view'] ?? 'alt';

// Generate/refresh CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['csrf_token_created'] = time();
}

// Rotate CSRF token every 24 hours for security
$csrfMaxAge = 24 * 3600; // 24 hours
if (($_SESSION['csrf_token_created'] ?? 0) + $csrfMaxAge < time()) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['csrf_token_created'] = time();
}

// Обработка POST-запросов
$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Ошибка безопасности. Пожалуйста, попробуйте еще раз.";
    } else {
        if (isset($_POST['update_profile'])) {
            // Обновление профиля
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            // Валидация
            if (empty($name)) {
                $errors[] = "Имя обязательно для заполнения";
            } elseif (strlen($name) < 2) {
                $errors[] = "Имя должно содержать минимум 2 символа";
            }

            if (empty($errors)) {
                if ($db->updateUser($_SESSION['user_id'], $name, $phone)) {
                    $_SESSION['user_name'] = $name;
                    $successMessage = "Профиль успешно обновлен";
                    $user = $db->getUserById($_SESSION['user_id']); // Обновляем данные
                } else {
                    $errors[] = "Ошибка при обновлении профиля";
                }
            }
        } elseif (isset($_POST['change_password'])) {
            // Смена пароля
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            // Валидация
            if (!password_verify($currentPassword, $user['password_hash'])) {
                $errors[] = "Текущий пароль неверен";
            } elseif (strlen($newPassword) < 8) {
                $errors[] = "Новый пароль должен содержать минимум 8 символов";
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = "Новые пароли не совпадают";
            }

            if (empty($errors)) {
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                if ($db->updatePassword($_SESSION['user_id'], $newPasswordHash)) {
                    $successMessage = "Пароль успешно изменен";
                } else {
                    $errors[] = "Ошибка при изменении пароля";
                }
            }
        } elseif (isset($_POST['change_menu_view'])) {
            // Изменение вида меню
            $newView = $_POST['menu_view'] ?? 'default';
            if (in_array($newView, ['default', 'alt', 'info'])) {
                if ($db->updateMenuView($_SESSION['user_id'], $newView)) {
                    $menuView = $newView;
                    // Обновляем данные пользователя в сессии
                    $_SESSION['user'] = $db->getUserById($_SESSION['user_id']);
                    $successMessage = "Вид меню успешно изменен";
                } else {
                    $errors[] = "Ошибка при изменении вида меню";
                }
            }
        } elseif (isset($_POST['repeat_order'])) {
            // Повтор заказа
            $orderId = (int)$_POST['order_id'];
            $order = $db->getOrderById($orderId);

            if ($order && $order['user_id'] == $_SESSION['user_id'] && strtolower($order['status']) === 'завершён') {
                // Подготавливаем данные для корзины
                $cartItems = [];
                $products = [];

                foreach ($order['items'] as $item) {
                    if (isset($item['id'])) {
                        $cartItems[$item['id']] = $item['quantity'] ?? 1;

                        // Получаем полные данные о продукте
                        $product = $db->getProductById($item['id']);
                        if ($product) {
                            $products[$item['id']] = [
                                'id' => $product['id'],
                                'name' => $product['name'],
                                'price' => $product['price'],
                                'image' => $product['image'],
                                'calories' => $product['calories'],
                                'protein' => $product['protein'],
                                'fat' => $product['fat'],
                                'carbs' => $product['carbs']
                            ];
                        }
                    }
                }

                // Сохраняем в сессии (для бэкенда)
                $_SESSION['cart'] = $cartItems;
                $_SESSION['cart_products'] = $products;

                // Генерируем JavaScript для сохранения в localStorage
                $cartData = json_encode($cartItems, JSON_UNESCAPED_UNICODE);
                $productsData = json_encode($products, JSON_UNESCAPED_UNICODE);

                // Сохраняем в сессии для передачи в cart.php
                $_SESSION['repeat_order_js'] = "
                    <script>
                        localStorage.setItem('cart', '{$cartData}');
                        localStorage.setItem('products', '{$productsData}');
                    </script>
                ";

                header("Location: cart.php");
                exit;
            } else {
                $errors[] = "Невозможно повторить заказ. Проверьте статус заказа или обратитесь в поддержку.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.webmanifest?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/version.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <title>Личный кабинет | labus</title>

    <!-- Preloader - мгновенная загрузка -->
    
</head>

<body class="customer_orders-page account-page">
        <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
        <?php require_once __DIR__ . '/account-header.php'; ?>

        <div class="account-container">
            <?php if ($successMessage): ?>
                <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="account-sections">
                <section class="account-section">
                    <h2>Профиль</h2>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="form-group">
                            <label>Email:</label><br>
                            <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="name">Имя:</label><br>
                            <input type="text" name="name" id="name" value="<?= htmlspecialchars($user['name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Телефон:</label><br>
                            <input type="tel" name="phone" id="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                        </div>
                        <button type="submit" name="update_profile" class="checkout-btn">Сохранить изменения</button>
                    </form>
                </section>

                <section class="account-section">
                    <h2>Безопасность</h2>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="form-group">
                            <label for="current_password">Текущий пароль:</label><br>
                            <input type="password" name="current_password" id="current_password" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">Новый пароль:</label><br>
                            <input type="password" name="new_password" id="new_password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Подтвердите пароль:</label><br>
                            <input type="password" name="confirm_password" id="confirm_password" required>
                        </div>
                        <button type="submit" name="change_password" class="checkout-btn">Изменить пароль</button>
                    </form>
                </section>
            </div>

            <!-- Секция настройки вида меню -->
            <section class="account-section">
                <h2>Настройки отображения меню</h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="form-group">
                        <label>Выберите вид меню:</label><br>
                        <div class="menu-view-options">
                            <label>
                                <input type="radio" name="menu_view" value="default" <?= $menuView === 'default' ? 'checked' : '' ?>>
                                <p class="menu-view-text">Меню список. С информацией и увеличением фото.</p>
                            </label>
                            <label>
                                <input type="radio" name="menu_view" value="info" <?= $menuView === 'info' ? 'checked' : '' ?>>
                                <p class="menu-view-text">Меню плиткой с информацией о составе и увеличением фото.</p>
                            </label>
                            <label>
                                <input type="radio" name="menu_view" value="alt" <?= $menuView === 'alt' ? 'checked' : '' ?>>
                                <p class="menu-view-text">Меню плиткой без информации о составе и увеличения фото.</p>
                            </label>
                        </div>
                    </div>
                    <button type="submit" name="change_menu_view" class="checkout-btn">Сохранить настройки</button>
                </form>
            </section>

            <div class="section-header-menu">
                <a href="logout.php" class="back-to-menu-btn">Выйти</a>
            </div>
        </div>
        <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
        <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
        <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
        <script src="/js/account.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
        <script src="/js/version-checker.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
