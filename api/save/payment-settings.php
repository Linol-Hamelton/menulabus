<?php
/**
 * api/save/payment-settings.php — save ЮKassa payment credentials
 *
 * POST /api/save/payment-settings.php
 * Content-Type: application/json
 * Body: { "payment": { "yookassa_enabled": true, "yookassa_shop_id": "...", "yookassa_secret_key": "..." } }
 *
 * Admin / owner only.
 */

$required_role = 'admin';
require_once __DIR__ . '/../../session_init.php';
require_once __DIR__ . '/../../require_auth.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF mismatch'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payment = $input['payment'] ?? null;
if (!is_array($payment)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db      = Database::getInstance();
$userId  = (int)$_SESSION['user_id'];

$allowedBool   = ['yookassa_enabled', 'tbank_enabled'];
$allowedString = ['yookassa_shop_id', 'yookassa_secret_key', 'yookassa_return_url',
                   'tbank_terminal_key', 'tbank_password'];

foreach ($allowedBool as $key) {
    if (array_key_exists($key, $payment)) {
        $val = (bool)$payment[$key] ? 'true' : 'false';
        $db->setSetting($key, json_encode($val), $userId);
    }
}

foreach ($allowedString as $key) {
    if (array_key_exists($key, $payment)) {
        $val = strip_tags(trim((string)$payment[$key]));
        if (mb_strlen($val) > 200) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => "Значение $key слишком длинное"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Secret key: any printable non-whitespace chars (ЮKassa keys vary in format)
        if (in_array($key, ['yookassa_shop_id', 'yookassa_secret_key', 'tbank_terminal_key', 'tbank_password'], true) && $val !== '') {
            $pattern = in_array($key, ['yookassa_shop_id', 'tbank_terminal_key'], true)
                ? '/^[A-Za-z0-9_\-]{1,100}$/'
                : '/^\S{1,200}$/';
            if (!preg_match($pattern, $val)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => "Недопустимый формат $key"], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $db->setSetting($key, json_encode($val), $userId);
    }
}

// Bump app version so clients re-fetch
$_SESSION['app_version'] = time();

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
