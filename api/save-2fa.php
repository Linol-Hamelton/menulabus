<?php
/**
 * api/save-2fa.php — TOTP setup, verify, disable (Phase 9.3).
 *
 * POST JSON {
 *   action: 'setup' | 'enable' | 'disable' | 'regenerate_backup',
 *   code?: string,            // for enable + disable
 *   csrf_token: string
 * }
 *
 * Self-only — operates on the current session user. CSRF required.
 *
 * setup:
 *   - Generates a fresh secret + provisioning URI; saves with enabled=0.
 *   - Returns { secret, uri }. Caller renders QR client-side from uri.
 * enable:
 *   - Reads pending secret, verifies the submitted TOTP code, flips
 *     enabled=1, generates 10 backup codes, returns them ONCE.
 * disable:
 *   - Verifies the submitted TOTP code (or backup code), wipes the row.
 * regenerate_backup:
 *   - Verifies the submitted code, generates fresh backup codes, returns them.
 */

require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/Totp.php';
require_once __DIR__ . '/../lib/AuditLog.php';

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

Csrf::requireValid();

$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) { $input = $_POST; }

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];
$user = $db->getUserById($userId);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'user_not_found']);
    exit;
}

$action = (string)($input['action'] ?? '');
$existing = $db->getUser2FA($userId);

switch ($action) {
    case 'setup':
        // If 2FA is already enabled, the user must disable it first.
        if ($existing && (int)$existing['enabled'] === 1) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'already_enabled']);
            exit;
        }
        $secret = Totp::generateSecret();
        $issuer = $GLOBALS['siteName'] ?? 'CleanMenu';
        $accountLabel = (string)($user['email'] ?? ('user-' . $userId));
        $uri = Totp::provisioningUri($secret, $accountLabel, (string)$issuer);

        if (!$db->saveUser2FA($userId, $secret, false, null)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'save_failed']);
            exit;
        }
        AuditLog::record('2fa.setup_started', 'user', (string)$userId);
        echo json_encode(['success' => true, 'secret' => $secret, 'uri' => $uri], JSON_UNESCAPED_UNICODE);
        break;

    case 'enable':
        if (!$existing) { http_response_code(409); echo json_encode(['success' => false, 'error' => 'no_pending_setup']); exit; }
        $code = trim((string)($input['code'] ?? ''));
        if (!Totp::verify((string)$existing['secret'], $code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'bad_code']);
            exit;
        }
        $codes = Totp::generateBackupCodes();
        if (!$db->saveUser2FA($userId, (string)$existing['secret'], true, $codes['hashed'])) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'save_failed']);
            exit;
        }
        AuditLog::record('2fa.enabled', 'user', (string)$userId);
        echo json_encode(['success' => true, 'backup_codes' => $codes['raw']], JSON_UNESCAPED_UNICODE);
        break;

    case 'disable':
        if (!$existing || (int)$existing['enabled'] !== 1) {
            http_response_code(409); echo json_encode(['success' => false, 'error' => 'not_enabled']); exit;
        }
        $code = trim((string)($input['code'] ?? ''));
        $ok = Totp::verify((string)$existing['secret'], $code);
        if (!$ok) {
            // Try backup code.
            $hashed = json_decode((string)($existing['backup_codes_json'] ?? '[]'), true) ?: [];
            $left = Totp::consumeBackupCode($hashed, $code);
            if ($left === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'bad_code']);
                exit;
            }
        }
        $db->deleteUser2FA($userId);
        AuditLog::record('2fa.disabled', 'user', (string)$userId);
        echo json_encode(['success' => true]);
        break;

    case 'regenerate_backup':
        if (!$existing || (int)$existing['enabled'] !== 1) {
            http_response_code(409); echo json_encode(['success' => false, 'error' => 'not_enabled']); exit;
        }
        $code = trim((string)($input['code'] ?? ''));
        if (!Totp::verify((string)$existing['secret'], $code)) {
            http_response_code(400); echo json_encode(['success' => false, 'error' => 'bad_code']); exit;
        }
        $codes = Totp::generateBackupCodes();
        $db->saveUser2FA($userId, (string)$existing['secret'], true, $codes['hashed']);
        AuditLog::record('2fa.backup_regenerated', 'user', (string)$userId);
        echo json_encode(['success' => true, 'backup_codes' => $codes['raw']], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
