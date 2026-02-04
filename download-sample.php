<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

// ✅ Add download-specific headers AFTER:
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Update.csv"');

$db = Database::getInstance();
$items = $db->getMenuItems();   // все товары из БД

// Заголовки, чтобы браузер предложил сохранить файл
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Update.csv"');

// Открываем поток для вывода
$out = fopen('php://output', 'w');

// Записываем BOM для корректного отображения кириллицы в Excel
fwrite($out, "\xEF\xBB\xBF");

// Заголовки столбцов
fputcsv($out, [
    'name', 'description', 'composition', 'price', 'image',
    'calories', 'protein', 'fat', 'carbs', 'category', 'available'
], ';');

// Строки данных
foreach ($items as $row) {
    // Превращаем массив полей в нужный порядок
    $csvRow = [
        $row['name'],
        $row['description'],
        $row['composition'],
        number_format($row['price'], 2, ',', ''), // русский формат
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