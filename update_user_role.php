<?php
// Отключаем редиректы для AJAX-запросов
define('DISABLE_REDIRECTS', true);
// Отключаем вывод любых HTML ошибок
ini_set('display_errors', 0);
ini_set('html_errors', 0);

// Включаем буферизацию для перехвата любого вывода
ob_start();

require_once __DIR__ . '/session_init.php';

// Очищаем буфер на случай любого вывода от session_init.php
ob_end_clean();

// Проверяем сессию вручную, чтобы избежать редиректов
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

require_once __DIR__ . '/db.php';
$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);

if (!$user || !$user['is_active']) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Пользователь не найден или не активен']);
    exit;
}

// Проверяем роль владельца
if ($user['role'] !== 'owner') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Только владелец может изменять роли']);
    exit;
}

header('Content-Type: application/json');

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не разрешён']);
    exit;
}

// Получаем входные данные
$input = json_decode(file_get_contents('php://input'), true);
$userId = (int)($input['user_id'] ?? 0);
$newRole = $input['role'] ?? '';

// Валидация
if ($userId <= 0 || !in_array($newRole, ['customer', 'employee', 'admin', 'owner'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверные параметры']);
    exit;
}

// Проверяем, что текущий пользователь не меняет свою собственную роль (опционально)
if ($userId === $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Нельзя изменить свою собственную роль']);
    exit;
}

// Обновляем роль в базе данных
$success = $db->updateUserRole($userId, $newRole);
if ($success) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка базы данных']);
}
?>