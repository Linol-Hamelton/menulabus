<?php
/**
 * api/save-webhook.php — CRUD endpoint for outgoing webhook subscriptions.
 *
 * POST body (JSON): {
 *   action: 'list' | 'create' | 'update' | 'delete' | 'rotate_secret' | 'history',
 *   id?: int,             // for update/delete/rotate_secret/history
 *   event_type?: string,  // create / update
 *   target_url?: string,  // create / update
 *   description?: string, // create / update
 *   active?: bool,        // create / update
 *   csrf_token: string
 * }
 *
 * Admin/owner only. See docs/webhook-integration.md for the full event list.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/WebhookDispatcher.php';

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

$db     = Database::getInstance();
$action = (string)($input['action'] ?? '');

function webhook_validate_event_type(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 64) {
        return null;
    }
    if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $value)) {
        return null;
    }
    return $value;
}

function webhook_validate_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 512) {
        return null;
    }
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    return $value;
}

switch ($action) {
    case 'list':
        $rows = $db->listWebhooks();
        // never echo the secret in the list view
        foreach ($rows as &$row) { unset($row['secret']); }
        echo json_encode(['success' => true, 'webhooks' => $rows], JSON_UNESCAPED_UNICODE);
        break;

    case 'create':
        $event = webhook_validate_event_type((string)($input['event_type'] ?? ''));
        $url   = webhook_validate_url((string)($input['target_url'] ?? ''));
        if ($event === null || $url === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        $description = isset($input['description']) ? trim((string)$input['description']) : null;
        $active      = isset($input['active']) ? (bool)$input['active'] : true;
        $secret      = WebhookDispatcher::generateSecret();
        $id = $db->createWebhook($event, $url, $secret, $description, $active);
        if ($id === null) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'create_failed']);
            exit;
        }
        // Return the secret exactly once on creation so the operator can copy it.
        echo json_encode(['success' => true, 'id' => $id, 'secret' => $secret], JSON_UNESCAPED_UNICODE);
        break;

    case 'update':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        $event = isset($input['event_type']) ? webhook_validate_event_type((string)$input['event_type']) : null;
        $url   = isset($input['target_url']) ? webhook_validate_url((string)$input['target_url']) : null;
        if ((isset($input['event_type']) && $event === null)
            || (isset($input['target_url']) && $url === null)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        $description = array_key_exists('description', $input) ? trim((string)$input['description']) : null;
        $active = array_key_exists('active', $input) ? (bool)$input['active'] : null;
        $ok = $db->updateWebhook($id, $event, $url, $description, $active);
        echo json_encode(['success' => $ok]);
        break;

    case 'delete':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        echo json_encode(['success' => $db->deleteWebhook($id)]);
        break;

    case 'rotate_secret':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        $secret = WebhookDispatcher::generateSecret();
        try {
            $stmt = $db->getConnection()->prepare("UPDATE outgoing_webhooks SET secret = :secret WHERE id = :id");
            $ok = $stmt->execute([':secret' => $secret, ':id' => $id]);
        } catch (Throwable $e) {
            error_log('rotate_secret error: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) { http_response_code(500); echo json_encode(['success' => false, 'error' => 'rotate_failed']); exit; }
        echo json_encode(['success' => true, 'secret' => $secret], JSON_UNESCAPED_UNICODE);
        break;

    case 'history':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        $rows = $db->getRecentWebhookDeliveries($id, 20);
        echo json_encode(['success' => true, 'deliveries' => $rows], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
