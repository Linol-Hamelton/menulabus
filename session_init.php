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

// Р РЋР Р…Р В°РЎвЂЎР В°Р В»Р В° Р С•Р С—РЎР‚Р ВµР Т‘Р ВµР В»РЎРЏР ВµР С Р С—Р ВµРЎР‚Р ВµР СР ВµР Р…Р Р…РЎС“РЎР‹ (Р вЂќР С›Р вЂР С’Р вЂ™Р вЂєР вЂўР Сњ webmanifest)
$isStaticFile = preg_match('/\.(?:css|js|png|jpg|webp|jpeg|gif|ico|svg|woff|woff2|ttf|json|webmanifest)$/', $_SERVER['REQUEST_URI']);

// Р СџР С•РЎвЂљР С•Р С Р С•Р В±РЎР‚Р В°Р В±Р В°РЎвЂљРЎвЂ№Р Р†Р В°Р ВµР С РЎРѓРЎвЂљР В°РЎвЂљР С‘РЎвЂЎР ВµРЎРѓР С”Р С‘Р Вµ РЎвЂћР В°Р в„–Р В»РЎвЂ№
if ($isStaticFile) {
    // Р вЂќР В»РЎРЏ version.json - Р С”Р С•РЎР‚Р С•РЎвЂљР С”Р С•Р Вµ Р С”РЎРЊРЎв‚¬Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘Р Вµ
    if (strpos($_SERVER['REQUEST_URI'], 'version.json') !== false) {
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        header("Pragma: no-cache");
    } else {
        // Р вЂќР В»РЎРЏ Р С•РЎРѓРЎвЂљР В°Р В»РЎРЉР Р…Р С•Р в„– РЎРѓРЎвЂљР В°РЎвЂљР С‘Р С”Р С‘ - Р Т‘Р В»Р С‘РЎвЂљР ВµР В»РЎРЉР Р…Р С•Р Вµ Р С”РЎРЊРЎв‚¬Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘Р Вµ
        header("Cache-Control: public, max-age=31536000, immutable");
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31536000) . " GMT");
        header("Pragma: cache");
    }
    exit;
}

// Р СџРЎР‚Р С•Р Р†Р ВµРЎР‚РЎРЏР ВµР С, Р В±РЎвЂ№Р В»Р В° Р В»Р С‘ РЎС“Р В¶Р Вµ Р В·Р В°Р С—РЎС“РЎвЂ°Р ВµР Р…Р В° РЎРѓР ВµРЎРѓРЎРѓР С‘РЎРЏ
if (session_status() === PHP_SESSION_NONE) {
    // Р С™Р С•Р Р…РЎвЂћР С‘Р С–РЎС“РЎР‚Р В°РЎвЂ Р С‘РЎРЏ РЎРѓР ВµРЎРѓРЎРѓР С‘Р С‘ Р вЂќР С› Р В·Р В°Р С—РЎС“РЎРѓР С”Р В°
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_secure', tenant_current_scheme() === 'https');
    ini_set('session.cookie_httponly', true);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 1000); // GC СЂРµР¶Рµ, Redis TTL СѓР¶Рµ С‡РёСЃС‚РёС‚ СЃРµСЃСЃРёРё
    ini_set('session.lazy_write', 1);
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.trans_sid_hosts', '');
    ini_set('session.trans_sid_tags', '');

    // Р СџР С• РЎС“Р СР С•Р В»РЎвЂЎР В°Р Р…Р С‘РЎР‹: Р С”Р С•РЎР‚Р С•РЎвЂљР С”Р В°РЎРЏ РЎРѓР ВµРЎРѓРЎРѓР С‘РЎРЏ (2 РЎвЂЎР В°РЎРѓР В°) Р Т‘Р В»РЎРЏ Р В°Р Р…Р С•Р Р…Р С‘Р СР Р…РЎвЂ№РЎвЂ¦ Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»Р ВµР в„–.
    // Р вЂќР В»РЎРЏ Р В°Р Р†РЎвЂљР С•РЎР‚Р С‘Р В·Р С•Р Р†Р В°Р Р…Р Р…РЎвЂ№РЎвЂ¦ РІР‚вЂќ Р С—РЎР‚Р С•Р Т‘Р В»Р С‘Р С cookie Р С—Р С•РЎРѓР В»Р Вµ Р С—РЎР‚Р С•Р Р†Р ВµРЎР‚Р С”Р С‘ Р Р…Р С‘Р В¶Р Вµ.
    $defaultLifetime = 7200; // 2 РЎвЂЎР В°РЎРѓР В°
    ini_set('session.cookie_lifetime', $defaultLifetime);
    ini_set('session.gc_maxlifetime', 2592000); // 30 Р Т‘Р Р…Р ВµР в„– РІР‚вЂќ Р СР В°Р С”РЎРѓР С‘Р СРЎС“Р С Р В¶Р С‘Р В·Р Р…Р С‘ РЎвЂћР В°Р в„–Р В»Р В° РЎРѓР ВµРЎРѓРЎРѓР С‘Р С‘

    // Р вЂ”Р В°Р С—РЎС“РЎРѓР С”Р В°Р ВµР С РЎРѓР ВµРЎРѓРЎРѓР С‘РЎР‹ РЎРѓ Р В±Р ВµР В·Р С•Р С—Р В°РЎРѓР Р…РЎвЂ№Р СР С‘ Р Р…Р В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р В°Р СР С‘
    tenant_apply_session_cookie_params($defaultLifetime, 'Strict');
    session_start([
        'cookie_lifetime' => $defaultLifetime,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict'
    ]);
}

