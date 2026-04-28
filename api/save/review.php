<?php
// api/save/review.php — customer feedback submission endpoint.
//
// Accessible to guests and logged-in users (the order tracker page is public,
// so review submission must be too). Protections:
//
//   1. CSRF token from session (validated below) — requires the submitter to
//      have a live session that visited a page on this host.
//   2. Per-session "viewed tracked orders" allow-list written by
//      order-track.php when it renders — a client cannot POST a review for
//      order N unless the same session loaded /order-track.php?id=N first.
//      This is the practical "ownership" proof for guests who have no user_id.
//   3. Unique (order_id) index in the reviews table — the DB refuses a second
//      submission on the same order even if the session guard is bypassed.
//   4. Rating clamped to 1..5 at two layers (here and the DB CHECK).
//   5. Order must exist and be in the terminal `завершён` state — you cannot
//      review a pending or cancelled order.
//
// Response shape on success:
//   { "success": true, "review_id": 42, "rating": 5, "google_review_url": "https://..." | null }
//
// On failure: HTTP 4xx with { "error": "..." }.

require_once __DIR__ . '/../../session_init.php';
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (!is_string($csrfToken) || $csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF mismatch']);
    exit;
}

$orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
$rating  = isset($input['rating']) ? (int)$input['rating'] : 0;
$comment = isset($input['comment']) ? (string)$input['comment'] : '';

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'order_id required']);
    exit;
}
if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['error' => 'rating must be between 1 and 5']);
    exit;
}

// Session-scoped ownership proof: only orders the same session viewed via
// /order-track.php can be reviewed from this session. order-track.php writes
// the seen order id into $_SESSION['reviewable_orders'] on render.
$seen = $_SESSION['reviewable_orders'] ?? [];
if (!is_array($seen) || !in_array($orderId, $seen, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Order not accessible from this session']);
    exit;
}

$db = Database::getInstance();

$order = $db->getOrderById($orderId);
if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found']);
    exit;
}

$normalizedStatus = mb_strtolower(trim((string)($order['status'] ?? '')));
if ($normalizedStatus !== 'завершён') {
    http_response_code(409);
    echo json_encode(['error' => 'Order is not completed yet']);
    exit;
}

// Short-circuit duplicates before the INSERT so the client gets a clean 409
// instead of relying on the unique-index race. The INSERT path still catches
// a racy duplicate via SQLSTATE 23000 → createReview() returns null.
$existing = $db->getReviewByOrderId($orderId);
if ($existing !== null) {
    http_response_code(409);
    echo json_encode([
        'error' => 'Review already submitted',
        'existing' => [
            'rating' => (int)$existing['rating'],
            'created_at' => (string)$existing['created_at'],
        ],
    ]);
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
// For logged-in submitters, require the review to be on their own order.
// Guests pass through this check because $userId is null.
if ($userId !== null) {
    $orderOwnerId = isset($order['user_id']) ? (int)$order['user_id'] : 0;
    if ($orderOwnerId !== 0 && $orderOwnerId !== $userId) {
        http_response_code(403);
        echo json_encode(['error' => 'Not your order']);
        exit;
    }
}

// Hash the IP for rate-limit/abuse review without storing raw PII. The salt
// is per-session so the same IP across different sessions hashes differently;
// this is deliberate — reviews are append-only and we never need to correlate
// submissions across sessions.
$ipRaw = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ipHash = $ipRaw !== '' ? hash('sha256', $ipRaw . '|' . session_id()) : null;

$reviewId = $db->createReview($orderId, $rating, $comment === '' ? null : $comment, $userId, $ipHash);
if ($reviewId === null) {
    // Either the DB refused (likely duplicate via racy insert) or a soft
    // error. The getReviewByOrderId check above already handled the clean
    // path; if we land here it's almost certainly a race — tell the client
    // to reload to see the existing review.
    http_response_code(409);
    echo json_encode(['error' => 'Review could not be stored (likely already submitted)']);
    exit;
}

// Surface the Google review deep-link for 5-star submissions only.
$googleReviewUrl = null;
if ($rating === 5) {
    $raw = $db->getSetting('google_review_url');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $candidate = is_string($decoded) ? $decoded : $raw;
        if (filter_var($candidate, FILTER_VALIDATE_URL)) {
            $googleReviewUrl = $candidate;
        }
    }
}

echo json_encode([
    'success' => true,
    'review_id' => $reviewId,
    'rating' => $rating,
    'google_review_url' => $googleReviewUrl,
]);
