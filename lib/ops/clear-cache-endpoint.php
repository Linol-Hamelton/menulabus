<?php
if (!defined('MENU_LABUS_ROOT')) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    session_start([
        'cookie_lifetime' => 7200,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => $isHttps,
    ]);
}

$response = [
    'status' => 'success',
    'timestamp' => time(),
    'scope' => 'client',
    'force_reload' => false,
];

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['force_reload'] = true;
    $response['force_reload'] = true;
}

// Default behavior stays compatible with client update flow.
$scope = isset($_REQUEST['scope']) ? (string) $_REQUEST['scope'] : 'client';
if ($scope !== 'server') {
    echo json_encode($response);
    exit;
}

// Server-wide cache clear is privileged and must be explicit.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Use POST for server cache clear',
    ]);
    exit;
}

$userRole = $_SESSION['user_role'] ?? ($_SESSION['user']['role'] ?? null);
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';

if (empty($_SESSION['user_id']) || !in_array($userRole, ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Forbidden',
    ]);
    exit;
}

if ($csrfHeader === '' || $csrfSession === '' || !hash_equals($csrfSession, $csrfHeader)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid CSRF token',
    ]);
    exit;
}

try {
    require_once MENU_LABUS_ROOT . '/db.php';
    $redisCacheCleared = function_exists('redis_cache_clear') ? (bool) redis_cache_clear() : false;

    echo json_encode([
        'status' => 'success',
        'timestamp' => time(),
        'scope' => 'server',
        'force_reload' => $response['force_reload'],
        'details' => [
            'redis_cache_cleared' => $redisCacheCleared,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to clear server cache',
    ]);
}