// Р СџРЎР‚Р С‘Р Р…РЎС“Р Т‘Р С‘РЎвЂљР ВµР В»РЎРЉР Р…Р С•Р Вµ Р С•Р В±Р Р…Р С•Р Р†Р В»Р ВµР Р…Р С‘Р Вµ Р С”РЎРЊРЎв‚¬Р В° Р С—РЎР‚Р С‘ Р Р…Р С•Р Р†Р С•Р в„– Р Р†Р ВµРЎР‚РЎРѓР С‘Р С‘
if (session_status() === PHP_SESSION_ACTIVE) {
    // Р СџРЎР‚Р С•Р Р†Р ВµРЎР‚РЎРЏР ВµР С Р Р†Р ВµРЎР‚РЎРѓР С‘РЎР‹ Р С—РЎР‚Р С‘Р В»Р С•Р В¶Р ВµР Р…Р С‘РЎРЏ (Р Р…Р Вµ Р С”Р В°Р В¶Р Т‘РЎвЂ№Р в„– Р В·Р В°Р С—РЎР‚Р С•РЎРѓ)
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
                // Р СњР С•Р Р†Р В°РЎРЏ Р Р†Р ВµРЎР‚РЎРѓР С‘РЎРЏ - Р С—РЎР‚Р С‘Р Р…РЎС“Р Т‘Р С‘РЎвЂљР ВµР В»РЎРЉР Р…Р С• Р С•Р В±Р Р…Р С•Р Р†Р В»РЎРЏР ВµР С Р С”РЎРЊРЎв‚¬
                $_SESSION['app_version'] = $currentVersion;
                $_SESSION['force_no_cache'] = true;

                // Р вЂќР С•Р В±Р В°Р Р†Р В»РЎРЏР ВµР С Р В·Р В°Р С–Р С•Р В»Р С•Р Р†Р С”Р С‘ Р С—РЎР‚Р С•РЎвЂљР С‘Р Р† Р С”РЎРЊРЎв‚¬Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ
                header("Pragma: no-cache");
                header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
            }
        }
    }

    // Р СџРЎР‚Р С‘Р Р…РЎС“Р Т‘Р С‘РЎвЂљР ВµР В»РЎРЉР Р…Р С•Р Вµ Р С•Р В±Р Р…Р С•Р Р†Р В»Р ВµР Р…Р С‘Р Вµ Р С—Р С• Р С—Р В°РЎР‚Р В°Р СР ВµРЎвЂљРЎР‚РЎС“
    if (isset($_GET['forceReload'])) {
        header("Pragma: no-cache");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        $_SESSION['force_reload'] = true;
    }
}

