<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$db = Database::getInstance();
$errors = [];
$success = false;
$token = $_GET['token'] ?? '';
$mode = $token ? 'reset' : 'request';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'request') {
        // Запрос на сброс пароля
        $email = trim($_POST['email']);
        $user = $db->getUserByEmail($email);
        
        if ($user) {
            $resetToken = bin2hex(random_bytes(16));
            $db->setPasswordResetToken($email, $resetToken);
            
            // Отправка письма со ссылкой для сброса
            $mailer = new Mailer();
            if ($mailer->sendPasswordResetEmail($email, $user['name'], $resetToken)) {
                $success = true;
            } else {
                $errors[] = "Не удалось отправить письмо для сброса пароля. Пожалуйста, попробуйте позже.";
            }
        } else {
            $errors[] = "Пользователь с таким email не найден";
        }
    } else {
        // Установка нового пароля
        $password = $_POST['password'];
        $passwordConfirm = $_POST['password_confirm'];
        
        if (strlen($password) < 8) {
            $errors[] = "Пароль должен содержать минимум 8 символов";
        } elseif ($password !== $passwordConfirm) {
            $errors[] = "Пароли не совпадают";
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($db->resetPassword($token, $passwordHash)) {
                $_SESSION['auth_message'] = "Пароль успешно изменен! Теперь вы можете войти.";
                header("Location: auth.php");
                exit;
            } else {
                $errors[] = "Неверная или устаревшая ссылка для сброса пароля";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <?php include 'preloader-universal.php'; ?>
    
    <meta charset="UTF-8">
    <title><?= $mode === 'reset' ? 'Сброс пароля' : 'Забыли пароль' ?></title>

    <!-- Preloader - мгновенная загрузка -->
    
</head>
<body>
    <div class="auth-container">
        <h2><?= $mode === 'reset' ? 'Сброс пароля' : 'Забыли пароль' ?></h2>
        
        <?php if ($success): ?>
            <div class="success-message">
                Ссылка для сброса пароля отправлена на ваш email.
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="auth-errors">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($mode === 'reset'): ?>
            <form method="POST">
                <div class="form-group">
                    <input type="password" name="password" placeholder="Новый пароль" required>
                </div>
                <div class="form-group">
                    <input type="password" name="password_confirm" placeholder="Подтвердите пароль" required>
                </div>
                <button type="submit" class="btn">Изменить пароль</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <input type="email" name="email" placeholder="Ваш email" required>
                </div>
                <button type="submit" class="btn">Отправить ссылку</button>
            </form>
            <p class="auth-link"><a href="auth.php">Вернуться к входу</a></p>
        <?php endif; ?>
    </div>
    <link rel="preload" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" as="style" id="fa-styles-preload">
<script nonce="<?= $scriptNonce ?>">
    // Загрузка CSS после загрузки страницы
    document.getElementById('fa-styles-preload').onload = function() {
        this.onload = null;
        this.rel = 'stylesheet';
    };
    
    // Fallback на случай если onload не сработает
    setTimeout(function() {
        var link = document.getElementById('fa-styles-preload');
        if (link && link.rel === 'preload') {
            link.rel = 'stylesheet';
        }
    }, 3000);
</script>
</body>
</html>