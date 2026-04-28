<?php
/**
 * api/save-group-order.php — shared tab CRUD (Phase 8.3).
 *
 * POST body (JSON): {
 *   action: 'create' | 'add_item' | 'remove_item' | 'submit',
 *   ...payload,
 *   csrf_token: string
 * }
 *
 * `create` / `add_item` / `remove_item` are open to any session; `submit`
 * freezes the group into orders. CSRF required on all.
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
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

switch ($action) {
    case 'create':
        $tableLabel = isset($input['table_label']) ? (string)$input['table_label'] : null;
        $locationId = isset($input['location_id']) && $input['location_id'] !== '' ? (int)$input['location_id'] : null;
        $group = $db->createGroupOrder($userId, $tableLabel, $locationId);
        if (!$group) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'create_failed']);
            exit;
        }
        echo json_encode(['success' => true, 'id' => $group['id'], 'code' => $group['code']], JSON_UNESCAPED_UNICODE);
        break;

    case 'add_item':
        $code = (string)($input['code'] ?? '');
        $seat = (string)($input['seat_label'] ?? '');
        $mid  = (int)($input['menu_item_id'] ?? 0);
        $qty  = (int)($input['quantity'] ?? 1);
        $note = isset($input['note']) ? (string)$input['note'] : null;
        $group = $db->getGroupOrderByCode($code);
        if (!$group || $group['status'] !== 'open') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'group_not_open']);
            exit;
        }
        $id = $db->addGroupOrderItem((int)$group['id'], $seat, $mid, $qty, $note, $userId);
        if ($id === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'add_failed']);
            exit;
        }
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'remove_item':
        $code = (string)($input['code'] ?? '');
        $itemId = (int)($input['item_id'] ?? 0);
        $group = $db->getGroupOrderByCode($code);
        if (!$group || $group['status'] !== 'open') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'group_not_open']);
            exit;
        }
        echo json_encode(['success' => $db->removeGroupOrderItem($itemId, (int)$group['id'])]);
        break;

    case 'submit':
        $code = (string)($input['code'] ?? '');
        $mode = (string)($input['mode'] ?? 'single');
        $group = $db->getGroupOrderByCode($code);
        if (!$group || $group['status'] !== 'open') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'group_not_open']);
            exit;
        }
        // Only host (if logged-in) or staff can submit. Guests without session
        // user_id can submit if the group host_user_id is also NULL (pure guest flow).
        $role = (string)($_SESSION['user_role'] ?? '');
        $isStaff = in_array($role, ['employee', 'admin', 'owner'], true);
        $isHost  = $group['host_user_id'] !== null && $userId !== null && (int)$group['host_user_id'] === $userId;
        $isGuestFlow = $group['host_user_id'] === null;
        if (!$isStaff && !$isHost && !$isGuestFlow) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'not_host']);
            exit;
        }
        $orderIds = $db->submitGroupOrder((int)$group['id'], $mode);
        if ($orderIds === null) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'submit_failed']);
            exit;
        }

        // Cross-cutting hooks for every spawned order — mirrors the contract
        // that create_new_order.php enforces. Without these, group orders
        // would silently bypass KDS routing, inventory deduction, the
        // order.created webhook, and the staff Telegram ping.
        require_once __DIR__ . '/../lib/WebhookDispatcher.php';
        require_once __DIR__ . '/../config.php';
        require_once __DIR__ . '/../telegram-notifications.php';

        $tgChatId = json_decode($db->getSetting('telegram_chat_id') ?? 'null', true);

        foreach ($orderIds as $oid) {
            try {
                $orderRow = $db->getOrderById((int)$oid);
                if (!$orderRow) continue;

                $orderItems = is_string($orderRow['items'] ?? null)
                    ? (json_decode((string)$orderRow['items'], true) ?: [])
                    : (array)($orderRow['items'] ?? []);

                // KDS routing per spawned order.
                try { $db->routeOrderItemsToStations((int)$oid, $orderItems); }
                catch (Throwable $kdsEx) { error_log('group submit KDS error: ' . $kdsEx->getMessage()); }

                // Inventory deduction + low-stock alerts.
                try {
                    $nowLow = $db->deductIngredientsForOrder((int)$oid, $orderItems);
                    if (!empty($nowLow)) {
                        $alertIds = $db->markIngredientsAlerted($nowLow, 60);
                        foreach ($alertIds as $iid) {
                            $ing = $db->getIngredientById((int)$iid);
                            if (!$ing) continue;
                            if ($tgChatId && defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN) {
                                $text = '⚠️ <b>Низкий остаток:</b> ' . htmlspecialchars((string)$ing['name'])
                                      . ' — ' . rtrim(rtrim(number_format((float)$ing['stock_qty'], 3, '.', ''), '0'), '.')
                                      . ' ' . htmlspecialchars((string)$ing['unit']);
                                sendTelegramMessage((string)$tgChatId, $text);
                            }
                            WebhookDispatcher::dispatch('inventory.stock_low', $ing, $db);
                        }
                    }
                } catch (Throwable $invEx) { error_log('group submit inventory error: ' . $invEx->getMessage()); }

                // Telegram order card with accept/reject buttons.
                try {
                    if ($tgChatId && defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN) {
                        sendOrderToTelegram((int)$oid, $orderRow, $db);
                    }
                } catch (Throwable $tgEx) { error_log('group submit telegram error: ' . $tgEx->getMessage()); }

                // order.created webhook for external integrations.
                try { WebhookDispatcher::dispatch('order.created', $orderRow, $db); }
                catch (Throwable $whEx) { error_log('group submit webhook error: ' . $whEx->getMessage()); }
            } catch (Throwable $hookEx) {
                error_log('group submit per-order hook error: ' . $hookEx->getMessage());
            }
        }

        // Group-level event: lets a consumer correlate all spawned orders to
        // the original shared tab.
        try {
            WebhookDispatcher::dispatch('group_order.submitted', [
                'group_code' => $group['code'],
                'order_ids'  => $orderIds,
                'mode'       => $mode,
                'table_label'=> $group['table_label'],
            ], $db);
        } catch (Throwable $e) { error_log('group_order webhook error: ' . $e->getMessage()); }

        echo json_encode(['success' => true, 'order_ids' => $orderIds, 'mode' => $mode]);
        break;

    case 'set_split_mode':
        // Phase 13A.1 — picker on /group.php payment block flips
        // group_orders.split_mode between host / per_seat / equal.
        $code = trim((string)($input['group_code'] ?? ''));
        $mode = trim((string)($input['split_mode'] ?? ''));
        if ($code === '') { http_response_code(400); echo json_encode(['success' => false, 'error' => 'missing_code']); break; }
        $g = $db->getGroupOrderByCode($code);
        if (!$g) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'group_not_found']); break; }
        if (!$db->setGroupOrderSplitMode((int)$g['id'], $mode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_mode_or_save_failed']);
            break;
        }
        echo json_encode(['success' => true, 'split_mode' => $mode]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
