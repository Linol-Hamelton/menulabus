<?php
ob_start();

header_remove('Cache-Control');
header_remove('Expires');
header_remove('Pragma');

// РЎРЅР°С‡Р°Р»Р° РѕРїСЂРµРґРµР»СЏРµРј РїРµСЂРµРјРµРЅРЅСѓСЋ (Р”РћР‘РђР’Р›Р•Рќ webmanifest)
$isStaticFile = preg_match('/\.(?:css|js|png|jpg|webp|jpeg|gif|ico|svg|woff|woff2|ttf|json|webmanifest)$/', $_SERVER['REQUEST_URI']);

// РџРѕС‚РѕРј РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј СЃС‚Р°С‚РёС‡РµСЃРєРёРµ С„Р°Р№Р»С‹
if ($isStaticFile) {
    // Р”Р»СЏ version.json - РєРѕСЂРѕС‚РєРѕРµ РєСЌС€РёСЂРѕРІР°РЅРёРµ
    if (strpos($_SERVER['REQUEST_URI'], 'version.json') !== false) {
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        header("Pragma: no-cache");
    } else {
        // Р”Р»СЏ РѕСЃС‚Р°Р»СЊРЅРѕР№ СЃС‚Р°С‚РёРєРё - РґР»РёС‚РµР»СЊРЅРѕРµ РєСЌС€РёСЂРѕРІР°РЅРёРµ
        header("Cache-Control: public, max-age=31536000, immutable");
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31536000) . " GMT");
        header("Pragma: cache");
    }
    exit;
}

// РџСЂРѕРІРµСЂСЏРµРј, Р±С‹Р»Р° Р»Рё СѓР¶Рµ Р·Р°РїСѓС‰РµРЅР° СЃРµСЃСЃРёСЏ
if (session_status() === PHP_SESSION_NONE) {
    // РљРѕРЅС„РёРіСѓСЂР°С†РёСЏ СЃРµСЃСЃРёРё Р”Рћ Р·Р°РїСѓСЃРєР°
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.cookie_httponly', true);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 1000); // GC реже, Redis TTL уже чистит сессии
    ini_set('session.lazy_write', 1);
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.trans_sid_hosts', '');
    ini_set('session.trans_sid_tags', '');

    // РџРѕ СѓРјРѕР»С‡Р°РЅРёСЋ: РєРѕСЂРѕС‚РєР°СЏ СЃРµСЃСЃРёСЏ (2 С‡Р°СЃР°) РґР»СЏ Р°РЅРѕРЅРёРјРЅС‹С… РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№.
    // Р”Р»СЏ Р°РІС‚РѕСЂРёР·РѕРІР°РЅРЅС‹С… вЂ” РїСЂРѕРґР»РёРј cookie РїРѕСЃР»Рµ РїСЂРѕРІРµСЂРєРё РЅРёР¶Рµ.
    $defaultLifetime = 7200; // 2 С‡Р°СЃР°
    ini_set('session.cookie_lifetime', $defaultLifetime);
    ini_set('session.gc_maxlifetime', 2592000); // 30 РґРЅРµР№ вЂ” РјР°РєСЃРёРјСѓРј Р¶РёР·РЅРё С„Р°Р№Р»Р° СЃРµСЃСЃРёРё

    // Р—Р°РїСѓСЃРєР°РµРј СЃРµСЃСЃРёСЋ СЃ Р±РµР·РѕРїР°СЃРЅС‹РјРё РЅР°СЃС‚СЂРѕР№РєР°РјРё
    session_start([
        'cookie_lifetime' => $defaultLifetime,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict'
    ]);
}

// РџСЂРёРЅСѓРґРёС‚РµР»СЊРЅРѕРµ РѕР±РЅРѕРІР»РµРЅРёРµ РєСЌС€Р° РїСЂРё РЅРѕРІРѕР№ РІРµСЂСЃРёРё
if (session_status() === PHP_SESSION_ACTIVE) {
    // РџСЂРѕРІРµСЂСЏРµРј РІРµСЂСЃРёСЋ РїСЂРёР»РѕР¶РµРЅРёСЏ (РЅРµ РєР°Р¶РґС‹Р№ Р·Р°РїСЂРѕСЃ)
    $versionCheckInterval = 60;
    $now = time();
    $lastVersionCheck = $_SESSION['version_checked_at'] ?? 0;
    if (($now - $lastVersionCheck) >= $versionCheckInterval) {
        $_SESSION['version_checked_at'] = $now;
        $versionFile = __DIR__ . '/version.json';
        if (file_exists($versionFile)) {
            $versionData = json_decode(file_get_contents($versionFile), true);
            $currentVersion = $versionData['version'] ?? '1.0.0';

            if (empty($_SESSION['app_version']) || $_SESSION['app_version'] !== $currentVersion) {
                // РќРѕРІР°СЏ РІРµСЂСЃРёСЏ - РїСЂРёРЅСѓРґРёС‚РµР»СЊРЅРѕ РѕР±РЅРѕРІР»СЏРµРј РєСЌС€
                $_SESSION['app_version'] = $currentVersion;
                $_SESSION['force_no_cache'] = true;

                // Р”РѕР±Р°РІР»СЏРµРј Р·Р°РіРѕР»РѕРІРєРё РїСЂРѕС‚РёРІ РєСЌС€РёСЂРѕРІР°РЅРёСЏ
                header("Pragma: no-cache");
                header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
            }
        }
    }

    // РџСЂРёРЅСѓРґРёС‚РµР»СЊРЅРѕРµ РѕР±РЅРѕРІР»РµРЅРёРµ РїРѕ РїР°СЂР°РјРµС‚СЂСѓ
    if (isset($_GET['forceReload'])) {
        header("Pragma: no-cache");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        $_SESSION['force_reload'] = true;
    }
}

