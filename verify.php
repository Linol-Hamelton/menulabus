<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$db = Database::getInstance();

// Проверяем, что токен не пустой
if (empty($token)) {
    $_SESSION['auth_error'] = "Отсутствует токен подтверждения.";
    header("Location: auth.php");
    exit;
}

try {
    // Пытаемся верифицировать пользователя
    $verificationResult = $db->verifyUser($token);
    
    switch ($verificationResult) {
        case 'success':
            $_SESSION['auth_message'] = "Email успешно подтвержден! Теперь вы можете войти.";
            error_log("User verified successfully with token: $token");
            break;
            
        case 'already_verified':
            $_SESSION['auth_message'] = "Ваш email уже был подтвержден ранее.";
            break;
            
        case 'invalid_token':
            $_SESSION['auth_error'] = "Неверная или устаревшая ссылка подтверждения. Запросите новую ссылку.";
            error_log("Invalid verification token: $token");
            break;
            
        default:
            $_SESSION['auth_error'] = "Произошла ошибка при обработке вашего запроса. Пожалуйста, попробуйте позже.";
            error_log("Verification error for token: $token");
    }
} catch (Exception $e) {
    // Обработка ошибок БД
    error_log("Database error during verification: " . $e->getMessage());
    $_SESSION['auth_error'] = "Произошла ошибка при обработке вашего запроса. Пожалуйста, попробуйте позже.";
}

// Явно сохраняем сессию перед редиректом
session_write_close();

// Редирект с кодом 303 See Other для POST-GET редиректа
header("Location: auth.php", true, 303);
exit;
?>