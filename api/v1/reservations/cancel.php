<?php

require_once __DIR__ . '/../bootstrap.php';

api_v1_require_method('POST');
$user = api_v1_auth_user_from_bearer();
$input = ApiResponse::readJsonBody();

$reservationId = (int)($input['reservation_id'] ?? 0);
if ($reservationId <= 0) {
    ApiResponse::error('reservation_id is required', 400);
}

$db = Database::getInstance();
$reservation = $db->getReservationById($reservationId);
if (!$reservation) {
    ApiResponse::error('Reservation not found', 404);
}

$role = (string)($user['role'] ?? '');
$isPrivileged = in_array($role, ['employee', 'owner', 'admin'], true);
if (!$isPrivileged && (int)($reservation['user_id'] ?? 0) !== (int)$user['id']) {
    ApiResponse::error('Forbidden', 403);
}

$status = (string)($reservation['status'] ?? '');
if (!in_array($status, ['pending', 'confirmed'], true)) {
    ApiResponse::error('Reservation cannot be cancelled in its current state', 409, [
        'current_status' => $status,
    ]);
}

if (!$db->updateReservationStatus($reservationId, 'cancelled')) {
    ApiResponse::error('Failed to cancel reservation', 500);
}

ApiResponse::success([
    'reservation_id' => $reservationId,
    'status'         => 'cancelled',
]);
