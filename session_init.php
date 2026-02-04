<?php
ob_start();

header_remove('Cache-Control');
header_remove('Expires');
header_remove('Pragma');

// Сначала определяем переменную (ДОБАВЛЕН webmanifest)
$isStaticFile = preg_match('/\.(?:css|js|png|jpg|webp|jpeg|gif|ico|svg|woff|woff2|ttf|json|webmanifest)$/', $_SERVER['REQUEST_URI']);

// Потом обрабатываем статические файлы
if ($isStaticFile) {
    // Для version.json - короткое кэширование
    if (strpos($_SERVER['REQUEST_URI'], 'version.json') !== false) {
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        header("Pragma: no-cache");
    } else {
        // Для остальной статики - длительное кэширование
        header("Cache-Control: public, max-age=31536000, immutable");
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31536000) . " GMT");
        header("Pragma: cache");
    }
    exit;
}

// Проверяем, была ли уже запущена сессия
if (session_status() === PHP_SESSION_NONE) {
    // Конфигурация сессии ДО запуска
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.cookie_httponly', true);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 10); // GC запускается в 10% запросов
    ini_set('session.lazy_write', 1);
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.trans_sid_hosts', '');
    ini_set('session.trans_sid_tags', '');
    ini_set('session.save_handler', 'files');
    ini_set('session.save_path', '/var/www/labus_pro_usr/data/www/menu.pub.labus.pro/data/tmp');

    // По умолчанию: короткая сессия (2 часа) для анонимных пользователей.
    // Для авторизованных — продлим cookie после проверки ниже.
    $defaultLifetime = 7200; // 2 часа
    ini_set('session.cookie_lifetime', $defaultLifetime);
    ini_set('session.gc_maxlifetime', 2592000); // 30 дней — максимум жизни файла сессии

    // Запускаем сессию с безопасными настройками
    session_start([
        'cookie_lifetime' => $defaultLifetime,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict'
    ]);
}

// Принудительное обновление кэша при новой версии
if (session_status() === PHP_SESSION_ACTIVE) {
    // Проверяем версию приложения
    $versionFile = __DIR__ . '/version.json';
    if (file_exists($versionFile)) {
        $versionData = json_decode(file_get_contents($versionFile), true);
        $currentVersion = $versionData['version'] ?? '1.0.0';

        if (empty($_SESSION['app_version']) || $_SESSION['app_version'] !== $currentVersion) {
            // Новая версия - принудительно обновляем кэш
            $_SESSION['app_version'] = $currentVersion;
            $_SESSION['force_no_cache'] = true;

            // Добавляем заголовки против кэширования
            header("Pragma: no-cache");
            header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        }
    }

    // Принудительное обновление по параметру
    if (isset($_GET['forceReload'])) {
        header("Pragma: no-cache");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        $_SESSION['force_reload'] = true;
    }
}

// Принудительное обновление кэша при force_reload
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['force_reload'])) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
    unset($_SESSION['force_reload']);
}

// Генерируем nonce если еще не сгенерирован
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = [
        'script' => base64_encode(random_bytes(16)),
        'style' => base64_encode(random_bytes(16))
    ];
}

$scriptNonce = $_SESSION['csp_nonce']['script'] ?? '';
$styleNonce = $_SESSION['csp_nonce']['style'] ?? '';

// Убедитесь, что nonce передается в переменные PHP
$GLOBALS['scriptNonce'] = $scriptNonce;
$GLOBALS['csrfToken'] = $_SESSION['csrf_token'] ?? '';

