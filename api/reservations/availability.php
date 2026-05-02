<?php
/**
 * api/reservations/availability.php — session-based availability lookup for
 * the public /reservation.php picker. Mirrors api/v1/reservations/availability.php
 * but uses session auth so unauthenticated guests can pre-check slots
 * without a bearer token.
 *
 * GET ?table_label=...&date=YYYY-MM-DD
 *
 * Returns:
 *   200 { success: true, table_label, date, busy: [{starts_at, ends_at, status}] }
 *   400 validation error
 *
 * No CSRF: read-only, idempotent.
 */

require_once __DIR__ . '/../../session_init.php';
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$tableLabel = trim((string)($_GET['table_label'] ?? ''));
$date       = trim((string)($_GET['date'] ?? ''));

if ($tableLabel === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'table_label_required']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_date']);
    exit;
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

echo json_encode([
    'success'     => true,
    'table_label' => $tableLabel,
    'date'        => $date,
    'busy'        => $busy,
], JSON_UNESCAPED_UNICODE);
