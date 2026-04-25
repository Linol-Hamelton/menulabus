<?php
// session_init.php
// Default behavior is for web pages. For performance-critical paths (API/SSE),
// callers can set a context before including this file:
//   define('LABUS_CTX', 'api');  // API endpoints (/api/v1/*)
//   define('LABUS_CTX', 'sse');  // long-lived SSE/polling endpoints
//
// Context goals:
// - api: do NOT start sessions, do NOT generate CSP nonces/CSRF, do NOT do file I/O.
// - sse: keep auth via session, but keep init minimal (caller should session_write_close()).

require_once __DIR__ . '/tenant_runtime.php';
require_once __DIR__ . '/lib/tenant/launch-contract.php';
// i18n is loaded eagerly so every template can call t() without an extra
// require. Locale resolution itself is lazy and reads from /locales/*.json
// only when t() is first invoked — bundles are cached per request.
require_once __DIR__ . '/lib/I18n.php';

$labusCtx = defined('LABUS_CTX') ? (string)LABUS_CTX : 'web';
if (!in_array($labusCtx, ['web', 'api', 'sse'], true)) {
    $labusCtx = 'web';
}

$requestStart = microtime(true);
$requestId = bin2hex(random_bytes(8));
$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$GLOBALS['request_id'] = $requestId;
$tenantContext = tenant_runtime();
if (!tenant_is_cli() && ($tenantContext['state'] ?? '') !== 'resolved') {
    tenant_runtime_require_resolved();
}
$GLOBALS['tenantContext'] = $tenantContext;
$GLOBALS['tenantMode'] = (string)($tenantContext['mode'] ?? 'provider');
$GLOBALS['isProviderMode'] = !empty($tenantContext['is_provider']);
$GLOBALS['currentHost'] = (string)($tenantContext['current_host'] ?? '');
$GLOBALS['primaryHost'] = (string)($tenantContext['primary_host'] ?? '');
$GLOBALS['baseUrl'] = (string)($tenantContext['base_url'] ?? '');
$GLOBALS['primaryBaseUrl'] = (string)($tenantContext['primary_base_url'] ?? '');

header_remove('Cache-Control');
header_remove('Expires');
header_remove('Pragma');

// Lightweight init for API: no sessions, no CSP, no CSRF, fast OPTIONS.
if ($labusCtx === 'api') {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowedCorsOrigin = tenant_default_allowed_origin();
    if ($origin !== '' && tenant_is_allowed_origin($origin)) {
        $allowedCorsOrigin = $origin;
    }

    if (!headers_sent()) {
        header('X-Request-Id: ' . $requestId);
        header('Vary: Origin', false);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Access-Control-Allow-Origin: ' . $allowedCorsOrigin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization, Idempotency-Key, X-Request-Id');
        header('Access-Control-Allow-Credentials: true');
        header('Cache-Control: no-store');
    }

    // Fast CORS preflight.
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    // No session/csp/csrf for API.
    $GLOBALS['scriptNonce'] = '';
    $GLOBALS['csrfToken'] = '';

    register_shutdown_function(function() use ($requestStart, $requestId, $requestUri, $requestMethod) {
        // Keep existing API perf log behavior.
        if (strpos($requestUri, '/api/') !== false) {
            $durationMs = (microtime(true) - $requestStart) * 1000;
            $statusCode = http_response_code();
            $logLine = sprintf(
                "[%s] request_id=%s method=%s uri=%s status=%d duration_ms=%.2f\n",
                date('Y-m-d H:i:s'),
                $requestId,
                $requestMethod,
                $requestUri,
                (int)$statusCode,
                $durationMs
            );
            $logFile = __DIR__ . '/data/logs/api-performance.log';
            @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        }

        if (isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
            $GLOBALS['db']->close();
        }
    });

    return;
}

// SSE context: keep init minimal, but allow session for auth (caller closes lock).
if ($labusCtx === 'sse') {
    // Avoid output buffering/compression that can break streaming.
    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        // Important: do NOT flush buffers here, flushing can trigger header send
        // and break SSE (EventSource expects Content-Type: text/event-stream).
        @ob_end_clean();
    }

    if (session_status() === PHP_SESSION_NONE) {
        // Prevent PHP's session module from emitting its own cache headers.
        // SSE endpoints will set Cache-Control themselves.
        @session_cache_limiter('');
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_secure', tenant_current_scheme() === 'https');
        ini_set('session.cookie_httponly', true);
        ini_set('session.cookie_samesite', 'Strict');
        tenant_apply_session_cookie_params(7200, 'Strict');
        session_start([
            'cookie_lifetime' => 7200,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict'
        ]);
    }

    if (!headers_sent()) {
        header('X-Request-Id: ' . $requestId);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    // No CSP/CSRF generation here.
    $GLOBALS['scriptNonce'] = '';
    $GLOBALS['csrfToken'] = $_SESSION['csrf_token'] ?? '';

    register_shutdown_function(function() use ($requestStart, $requestId, $requestUri, $requestMethod) {
        if (isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
            $GLOBALS['db']->close();
        }
    });

    return;
}

