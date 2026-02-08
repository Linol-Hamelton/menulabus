<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../../lib/OAuthGoogle.php';

api_v1_require_method('POST');
$input = ApiResponse::readJsonBody();

$idToken = trim((string)($input['id_token'] ?? ''));
$deviceName = trim((string)($input['device_name'] ?? 'mobile'));

if ($idToken === '') {
    ApiResponse::error('id_token is required', 400);
}

$clientIds = (string)getenv('GOOGLE_OAUTH_CLIENT_IDS');
if ($clientIds === '') {
    ApiResponse::error('Google OAuth is not configured (GOOGLE_OAUTH_CLIENT_IDS)', 500);
}

try {
    $claims = OAuthGoogle::verifyIdToken($idToken, preg_split('/\s*,\s*/', $clientIds) ?: []);
} catch (Throwable $e) {
    ApiResponse::error('Invalid Google id_token', 401, ['reason' => $e->getMessage()]);
}

$db = Database::getInstance();
$pdo = $db->getConnection();

$provider = 'google';
$subject = $claims['subject'];
$email = $claims['email'];
$emailVerified = $claims['email_verified'] ? 1 : 0;
$name = $claims['name'] ?: 'User';

// 1) Try to find existing identity by provider+subject
$stmt = $pdo->prepare("SELECT user_id FROM oauth_identities WHERE provider = :p AND subject = :s LIMIT 1");
$stmt->execute([':p' => $provider, ':s' => $subject]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $user = $db->getUserById((int)$userId);
    if (!$user) {
        ApiResponse::error('User linked to OAuth identity not found', 500);
    }
} else {
    // 2) Link by email if user exists; otherwise create a new active user.
    $user = $db->getUserByEmail($email);
    if (!$user) {
        // Create an active user immediately (email is already verified by Google).
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
            ApiResponse::error('Failed to create user', 500);
        }
    } else {
        // Ensure active (optional: you can remove this if you want manual activation).
        if (empty($user['is_active'])) {
            $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = :id")->execute([':id' => (int)$user['id']]);
            $user = $db->getUserById((int)$user['id']);
        }
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
    ApiResponse::error('Account is not active', 403);
}

$pair = MobileTokenAuth::issueTokenPair($pdo, $user, $deviceName);
$needsPhone = empty(trim((string)($user['phone'] ?? '')));

ApiResponse::success([
    'tokens' => $pair,
    'user' => [
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'name' => (string)$user['name'],
        'phone' => $user['phone'] ?? null,
        'role' => (string)$user['role'],
    ],
    'needs_phone' => $needsPhone,
]);

