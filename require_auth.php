<?php
// require_auth.php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

// Проверка активной сессии
if (!isset($_SESSION['user_id'])) {
    $_SESSION['auth_error'] = "Для доступа необходимо авторизоваться";
    header("Location: auth.php");
    exit;
}

// Получение данных пользователя (правильный способ для Singleton)
$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);

if (!$user) {
    session_destroy();
    $_SESSION['auth_error'] = "Пользователь не найден";
    header("Location: auth.php");
    exit;
}

if (!$user['is_active']) {
    session_destroy();
    $_SESSION['auth_error'] = "Аккаунт не активирован. Проверьте вашу почту.";
    header("Location: auth.php");
    exit;
}

// Сохраняем данные пользователя в сессии для последующих проверок
$_SESSION['user'] = $user;
$_SESSION['user_role'] = $user['role'];

// Проверка роли (только если $required_role установлен)
if (isset($required_role)) {
    // Определяем разрешенные роли
    $allowed_roles = [$required_role];
    
    if ($required_role === 'admin') {
        $allowed_roles = ['admin', 'owner']; // Владелец имеет права администратора
    } elseif ($required_role === 'owner') {
        $allowed_roles = ['owner'];
    }
    
    // Проверяем, есть ли у пользователя нужная роль
    if (!isset($user['role']) || !in_array($user['role'], $allowed_roles)) {
        $_SESSION['auth_error'] = "У вас нет доступа к этой странице";
        header("Location: account.php");
        exit;
    }
}
?>