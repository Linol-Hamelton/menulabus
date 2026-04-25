<?php
/**
 * api/save-waitlist.php — Waitlist create + staff actions (Phase 8.4).
 *
 * POST body (JSON): {
 *   action: 'create' | 'list' | 'update_status',
 *   ...per-action payload,
 *   csrf_token: string
 * }
 *
 * `create` is open to any session (customer or staff); others are staff-only.
 * CSRF required on all POSTs.
 */

require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

Csrf::requireValid();

$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) { $input = $_POST; }

$db = Database::getInstance();
$action = (string)($input['action'] ?? '');
$role = (string)($_SESSION['user_role'] ?? '');
$isStaff = in_array($role, ['employee', 'admin', 'owner'], true);

function waitlist_staff_only(bool $isStaff): void {
    if (!$isStaff) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }
}

switch ($action) {
    case 'create':
        $userId       = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $guestName    = isset($input['guest_name']) ? (string)$input['guest_name'] : null;
        $guestPhone   = (string)($input['guest_phone'] ?? '');
        $guestsCount  = (int)($input['guests_count'] ?? 0);
        $preferredDate= (string)($input['preferred_date'] ?? '');
        $preferredTime= isset($input['preferred_time']) ? (string)$input['preferred_time'] : null;
        $locationId   = isset($input['location_id']) && $input['location_id'] !== '' ? (int)$input['location_id'] : null;
        $note         = isset($input['note']) ? (string)$input['note'] : null;

        if ($guestPhone === '' || $guestsCount < 1 || $preferredDate === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        $id = $db->createWaitlistEntry($userId, $guestName, $guestPhone, $guestsCount, $preferredDate, $preferredTime, $locationId, $note);
        if ($id === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'db_failure_or_invalid']);
            exit;
        }

        // Best-effort Telegram ping to staff — same chat used by reservations.
        try {
            require_once __DIR__ . '/../config.php';
            require_once __DIR__ . '/../telegram-notifications.php';
            if (defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN) {
                $tgChatId = json_decode($db->getSetting('telegram_chat_id') ?? 'null', true);
                if ($tgChatId) {
                    $when = $preferredTime ? $preferredDate . ' ' . $preferredTime : $preferredDate;
                    $text = '⏳ <b>Очередь:</b> ' . $guestsCount . ' гост. на ' . htmlspecialchars($when)
                          . ' · ' . htmlspecialchars($guestPhone);
                    if ($guestName) $text .= ' · ' . htmlspecialchars($guestName);
                    sendTelegramMessage((string)$tgChatId, $text);
                }
            }
            require_once __DIR__ . '/../lib/WebhookDispatcher.php';
            $row = $db->getWaitlistEntry($id);
            if ($row) WebhookDispatcher::dispatch('waitlist.created', $row, $db);
        } catch (Throwable $notifyEx) {
            error_log('waitlist notify error: ' . $notifyEx->getMessage());
        }
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'list':
        waitlist_staff_only($isStaff);
        $date       = isset($input['date']) && $input['date'] !== '' ? (string)$input['date'] : null;
        $locationId = isset($input['location_id']) && $input['location_id'] !== '' ? (int)$input['location_id'] : null;
        echo json_encode([
            'success' => true,
            'entries' => $db->listActiveWaitlist($date, $locationId),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'update_status':
        waitlist_staff_only($isStaff);
        $id = (int)($input['id'] ?? 0);
        $newStatus = (string)($input['status'] ?? '');
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        $ok = $db->updateWaitlistStatus($id, $newStatus);
        if (!$ok) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'update_failed']); exit; }

        try {
            require_once __DIR__ . '/../lib/WebhookDispatcher.php';
            $row = $db->getWaitlistEntry($id);
            if ($row) WebhookDispatcher::dispatch('waitlist.' . $newStatus, $row, $db);
        } catch (Throwable $e) { error_log('waitlist webhook error: ' . $e->getMessage()); }

        echo json_encode(['success' => true, 'id' => $id, 'status' => $newStatus]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
