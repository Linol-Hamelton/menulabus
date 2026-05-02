<?php
/**
 * api/reservations/create.php — session-based reservation creation for the
 * web client. Mirrors create_new_order.php / create_guest_order.php.
 *
 * POST JSON {
 *   table_label, guests_count, starts_at, ends_at,
 *   guest_name?, guest_phone?, note?, csrf_token
 * }
 *
 * - Authenticated users: user_id from session, guest_name/phone optional.
 * - Guests: guest_name + guest_phone required; user_id stored as NULL.
 *
 * Returns:
 *   201 { success, reservation_id, status }
 *   400 validation error
 *   403 csrf_mismatch
 *   409 slot_taken
 *   500 db_failure
 */

require_once __DIR__ . '/../../session_init.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/Csrf.php';
require_once __DIR__ . '/../../telegram-notifications.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

Csrf::requireValid();

$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    $input = $_POST;
}

$tableLabel  = trim((string)($input['table_label']  ?? ''));
$guestsCount = (int)($input['guests_count'] ?? 0);
$startsAt    = trim((string)($input['starts_at']   ?? ''));
$endsAt      = trim((string)($input['ends_at']     ?? ''));
$guestName   = isset($input['guest_name'])  ? trim((string)$input['guest_name'])  : null;
$guestPhone  = isset($input['guest_phone']) ? trim((string)$input['guest_phone']) : null;
$note        = isset($input['note'])        ? (string)$input['note']              : null;

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

if ($tableLabel === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'table_label_required']);
    exit;
}
if ($guestsCount < 1 || $guestsCount > 50) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'guests_count_out_of_range']);
    exit;
}
if ($startsAt === '' || $endsAt === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'starts_ends_required']);
    exit;
}

$startsTs = strtotime($startsAt);
$endsTs   = strtotime($endsAt);
if ($startsTs === false || $endsTs === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'datetime_unparseable']);
    exit;
}
if ($endsTs <= $startsTs) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'window_inverted']);
    exit;
}
if ($startsTs < time() - 60) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'starts_in_past']);
    exit;
}

if ($userId === null) {
    if (($guestName === null || $guestName === '') || ($guestPhone === null || $guestPhone === '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'guest_contact_required']);
        exit;
    }
}

$db = Database::getInstance();

if (!$db->checkTableAvailable($tableLabel, $startsAt, $endsAt, null)) {
    http_response_code(409);
    echo json_encode([
        'success'     => false,
        'error'       => 'slot_taken',
        'table_label' => $tableLabel,
        'starts_at'   => $startsAt,
        'ends_at'     => $endsAt,
    ]);
    exit;
}

$reservationId = $db->createReservation(
    $tableLabel,
    $userId,
    $guestName,
    $guestPhone,
    $guestsCount,
    date('Y-m-d H:i:s', $startsTs),
    date('Y-m-d H:i:s', $endsTs),
    $note
);

if (!$reservationId) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_failure']);
    exit;
}

$created = $db->getReservationById($reservationId);
if ($created !== null) {
    sendReservationToTelegram((int)$reservationId, $created, $db);

    require_once __DIR__ . '/../../lib/WebhookDispatcher.php';
    WebhookDispatcher::dispatch('reservation.created', $created, $db);
}

http_response_code(201);
echo json_encode([
    'success'        => true,
    'reservation_id' => (int)$reservationId,
    'status'         => (string)($created['status'] ?? 'pending'),
]);
