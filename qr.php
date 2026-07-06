<?php
/**
 * qr.php — Server-side QR code proxy
 *
 * GET /qr.php?url=ENCODED_URL        – returns PNG image inline
 * GET /qr.php?url=ENCODED_URL&dl=1   – returns PNG with Content-Disposition: attachment
 *
 * admin / owner / employee can request this endpoint.
 * URL parameter must point to the same host (prevents open-proxy abuse).
 */

$required_role = 'employee';
require_once __DIR__ . '/session_init.php';

// Phase L103.9 — guests who guess /qr.php?table=N (or follow a legacy link)
// get the real guest QR landing instead of an auth wall. The staff PNG-proxy
// contract (?url=...) is untouched: this fires only when `table` is present
// and `url` is not.
if (isset($_GET['table']) && !isset($_GET['url'])) {
    $qrTable = max(1, min((int)$_GET['table'], 999));
    header('Location: /menu.php?table=' . $qrTable, true, 302);
    exit;
}

require_once __DIR__ . '/require_auth.php';

$url      = trim($_GET['url'] ?? '');
$download = !empty($_GET['dl']);

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit;
}

// Security: only QR-encode URLs on our own host
$parsed = parse_url($url);
if (($parsed['host'] ?? '') !== $_SERVER['HTTP_HOST']) {
    http_response_code(403);
    exit;
}

$apiUrl = 'https://api.qrserver.com/v1/create-qr-code/'
        . '?size=256x256&format=png&data=' . urlencode($url);

$ctx  = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
$data = @file_get_contents($apiUrl, false, $ctx);

if ($data === false || strlen($data) < 100) {
    http_response_code(502);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
header(
    $download
        ? 'Content-Disposition: attachment; filename="menu-qr.png"'
        : 'Content-Disposition: inline'
);
echo $data;
