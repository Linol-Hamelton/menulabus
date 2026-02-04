<?php
require_once 'session_init.php';
require_once 'require_auth.php';
$required_role = 'admin';

header('Content-Type: application/json');
error_log("save-colors.php called, method: " . $_SERVER['REQUEST_METHOD']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance();
error_log("DB instance created");

$input = json_decode(file_get_contents('php://input'), true);
error_log("Input: " . print_r($input, true));
if (!$input || !isset($input['colors'])) {
    error_log("Invalid input: " . print_r($input, true));
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$colors = $input['colors'];
if (!is_array($colors)) {
    error_log("Colors not array: " . print_r($colors, true));
    http_response_code(400);
    echo json_encode(['error' => 'Colors must be an array']);
    exit;
}

// Получаем ID пользователя
$userId = $_SESSION['user_id'] ?? null;
error_log("User ID: " . $userId);
error_log("Session: " . print_r($_SESSION, true));

// Сохраняем цвета в базу
foreach ($colors as $key => $value) {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
        error_log("Invalid key: $key");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid color key']);
        exit;
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        error_log("Invalid value: $value");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid color value']);
        exit;
    }
    error_log("Saving color_$key = $value");
    $result = $db->setSetting("color_$key", json_encode($value), $userId);
    error_log("Saved color_$key, result: " . ($result ? 'true' : 'false'));
    if (!$result) {
        error_log("PDO error: " . print_r($db->getConnection()->errorInfo(), true));
    } else {
        error_log("Success for color_$key");
    }
}

// Обновляем версию приложения для инвалидации кэша
$_SESSION['app_version'] = time();

echo json_encode(['success' => true]);
?>