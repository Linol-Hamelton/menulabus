<?php
/**
 * api/import-recipes.php — Phase 33 endpoint for bulk-importing recipes
 * (tech-cards) from a user-uploaded CSV.
 *
 * Accepts multipart/form-data with fields:
 *   csv_file    — file upload (text/csv, UTF-8, ≤2 MB)
 *   mode        — 'merge' (default) or 'replace'
 *   auto_create — '1' (default) or '0' — whether missing ingredients are auto-created
 *   csrf_token  — required (also accepted via X-CSRF-Token header)
 *
 * Returns JSON:
 *   { success: true,
 *     summary: { inserted, updated, deleted, ingredients_created,
 *                dishes_touched, errors: [...], warnings: [...] }
 *   }
 *
 * Admin/owner only.
 */

declare(strict_types=1);

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/Billing/TierGate.php';

header('Content-Type: application/json; charset=utf-8');

// Phase L103.5e — gate behind inventory.recipes feature (tier 6+ «Кухня+»).
\Cleanmenu\Billing\TierGate::requireFeature(
    \Cleanmenu\Billing\Features::INVENTORY_RECIPES,
    'Импорт техкарт / рецептов'
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

Csrf::requireValid();

if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'no_file']);
    exit;
}
if ((int)$_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'upload_failed', 'code' => (int)$_FILES['csv_file']['error']]);
    exit;
}

$tmp = (string)$_FILES['csv_file']['tmp_name'];
if (!is_uploaded_file($tmp)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_upload']);
    exit;
}

$size = (int)$_FILES['csv_file']['size'];
if ($size <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'empty_csv']);
    exit;
}
if ($size > 2 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'csv_too_large']);
    exit;
}

$content = file_get_contents($tmp);
if ($content === false || trim($content) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'empty_csv']);
    exit;
}
if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'not_utf8']);
    exit;
}

$mode = (string)($_POST['mode'] ?? 'merge');
if (!in_array($mode, ['merge', 'replace'], true)) { $mode = 'merge'; }

$autoCreate = isset($_POST['auto_create']) ? ((string)$_POST['auto_create'] === '1') : true;

// Delimiter detection — same heuristic as bulkSyncMenuFromCsv handler.
$firstLine = strtok($content, "\r\n");
$delimiter = (is_string($firstLine) && strpos($firstLine, ',') !== false && strpos($firstLine, ';') === false) ? ',' : ';';

$handle = fopen('php://temp', 'r+');
fwrite($handle, $content);
rewind($handle);

$db = Database::getInstance();
$summary = $db->bulkSyncRecipesFromCsv($handle, $mode, $delimiter, $autoCreate);
fclose($handle);

if (!is_array($summary)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'import_failed']);
    exit;
}
if (!empty($summary['fatal'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'import_failed', 'summary' => $summary], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true, 'summary' => $summary], JSON_UNESCAPED_UNICODE);
