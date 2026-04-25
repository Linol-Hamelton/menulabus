<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('POST');
$user = api_v1_auth_user_from_bearer();
$input = ApiResponse::readJsonBody();

$tableLabel  = trim((string)($input['table_label'] ?? ''));
$guestsCount = (int)($input['guests_count'] ?? 0);
$startsAt    = trim((string)($input['starts_at'] ?? ''));
$endsAt      = trim((string)($input['ends_at'] ?? ''));
$note        = isset($input['note']) ? (string)$input['note'] : null;
$guestName   = isset($input['guest_name']) ? (string)$input['guest_name'] : null;
$guestPhone  = isset($input['guest_phone']) ? (string)$input['guest_phone'] : null;

if ($tableLabel === '') {
    ApiResponse::error('table_label is required', 400);
}
if ($guestsCount < 1 || $guestsCount > 50) {
    ApiResponse::error('guests_count must be between 1 and 50', 400);
}
if ($startsAt === '' || $endsAt === '') {
    ApiResponse::error('starts_at and ends_at are required', 400);
}
$startsTs = strtotime($startsAt);
$endsTs   = strtotime($endsAt);
if ($startsTs === false || $endsTs === false) {
    ApiResponse::error('starts_at / ends_at must be parseable datetimes', 400);
}
if ($endsTs <= $startsTs) {
    ApiResponse::error('ends_at must be after starts_at', 400);
}
if ($startsTs < time() - 60) {
    ApiResponse::error('starts_at cannot be in the past', 400);
}

$idempotencyKey = Idempotency::getHeaderKey();
$requestHash = Idempotency::hashPayload([
    'user_id'      => (int)$user['id'],
    'table_label'  => $tableLabel,
    'starts_at'    => $startsAt,
    'ends_at'      => $endsAt,
    'guests_count' => $guestsCount,
]);

$db  = Database::getInstance();
$pdo = $db->getConnection();

if ($idempotencyKey !== null) {
    $hit = Idempotency::find($pdo, 'api_v1_reservation_create', $idempotencyKey, $requestHash);
    if ($hit && !empty($hit['conflict'])) {
        ApiResponse::error('Idempotency-Key was already used with a different payload', 409);
    }
    if ($hit && is_array($hit['response'])) {
        ApiResponse::success($hit['response']);
    }
}

if (!$db->checkTableAvailable($tableLabel, $startsAt, $endsAt, null)) {
    ApiResponse::error('Selected slot is no longer available', 409, [
        'table_label' => $tableLabel,
        'starts_at'   => $startsAt,
        'ends_at'     => $endsAt,
    ]);
}

$reservationId = $db->createReservation(
    $tableLabel,
    (int)$user['id'],
    $guestName,
    $guestPhone,
    $guestsCount,
    date('Y-m-d H:i:s', $startsTs),
    date('Y-m-d H:i:s', $endsTs),
    $note
);

if (!$reservationId) {
    ApiResponse::error('Failed to create reservation', 500);
}

$created = $db->getReservationById($reservationId);
$data = [
    'reservation_id' => (int)$reservationId,
    'status'         => (string)($created['status'] ?? 'pending'),
    'table_label'    => (string)($created['table_label'] ?? $tableLabel),
    'starts_at'      => (string)($created['starts_at'] ?? $startsAt),
    'ends_at'        => (string)($created['ends_at'] ?? $endsAt),
];

if ($created !== null) {
    require_once __DIR__ . '/../../../telegram-notifications.php';
    sendReservationToTelegram((int)$reservationId, $created, $db);

    require_once __DIR__ . '/../../../lib/WebhookDispatcher.php';
    WebhookDispatcher::dispatch('reservation.created', $created, $db);
}

if ($idempotencyKey !== null) {
    Idempotency::store($pdo, 'api_v1_reservation_create', $idempotencyKey, $requestHash, $data);
}

ApiResponse::success($data, 201);
