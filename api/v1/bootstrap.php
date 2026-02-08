<?php

require_once __DIR__ . '/../../session_init.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/ApiResponse.php';
require_once __DIR__ . '/../../lib/MobileTokenAuth.php';
require_once __DIR__ . '/../../lib/Idempotency.php';

// Optional composer autoload (some deployments may ship an incomplete vendor/ tree).
// We guard against a broken vendor/autoload.php that would fatal at include-time.
$autoload = __DIR__ . '/../../vendor/autoload.php';
$autoloadReal = __DIR__ . '/../../vendor/composer/autoload_real.php';
if (is_file($autoload) && is_file($autoloadReal)) {
    require_once $autoload;
}

function api_v1_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        ApiResponse::error('Method not allowed', 405);
    }
}

function api_v1_auth_user_from_bearer(): array
{
    $token = MobileTokenAuth::extractBearerToken();
    if (!$token) {
        ApiResponse::error('Missing bearer token', 401);
    }

    $payload = MobileTokenAuth::verifyToken($token, 'access');
    if (!$payload) {
        ApiResponse::error('Invalid or expired access token', 401);
    }

    $db = Database::getInstance();
    $user = $db->getUserById((int)$payload['sub']);
    if (!$user || empty($user['is_active'])) {
        ApiResponse::error('User not found or inactive', 401);
    }

    return $user;
}