// Web context (default).
ob_start();

// Static assets bypass the full web-session bootstrap.
$isStaticFile = preg_match('/\.(?:css|js|png|jpg|webp|jpeg|gif|ico|svg|woff|woff2|ttf|json|webmanifest)$/', $_SERVER['REQUEST_URI']);

// Handle static assets separately to allow long-lived cache headers.
if ($isStaticFile) {
    // version.json is always served without cache.
    if (strpos($_SERVER['REQUEST_URI'], 'version.json') !== false) {
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        header("Pragma: no-cache");
    } else {
        // Other assets may use immutable cache headers.
        header("Cache-Control: public, max-age=31536000, immutable");
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31536000) . " GMT");
        header("Pragma: cache");
    }
    exit;
}

// Start the session only once in web context.
if (session_status() === PHP_SESSION_NONE) {
    // Harden session configuration before session_start().
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_secure', tenant_current_scheme() === 'https');
    ini_set('session.cookie_httponly', true);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 1000);
    ini_set('session.lazy_write', 1);
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.trans_sid_hosts', '');
    ini_set('session.trans_sid_tags', '');

// Anonymous sessions use a shorter browser lifetime.
// Long-lived cookie rotation is handled below after session start.
    $defaultLifetime = 7200;
    ini_set('session.cookie_lifetime', $defaultLifetime);
    ini_set('session.gc_maxlifetime', 2592000);

    // Apply secure cookie parameters and start the session.
    tenant_apply_session_cookie_params($defaultLifetime, 'Strict');
    session_start([
        'cookie_lifetime' => $defaultLifetime,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict'
    ]);
}

// Periodically refresh app version state from version.json.
if (session_status() === PHP_SESSION_ACTIVE) {
    // Do not perform the version check on every request.
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
                // On version change, mark the session for cache bypass.
                $_SESSION['app_version'] = $currentVersion;
                $_SESSION['force_no_cache'] = true;

                // Force no-cache headers on the current response.
                header("Pragma: no-cache");
                header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
            }
        }
    }

    // Honor explicit forceReload requests.
    if (isset($_GET['forceReload'])) {
        header("Pragma: no-cache");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        $_SESSION['force_reload'] = true;
    }
}

// Force a no-cache response once after force_reload was set.
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['force_reload'])) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
    unset($_SESSION['force_reload']);
}

// Ensure CSP nonces exist for the active session.
if (session_status() === PHP_SESSION_ACTIVE) {
    if (empty($_SESSION['csp_nonce']['script'])) {
        $_SESSION['csp_nonce']['script'] = base64_encode(random_bytes(16));
    }
    if (empty($_SESSION['csp_nonce']['style'])) {
        $_SESSION['csp_nonce']['style'] = base64_encode(random_bytes(16));
    }
}

$scriptNonce = $_SESSION['csp_nonce']['script'] ?? '';
$styleNonce = $_SESSION['csp_nonce']['style'] ?? '';

// Expose nonce and CSRF values to templates.
$GLOBALS['scriptNonce'] = $scriptNonce;
$GLOBALS['styleNonce']  = $styleNonce;
$GLOBALS['csrfToken'] = $_SESSION['csrf_token'] ?? '';

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$allowedCorsOrigin = tenant_default_allowed_origin();
if ($origin !== '' && tenant_is_allowed_origin($origin)) {
    $allowedCorsOrigin = $origin;
}
$permissionsOrigins = array_values(array_filter(array_unique([
    tenant_base_url(),
    tenant_base_url(true),
])));
$permissionsCamera = $permissionsOrigins === []
    ? 'camera=()'
    : 'camera=(self ' . implode(' ', array_map(static fn(string $value): string => '"' . $value . '"', $permissionsOrigins)) . ')';
$permissionsGeolocation = $permissionsOrigins === []
    ? 'geolocation=()'
    : 'geolocation=(self ' . implode(' ', array_map(static fn(string $value): string => '"' . $value . '"', $permissionsOrigins)) . ')';
$scriptSrcParts = ["'self'", "'nonce-$scriptNonce'"];
foreach ($permissionsOrigins as $permissionsOrigin) {
    $scriptSrcParts[] = $permissionsOrigin;
}
$connectSrcParts = tenant_connect_src_origins();


// 1. SECURITY HEADERS - Zero-tolerance policy

