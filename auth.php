<?php
require_once __DIR__ . '/session_init.php';

require_once __DIR__ . '/mailer.php';

// Получаем экземпляр базы данных (правильный способ для Singleton)
$db = Database::getInstance(); // <- Ключевое исправление здесь
$mode = $_GET['mode'] ?? 'login';
$successMessage = $_SESSION['auth_message'] ?? null;
unset($_SESSION['auth_message']);
$sessionErrorMessage = $_SESSION['auth_error_message'] ?? null;
unset($_SESSION['auth_error_message']);

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'register') {
        // Обработка регистрации
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        // Расширенная валидация
        if (empty($email)) {
            $errors[] = "Email обязателен для заполнения";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Введите корректный email";
        } elseif (strlen($email) > 255) {
            $errors[] = "Email слишком длинный";
        }

        if (empty($name)) {
            $errors[] = "Имя обязательно для заполнения";
        } elseif (strlen($name) < 2) {
            $errors[] = "Имя должно содержать минимум 2 символа";
        } elseif (strlen($name) > 100) {
            $errors[] = "Имя слишком длинное";
        }

        if (strlen($password) < 8) {
            $errors[] = "Пароль должен содержать минимум 8 символов";
        } elseif (!preg_match('/[A-ZА-Я]/u', $password)) {
            $errors[] = "Пароль должен содержать хотя бы одну заглавную букву";
        } elseif ($password !== $passwordConfirm) {
            $errors[] = "Пароли не совпадают";
        }

        if (empty($errors)) {
            try {
                $existingUser = $db->getUserByEmail($email);
                if ($existingUser) {
                    if ($existingUser['is_active']) {
                        $errors[] = "Пользователь с таким email уже существует";
                    } else {
                        $errors[] = "Аккаунт уже зарегистрирован, но не активирован. Проверьте вашу почту.";
                    }
                } else {
                    $verificationToken = bin2hex(random_bytes(16));
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    
                    if ($db->createUser($email, $passwordHash, $name, $phone, $verificationToken)) {
                        require_once __DIR__ . '/Queue.php';
                        $queue = new Queue();
                        $jobId = $queue->push('send_verification_email', [
                            'email' => $email,
                            'name' => $name,
                            'token' => $verificationToken
                        ]);
                        if ($jobId !== false) {
                            $_SESSION['auth_message'] = "Регистрация успешна! Проверьте вашу почту для подтверждения.";
                            header("Location: auth.php?mode=login");
                            exit;
                        } else {
                            $errors[] = "Не удалось отправить письмо с подтверждением. Пожалуйста, попробуйте позже.";
                        }
                    } else {
                        $errors[] = "Ошибка при создании пользователя";
                    }
                }
            } catch (Exception $e) {
                error_log("Registration error: " . $e->getMessage());
                $errors[] = "Произошла ошибка при регистрации";
            }
        }
    } elseif ($mode === 'login') {
        // Обработка входа
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        try {
            $user = $db->getUserByEmail($email);
            
            if (!$user) {
                $errors[] = "Неверный email или пароль";
            } elseif (!password_verify($password, $user['password_hash'])) {
                $errors[] = "Неверный email или пароль";
            } elseif (!$user['is_active']) {
                $errors[] = "Аккаунт не активирован. Проверьте вашу почту для подтверждения или обратитесь в поддержку.";
                error_log("Failed login attempt - account not active for email: $email");
            } else {
                // =============================================================
                // ИЗМЕНЯЕМ ЭТОТ БЛОК ДЛЯ ПРОДЛЕНИЯ СЕССИИ
                // =============================================================
                
                // Сохраняем данные сессии перед регенерацией
                $sessionData = $_SESSION;
                
                // Регенерируем ID с удалением старой сессии (ВАЖНО для безопасности)
                session_regenerate_id(true);
                
                // Восстанавливаем данные пользователя в новую сессию
                $_SESSION = $sessionData;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['last_login'] = time();
                $_SESSION['last_activity'] = time();
                $_SESSION['last_regeneration'] = time();
                
                // Устанавливаем длительную куку сессии
                $sessionParams = session_get_cookie_params();
                setcookie(
                    session_name(),
                    session_id(),
                    time() + 2592000, // 30 дней
                    $sessionParams['path'],
                    $sessionParams['domain'],
                    $sessionParams['secure'],
                    $sessionParams['httponly']
                );

                // Если выбрано "Запомнить меня"
                if (isset($_POST['remember_me'])) {
                    $selector = bin2hex(random_bytes(12));
                    $validator = bin2hex(random_bytes(16));
                    $hashed_validator = password_hash($validator, PASSWORD_DEFAULT);
                    $expires = time() + 2592000; // 30 дней
                    
                    // Сохраняем токен в БД
                    $db->saveRememberToken($user['id'], $selector, $hashed_validator, $expires);
                    
                    // Устанавливаем куки
                    setcookie(
                        'remember',
                        $selector.':'.$validator,
                        $expires,
                        '/',
                         'menu.labus.pro',  // Исправляем домен
                        true,  // HTTPS only
                        true   // HTTPOnly
                    );
                }
                
                header("Location: account.php");
                exit;
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $errors[] = "Произошла ошибка при входе";
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
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <title><?= htmlspecialchars($mode === 'register' ? 'Регистрация' : 'Вход') ?></title>

    <!-- Preloader - мгновенная загрузка -->
    
</head>
<body class="auth-page">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    
    <div class="auth-container">
<div class="auth-tabs">
    <a href="auth.php?mode=login" class="auth-tab <?= $mode === 'login' ? 'active' : '' ?>">Вход</a>
    <a href="auth.php?mode=register" class="auth-tab <?= $mode === 'register' ? 'active' : '' ?>">Регистрация</a>
</div>
        
        <?php if ($successMessage): ?>
            <div class="auth-success">
                <p><?= htmlspecialchars($successMessage) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($sessionErrorMessage): ?>
            <div class="auth-errors">
                <p><?= htmlspecialchars($sessionErrorMessage) ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="auth-errors">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($mode === 'register'): ?>
            <form class="auth-form" method="POST" id="registerForm" autocomplete="on">
                <div class="form-group">
                    <label for="reg_email" class="visually-hidden"></label>
                    <input 
                        type="email" 
                        id="reg_email" 
                        name="email" 
                        placeholder="Email" 
                        required
                        autocomplete="email"
                        value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                    >
                </div>
                <div class="form-group">
                    <label for="reg_name" class="visually-hidden"></label>
                    <input 
                        type="text" 
                        id="reg_name" 
                        name="name" 
                        placeholder="Имя" 
                        required
                        autocomplete="name"
                        value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>"
                    >
                </div>
                <div class="form-group">
                    <label for="reg_phone" class="visually-hidden"></label>
                    <input 
                        type="tel" 
                        id="reg_phone" 
                        name="phone" 
                        placeholder="Телефон (необязательно)"
                        autocomplete="tel"
                        value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>"
                    >
                </div>
<div class="form-group has-password-visibility">
    <label for="reg_password" class="visually-hidden"></label>
    <input 
        type="password" 
        id="reg_password" 
        name="password" 
        placeholder="Пароль (минимум 8 символов)" 
        required
        autocomplete="new-password"
        minlength="8"
        class="password-input"
    >
    <div class="password-visibility" data-target="reg_password" role="button" tabindex="0" aria-label="Show password"></div>
</div>

<div class="form-group has-password-visibility">
    <label for="reg_password_confirm" class="visually-hidden"></label>
    <input 
        type="password" 
        id="reg_password_confirm" 
        name="password_confirm" 
        placeholder="Подтвердите пароль" 
        required
        autocomplete="new-password"
        class="password-input"
    >
    <div class="password-visibility" data-target="reg_password_confirm" role="button" tabindex="0" aria-label="Show password"></div>
</div>
                <button type="submit" class="btn btn-primary">
                    <span class="btn-text">Зарегистрироваться</span>
                </button>
            </form>

            <div class="auth-oauth-divider">или</div>
            <div class="auth-form auth-oauth-form">
                <a href="/google-oauth-start.php?mode=register" class="btn btn-google" role="button">
                    <span class="btn-text">Продолжить через Google</span>
                    <span class="btn-loader" aria-hidden="true"></span>
                </a>
                <a href="/yandex-oauth-start.php?mode=register" class="btn btn-yandex" role="button">
                    <span class="btn-text">Продолжить через Яндекс ID</span>
                    <span class="btn-loader" aria-hidden="true"></span>
                </a>
                <a href="/vk-oauth-start.php?mode=register" class="btn btn-vk" role="button">
                    <span class="btn-text">Продолжить через VK ID</span>
                    <span class="btn-loader" aria-hidden="true"></span>
                </a>
            </div>
        <?php else: ?>
            <form class="auth-form" method="POST" autocomplete="on">
                <div class="form-group">
                    <label for="login_email" class="visually-hidden"></label>
                    <input 
                        type="email" 
                        id="login_email" 
                        name="email" 
                        placeholder="Email" 
                        required
                        autocomplete="email"
                        value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                    >
                </div>
<div class="form-group has-password-visibility">
    <label for="login_password" class="visually-hidden"></label>
    <input 
        type="password" 
        id="login_password" 
        name="password" 
        placeholder="Пароль" 
        required
        autocomplete="current-password"
        minlength="8"
        class="password-input"
    >
    <div class="password-visibility" data-target="login_password" role="button" tabindex="0" aria-label="Show password"></div>
</div>
                <div class="form-group remember-me">
                    <input 
                        type="checkbox" 
                        id="remember_me" 
                        name="remember_me"
                        class="custom-checkbox"
                    >
                    <label for="remember_me">Запомнить меня на этом устройстве</label>
                </div>
                <button type="submit" class="btn btn-primary">
                    <span class="btn-text">Войти</span>
                    <span class="btn-loader" aria-hidden="true"></span>
                </button>
                <div class="auth-footer">
                    <a href="password-reset.php" class="auth-link">Забыли пароль?</a>
                    <span class="auth-separator">|</span>
                    <a href="auth.php?mode=register" class="auth-link">Регистрация</a>
                </div>
            </form>

            <div class="auth-oauth-divider">или</div>
            <div class="auth-form auth-oauth-form">
                <a href="/google-oauth-start.php?mode=login" class="btn btn-google" role="button">
                    <span class="btn-text">Войти через Google</span>
                    <span class="btn-loader" aria-hidden="true"></span>
                </a>
                <a href="/yandex-oauth-start.php?mode=login" class="btn btn-yandex" role="button">
                    <span class="btn-text">Войти через Яндекс ID</span>
                    <span class="btn-loader" aria-hidden="true"></span>
                </a>
                <a href="/vk-oauth-start.php?mode=login" class="btn btn-vk" role="button">
                    <span class="btn-text">Войти через VK ID</span>
                    <span class="btn-loader" aria-hidden="true"></span>
                </a>
            </div>
        <?php endif; ?>
    </div>
	    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
	    <script src="/js/auth.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>



