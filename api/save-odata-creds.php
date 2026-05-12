<?php
/**
 * api/save-odata-creds.php — Phase 37 (1С OData).
 *
 * POST JSON { action: 'rotate' | 'enable' | 'disable', csrf_token }
 *
 * Only owner role. Returns plaintext api_key once on `rotate`.
 */

$required_role = 'owner';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
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
$role = (string)($_SESSION['user_role'] ?? '');
if ($role !== 'owner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'owner_only']);
    exit;
}

$action = (string)($input['action'] ?? '');
switch ($action) {
    case 'rotate': {
        $resp = $db->rotateOdataCreds();
        if (!$resp) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'rotate_failed']);
            exit;
        }
        // Auto-enable on first creation if not already enabled.
        $existing = $db->getOdataCreds();
        if ($existing && (int)$existing['enabled'] !== 1) {
            $db->setOdataEnabled(true);
        }
        echo json_encode([
            'success'  => true,
            'username' => $resp['username'],
            'api_key'  => $resp['api_key'],
        ]);
        break;
    }
    case 'enable':
    case 'disable': {
        $ok = $db->setOdataEnabled($action === 'enable');
        if (!$ok) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'no_credentials']);
            exit;
        }
        echo json_encode(['success' => true]);
        break;
    }
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
