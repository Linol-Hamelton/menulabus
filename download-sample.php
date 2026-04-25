<?php
$required_role = 'admin';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$items = $db->getMenuItems(null, false);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Update.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'external_id', 'name', 'description', 'composition', 'price', 'image',
    'calories', 'protein', 'fat', 'carbs', 'category', 'available'
], ';');

foreach ($items as $row) {
    $csvRow = [
        $row['external_id'] ?? '',
        $row['name'],
        $row['description'],
        $row['composition'],
        number_format((float)$row['price'], 2, '.', ''),
        $row['image'],
        $row['calories'],
        $row['protein'],
        $row['fat'],
        $row['carbs'],
        $row['category'],
        $row['available']
    ];
    fputcsv($out, $csvRow, ';');
}

fclose($out);
exit;
