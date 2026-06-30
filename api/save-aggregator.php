<?php
/**
 * api/save-aggregator.php — Phase 36 (Yandex.Еда + DC settings).
 *
 * POST JSON {
 *   action: 'save_settings' | 'save_mapping' | 'rotate_secret',
 *   provider: 'yandex_eda' | 'delivery_club',
 *   ...payload,
 *   csrf_token
 * }
 *
 * Owner-only.
 */

$required_role = 'owner';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../lib/Billing/TierGate.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

Csrf::requireValid();

// Phase L103.5c — gate behind order.aggregator feature (tier 3+ «Доставка+»).
\Cleanmenu\Billing\TierGate::requireFeature(
    \Cleanmenu\Billing\Features::ORDER_AGGREGATOR,
    'Интеграции с агрегаторами (Яндекс.Еда, Delivery Club)'
);

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

$action   = (string)($input['action'] ?? '');
$provider = (string)($input['provider'] ?? '');
$allowedProviders = ['yandex_eda', 'delivery_club'];
if (!in_array($provider, $allowedProviders, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_provider']);
    exit;
}

switch ($action) {
    case 'save_settings': {
        $apiKey  = (string)($input['api_key'] ?? '');
        $secret  = isset($input['webhook_secret']) ? (string)$input['webhook_secret'] : null;
        $enabled = (bool)($input['enabled'] ?? false);
        if (!$db->saveAggregatorSettings($provider, $apiKey, $secret, $enabled)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'save_failed']);
            exit;
        }
        AuditLog::record('aggregator.settings.save', 'aggregator_settings', $provider, [
            'enabled'    => $enabled,
            'secret_set' => $secret !== null && $secret !== '',
            'key_set'    => $apiKey !== '',
        ]);
        // Return fresh settings so UI can show the (possibly auto-generated) secret.
        $fresh = $db->getAggregatorSettings($provider);
        echo json_encode([
            'success' => true,
            'settings' => [
                'enabled'        => (int)($fresh['enabled'] ?? 0),
                'webhook_secret' => (string)($fresh['webhook_secret'] ?? ''),
            ],
        ]);
        break;
    }

    case 'rotate_secret': {
        // Generate a fresh secret and persist (preserve api_key + enabled).
        $existing = $db->getAggregatorSettings($provider);
        $newSecret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $apiKey = (string)($existing['api_key'] ?? '');
        $enabled = (bool)((int)($existing['enabled'] ?? 0));
        if (!$db->saveAggregatorSettings($provider, $apiKey, $newSecret, $enabled)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'rotate_failed']);
            exit;
        }
        AuditLog::record('aggregator.secret.rotate', 'aggregator_settings', $provider, []);
        echo json_encode(['success' => true, 'webhook_secret' => $newSecret]);
        break;
    }

    case 'save_mapping': {
        $mappings = $input['mappings'] ?? [];
        if (!is_array($mappings)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_mappings']);
            exit;
        }
        $saved = 0;
        foreach ($mappings as $m) {
            $miId = isset($m['menu_item_id']) ? (int)$m['menu_item_id'] : 0;
            if ($miId <= 0) continue;
            $extId = isset($m['external_id']) ? (string)$m['external_id'] : '';
            if ($db->saveAggregatorItemMapping($miId, $provider, $extId)) {
                $saved++;
            }
        }
        echo json_encode(['success' => true, 'saved' => $saved]);
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
