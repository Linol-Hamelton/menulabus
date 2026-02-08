<?php
ob_clean();
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

// 1. Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    $_SESSION['auth_error'] = "Для доступа необходимо авторизоваться";
    header("Location: auth.php");
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);

// 2. Проверка существования пользователя и активности
if (!$user || !$user['is_active']) {
    session_destroy();
    $_SESSION['auth_error'] = $user ? "Аккаунт деактивирован" : "Пользователь не найден";
    header("Location: auth.php");
    exit;
}

// 3. Проверка роли (строго по значениям из БД)
if (!in_array($user['role'], ['owner', 'admin', 'employee'], true)) {
    error_log("Access denied for user_id: {$_SESSION['user_id']}. Role: {$user['role']}");
    $_SESSION['auth_error'] = "У вас нет доступа к панели управления";
    header("Location: account.php");
    exit;
}

// 4. Сохраняем только **после** проверки
$_SESSION['user'] = $user;
$_SESSION['user_role'] = $user['role'];

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

$activeTab = $_COOKIE['activeOrderTab'] ?? 'Приём';

// Partial fetch for SSE refresh (reduces HTML size and prevents UI freezes on low-end devices).
if (($_GET['partial'] ?? '') === 'account-sections') {
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/partials/employee_account_sections.php';
    exit;
}
// POST-обработка (профиль / пароль)
$errors = $successMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = "Ошибка безопасности";
    } else {
        if (isset($_POST['update_profile'])) {
            $name = trim($_POST['name'] ?? '');
            if (strlen($name) < 2) {
                $errors[] = "Имя должно содержать минимум 2 символа";
            } elseif ($db->updateUser($_SESSION['user_id'], $name, trim($_POST['phone'] ?? ''))) {
                $_SESSION['user_name'] = $name;
                $successMessage = "Профиль обновлен";
                $user = $db->getUserById($_SESSION['user_id']);
            } else {
                $errors[] = "Ошибка при обновлении";
            }
        } elseif (isset($_POST['change_password'])) {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!password_verify($current, $user['password_hash'])) {
                $errors[] = "Текущий пароль неверен";
            } elseif (strlen($new) < 8 || $new !== $confirm) {
                $errors[] = "Новый пароль должен быть ≥8 символов и совпадать";
            } elseif ($db->updatePassword($_SESSION['user_id'], password_hash($new, PASSWORD_DEFAULT))) {
                $successMessage = "Пароль изменён";
            } else {
                $errors[] = "Ошибка при изменении пароля";
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
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <title>Панель сотрудника | labus</title>

    <!-- Preloader - мгновенная загрузка -->
    
</head>

<body class="employee-page">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?>
                    <p><?= htmlspecialchars($e) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php require __DIR__ . '/partials/employee_account_sections.php'; ?>

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
    <script src="/js/employee-status-fix.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/ws-orders.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
