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
    'external_id', 'name', 'description', 'composition', 'price', 'cost', 'image',
    'calories', 'protein', 'fat', 'carbs', 'category', 'available'
], ';');

foreach ($items as $row) {
    // Phase L101: cost — effective value из БД (если cost_source='recipe',
    // колонка уже синхронизирована с getRecipeCost через hooks; если
    // 'manual' — это то, что admin задал явно). При reimport — пустая
    // ячейка означает «не трогать», заполненная — switch to manual mode.
    $csvRow = [
        $row['external_id'] ?? '',
        $row['name'],
        $row['description'],
        $row['composition'],
        number_format((float)$row['price'], 2, '.', ''),
        number_format((float)($row['cost'] ?? 0), 2, '.', ''),
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
