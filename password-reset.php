<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/Csrf.php';

$db = Database::getInstance();
$errors = [];
$success = false;
$token = $_GET['token'] ?? '';
$mode = $token ? 'reset' : 'request';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($mode === 'request') {
        // Запрос на сброс пароля
        $email = trim($_POST['email']);
        $user = $db->getUserByEmail($email);
        $success = true;
        
        if ($user) {
            $resetToken = bin2hex(random_bytes(16));
            if ($db->setPasswordResetToken($email, $resetToken)) {
                // Пытаемся отправить письмо сразу (без зависимости от воркера очереди)
                $sent = false;
                try {
                    $mailer = new Mailer();
                    $sent = $mailer->sendPasswordResetEmail(
                        $email,
                        (string)($user['name'] ?? ''),
                        $resetToken,
                        tenant_base_url(true)
                    );
                } catch (Throwable $e) {
                    $sent = false;
                    error_log('Password reset direct send failed for user id ' . (int)$user['id'] . ': ' . $e->getMessage());
                }

                // Резерв: ставим в очередь для повторной отправки, если SMTP сейчас недоступен
                if (!$sent) {
                    require_once __DIR__ . '/Queue.php';
                    $queue = new Queue();
                    $jobId = $queue->push('send_password_reset_email', [
                        'email' => $email,
                        'name' => (string)($user['name'] ?? ''),
                        'token' => $resetToken,
                        'base_url' => tenant_base_url(true)
                    ]);
                    if ($jobId === false) {
                        error_log('Password reset email enqueue failed for user id: ' . (int)$user['id']);
                    }
                }
            } else {
                error_log('Password reset token was not saved for user id: ' . (int)$user['id']);
            }
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
                header("Location: /auth.php");
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
    
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <title><?= $mode === 'reset' ? 'Сброс пароля' : 'Забыли пароль' ?></title>

    <!-- Preloader - мгновенная загрузка -->
    
</head>
<body class="auth-page password-reset-page">
    <div class="auth-container">
        <h2><?= $mode === 'reset' ? 'Сброс пароля' : 'Забыли пароль' ?></h2>
        
        <?php if ($success): ?>
            <div class="success-message">
                Если такой email существует, мы отправим письмо для сброса пароля.
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                <div class="form-group">
                    <input type="email" name="email" placeholder="Ваш email" required>
                </div>
                <button type="submit" class="btn">Отправить ссылку</button>
            </form>
            <p class="auth-link"><a href="/auth.php">Вернуться к входу</a></p>
        <?php endif; ?>
    </div>
</body>
</html>

