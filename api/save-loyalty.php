<?php
/**
 * api/save-loyalty.php — admin CRUD for loyalty tiers and promo codes.
 *
 * POST body (JSON): {
 *   action: 'list_tiers' | 'save_tier' | 'archive_tier' |
 *           'list_promos' | 'save_promo' | 'archive_promo',
 *   ...payload,
 *   csrf_token: string
 * }
 *
 * Admin/owner only.
 */

$required_role = 'admin';
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
$action = (string)($input['action'] ?? '');

switch ($action) {
    case 'list_tiers':
        echo json_encode([
            'success' => true,
            'tiers'   => $db->listLoyaltyTiers(!empty($input['include_archived'])),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save_tier':
        $id          = isset($input['id']) ? (int)$input['id'] : null;
        $name        = (string)($input['name'] ?? '');
        $minSpent    = (float)($input['min_spent'] ?? 0);
        $cashbackPct = (float)($input['cashback_pct'] ?? 0);
        $sortOrder   = (int)($input['sort_order'] ?? 0);

        $savedId = $db->saveLoyaltyTier($id, $name, $minSpent, $cashbackPct, $sortOrder);
        if ($savedId === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        echo json_encode(['success' => true, 'id' => $savedId]);
        break;

    case 'archive_tier':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        echo json_encode(['success' => $db->archiveLoyaltyTier($id)]);
        break;

    case 'list_promos':
        echo json_encode([
            'success' => true,
            'promos'  => $db->listPromoCodes(!empty($input['include_archived'])),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save_promo':
        $id              = isset($input['id']) ? (int)$input['id'] : null;
        $code            = (string)($input['code'] ?? '');
        $discountPct     = isset($input['discount_pct']) && $input['discount_pct'] !== '' ? (float)$input['discount_pct'] : null;
        $discountAmount  = isset($input['discount_amount']) && $input['discount_amount'] !== '' ? (float)$input['discount_amount'] : null;
        $minOrderTotal   = (float)($input['min_order_total'] ?? 0);
        $validFrom       = isset($input['valid_from']) ? trim((string)$input['valid_from']) : null;
        $validTo         = isset($input['valid_to']) ? trim((string)$input['valid_to']) : null;
        $usageLimit      = (int)($input['usage_limit'] ?? 0);
        $description     = isset($input['description']) ? trim((string)$input['description']) : null;
        if ($description === '') $description = null;

        $savedId = $db->savePromoCode(
            $id, $code, $discountPct, $discountAmount, $minOrderTotal,
            $validFrom, $validTo, $usageLimit, $description
        );
        if ($savedId === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        echo json_encode(['success' => true, 'id' => $savedId]);
        break;

    case 'archive_promo':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        echo json_encode(['success' => $db->archivePromoCode($id)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
