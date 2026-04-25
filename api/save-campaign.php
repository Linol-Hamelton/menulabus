<?php
/**
 * api/save-campaign.php — marketing campaign CRUD + send (Phase 8.1).
 *
 * POST JSON {
 *   action: 'list' | 'save' | 'queue' | 'cancel',
 *   ...payload,
 *   csrf_token: string
 * }
 *
 * Owner/admin only.
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/AuditLog.php';

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
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$action = (string)($input['action'] ?? '');

switch ($action) {
    case 'list':
        echo json_encode([
            'success'   => true,
            'campaigns' => $db->listMarketingCampaigns(),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save':
        $id        = isset($input['id']) ? (int)$input['id'] : null;
        $name      = (string)($input['name'] ?? '');
        $channel   = (string)($input['channel'] ?? 'email');
        $subject   = isset($input['subject']) ? (string)$input['subject'] : null;
        $bodyText  = (string)($input['body_text'] ?? '');
        $bodyHtml  = isset($input['body_html']) ? (string)$input['body_html'] : null;
        $segment   = is_array($input['segment'] ?? null) ? $input['segment'] : ['type' => 'all'];
        $scheduled = isset($input['scheduled_at']) ? (string)$input['scheduled_at'] : null;

        $savedId = $db->saveMarketingCampaign($id, $name, $channel, $subject, $bodyText, $bodyHtml, $segment, $scheduled, $userId);
        if ($savedId === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        AuditLog::record('marketing.campaign_saved', 'campaign', (string)$savedId);
        echo json_encode(['success' => true, 'id' => $savedId]);
        break;

    case 'queue':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        $count = $db->queueMarketingCampaign($id);
        AuditLog::record('marketing.campaign_queued', 'campaign', (string)$id, ['queued' => $count]);
        echo json_encode(['success' => true, 'queued' => $count]);
        break;

    case 'cancel':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'invalid_id']); exit; }
        $ok = $db->updateMarketingCampaignStatus($id, 'cancelled');
        if ($ok) AuditLog::record('marketing.campaign_cancelled', 'campaign', (string)$id);
        echo json_encode(['success' => $ok]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
