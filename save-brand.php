<?php
$required_role = 'admin';
require_once 'session_init.php';
require_once 'require_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['brand']) || !is_array($input['brand'])) {
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

// Allowed keys and their validation type
$allowedKeys = [
    'app_name'        => 'text',
    'app_tagline'     => 'text',
    'app_description' => 'text',
    'contact_phone'   => 'text',
    'contact_address' => 'text',
    'social_tg'       => 'url',
    'social_vk'       => 'url',
    'logo_url'             => 'path',
    'favicon_url'          => 'path',
    'custom_domain'        => 'domain',
    'hide_labus_branding'  => 'bool',
    'telegram_chat_id'     => 'text',
];

$db     = Database::getInstance();
$userId = $_SESSION['user_id'] ?? null;

foreach ($input['brand'] as $key => $value) {
    if (!array_key_exists($key, $allowedKeys)) {
        http_response_code(400);
        echo json_encode(['error' => "Unknown key: $key"]);
        exit;
    }

    // Sanitize
    $value = strip_tags((string)$value);
    $value = trim($value);

    if (mb_strlen($value) > 200) {
        http_response_code(400);
        echo json_encode(['error' => "Value too long for key: $key"]);
        exit;
    }

    $type = $allowedKeys[$key];
    if ($type === 'url' && $value !== '') {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid URL for key: $key"]);
            exit;
        }
    }
    if ($type === 'path' && $value !== '') {
        if (!preg_match('#^/[a-zA-Z0-9/_.\-]+$#', $value)) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid path for key: $key"]);
            exit;
        }
    }
    if ($type === 'domain' && $value !== '') {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{1,253}$/', $value)) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid domain for key: $key"]);
            exit;
        }
    }
    if ($type === 'bool') {
        $value = ($value === 'true' || $value === '1' || $value === true) ? 'true' : 'false';
    }

    // settings.value column is JSON type — must json_encode before saving
    $db->setSetting($key, json_encode($value), $userId);
}

// Invalidate cache
$_SESSION['app_version'] = time();

echo json_encode(['success' => true]);
?>
