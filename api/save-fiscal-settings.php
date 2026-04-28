<?php
/**
 * api/save-fiscal-settings.php — owner-tab fiscal-config endpoint
 * (Phase 13A.3, 2026-04-28).
 *
 * Three modes selected by query string:
 *   ?test=1       → call AtolOnline::ensureToken() with the supplied
 *                   credentials (without saving). Returns
 *                   { success, error? }.
 *   ?reemit=N     → re-emit fiscal receipt for a legacy order id N.
 *                   Returns { success, uuid? }.
 *   default       → upsert all fiscal_* settings keys via
 *                   Database::updateSetting (JSON-encoded values).
 *
 * Auth: owner role only. CSRF: required via Csrf::requireValid().
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

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'auth_required']);
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById((int)$_SESSION['user_id']);
if (!$user || $user['role'] !== 'owner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'owner_only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// Build the candidate config from the input. Password handling: if empty
// in payload, KEEP the existing stored password (so the operator can edit
// other fields without re-typing the password).
$existingPw = (string)json_decode($db->getSetting('fiscal_atol_password') ?? '""', true);
$cfg = [
    'login'           => trim((string)($input['fiscal_atol_login'] ?? '')),
    'password'        => $input['fiscal_atol_password'] !== '' && isset($input['fiscal_atol_password'])
                            ? trim((string)$input['fiscal_atol_password'])
                            : $existingPw,
    'group_code'      => trim((string)($input['fiscal_atol_group_code'] ?? '')),
    'inn'             => trim((string)($input['fiscal_atol_inn'] ?? '')),
    'payment_address' => trim((string)($input['fiscal_atol_payment_address'] ?? '')),
    'sno'             => trim((string)($input['fiscal_atol_sno'] ?? 'usn_income')),
    'sandbox'         => !empty($input['fiscal_atol_sandbox']),
];

// ── Mode 1: test connection ────────────────────────────────────────
if (!empty($_GET['test'])) {
    foreach (['login', 'password', 'group_code', 'inn', 'payment_address'] as $req) {
        if ($cfg[$req] === '') {
            echo json_encode(['success' => false, 'error' => "missing_{$req}"]);
            exit;
        }
    }
    require_once __DIR__ . '/../lib/Fiscal/AtolOnline.php';
    try {
        $atol = new \Cleanmenu\Fiscal\AtolOnline($cfg);
        // ensureToken is private; call emitSaleReceipt with bogus data
        // would actually charge — instead test indirectly by triggering
        // the http call via reflection. Simpler: call a helper that
        // exercises auth without sale.
        $r = new ReflectionClass($atol);
        $m = $r->getMethod('ensureToken');
        $m->setAccessible(true);
        $token = (string)$m->invoke($atol);
        echo json_encode([
            'success'      => true,
            'token_prefix' => substr($token, 0, 12) . '…',
            'sandbox'      => $cfg['sandbox'],
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Mode 2: re-emit receipt for legacy order ───────────────────────
if (!empty($_GET['reemit'])) {
    $orderId = (int)$_GET['reemit'];
    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'error' => 'bad_order_id']);
        exit;
    }
    $order = $db->getOrderById($orderId);
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'order_not_found']);
        exit;
    }
    require_once __DIR__ . '/../lib/OrderPaidHook.php';
    try {
        // Drop any existing uuid so the helper actually re-emits.
        $r = new ReflectionClass($db);
        $p = $r->getProperty('connection');
        $p->setAccessible(true);
        $pdo = $p->getValue($db);
        $pdo->prepare('UPDATE orders SET fiscal_receipt_uuid = NULL, fiscal_receipt_url = NULL WHERE id = :id')
            ->execute([':id' => $orderId]);
        $order['fiscal_receipt_uuid'] = null;

        cleanmenu_emit_fiscal_receipt($db, $order);

        // Re-read to confirm uuid landed.
        $reread = $db->getOrderById($orderId);
        echo json_encode([
            'success' => true,
            'uuid'    => $reread['fiscal_receipt_uuid'] ?? null,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Mode 3: save all settings ──────────────────────────────────────
$provider = trim((string)($input['fiscal_provider'] ?? ''));
if (!in_array($provider, ['', 'atol'], true)) {
    echo json_encode(['success' => false, 'error' => 'bad_provider']);
    exit;
}

$db->setSetting('fiscal_provider',              json_encode($provider, JSON_UNESCAPED_UNICODE));
$db->setSetting('fiscal_atol_login',            json_encode($cfg['login'], JSON_UNESCAPED_UNICODE));
$db->setSetting('fiscal_atol_password',         json_encode($cfg['password'], JSON_UNESCAPED_UNICODE));
$db->setSetting('fiscal_atol_group_code',       json_encode($cfg['group_code'], JSON_UNESCAPED_UNICODE));
$db->setSetting('fiscal_atol_inn',              json_encode($cfg['inn'], JSON_UNESCAPED_UNICODE));
$db->setSetting('fiscal_atol_payment_address',  json_encode($cfg['payment_address'], JSON_UNESCAPED_UNICODE));
$db->setSetting('fiscal_atol_sno',              json_encode($cfg['sno'], JSON_UNESCAPED_UNICODE));
$db->setSetting('fiscal_atol_sandbox',          json_encode($cfg['sandbox'] ? '1' : '0', JSON_UNESCAPED_UNICODE));

echo json_encode(['success' => true, 'provider' => $provider]);
