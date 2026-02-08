<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('POST');
$input = ApiResponse::readJsonBody();

$refreshToken = (string)($input['refresh_token'] ?? '');
if ($refreshToken === '') {
    ApiResponse::error('refresh_token is required', 400);
}

$db = Database::getInstance();
$revoked = MobileTokenAuth::revokeRefreshToken($db->getConnection(), $refreshToken);
if (!$revoked) {
    ApiResponse::error('Token not found or already revoked', 400);
}

ApiResponse::success(['revoked' => true]);

