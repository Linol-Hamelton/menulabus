<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('POST');
$user = api_v1_auth_user_from_bearer();
$input = ApiResponse::readJsonBody();

$phoneRaw = (string)($input['phone'] ?? '');
$phone = preg_replace('/[^\d\+]/', '', $phoneRaw);
$phone = (string)$phone;

// Normalize common cases:
// - "7903..." -> "+7903..."
// - "+7..." stays
if ($phone !== '' && $phone[0] !== '+') {
    if ($phone[0] === '7') {
        $phone = '+' . $phone;
    } elseif ($phone[0] === '8' && strlen($phone) === 11) {
        $phone = '+7' . substr($phone, 1);
    }
}

if (!preg_match('/^\+7\d{10}$/', $phone)) {
    ApiResponse::error('phone must be in +7XXXXXXXXXX format', 400);
}

$db = Database::getInstance();
$ok = $db->updateUserPhone((int)$user['id'], $phone);
if (!$ok) {
    ApiResponse::error('Failed to update phone', 500);
}

ApiResponse::success([
    'phone' => $phone,
]);

