<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('GET');
api_v1_auth_user_from_bearer();

$tableLabel = trim((string)($_GET['table_label'] ?? ''));
$date       = trim((string)($_GET['date'] ?? ''));

if ($tableLabel === '') {
    ApiResponse::error('table_label is required', 400);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    ApiResponse::error('date must be YYYY-MM-DD', 400);
}

$dayStart = $date . ' 00:00:00';
$dayEnd   = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));

$db = Database::getInstance();
$rows = $db->getReservationsByRange($dayStart, $dayEnd);

$busy = [];
foreach ($rows as $r) {
    if ((string)$r['table_label'] !== $tableLabel) {
        continue;
    }
    if (!in_array((string)$r['status'], ['pending', 'confirmed', 'seated'], true)) {
        continue;
    }
    $busy[] = [
        'starts_at' => (string)$r['starts_at'],
        'ends_at'   => (string)$r['ends_at'],
        'status'    => (string)$r['status'],
    ];
}

ApiResponse::success([
    'table_label' => $tableLabel,
    'date'        => $date,
    'busy'        => $busy,
]);