// РџСЂРёРЅСѓРґРёС‚РµР»СЊРЅРѕРµ РѕР±РЅРѕРІР»РµРЅРёРµ РєСЌС€Р° РїСЂРё force_reload
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['force_reload'])) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
    unset($_SESSION['force_reload']);
}

// Р“РµРЅРµСЂРёСЂСѓРµРј nonce РµСЃР»Рё РµС‰Рµ РЅРµ СЃРіРµРЅРµСЂРёСЂРѕРІР°РЅ
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = [
        'script' => base64_encode(random_bytes(16)),
        'style' => base64_encode(random_bytes(16))
    ];
}

$scriptNonce = $_SESSION['csp_nonce']['script'] ?? '';
$styleNonce = $_SESSION['csp_nonce']['style'] ?? '';

// РЈР±РµРґРёС‚РµСЃСЊ, С‡С‚Рѕ nonce РїРµСЂРµРґР°РµС‚СЃСЏ РІ РїРµСЂРµРјРµРЅРЅС‹Рµ PHP
$GLOBALS['scriptNonce'] = $scriptNonce;
$GLOBALS['csrfToken'] = $_SESSION['csrf_token'] ?? '';

// в”Њв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”ђ
// в”‚ 1. SECURITY HEADERS - Zero-tolerance policy               в”‚
// в””в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”
$securityHeaders = [
    // Basic security
    'X-Content-Type-Options'       => 'nosniff',
    'X-Frame-Options'              => 'DENY',
    'X-XSS-Protection'             => '1; mode=block',
    'Referrer-Policy'              => 'strict-origin-when-cross-origin',
    'Cross-Origin-Resource-Policy' => 'same-origin',
    'Cross-Origin-Embedder-Policy' => 'require-corp',
    'Cross-Origin-Opener-Policy' => 'same-origin',
    'Access-Control-Allow-Origin' => 'https://menu.labus.pro',
    'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers' => 'Content-Type, X-CSRF-Token',
    'Access-Control-Allow-Credentials' => 'true',

    // Permissions-Policy - СЂР°Р·СЂРµС€Р°РµРј РєР°РјРµСЂСѓ Рё РіРµРѕР»РѕРєР°С†РёСЋ РґР»СЏ С‚РµРєСѓС‰РµРіРѕ РґРѕРјРµРЅР°
    'Permissions-Policy'           => join(', ', [
        'accelerometer=()',
        'autoplay=()',
        'camera=(self "https://menu.labus.pro")',
        'encrypted-media=()',
        'fullscreen=()',
        'geolocation=(self "https://menu.labus.pro")',
        'gyroscope=()',
        'magnetometer=()',
        'microphone=()',
        'midi=()',
        'payment=()',
        'usb=()'
    ]),

    // Content Security Policy - РґРѕР±Р°РІР»СЏРµРј РЅРµРѕР±С…РѕРґРёРјС‹Рµ СЂР°Р·СЂРµС€РµРЅРёСЏ (РћР‘РќРћР’Р›Р•РќРћ РґР»СЏ PWA)
    'Content-Security-Policy' => join('; ', [
        "default-src 'none'",
        "script-src 'self' 'nonce-$scriptNonce' https://menu.labus.pro",
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

// РџСЂРёРЅСѓРґРёС‚РµР»СЊРЅС‹Рµ Р·Р°РіРѕР»РѕРІРєРё РїСЂРѕС‚РёРІ РєСЌС€РёСЂРѕРІР°РЅРёСЏ РґР»СЏ HTML СЃС‚СЂР°РЅРёС†
/* if (!headers_sent() && !$isStaticFile) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
} */

// в”Њв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”ђ
// в”‚ 3. CSRF & USER SYNC - Automatic                            в”‚
// в””в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”
// Generate/refresh CSRF token
require_once __DIR__ . '/db.php';

// РРЅРёС†РёР°Р»РёР·Р°С†РёСЏ CSRF С‚РѕРєРµРЅР°
if (session_status() === PHP_SESSION_ACTIVE) {
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    if (empty($csrfToken)) {
        $csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $csrfToken;
    }
}

// РљРѕРЅСЃС‚Р°РЅС‚С‹ РґР»СЏ QueryCache
if (!defined('QUERY_CACHE_MEMORY_LIMIT')) {
    define('QUERY_CACHE_MEMORY_LIMIT', 10 * 1024 * 1024); // 10MB
}
if (!defined('QUERY_CACHE_DEFAULT_TTL')) {
    define('QUERY_CACHE_DEFAULT_TTL', 300); // 5 РјРёРЅСѓС‚
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

// в”Њв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”ђ
// в”‚ 4. ADDITIONAL SECURITY HARDENING                           в”‚
// в””в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”
// Remove PHP version exposure
header_remove('X-Powered-By');
// Disable unnecessary features
header('X-Permitted-Cross-Domain-Policies: none');
header('X-DNS-Prefetch-Control: off');

// в”Њв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”ђ
// в”‚ 5. UTILITY FUNCTIONS                                       в”‚
// в””в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”
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

// в”Њв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”ђ
// в”‚ 6. CSRF VALIDATION FOR API REQUESTS                        в”‚
// в””в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”

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
    // РџСЂРѕРІРµСЂРєР° Content-Type РґР»СЏ РЅРµ-GET Р·Р°РїСЂРѕСЃРѕРІ
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
// РџР РћР”Р›Р•РќРР• РЎР•РЎРЎРР Р”Р›РЇ РђР’РўРћР РР—РћР’РђРќРќР«РҐ РџРћР›Р¬Р—РћР’РђРўР•Р›Р•Р™
// =================================================================

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
    // РћР±РЅРѕРІР»СЏРµРј РІСЂРµРјСЏ РїРѕСЃР»РµРґРЅРµР№ Р°РєС‚РёРІРЅРѕСЃС‚Рё
    $now = time();
    $activityInterval = 60;
    if (empty($_SESSION['last_activity']) || ($now - $_SESSION['last_activity']) >= $activityInterval) {
        $_SESSION['last_activity'] = $now;
    }

    // РџСЂРѕРґР»РµРІР°РµРј cookie СЃРµСЃСЃРёРё РЅРµ С‡Р°С‰Рµ 1 СЂР°Р·Р° РІ 6 С‡Р°СЃРѕРІ
    $cookieRefreshInterval = 21600; // 6h
    $lastCookieRefresh = $_SESSION['last_cookie_refresh'] ?? 0;
    if (($now - $lastCookieRefresh) >= $cookieRefreshInterval) {
        $sessionParams = session_get_cookie_params();
        setcookie(
            session_name(),
            session_id(),
            $now + 2592000, // 30 РґРЅРµР№
            $sessionParams['path'],
            $sessionParams['domain'],
            $sessionParams['secure'],
            $sessionParams['httponly']
        );
        $_SESSION['last_cookie_refresh'] = $now;
    }

    // Р РµРіРµРЅРµСЂРёСЂСѓРµРј ID СЃРµСЃСЃРёРё РўРћР›Р¬РљРћ РїСЂРё РїРѕРґРѕР·СЂРёС‚РµР»СЊРЅРѕР№ Р°РєС‚РёРІРЅРѕСЃС‚Рё
    // Р”Р»СЏ Р·Р°СЂРµРіРёСЃС‚СЂРёСЂРѕРІР°РЅРЅС‹С… РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№ - РѕРґРЅР° СЃРµСЃСЃРёСЏ РЅР° РІСЃРµ СѓСЃС‚СЂРѕР№СЃС‚РІР°/РІРєР»Р°РґРєРё
    // РџРѕРґРѕР·СЂРёС‚РµР»СЊРЅР°СЏ Р°РєС‚РёРІРЅРѕСЃС‚СЊ: СЃРјРµРЅР° IP, user-agent РёР»Рё РґРѕР»РіР°СЏ РЅРµР°РєС‚РёРІРЅРѕСЃС‚СЊ
    $currentIP = $_SERVER['REMOTE_ADDR'] ?? '';
    $currentUA = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $securityCheckInterval = 300; // 5 min
    $lastSecurityCheck = $_SESSION['security_check']['last_check'] ?? 0;

    if (!isset($_SESSION['security_check']) || ($now - $lastSecurityCheck) >= $securityCheckInterval) {
        if (!isset($_SESSION['security_check'])) {
            $_SESSION['security_check'] = [
                'ip' => $currentIP,
                'ua' => $currentUA,
                'last_check' => $now
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
        }

        $_SESSION['security_check']['ip'] = $currentIP;
        $_SESSION['security_check']['ua'] = $currentUA;
        $_SESSION['security_check']['last_check'] = $now;
    }
}

// =================================================================
// Р Р•Р“Р•РќР•Р РђР¦РРЇ РЎР•РЎРЎРР Р”Р›РЇ РќР•Р—РђР Р•Р“РРЎРўР РР РћР’РђРќРќР«РҐ РџРћР›Р¬Р—РћР’РђРўР•Р›Р•Р™
// =================================================================

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user_id'])) {
    // Р”Р»СЏ Р°РЅРѕРЅРёРјРЅС‹С… РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№ СЂРµРіРµРЅРµСЂРёСЂСѓРµРј ID С‡Р°С‰Рµ РґР»СЏ Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё
    $regenerateInterval = 1800; // 30 РјРёРЅСѓС‚
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
