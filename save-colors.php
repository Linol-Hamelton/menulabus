<?php
$required_role = 'admin';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['colors'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF mismatch']);
    exit;
}

$colors = $input['colors'];
if (!is_array($colors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Colors must be an array']);
    exit;
}

$db = Database::getInstance();
$userId = (int)($_SESSION['user_id'] ?? 0);

foreach ($colors as $key => $value) {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid color key']);
        exit;
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid color value']);
        exit;
    }
    $db->setSetting("color_$key", json_encode($value), $userId);
}

$_SESSION['app_version'] = time();

echo json_encode(['success' => true]);
