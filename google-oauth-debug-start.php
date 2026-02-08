<?php

// Temporary helper page: starts Google OAuth (Authorization Code) in browser without external JS.
//
// IMPORTANT: our site uses `SameSite=Strict` for PHPSESSID, so the session cookie will NOT be sent back
// after a cross-site redirect from accounts.google.com. Therefore, this debug flow must be STATELESS
// (do not rely on $_SESSION / require_auth.php).
//
// Protected by a debug key (env GOOGLE_OAUTH_DEBUG_KEY). Delete this file after verification.

require_once __DIR__ . '/db.php';

$debugKey = (string)getenv('GOOGLE_OAUTH_DEBUG_KEY');
$k = (string)($_GET['k'] ?? '');
if ($debugKey === '' || $k === '' || !hash_equals($debugKey, $k)) {
    http_response_code(404);
    echo "Not found";
    exit;
}

function oauth_debug_secret(): string
{
    $env = getenv('MOBILE_TOKEN_SECRET');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return hash('sha256', DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS);
}

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function oauth_make_state(): string
{
    $payload = [
        'ts' => time(),
        'rnd' => bin2hex(random_bytes(16)),
    ];
    $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = b64url_encode(hash_hmac('sha256', $p, oauth_debug_secret(), true));
    return $p . '.' . $sig;
}

$state = oauth_make_state();

$ids = (string)getenv('GOOGLE_OAUTH_CLIENT_IDS');
$clientId = trim(explode(',', $ids)[0] ?? '');
if ($clientId === '') {
    http_response_code(500);
    echo "Missing GOOGLE_OAUTH_CLIENT_IDS in php-fpm env";
    exit;
}

$redirectUri = 'https://menu.labus.pro/google-oauth-debug-callback.php';

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'access_type' => 'offline',
    'prompt' => 'consent',
];

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
header('Location: ' . $url, true, 302);
exit;
