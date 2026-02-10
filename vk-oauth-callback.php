<?php

// Web login/register via VK ID OAuth callback.
// Exchanges code -> access_token, retrieves user info, links/creates a user, then creates a normal PHP session.

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/OAuthVK.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function b64url_decode(string $data): string|false
{
    $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 ? strlen($data) + 4 - strlen($data) % 4 : strlen($data), '=', STR_PAD_RIGHT);
    return base64_decode($padded, true);
}

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

function oauth_verify_state(string $state): ?array
{
    if (strpos($state, '.') === false) {
        return null;
    }
    [$p, $sig] = explode('.', $state, 2);
    $expected = b64url_encode(hash_hmac('sha256', $p, oauth_secret(), true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $decoded = b64url_decode($p);
    if (!is_string($decoded) || $decoded === '') {
        return null;
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return null;
    }
    $ts = (int)($payload['ts'] ?? 0);
    if ($ts <= 0 || (time() - $ts) > 300) {
        return null;
    }
    return $payload;
}

function auth_fail(string $msg): void
{
    $_SESSION['auth_error_message'] = $msg;
    header('Location: auth.php?mode=login', true, 302);
    exit;
}

$err = (string)($_GET['error'] ?? '');
if ($err !== '') {
    $errDesc = (string)($_GET['error_description'] ?? $err);
    auth_fail('VK OAuth error: ' . $errDesc);
}

$code = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
if ($code === '' || $state === '') {
    auth_fail('VK OAuth: missing code/state');
}

// CSRF binding cookie check.
$cookieState = (string)($_COOKIE['vk_oauth_state'] ?? '');
setcookie('vk_oauth_state', '', [
    'expires' => time() - 3600,
    'path' => '/vk-oauth-callback.php',
    'domain' => 'menu.labus.pro',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if ($cookieState === '' || !hash_equals($cookieState, $state)) {
    auth_fail('VK OAuth: invalid state (cookie mismatch)');
}

$statePayload = oauth_verify_state($state);
if (!$statePayload) {
    auth_fail('VK OAuth: invalid state');
}

$mode = (string)($statePayload['mode'] ?? 'login');
if ($mode !== 'login' && $mode !== 'register') {
    $mode = 'login';
}

$clientId = (string)getenv('VK_OAUTH_CLIENT_ID');
$clientSecret = (string)getenv('VK_OAUTH_CLIENT_SECRET');
if ($clientId === '' || $clientSecret === '') {
    auth_fail('VK OAuth is not configured');
}

$redirectUri = 'https://menu.labus.pro/vk-oauth-callback.php';

// Exchange code -> access_token
$body = http_build_query([
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
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

$raw = @file_get_contents('https://oauth.vk.com/access_token', false, $ctx);
if (!is_string($raw) || $raw === '') {
    auth_fail('VK OAuth: token exchange failed');
}
$tok = json_decode($raw, true);
if (!is_array($tok)) {
    auth_fail('VK OAuth: invalid token response');
}

if (isset($tok['error'])) {
    $errMsg = $tok['error_description'] ?? $tok['error'];
    auth_fail("VK OAuth: {$errMsg}");
}

$accessToken = (string)($tok['access_token'] ?? '');
$userId = (int)($tok['user_id'] ?? 0);
$email = (string)($tok['email'] ?? '');

if ($accessToken === '' || $userId === 0) {
    auth_fail('VK OAuth: missing access_token or user_id');
}

// VK может не вернуть email, если пользователь не разрешил доступ
// В этом случае нельзя создать аккаунт (email обязателен)
if ($email === '') {
    auth_fail('VK OAuth: email is required. Please grant access to your email in VK settings.');
}

// Retrieve user info from VK API
try {
    $claims = OAuthVK::getUserInfo($accessToken, $userId, $email);
} catch (Throwable $e) {
    auth_fail('VK OAuth: ' . $e->getMessage());
}

$db = Database::getInstance();
$pdo = $db->getConnection();

$provider = 'vk';
$subject = $claims['subject'];
$email = $claims['email'];
$emailVerified = $claims['email_verified'] ? 1 : 0;
$name = $claims['name'] ?: 'User';

// 1) Find existing identity by provider+subject
$stmt = $pdo->prepare("SELECT user_id FROM oauth_identities WHERE provider = :p AND subject = :s LIMIT 1");
$stmt->execute([':p' => $provider, ':s' => $subject]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $user = $db->getUserById((int)$userId);
    if (!$user) {
        auth_fail('VK OAuth: linked user not found');
    }
} else {
    // 2) Link by email if user exists; otherwise create a new active user.
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
            auth_fail('VK OAuth: failed to create user');
        }
    } else {
        // Update user's name if it's empty and OAuth provides one
        if ((empty($user['name']) || $user['name'] === 'User') && !empty($name) && $name !== 'User') {
            $stmt = $pdo->prepare("UPDATE users SET name = :name WHERE id = :id");
            $stmt->execute([':name' => $name, ':id' => (int)$user['id']]);
            $user['name'] = $name;
        }
        // Update email_verified_at if not set and OAuth email is verified
        if (empty($user['email_verified_at']) && $emailVerified) {
            $stmt = $pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => (int)$user['id']]);
        }
    }

    if (empty($user['is_active'])) {
        auth_fail('Аккаунт не активирован. Проверьте почту или обратитесь в поддержку.');
    }

    // 3) Upsert identity mapping
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
    auth_fail('Аккаунт не активирован.');
}

// Create normal web session (same logic style as password login).
$sessionData = $_SESSION;
session_regenerate_id(true);
$_SESSION = $sessionData;
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_role'] = $user['role'];
$_SESSION['last_login'] = time();
$_SESSION['last_activity'] = time();
$_SESSION['last_regeneration'] = time();

$sessionParams = session_get_cookie_params();
setcookie(
    session_name(),
    session_id(),
    time() + 2592000,
    $sessionParams['path'],
    $sessionParams['domain'],
    $sessionParams['secure'],
    $sessionParams['httponly']
);

header('Location: account.php', true, 302);
exit;
