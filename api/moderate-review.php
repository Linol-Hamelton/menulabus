<?php
/**
 * api/moderate-review.php — owner/admin reply + publish toggle (Phase 8.5).
 *
 * POST JSON {
 *   action: 'set_reply' | 'toggle_publish',
 *   review_id: int,
 *   reply_text?: string,   // only for set_reply
 *   published?: bool,      // only for toggle_publish
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

$reviewId = (int)($input['review_id'] ?? 0);
$action   = (string)($input['action'] ?? '');
if ($reviewId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_review_id']);
    exit;
}

$db = Database::getInstance();

switch ($action) {
    case 'set_reply':
        $replyText = isset($input['reply_text']) ? (string)$input['reply_text'] : '';
        $ok = $db->setReviewReply($reviewId, $replyText);
        echo json_encode(['success' => $ok]);
        break;

    case 'toggle_publish':
        $published = !empty($input['published']);
        $ok = $db->setReviewPublished($reviewId, $published);
        echo json_encode(['success' => $ok, 'published' => $published]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
