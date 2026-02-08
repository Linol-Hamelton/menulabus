<?php

// Web login/register via Google OAuth (authorization code flow).
// Does not use external JS (compatible with strict CSP).
//
// IMPORTANT: PHP session cookie is SameSite=Strict, so it will not be sent back after Google redirect.
// We therefore use a short-lived Lax cookie to bind "state" to the browser (prevents login CSRF).

require_once __DIR__ . '/session_init.php';

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function oauth_secret(): string
{
    $env = getenv('MOBILE_TOKEN_SECRET');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return hash('sha256', DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS);
}

function oauth_make_state(string $mode): string
{
    $payload = [
        'ts' => time(),
        'rnd' => bin2hex(random_bytes(16)),
        'mode' => $mode,
    ];
    $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = b64url_encode(hash_hmac('sha256', $p, oauth_secret(), true));
    return $p . '.' . $sig;
}

$mode = (string)($_GET['mode'] ?? 'login');
if ($mode !== 'login' && $mode !== 'register') {
    $mode = 'login';
}

$ids = (string)getenv('GOOGLE_OAUTH_CLIENT_IDS');
$clientId = trim(explode(',', $ids)[0] ?? '');
if ($clientId === '') {
    http_response_code(500);
    echo "Google OAuth is not configured (GOOGLE_OAUTH_CLIENT_IDS)";
    exit;
}

$state = oauth_make_state($mode);

// Bind state to the browser across cross-site redirect.
// Lax is required so cookie is sent on top-level GET navigation back from Google.
$cookieOpts = [
    'expires' => time() + 300,
    'path' => '/google-oauth-callback.php',
    'domain' => 'menu.labus.pro',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
];
setcookie('g_oauth_state', $state, $cookieOpts);

$redirectUri = 'https://menu.labus.pro/google-oauth-callback.php';
$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    // Force refresh_token on first consent; harmless for web login even if not used.
    'access_type' => 'offline',
    'prompt' => 'consent',
];

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $url, true, 302);
exit;