$securityHeaders = [
    // Basic security
    'X-Content-Type-Options'       => 'nosniff',
    'X-Frame-Options'              => 'DENY',
    'X-XSS-Protection'             => '1; mode=block',
    'Referrer-Policy'              => 'strict-origin-when-cross-origin',
    'Cross-Origin-Resource-Policy' => 'same-origin',
    'Cross-Origin-Embedder-Policy' => 'require-corp',
    'Cross-Origin-Opener-Policy' => 'same-origin',
    'Access-Control-Allow-Origin' => $allowedCorsOrigin,
    'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers' => 'Content-Type, X-CSRF-Token, Authorization, Idempotency-Key, X-Request-Id',
    'Access-Control-Allow-Credentials' => 'true',

    // Permissions-Policy: disable browser features we do not use.
    'Permissions-Policy'           => join(', ', [
        'accelerometer=()',
        'autoplay=()',
        $permissionsCamera,
        'encrypted-media=()',
        'fullscreen=()',
        $permissionsGeolocation,
        'gyroscope=()',
        'magnetometer=()',
        'microphone=()',
        'midi=()',
        'payment=()',
        'usb=()'
    ]),

    // Content-Security-Policy for the current tenant origin.
    'Content-Security-Policy' => join('; ', [
        "default-src 'none'",
        'script-src ' . implode(' ', $scriptSrcParts),
        "style-src 'self' 'nonce-$styleNonce'",
        "img-src 'self' data: blob:",
        "font-src 'self'",
        // Allow API calls if the app is served from Capacitor local origin (capacitor://localhost).
        'connect-src ' . implode(' ', $connectSrcParts),
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
if (!headers_sent()) {
    header('X-Request-Id: ' . $requestId);
    header('Vary: Origin', false);
}

// Additional cache-control rules for HTML responses.
/* if (!headers_sent() && !$isStaticFile) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
} */


// 3. CSRF & USER SYNC - Automatic

// Generate/refresh CSRF token
require_once __DIR__ . '/db.php';

// Generate or refresh the CSRF token.
if (session_status() === PHP_SESSION_ACTIVE) {
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    if (empty($csrfToken)) {
        $csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $csrfToken;
    }
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
            header("Location: /auth.php");
            exit;
        }
    } catch (Exception $e) {
        error_log("User sync failed: " . $e->getMessage());
    }
}

// Brand globals (White Label)
if (session_status() === PHP_SESSION_ACTIVE && $labusCtx === 'web') {
    try {
        $db = Database::getInstance();
        // settings.value is a JSON column; decode before use
        $bsGet = static function(string $key, string $default = '') use ($db): string {
            $raw = $db->getSetting($key);
            return $raw !== null ? (string)(json_decode($raw, true) ?? $default) : $default;
        };
        [$contactAddress, $contactMapUrl] = cleanmenu_normalize_brand_contacts(
            $bsGet('contact_address', ''),
            $bsGet('contact_map_url', '')
        );
        $publicEntryMode = cleanmenu_normalize_tenant_public_entry_mode(
            $bsGet('public_entry_mode', ''),
            !empty($GLOBALS['isProviderMode'])
        );
        $GLOBALS['siteName']       = $bsGet('app_name',        'labus');
        $GLOBALS['siteTagline']    = $bsGet('app_tagline', 'Restaurant menu');
        $GLOBALS['siteDesc']       = $bsGet('app_description', '');
        $GLOBALS['contactPhone']   = $bsGet('contact_phone',   '');
        $GLOBALS['contactAddress'] = $contactAddress;
        $GLOBALS['contactMapUrl']  = $contactMapUrl;
        $GLOBALS['logoUrl']        = $bsGet('logo_url',        '');
        $GLOBALS['faviconUrl']     = $bsGet('favicon_url',     '/icons/favicon.ico');
        $GLOBALS['socialTg']           = $bsGet('social_tg',            '');
        $GLOBALS['socialVk']           = $bsGet('social_vk',            '');
        $GLOBALS['hideLabusBranding']  = ($bsGet('hide_labus_branding', 'false') === 'true');
        $GLOBALS['customDomain']       = $bsGet('custom_domain',        '');
        $GLOBALS['publicEntryMode']    = $publicEntryMode;
        $_SESSION['project_name']  = $GLOBALS['siteName'];
    } catch (Exception $e) {
        error_log("Brand globals load failed: " . $e->getMessage());
        $GLOBALS['siteName']           = 'labus';
        $GLOBALS['siteTagline']        = 'Restaurant menu';
        $GLOBALS['siteDesc']           = '';
        $GLOBALS['contactPhone']       = '';
        $GLOBALS['contactAddress']     = '';
        $GLOBALS['contactMapUrl']      = '';
        $GLOBALS['logoUrl']            = '';
        $GLOBALS['faviconUrl']         = '/icons/favicon.ico';
        $GLOBALS['socialTg']           = '';
        $GLOBALS['socialVk']           = '';
        $GLOBALS['hideLabusBranding']  = false;
        $GLOBALS['customDomain']       = '';
        $GLOBALS['publicEntryMode']    = cleanmenu_normalize_tenant_public_entry_mode(null, !empty($GLOBALS['isProviderMode']));
    }
}


// 4. ADDITIONAL SECURITY HARDENING

// Remove PHP version exposure
header_remove('X-Powered-By');
// Disable unnecessary features
header('X-Permitted-Cross-Domain-Policies: none');
header('X-DNS-Prefetch-Control: off');


// 5. UTILITY FUNCTIONS

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


// 6. CSRF VALIDATION FOR API REQUESTS


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
    if ($requestMethod === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    // Reject unsupported content types on non-GET API requests.
    if ($requestMethod !== 'GET') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (
            strpos($contentType, 'application/json') === false &&
            strpos($contentType, 'application/x-www-form-urlencoded') === false &&
            strpos($contentType, 'multipart/form-data') === false
        ) {
            http_response_code(415);
            echo json_encode(['error' => 'Unsupported Media Type']);
            exit;
        }
    }

    $hasBearer = isset($_SERVER['HTTP_AUTHORIZATION']) &&
        stripos((string)$_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0;
    $isMobileAuthEndpoint = strpos($requestUri, '/api/v1/auth/') !== false;
    $isPushSubscribeEndpoint =
        strpos($requestUri, '/api/v1/push/subscribe.php') !== false ||
        strpos($requestUri, '/api/save-push-subscription.php') !== false;
    $requiresCsrf = !$hasBearer && !$isMobileAuthEndpoint && !$isPushSubscribeEndpoint;

    if ($requiresCsrf) {
        validate_csrf_token();
    }
}

if (isset($_GET['force_reload']) && session_status() === PHP_SESSION_ACTIVE) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
    $_SESSION['force_reload'] = true;
}

