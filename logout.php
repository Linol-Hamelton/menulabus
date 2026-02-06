<?php

// Инициализация сессии с безопасными настройками
require_once __DIR__ . '/session_init.php';

// Подключение к базе данных
require_once __DIR__ . '/db.php';
$db = Database::getInstance();

// 1. Удаляем remember-токен из базы данных и куки
if (isset($_COOKIE['remember'])) {
    try {
        // Разбираем токен на селектор и валидатор
        $tokenParts = explode(':', $_COOKIE['remember']);
        if (count($tokenParts) === 2) {
            $selector = $tokenParts[0];
            
            // Удаляем токен из базы
            $db->deleteRememberToken($selector);
            
            // Удаляем куку (устанавливаем срок в прошлое)
            setcookie(
                'remember', 
                '', 
                time() - 3600, 
                '/', 
                'menu.labus.pro', 
                true,    // HTTPS only
                true     // HTTPOnly
            );
        }
    } catch (Exception $e) {
        error_log('Logout error (remember token): ' . $e->getMessage());
    }
}

// 2. Очищаем все данные сессии
$_SESSION = [];

// 3. Уничтожаем сессию
if (session_status() === PHP_SESSION_ACTIVE) {
    // Удаляем куку сессии
    $sessionParams = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 3600,
        $sessionParams['path'],
        $sessionParams['domain'],
        $sessionParams['secure'],
        $sessionParams['httponly']
    );
    
    session_destroy();
}

// 4. Удаляем CSRF-токен если есть
if (isset($_COOKIE['csrf_token'])) {
    setcookie(
        'csrf_token',
        '',
        time() - 3600,
        '/',
        'menu.labus.pro',
        true,
        true
    );
}

// 5. Перенаправляем на страницу входа
header("Location: auth.php");
exit;
?>