// Р СџРЎР‚Р С‘Р Р…РЎС“Р Т‘Р С‘РЎвЂљР ВµР В»РЎРЉР Р…Р С•Р Вµ Р С•Р В±Р Р…Р С•Р Р†Р В»Р ВµР Р…Р С‘Р Вµ Р С”РЎРЊРЎв‚¬Р В° Р С—РЎР‚Р С‘ force_reload
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['force_reload'])) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
    unset($_SESSION['force_reload']);
}

// Р вЂњР ВµР Р…Р ВµРЎР‚Р С‘РЎР‚РЎС“Р ВµР С nonce Р ВµРЎРѓР В»Р С‘ Р ВµРЎвЂ°Р Вµ Р Р…Р Вµ РЎРѓР С–Р ВµР Р…Р ВµРЎР‚Р С‘РЎР‚Р С•Р Р†Р В°Р Р…
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

// Р Р€Р В±Р ВµР Т‘Р С‘РЎвЂљР ВµРЎРѓРЎРЉ, РЎвЂЎРЎвЂљР С• nonce Р С—Р ВµРЎР‚Р ВµР Т‘Р В°Р ВµРЎвЂљРЎРѓРЎРЏ Р Р† Р С—Р ВµРЎР‚Р ВµР СР ВµР Р…Р Р…РЎвЂ№Р Вµ PHP
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

// РІвЂќРЉРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќС’
// РІвЂќвЂљ 1. SECURITY HEADERS - Zero-tolerance policy               РІвЂќвЂљ
// РІвЂќвЂќРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќВ
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

    // Permissions-Policy - РЎР‚Р В°Р В·РЎР‚Р ВµРЎв‚¬Р В°Р ВµР С Р С”Р В°Р СР ВµРЎР‚РЎС“ Р С‘ Р С–Р ВµР С•Р В»Р С•Р С”Р В°РЎвЂ Р С‘РЎР‹ Р Т‘Р В»РЎРЏ РЎвЂљР ВµР С”РЎС“РЎвЂ°Р ВµР С–Р С• Р Т‘Р С•Р СР ВµР Р…Р В°
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

    // Content Security Policy - Р Т‘Р С•Р В±Р В°Р Р†Р В»РЎРЏР ВµР С Р Р…Р ВµР С•Р В±РЎвЂ¦Р С•Р Т‘Р С‘Р СРЎвЂ№Р Вµ РЎР‚Р В°Р В·РЎР‚Р ВµРЎв‚¬Р ВµР Р…Р С‘РЎРЏ (Р С›Р вЂР СњР С›Р вЂ™Р вЂєР вЂўР СњР С› Р Т‘Р В»РЎРЏ PWA)
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

// Р СџРЎР‚Р С‘Р Р…РЎС“Р Т‘Р С‘РЎвЂљР ВµР В»РЎРЉР Р…РЎвЂ№Р Вµ Р В·Р В°Р С–Р С•Р В»Р С•Р Р†Р С”Р С‘ Р С—РЎР‚Р С•РЎвЂљР С‘Р Р† Р С”РЎРЊРЎв‚¬Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ Р Т‘Р В»РЎРЏ HTML РЎРѓРЎвЂљРЎР‚Р В°Р Р…Р С‘РЎвЂ 
/* if (!headers_sent() && !$isStaticFile) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
} */

// РІвЂќРЉРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќС’
// РІвЂќвЂљ 3. CSRF & USER SYNC - Automatic                            РІвЂќвЂљ
// РІвЂќвЂќРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќВ
// Generate/refresh CSRF token
require_once __DIR__ . '/db.php';

// Р ВР Р…Р С‘РЎвЂ Р С‘Р В°Р В»Р С‘Р В·Р В°РЎвЂ Р С‘РЎРЏ CSRF РЎвЂљР С•Р С”Р ВµР Р…Р В°
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

