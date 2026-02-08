<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('GET');
$user = api_v1_auth_user_from_bearer();

ApiResponse::success([
    'user' => [
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'name' => (string)$user['name'],
        'phone' => $user['phone'] ?? null,
        'role' => (string)$user['role'],
        'menu_view' => $user['menu_view'] ?? null,
    ],
]);