// =================================================================
// Anonymous sessions: rotate session IDs periodically.
// =================================================================

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
    // Update the last-activity timestamp on a short interval.
    $now = time();
    $activityInterval = 60;
    if (empty($_SESSION['last_activity']) || ($now - $_SESSION['last_activity']) >= $activityInterval) {
        $_SESSION['last_activity'] = $now;
    }

    // Refresh the session cookie periodically for signed-in users.
    $cookieRefreshInterval = 21600; // 6h
    $lastCookieRefresh = $_SESSION['last_cookie_refresh'] ?? 0;
    if (($now - $lastCookieRefresh) >= $cookieRefreshInterval) {
        $sessionParams = session_get_cookie_params();
        setcookie(
            session_name(),
            session_id(),
            tenant_host_only_cookie_options([
                'expires' => $now + 2592000,
                'path' => $sessionParams['path'] ?? '/',
                'secure' => (bool)($sessionParams['secure'] ?? false),
                'httponly' => (bool)($sessionParams['httponly'] ?? true),
                'samesite' => $sessionParams['samesite'] ?? 'Strict',
            ])
        );
        $_SESSION['last_cookie_refresh'] = $now;
    }

    // Regenerate session IDs after suspicious activity.
    // This reduces the impact of fixation across tabs or devices.
    // Suspicious activity includes IP or user-agent changes.
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
// Anonymous session hardening.
// =================================================================

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user_id'])) {
    // Rotate anonymous session IDs on a fixed interval.
    $regenerateInterval = 1800;
    if (!isset($_SESSION['last_regeneration']) ||
        (time() - $_SESSION['last_regeneration']) > $regenerateInterval) {

        $sessionData = $_SESSION;
        session_regenerate_id(true);
        $_SESSION = $sessionData;
        $_SESSION['last_regeneration'] = time();
    }
}

register_shutdown_function(function() use ($requestStart, $requestId, $requestUri, $requestMethod) {
    if (strpos($requestUri, '/api/') !== false) {
        $durationMs = (microtime(true) - $requestStart) * 1000;
        $statusCode = http_response_code();
        $logLine = sprintf(
            "[%s] request_id=%s method=%s uri=%s status=%d duration_ms=%.2f\n",
            date('Y-m-d H:i:s'),
            $requestId,
            $requestMethod,
            $requestUri,
            (int)$statusCode,
            $durationMs
        );
        $logFile = __DIR__ . '/data/logs/api-performance.log';
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    if (isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
        $GLOBALS['db']->close();
    }
});

ob_end_flush();
?>
