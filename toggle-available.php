<?php
/**
 * toggle-available.php — Toggle menu item availability (stop-list)
 *
 * POST /toggle-available.php
 * Body (JSON): { "id": 42, "csrf_token": "..." }
 * Response:    { "success": true, "available": 0|1 }
 *
 * Admin / owner only.
 */

$required_role = 'admin';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF mismatch']);
    exit;
}

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid id']);
    exit;
}

$db        = Database::getInstance();
$available = $db->toggleItemAvailable($id);

if ($available === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Item not found']);
    exit;
}

// Уведомить владельца в Telegram когда блюдо снято с продажи
if ($available === 0) {
    $itemName = $db->getMenuItemName($id) ?? 'Блюдо';
    $tgChatId = json_decode($db->getSetting('telegram_chat_id') ?? 'null', true);
    if ($tgChatId) {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/telegram-notifications.php';
        sendTelegramMessage((string)$tgChatId, "⛔ Стоп-лист: «$itemName» снято с продажи");
    }
}

echo json_encode(['success' => true, 'available' => $available]);