// в”Ђв”Ђ Brand globals (White Label) в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
if (session_status() === PHP_SESSION_ACTIVE && $labusCtx === 'web') {
    try {
        $db = Database::getInstance();
        // settings.value is a JSON column; decode before use
        $bsGet = static function(string $key, string $default = '') use ($db): string {
            $raw = $db->getSetting($key);
            return $raw !== null ? (string)(json_decode($raw, true) ?? $default) : $default;
        };
        $GLOBALS['siteName']       = $bsGet('app_name',        'labus');
        $GLOBALS['siteTagline']    = $bsGet('app_tagline',     'Меню ресторана');
        $GLOBALS['siteDesc']       = $bsGet('app_description', '');
        $GLOBALS['contactPhone']   = $bsGet('contact_phone',   '');
        $GLOBALS['contactAddress'] = $bsGet('contact_address', '');
        $GLOBALS['logoUrl']        = $bsGet('logo_url',        '');
        $GLOBALS['faviconUrl']     = $bsGet('favicon_url',     '/icons/favicon.ico');
        $GLOBALS['socialTg']           = $bsGet('social_tg',            '');
        $GLOBALS['socialVk']           = $bsGet('social_vk',            '');
        $GLOBALS['hideLabusBranding']  = ($bsGet('hide_labus_branding', 'false') === 'true');
        $GLOBALS['customDomain']       = $bsGet('custom_domain',        '');
        $_SESSION['project_name']  = $GLOBALS['siteName'];
    } catch (Exception $e) {
        error_log("Brand globals load failed: " . $e->getMessage());
        $GLOBALS['siteName']           = 'labus';
        $GLOBALS['siteTagline']        = 'Меню ресторана';
        $GLOBALS['siteDesc']           = '';
        $GLOBALS['contactPhone']       = '';
        $GLOBALS['contactAddress']     = '';
        $GLOBALS['logoUrl']            = '';
        $GLOBALS['faviconUrl']         = '/icons/favicon.ico';
        $GLOBALS['socialTg']           = '';
        $GLOBALS['socialVk']           = '';
        $GLOBALS['hideLabusBranding']  = false;
        $GLOBALS['customDomain']       = '';
    }
}

// РІ"РЉРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќС’
// РІвЂќвЂљ 4. ADDITIONAL SECURITY HARDENING                           РІвЂќвЂљ
// РІвЂќвЂќРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќВ
// Remove PHP version exposure
header_remove('X-Powered-By');
// Disable unnecessary features
header('X-Permitted-Cross-Domain-Policies: none');
header('X-DNS-Prefetch-Control: off');

// РІвЂќРЉРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќС’
// РІвЂќвЂљ 5. UTILITY FUNCTIONS                                       РІвЂќвЂљ
// РІвЂќвЂќРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќВ
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

// РІвЂќРЉРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќС’
// РІвЂќвЂљ 6. CSRF VALIDATION FOR API REQUESTS                        РІвЂќвЂљ
// РІвЂќвЂќРІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќР‚РІвЂќВ

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

    // Р СџРЎР‚Р С•Р Р†Р ВµРЎР‚Р С”Р В° Content-Type Р Т‘Р В»РЎРЏ Р Р…Р Вµ-GET Р В·Р В°Р С—РЎР‚Р С•РЎРѓР С•Р Р†
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
// Р СџР В Р С›Р вЂќР вЂєР вЂўР СњР ВР вЂў Р РЋР вЂўР РЋР РЋР ВР В Р вЂќР вЂєР Р‡ Р С’Р вЂ™Р СћР С›Р В Р ВР вЂ”Р С›Р вЂ™Р С’Р СњР СњР В«Р Тђ Р СџР С›Р вЂєР В¬Р вЂ”Р С›Р вЂ™Р С’Р СћР вЂўР вЂєР вЂўР в„ў
// =================================================================

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
    // Р С›Р В±Р Р…Р С•Р Р†Р В»РЎРЏР ВµР С Р Р†РЎР‚Р ВµР СРЎРЏ Р С—Р С•РЎРѓР В»Р ВµР Т‘Р Р…Р ВµР в„– Р В°Р С”РЎвЂљР С‘Р Р†Р Р…Р С•РЎРѓРЎвЂљР С‘
    $now = time();
    $activityInterval = 60;
    if (empty($_SESSION['last_activity']) || ($now - $_SESSION['last_activity']) >= $activityInterval) {
        $_SESSION['last_activity'] = $now;
    }

    // Р СџРЎР‚Р С•Р Т‘Р В»Р ВµР Р†Р В°Р ВµР С cookie РЎРѓР ВµРЎРѓРЎРѓР С‘Р С‘ Р Р…Р Вµ РЎвЂЎР В°РЎвЂ°Р Вµ 1 РЎР‚Р В°Р В·Р В° Р Р† 6 РЎвЂЎР В°РЎРѓР С•Р Р†
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

    // Р В Р ВµР С–Р ВµР Р…Р ВµРЎР‚Р С‘РЎР‚РЎС“Р ВµР С ID РЎРѓР ВµРЎРѓРЎРѓР С‘Р С‘ Р СћР С›Р вЂєР В¬Р С™Р С› Р С—РЎР‚Р С‘ Р С—Р С•Р Т‘Р С•Р В·РЎР‚Р С‘РЎвЂљР ВµР В»РЎРЉР Р…Р С•Р в„– Р В°Р С”РЎвЂљР С‘Р Р†Р Р…Р С•РЎРѓРЎвЂљР С‘
    // Р вЂќР В»РЎРЏ Р В·Р В°РЎР‚Р ВµР С–Р С‘РЎРѓРЎвЂљРЎР‚Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р Р…РЎвЂ№РЎвЂ¦ Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»Р ВµР в„– - Р С•Р Т‘Р Р…Р В° РЎРѓР ВµРЎРѓРЎРѓР С‘РЎРЏ Р Р…Р В° Р Р†РЎРѓР Вµ РЎС“РЎРѓРЎвЂљРЎР‚Р С•Р в„–РЎРѓРЎвЂљР Р†Р В°/Р Р†Р С”Р В»Р В°Р Т‘Р С”Р С‘
    // Р СџР С•Р Т‘Р С•Р В·РЎР‚Р С‘РЎвЂљР ВµР В»РЎРЉР Р…Р В°РЎРЏ Р В°Р С”РЎвЂљР С‘Р Р†Р Р…Р С•РЎРѓРЎвЂљРЎРЉ: РЎРѓР СР ВµР Р…Р В° IP, user-agent Р С‘Р В»Р С‘ Р Т‘Р С•Р В»Р С–Р В°РЎРЏ Р Р…Р ВµР В°Р С”РЎвЂљР С‘Р Р†Р Р…Р С•РЎРѓРЎвЂљРЎРЉ
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
// Р В Р вЂўР вЂњР вЂўР СњР вЂўР В Р С’Р В¦Р ВР Р‡ Р РЋР вЂўР РЋР РЋР ВР В Р вЂќР вЂєР Р‡ Р СњР вЂўР вЂ”Р С’Р В Р вЂўР вЂњР ВР РЋР СћР В Р ВР В Р С›Р вЂ™Р С’Р СњР СњР В«Р Тђ Р СџР С›Р вЂєР В¬Р вЂ”Р С›Р вЂ™Р С’Р СћР вЂўР вЂєР вЂўР в„ў
// =================================================================

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user_id'])) {
    // Р вЂќР В»РЎРЏ Р В°Р Р…Р С•Р Р…Р С‘Р СР Р…РЎвЂ№РЎвЂ¦ Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»Р ВµР в„– РЎР‚Р ВµР С–Р ВµР Р…Р ВµРЎР‚Р С‘РЎР‚РЎС“Р ВµР С ID РЎвЂЎР В°РЎвЂ°Р Вµ Р Т‘Р В»РЎРЏ Р В±Р ВµР В·Р С•Р С—Р В°РЎРѓР Р…Р С•РЎРѓРЎвЂљР С‘
    $regenerateInterval = 1800; // 30 Р СР С‘Р Р…РЎС“РЎвЂљ
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
