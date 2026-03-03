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

$db = Database::getInstance();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['fonts'])) {
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

$fonts = $input['fonts'];
if (!is_array($fonts)) {
    http_response_code(400);
    echo json_encode(['error' => 'Fonts must be an array']);
    exit;
}

$allowedKeys = ['logo', 'text', 'heading'];
$userId = $_SESSION['user_id'] ?? null;

foreach ($fonts as $key => $value) {
    if (!in_array($key, $allowedKeys)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid font key']);
        exit;
    }
    // Allow null to reset, or validate font string
    if ($value !== null && !preg_match("/^'[^']{1,100}',\s*(serif|sans-serif|monospace|cursive|fantasy)$/", $value)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid font value']);
        exit;
    }
    $db->setSetting("font_$key", json_encode($value), $userId);
}

$_SESSION['app_version'] = time();

echo json_encode(['success' => true]);
?>