// ┌────────────────────────────────────────────────────────────┐
// │ 1. SECURITY HEADERS - Zero-tolerance policy               │
// └────────────────────────────────────────────────────────────┘
$securityHeaders = [
    // Basic security
    'X-Content-Type-Options'       => 'nosniff',
    'X-Frame-Options'              => 'DENY',
    'X-XSS-Protection'             => '1; mode=block',
    'Referrer-Policy'              => 'strict-origin-when-cross-origin',
    'Cross-Origin-Resource-Policy' => 'same-origin',
    'Cross-Origin-Embedder-Policy' => 'require-corp',
    'Cross-Origin-Opener-Policy' => 'same-origin',
    'Access-Control-Allow-Origin' => 'https://menu.pub.labus.pro',
    'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers' => 'Content-Type, X-CSRF-Token',
    'Access-Control-Allow-Credentials' => 'true',

    // Permissions-Policy - разрешаем камеру и геолокацию для текущего домена
    'Permissions-Policy'           => join(', ', [
        'accelerometer=()',
        'autoplay=()',
        'camera=(self "https://menu.pub.labus.pro")',
        'encrypted-media=()',
        'fullscreen=()',
        'geolocation=(self "https://menu.pub.labus.pro")',
        'gyroscope=()',
        'magnetometer=()',
        'microphone=()',
        'midi=()',
        'payment=()',
        'usb=()'
    ]),

    // Content Security Policy - добавляем необходимые разрешения (ОБНОВЛЕНО для PWA)
    'Content-Security-Policy' => join('; ', [
        "default-src 'none'",
        "script-src 'self' 'nonce-$scriptNonce' https://menu.pub.labus.pro",
        "style-src 'self' 'nonce-$styleNonce'",
        "img-src 'self' data: blob:",
        "font-src 'self'",
        "connect-src 'self' https://nominatim.openstreetmap.org",
        "frame-src 'none'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "media-src 'self' blob:",
        "worker-src 'self' blob:",
        "manifest-src 'self'",
    ]),

    // Remove server fingerprinting
    'Server'                       => 'labus',
    'X-Powered-By'                 => '',

    // Cache control for sensitive pages
    'Cache-Control'                => 'no-store, no-cache, must-revalidate, max-age=0',
    'Pragma'                       => 'no-cache',
    'Expires'                      => 'Thu, 01 Jan 1970 00:00:00 GMT'
];

// Apply headers (prevent duplicates)
foreach ($securityHeaders as $key => $value) {
    if (!headers_sent() && !empty($value)) {
        header("$key: $value");
    }
}

// Принудительные заголовки против кэширования для HTML страниц
if (!headers_sent() && !$isStaticFile) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
}

// ┌────────────────────────────────────────────────────────────┐
// │ 3. CSRF & USER SYNC - Automatic                            │
// └────────────────────────────────────────────────────────────┘
// Generate/refresh CSRF token
require_once __DIR__ . '/db.php';

// Инициализация CSRF токена
if (session_status() === PHP_SESSION_ACTIVE) {
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    if (empty($csrfToken)) {
        $csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $csrfToken;
    }
}

// Определение CART_SECRET_KEY если не определен в config.php
if (!defined('CART_SECRET_KEY')) {
    define('CART_SECRET_KEY', 'cart-secret-key');
}

// Sync user data if logged in
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']) && empty($_SESSION['user_last_sync'])) {
    try {
        $db = Database::getInstance();
        $user = $db->getUserById($_SESSION['user_id']);
        if ($user) {
            $_SESSION['user'] = $user;
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_last_sync'] = time();
        } else {
            // User deleted/deactivated - logout
            session_destroy();
            header("Location: auth.php");
            exit;
        }
    } catch (Exception $e) {
        error_log("User sync failed: " . $e->getMessage());
    }
}

// ┌────────────────────────────────────────────────────────────┐
// │ 4. ADDITIONAL SECURITY HARDENING                           │
// └────────────────────────────────────────────────────────────┘
// Remove PHP version exposure
header_remove('X-Powered-By');
// Disable unnecessary features
header('X-Permitted-Cross-Domain-Policies: none');
header('X-DNS-Prefetch-Control: off');

// ┌────────────────────────────────────────────────────────────┐
// │ 5. UTILITY FUNCTIONS                                       │
// └────────────────────────────────────────────────────────────┘
function regenerate_session_id()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function destroy_session_secure()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
    }
}

// ┌────────────────────────────────────────────────────────────┐
// │ 6. CSRF VALIDATION FOR API REQUESTS                        │
// └────────────────────────────────────────────────────────────┘

