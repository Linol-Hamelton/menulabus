<?php

// Temporary helper page: handles Google OAuth callback, exchanges code for id_token, verifies it,
// then issues our mobile access/refresh tokens and prints them as HTML.
//
// Delete this file after you finish verifying Google OAuth.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/ApiResponse.php';
require_once __DIR__ . '/lib/MobileTokenAuth.php';
require_once __DIR__ . '/lib/OAuthGoogle.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$debugKey = (string)getenv('GOOGLE_OAUTH_DEBUG_KEY');
if ($debugKey === '') {
    http_response_code(500);
    echo "Missing GOOGLE_OAUTH_DEBUG_KEY in php-fpm env";
    exit;
}

function b64url_decode(string $data): string|false
{
    $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 ? strlen($data) + 4 - strlen($data) % 4 : strlen($data), '=', STR_PAD_RIGHT);
    return base64_decode($padded, true);
}

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function oauth_debug_secret(): string
{
    $env = getenv('MOBILE_TOKEN_SECRET');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return hash('sha256', DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS);
}

function oauth_verify_state(string $state): bool
{
    if (strpos($state, '.') === false) {
        return false;
    }
    [$p, $sig] = explode('.', $state, 2);
    $expected = b64url_encode(hash_hmac('sha256', $p, oauth_debug_secret(), true));
    if (!hash_equals($expected, $sig)) {
        return false;
    }
    $decoded = b64url_decode($p);
    if (!is_string($decoded) || $decoded === '') {
        return false;
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return false;
    }
    $ts = (int)($payload['ts'] ?? 0);
    if ($ts <= 0 || (time() - $ts) > 300) {
        return false;
    }
    return true;
}

$code = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
$err = (string)($_GET['error'] ?? '');

if ($err !== '') {
    http_response_code(400);
    echo "Google OAuth error: " . htmlspecialchars($err, ENT_QUOTES);
    exit;
}

if ($code === '' || $state === '') {
    http_response_code(400);
    echo "Missing code/state";
    exit;
}

if (!oauth_verify_state($state)) {
    http_response_code(400);
    echo "Invalid state";
    exit;
}

$ids = (string)getenv('GOOGLE_OAUTH_CLIENT_IDS');
$clientId = trim(explode(',', $ids)[0] ?? '');
$clientSecret = (string)getenv('GOOGLE_OAUTH_CLIENT_SECRET');
if ($clientId === '' || $clientSecret === '') {
    http_response_code(500);
    echo "Missing GOOGLE_OAUTH_CLIENT_IDS or GOOGLE_OAUTH_CLIENT_SECRET in php-fpm env";
    exit;
}

$redirectUri = 'https://menu.labus.pro/google-oauth-debug-callback.php';

// Exchange code -> tokens (includes id_token)
$body = http_build_query([
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
], '', '&', PHP_QUERY_RFC3986);

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'timeout' => 8,
        'ignore_errors' => true,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => $body,
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$raw = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
if (!is_string($raw) || $raw === '') {
    http_response_code(500);
    echo "Token exchange failed";
    exit;
}

$tok = json_decode($raw, true);
if (!is_array($tok)) {
    http_response_code(500);
    echo "Invalid token exchange JSON";
    exit;
}

$idToken = (string)($tok['id_token'] ?? '');
if ($idToken === '') {
    http_response_code(500);
    echo "Missing id_token in token exchange response\n\n" . htmlspecialchars($raw, ENT_QUOTES);
    exit;
}

// Verify id_token and issue our mobile tokens
$allowedIds = preg_split('/\s*,\s*/', $ids) ?: [];
try {
    $claims = OAuthGoogle::verifyIdToken($idToken, $allowedIds);
} catch (Throwable $e) {
    http_response_code(401);
    echo "Invalid Google id_token: " . htmlspecialchars($e->getMessage(), ENT_QUOTES);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();

$provider = 'google';
$subject = $claims['subject'];
$email = $claims['email'];
$emailVerified = $claims['email_verified'] ? 1 : 0;
$name = $claims['name'] ?: 'User';

// Find or create user, then upsert oauth identity (same logic as /api/v1/auth/oauth/google.php)
$stmt = $pdo->prepare("SELECT user_id FROM oauth_identities WHERE provider = :p AND subject = :s LIMIT 1");
$stmt->execute([':p' => $provider, ':s' => $subject]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $user = $db->getUserById((int)$userId);
    if (!$user) {
        http_response_code(500);
        echo "User linked to OAuth identity not found";
        exit;
    }
} else {
    $user = $db->getUserByEmail($email);
    if (!$user) {
        $pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, name, phone, is_active, email_verified_at, role, created_at)
            VALUES (:email, :password_hash, :name, NULL, 1, NOW(), 'customer', NOW())
        ");
        $stmt->execute([
            ':email' => $email,
            ':password_hash' => $pass,
            ':name' => $name,
        ]);
        $newId = (int)$pdo->lastInsertId();
        $user = $db->getUserById($newId);
        if (!$user) {
            http_response_code(500);
            echo "Failed to create user";
            exit;
        }
    } else {
        if (empty($user['is_active'])) {
            $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = :id")->execute([':id' => (int)$user['id']]);
            $user = $db->getUserById((int)$user['id']);
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO oauth_identities (user_id, provider, subject, email, email_verified, created_at, updated_at)
        VALUES (:uid, :p, :s, :email, :ev, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            email = VALUES(email),
            email_verified = VALUES(email_verified),
            updated_at = NOW()
    ");
    $stmt->execute([
        ':uid' => (int)$user['id'],
        ':p' => $provider,
        ':s' => $subject,
        ':email' => $email,
        ':ev' => $emailVerified,
    ]);
}

if (empty($user['is_active'])) {
    http_response_code(403);
    echo "Account is not active";
    exit;
}

$pair = MobileTokenAuth::issueTokenPair($pdo, $user, 'debug-web');
$needsPhone = empty(trim((string)($user['phone'] ?? '')));

$result = [
    'tokens' => $pair,
    'user' => [
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'name' => (string)$user['name'],
        'phone' => $user['phone'] ?? null,
        'role' => (string)$user['role'],
    ],
    'needs_phone' => $needsPhone,
];

?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Google OAuth Debug Result</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 16px; max-width: 980px; margin: 0 auto; }
    pre { white-space: pre-wrap; word-break: break-all; padding: 12px; background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 8px; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
  </style>
</head>
<body>
  <h1>Google OAuth debug result</h1>

  <p>id_token (first 60 chars): <code><?= htmlspecialchars(substr($idToken, 0, 60), ENT_QUOTES) ?></code></p>

  <h2>Our API payload</h2>
  <pre><?= htmlspecialchars(json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES) ?></pre>

  <h2>DB check</h2>
  <p>Run on server:</p>
  <pre>mysql -D menu_labus -e "SELECT provider, subject, email, email_verified, user_id, created_at FROM oauth_identities ORDER BY id DESC LIMIT 5;"</pre>

  <p>Recommendation: delete <code>google-oauth-debug-start.php</code> and <code>google-oauth-debug-callback.php</code> after testing.</p>
</body>
</html>
