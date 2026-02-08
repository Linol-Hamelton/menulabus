<?php

require_once __DIR__ . '/bootstrap.php';

api_v1_require_method('GET');

$category = isset($_GET['category']) ? trim((string)$_GET['category']) : null;
if ($category === '') {
    $category = null;
}

$db = Database::getInstance();
$items = $db->getMenuItems($category);
if (!is_array($items)) {
    ApiResponse::error('Failed to load menu', 500);
}

ApiResponse::success([
    'category' => $category,
    'items' => $items,
]);

