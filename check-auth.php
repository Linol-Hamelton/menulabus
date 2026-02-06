<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

$response = ['isLoggedIn' => false];
$db = Database::getInstance();

try {
    if (isset($_SESSION['user_id'])) {
        // Проверяем актуальность сессии
        $user = $db->getUserById($_SESSION['user_id']);
        
        if ($user && $user['is_active']) {
            $response['isLoggedIn'] = true;
            $response['user'] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'phone' => $user['phone'] ?? null,
                'role' => $user['role']
            ];
            
            // Обновляем время последней активности
            $_SESSION['last_activity'] = time();
        } else {
            // Пользователь не найден или неактивен - очищаем сессию
            session_destroy();
            if (isset($_COOKIE['remember'])) {
                setcookie('remember', '', time() - 3600, '/', 'menu.labus.pro', true, true);
            }
        }
    } 
    // Если нет сессии, но есть remember-кука
    elseif (isset($_COOKIE['remember'])) {
        $cookieParts = explode(':', $_COOKIE['remember']);
        if (count($cookieParts) === 2) {
            $selector = $cookieParts[0];
            $validator = $cookieParts[1];
            
            // Ищем токен в БД
            $token = $db->getRememberToken($selector);
            
            if ($token && password_verify($validator, $token['hashed_validator'])) {
                // Проверяем срок действия токена
                if ($token['expires_at'] > time()) {
                    // Получаем данные пользователя
                    $user = $db->getUserById($token['user_id']);
                    
                    if ($user && $user['is_active']) {
                        // Сохраняем данные сессии перед регенерацией
                        $sessionData = $_SESSION;
                        
                        // Создаем новую сессию с безопасной регенерацией
                        session_regenerate_id(true);
                        
                        // Восстанавливаем данные пользователя
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
                            time() + 2592000,
                            $sessionParams['path'],
                            $sessionParams['domain'],
                            $sessionParams['secure'],
                            $sessionParams['httponly']
                        );

                        // Обновляем токен (ротация)
                        $new_validator = bin2hex(random_bytes(16));
                        $new_hashed_validator = password_hash($new_validator, PASSWORD_DEFAULT);
                        $new_expires = time() + 2592000; // 30 дней
                        
                        $db->updateRememberToken($selector, $new_hashed_validator, $new_expires);
                        
                        setcookie(
                            'remember',
                            $selector.':'.$new_validator,
                            $new_expires,
                            '/',
                            'menu.labus.pro',  // Исправляем домен
                            true,
                            true
                        );
                        
                        $response['isLoggedIn'] = true;
                        $response['user'] = [
                            'id' => $user['id'],
                            'email' => $user['email'],
                            'name' => $user['name'],
                            'phone' => $user['phone'] ?? null,
                            'role' => $user['role']
                        ];
                    }
                } else {
                    // Токен просрочен - удаляем
                    $db->deleteRememberToken($selector);
                    setcookie('remember', '', time() - 3600, '/', 'menu.labus.pro', true, true);
                }
            } else {
                // Невалидный токен - удаляем
                setcookie('remember', '', time() - 3600, '/', 'menu.labus.pro', true, true);
            }
        }
    }
} catch (Exception $e) {
    error_log('Check auth error: ' . $e->getMessage());
    $response['error'] = 'server_error';
    
    if (ini_get('display_errors')) {
        $response['debug'] = $e->getMessage();
    }
}

// Отправляем ответ
header('Content-Type: application/json');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;