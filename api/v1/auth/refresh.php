<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('POST');
$input = ApiResponse::readJsonBody();

$refreshToken = (string)($input['refresh_token'] ?? '');
$deviceName = trim((string)($input['device_name'] ?? 'mobile'));
if ($refreshToken === '') {
    ApiResponse::error('refresh_token is required', 400);
}

$db = Database::getInstance();
$pair = MobileTokenAuth::rotateRefreshToken($db->getConnection(), $refreshToken, $deviceName);
if (!$pair) {
    ApiResponse::error('Invalid or expired refresh token', 401);
}

ApiResponse::success(['tokens' => $pair]);

