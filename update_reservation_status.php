<?php
/**
 * update_reservation_status.php — staff-only endpoint to move a reservation
 * along its lifecycle. Mirrors the shape of update_order_status.php.
 *
 * POST { reservation_id, status }
 *   status ∈ {confirmed, seated, cancelled, no_show}
 *
 * Auth: session-based, role ∈ {employee, admin, owner}.
 * CSRF: required (header X-CSRF-Token or POST/JSON body csrf_token).
 */

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Csrf.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$role = (string)($_SESSION['user_role'] ?? '');
if (!in_array($role, ['employee', 'admin', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

Csrf::requireValid();

$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    $input = $_POST;
}

$reservationId = (int)($input['reservation_id'] ?? 0);
$newStatus     = (string)($input['status'] ?? '');

$allowed = ['confirmed', 'seated', 'cancelled', 'no_show'];
if ($reservationId <= 0 || !in_array($newStatus, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'bad_request']);
    exit;
}

$db = Database::getInstance();
$reservation = $db->getReservationById($reservationId);
if (!$reservation) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

$currentStatus = (string)$reservation['status'];

$validTransitions = [
    'pending'   => ['confirmed', 'cancelled'],
    'confirmed' => ['seated', 'cancelled', 'no_show'],
    'seated'    => [],
    'cancelled' => [],
    'no_show'   => [],
];
if (!in_array($newStatus, $validTransitions[$currentStatus] ?? [], true)) {
    http_response_code(409);
    echo json_encode([
        'success'        => false,
        'error'          => 'invalid_transition',
        'current_status' => $currentStatus,
    ]);
    exit;
}

if (!$db->updateReservationStatus($reservationId, $newStatus)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'update_failed']);
    exit;
}

require_once __DIR__ . '/lib/WebhookDispatcher.php';
$updated = $db->getReservationById($reservationId);
if ($updated !== null) {
    WebhookDispatcher::dispatch('reservation.' . $newStatus, $updated, $db);
}

echo json_encode([
    'success'        => true,
    'reservation_id' => $reservationId,
    'status'         => $newStatus,
]);
