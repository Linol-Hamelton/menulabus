<?php
/**
 * download-recipe-sample.php — Phase 33 sample CSV for recipe import.
 *
 * Returns a 3-row example tied to the tenant's actual menu where possible:
 * picks up to 2 real dishes (with external_id set) and pairs them with
 * generic ingredients/units so the operator sees the column shape clearly.
 *
 * Admin/owner only.
 */

$required_role = 'admin';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$items = $db->getMenuItems(null, false);

$samples = [];
$picked = 0;
foreach ($items as $it) {
    if (empty($it['external_id'])) continue;
    $samples[] = [$it['external_id'], 'Молоко',  'мл', '120', '1'];
    $samples[] = [$it['external_id'], 'Сахар',   'г',  '5',   '1'];
    $picked++;
    if ($picked >= 2) break;
}
if (empty($samples)) {
    // Fallback if tenant has no dishes with external_id yet.
    $samples = [
        ['Capuccino-001',     'Молоко',   'мл', '120', '1'],
        ['Capuccino-001',     'Эспрессо', 'мл', '30',  '1'],
        ['Capuccino-001',     'Сахар',    'г',  '5',   '1'],
        ['Pasta-Carbonara-7', 'Спагетти', 'г',  '100', '1'],
        ['Pasta-Carbonara-7', 'Бекон',    'г',  '80',  '0'],
    ];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="recipes-sample.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'dish_external_id', 'ingredient_name', 'unit', 'quantity', 'auto_create_ingredient',
], ';');

foreach ($samples as $row) {
    fputcsv($out, $row, ';');
}

fclose($out);
exit;
