<?php
define('DISABLE_REDIRECTS', true);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

ob_start();
require_once __DIR__ . '/session_init.php';
ob_end_clean();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';
$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);

if (!$user || !$user['is_active']) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Пользователь не найден или не активен'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($user['role'] ?? '') !== 'owner') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Только владелец может изменять роли'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не разрешён'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверный формат запроса'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF mismatch'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)($input['user_id'] ?? 0);
$newRole = $input['role'] ?? '';

if ($userId <= 0 || !in_array($newRole, ['customer', 'employee', 'admin', 'owner'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверные параметры'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($userId === (int)$_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Нельзя изменить свою собственную роль'], JSON_UNESCAPED_UNICODE);
    exit;
}

$success = $db->updateUserRole($userId, $newRole);
if ($success) {
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(500);
echo json_encode(['error' => 'Ошибка базы данных'], JSON_UNESCAPED_UNICODE);
