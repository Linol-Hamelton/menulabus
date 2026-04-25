<?php
/**
 * kds-action.php — move a single KDS slot along its status machine.
 *
 * POST JSON {
 *   status_row_id: int,      // order_item_status.id
 *   status:        "cooking" | "ready" | "cancelled",
 *   csrf_token:    string
 * }
 *
 * Auth: role in {employee, admin, owner} with CSRF. The status value is
 * validated against the allowlist at both this layer and db.php. After a
 * successful "ready" flip, if every remaining slot for the order is also
 * ready, dispatches the `order.ready` webhook and pings Telegram.
 *
 * Responses:
 *   200 { success, changed, order_ready? }
 *   400 invalid_params / invalid_status
 *   403 csrf / role
 */

$required_role = 'employee';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Csrf.php';

header('Content-Type: application/json; charset=utf-8');

$role = (string)($_SESSION['user_role'] ?? '');
if (!in_array($role, ['employee', 'admin', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

Csrf::requireValid();

$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) { $input = $_POST; }

$statusRowId = (int)($input['status_row_id'] ?? 0);
$newStatus   = (string)($input['status'] ?? '');

if ($statusRowId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_status_row_id']);
    exit;
}
if (!in_array($newStatus, ['cooking', 'ready', 'cancelled'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_status']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();

// Look up the row first so we can figure out which order to finalize on ready.
$lookup = $pdo->prepare("SELECT order_id, status FROM order_item_status WHERE id = :id LIMIT 1");
$lookup->execute([':id' => $statusRowId]);
$existing = $lookup->fetch();
if (!$existing) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

$changed = $db->advanceKdsItemStatus($statusRowId, $newStatus);
$orderId = (int)$existing['order_id'];

$orderReady = false;
if ($changed && $newStatus === 'ready' && $orderId > 0 && $db->isOrderFullyReady($orderId)) {
    $orderReady = true;
    try {
        require_once __DIR__ . '/lib/WebhookDispatcher.php';
        $orderRow = $db->getOrderById($orderId);
        if ($orderRow) {
            WebhookDispatcher::dispatch('order.ready', $orderRow, $db);
        }
    } catch (Throwable $e) {
        error_log('order.ready dispatch error: ' . $e->getMessage());
    }

    try {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/telegram-notifications.php';
        if (defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN) {
            $tgChatId = json_decode($db->getSetting('telegram_chat_id') ?? 'null', true);
            if ($tgChatId) {
                sendTelegramMessage((string)$tgChatId, '🍽 <b>Заказ #' . $orderId . ' готов</b> — можно подавать', []);
            }
        }
    } catch (Throwable $e) {
        error_log('order.ready telegram ping error: ' . $e->getMessage());
    }
}

echo json_encode([
    'success'     => true,
    'changed'     => $changed,
    'status'      => $newStatus,
    'order_ready' => $orderReady,
]);