/**
 * Validate CSRF token for API requests
 */
function validate_csrf_token()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        http_response_code(403);
        echo json_encode(['error' => 'Session not active']);
        exit;
    }

    // For non-GET requests, validate CSRF token
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        // Get token from headers or POST data
        $receivedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ??
            ($_POST['csrf_token'] ?? '');

        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (empty($receivedToken) || empty($sessionToken) || !hash_equals($sessionToken, $receivedToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }
}

/**
 * Get current CSRF token
 */
function get_csrf_token()
{
    return $_SESSION['csrf_token'] ?? '';
}

// Auto-validate CSRF for API requests if it's an API endpoint
$isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/proxy/') !== false ||
    strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

if ($isApiRequest) {
    // Проверка Content-Type для не-GET запросов
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (
            strpos($contentType, 'application/json') === false &&
            strpos($contentType, 'application/x-www-form-urlencoded') === false
        ) {
            http_response_code(415);
            echo json_encode(['error' => 'Unsupported Media Type']);
            exit;
        }
    }

    validate_csrf_token();
}

if (isset($_GET['force_reload']) && session_status() === PHP_SESSION_ACTIVE) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
    $_SESSION['force_reload'] = true;
}

// =================================================================
// ПРОДЛЕНИЕ СЕССИИ ДЛЯ АВТОРИЗОВАННЫХ ПОЛЬЗОВАТЕЛЕЙ
// =================================================================

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
    // Обновляем время последней активности
    $_SESSION['last_activity'] = time();

    // Продлеваем cookie сессии на 30 дней (долгосрочная авторизация — через remember-me)
    $sessionParams = session_get_cookie_params();
    setcookie(
        session_name(),
        session_id(),
        time() + 2592000, // 30 дней
        $sessionParams['path'],
        $sessionParams['domain'],
        $sessionParams['secure'],
        $sessionParams['httponly']
    );

    // Регенерируем ID сессии ТОЛЬКО при подозрительной активности
    // Для зарегистрированных пользователей - одна сессия на все устройства/вкладки
    // Подозрительная активность: смена IP, user-agent или долгая неактивность
    $currentIP = $_SERVER['REMOTE_ADDR'] ?? '';
    $currentUA = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (!isset($_SESSION['security_check'])) {
        $_SESSION['security_check'] = [
            'ip' => $currentIP,
            'ua' => $currentUA,
            'last_check' => time()
        ];
    }

    $suspicious = false;
    if ($_SESSION['security_check']['ip'] !== $currentIP) {
        $suspicious = true;
        error_log("Suspicious activity: IP changed for user {$_SESSION['user_id']}");
    }
    if ($_SESSION['security_check']['ua'] !== $currentUA) {
        $suspicious = true;
        error_log("Suspicious activity: UA changed for user {$_SESSION['user_id']}");
    }

    if ($suspicious) {
        $sessionData = $_SESSION;
        session_regenerate_id(true);
        $_SESSION = $sessionData;
        $_SESSION['security_check']['ip'] = $currentIP;
        $_SESSION['security_check']['ua'] = $currentUA;
        $_SESSION['security_check']['last_check'] = time();
    }
}

// =================================================================
// РЕГЕНЕРАЦИЯ СЕССИИ ДЛЯ НЕЗАРЕГИСТРИРОВАННЫХ ПОЛЬЗОВАТЕЛЕЙ
// =================================================================

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user_id'])) {
    // Для анонимных пользователей регенерируем ID чаще для безопасности
    $regenerateInterval = 1800; // 30 минут
    if (!isset($_SESSION['last_regeneration']) ||
        (time() - $_SESSION['last_regeneration']) > $regenerateInterval) {

        $sessionData = $_SESSION;
        session_regenerate_id(true);
        $_SESSION = $sessionData;
        $_SESSION['last_regeneration'] = time();
    }
}

register_shutdown_function(function() {
    if (isset($GLOBALS['db'])) {
        $GLOBALS['db']->close();
    }
});

ob_end_flush();
?>