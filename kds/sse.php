<?php
/**
 * kds-sse.php — Server-Sent Events feed for the Kitchen Display System.
 *
 * Query: ?station=<id>  (0 = unrouted tab, absent = 400)
 *        ?t=<last_known_ts>  (int, optional; re-send immediately if newer data exists)
 *
 * Protocol: matches orders-sse.php conventions. Emits `update` events with the
 * full current board payload for that station; `ping` events keep the
 * connection warm. Session lock is released early so the long poll does not
 * block other same-user requests.
 */

define('LABUS_CTX', 'sse');
require_once __DIR__ . '/../session_init.php';

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Accel-Buffering: no');

ignore_user_abort(true);
@set_time_limit(35);

$role = (string)($_SESSION['user_role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($userId <= 0 || !in_array($role, ['employee', 'admin', 'owner'], true)) {
    http_response_code(401);
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'auth_required'], JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
    exit;
}

if (!isset($_GET['station'])) {
    http_response_code(400);
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'station_required'], JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
    exit;
}

$rawStation = (string)$_GET['station'];
$stationId = ($rawStation === '0') ? null : (int)$rawStation;
if ($stationId !== null && $stationId <= 0) {
    http_response_code(400);
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'invalid_station'], JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
    exit;
}

require_once __DIR__ . '/../db.php';
$db = Database::getInstance();

$lastKnown = (int)($_GET['t'] ?? 0);
$startedAt = time();
$sentUpdate = false;
$lastPingAt = 0;

while (!connection_aborted() && (time() - $startedAt) < 25) {
    $lastTs = $db->getKdsLastUpdateTs($stationId);
    if ($lastTs > $lastKnown) {
        $board = $db->getKdsBoardForStation($stationId);
        $payload = [
            'timestamp' => $lastTs,
            'station_id' => $stationId,
            'items' => $board,
        ];
        echo "event: update\n";
        echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        @flush();
        $sentUpdate = true;
        break;
    }

    if ((time() - $lastPingAt) >= 10) {
        echo "event: ping\n";
        echo "data: " . json_encode(['timestamp' => time()], JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        @flush();
        $lastPingAt = time();
    }

    sleep(2);
}

if (!$sentUpdate && !connection_aborted()) {
    echo "event: ping\n";
    echo "data: " . json_encode(['timestamp' => time()], JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
}
