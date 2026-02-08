<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('POST');
$input = ApiResponse::readJsonBody();

$email = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
$deviceName = trim((string)($input['device_name'] ?? 'mobile'));

if ($email === '' || $password === '') {
    ApiResponse::error('Email and password are required', 400);
}

$db = Database::getInstance();
$user = $db->getUserByEmail($email);
if (!$user || !password_verify($password, (string)$user['password_hash'])) {
    ApiResponse::error('Invalid credentials', 401);
}
if (empty($user['is_active'])) {
    ApiResponse::error('Account is not active', 403);
}

$pair = MobileTokenAuth::issueTokenPair($db->getConnection(), $user, $deviceName);
ApiResponse::success([
    'tokens' => $pair,
    'user' => [
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'name' => (string)$user['name'],
        'phone' => $user['phone'] ?? null,
        'role' => (string)$user['role'],
    ],
]);

