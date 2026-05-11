<?php

// Phase 33.2 — Web login/register via VK ID OAuth (PKCE flow).
// Replaces the legacy oauth.vk.com endpoint, which now rejects requests with
// `{"error":"invalid_request","error_description":"Security Error"}` for apps
// that have not been migrated to VK ID.
//
// Does not use external JS (CSP-friendly).
//
// IMPORTANT: PHP session cookie is SameSite=Strict, so it will not be sent back
// after the VK redirect. We use two short-lived Lax cookies bound to the
// callback path:
//   - vk_oauth_state — HMAC-signed state (CSRF)
//   - vk_oauth_pkce  — PKCE code_verifier (required by id.vk.com)

require_once __DIR__ . '/../../session_init.php';

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
    return hash('sha256', tenant_secret_material());
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

$clientId = (string)getenv('VK_OAUTH_CLIENT_ID');
if ($clientId === '') {
    http_response_code(500);
    echo "VK OAuth is not configured (VK_OAUTH_CLIENT_ID)";
    exit;
}

$state = oauth_make_state($mode);

// PKCE — required by VK ID. RFC 7636: code_verifier 43-128 chars from
// [A-Z][a-z][0-9]-._~ ; bin2hex(random_bytes(32)) = 64 hex chars, which fits.
$codeVerifier = bin2hex(random_bytes(32));
$codeChallenge = b64url_encode(hash('sha256', $codeVerifier, true));

$cookieOpts = [
    'path' => '/auth/oauth/vk-callback.php',
    'expires' => time() + 300,
    'samesite' => 'Lax',
];
$cookieOptsFinal = tenant_host_only_cookie_options($cookieOpts);
$okState = setcookie('vk_oauth_state', $state, $cookieOptsFinal);
$okPkce  = setcookie('vk_oauth_pkce',  $codeVerifier, $cookieOptsFinal);

// Phase 33.2 diagnostic — remove once cookie roundtrip is verified.
// Write directly to data/logs/vk-debug.log (writable by the webuser, known path).
$diagLine = sprintf(
    "[%s] [vk-start] host=%s scheme=%s setcookie_state=%s setcookie_pkce=%s opts=%s state_head=%s\n",
    date('Y-m-d H:i:s'),
    (string)($_SERVER['HTTP_HOST'] ?? ''),
    tenant_current_scheme(),
    $okState ? 'ok' : 'fail',
    $okPkce ? 'ok' : 'fail',
    json_encode($cookieOptsFinal),
    substr($state, 0, 16)
);
@file_put_contents(__DIR__ . '/../../data/logs/vk-debug.log', $diagLine, FILE_APPEND | LOCK_EX);
error_log(trim($diagLine));

$redirectUri = tenant_url('/auth/oauth/vk-callback.php');
$params = [
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'state' => $state,
    'code_challenge' => $codeChallenge,
    'code_challenge_method' => 'S256',
    'scope' => 'email vkid.personal_info',
];

$url = 'https://id.vk.com/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $url, true, 302);
exit;
