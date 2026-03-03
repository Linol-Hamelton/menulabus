<?php
/**
 * order-status.php — lightweight JSON polling endpoint for order tracking.
 * No session/auth required. Returns only public status data (no personal info).
 *
 * GET ?id=X
 * Response:
 *   {"status":"готовим","step":1,"delivery_type":"table","avg_minutes":18,"created_at_ts":1739800000}
 *
 * step: 0=Принят, 1=Готовим, 2=Доставляем/Готов, 3=Завершён, -1=Отказ
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_id']);
    exit;
}

$stmt = $db->getConnection()->prepare(
    "SELECT status, delivery_type, UNIX_TIMESTAMP(created_at) AS created_at_ts
     FROM orders WHERE id = :id LIMIT 1"
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$statusMap = [
    'принят'      => 0,
    'готовим'     => 1,
    'доставляем'  => 2,
    'завершён'    => 3,
    'отказ'       => -1,
];

$status = mb_strtolower(trim($row['status']));
$step   = $statusMap[$status] ?? 0;

// Fallback: map unknown statuses that partially match
if (!isset($statusMap[$status])) {
    if (mb_strpos($status, 'готов') !== false) $step = 2;
}

$avgMinutes = $db->getAvgCompletionMinutes($row['delivery_type']);

echo json_encode([
    'status'        => $row['status'],
    'step'          => $step,
    'delivery_type' => $row['delivery_type'],
    'avg_minutes'   => $avgMinutes,
    'created_at_ts' => (int)$row['created_at_ts'],
], JSON_UNESCAPED_UNICODE);
