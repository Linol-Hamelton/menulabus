<?php
/**
 * api/analytics-v2.php — read-only analytics endpoint for the owner surface.
 *
 * GET query: action=margins|cohorts|heatmap|forecast|bundle
 *
 * `bundle` returns everything in one roundtrip — preferred by the owner UI
 * to avoid four concurrent requests. Individual actions are kept for
 * scripted / cron-style consumers (e.g. a nightly export).
 *
 * Owner-only. Session auth, no CSRF (GET, read-only).
 */

$required_role = 'owner';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$role = (string)($_SESSION['user_role'] ?? '');
if (!in_array($role, ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$action = (string)($_GET['action'] ?? 'bundle');

$fromRaw = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
$toRaw   = (string)($_GET['to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_date']);
    exit;
}
$fromDt = $fromRaw . ' 00:00:00';
// Half-open interval: end-of-day exclusive so a query for "2026-04-23..2026-04-23" covers the full day.
$toDt   = date('Y-m-d 00:00:00', strtotime($toRaw . ' +1 day'));

$heatDays = max(7, min(365, (int)($_GET['heat_days'] ?? 30)));
$marginsLimit = max(1, min(100, (int)($_GET['margins_limit'] ?? 20)));
$cohortsLimit = max(1, min(24, (int)($_GET['cohorts_limit'] ?? 12)));

$db = Database::getInstance();

function analyticsV2Run(string $action, Database $db, array $p): array {
    switch ($action) {
        case 'margins':
            return ['margins' => $db->getDishMargins($p['fromDt'], $p['toDt'], $p['marginsLimit'])];
        case 'cohorts':
            return ['cohorts' => $db->getCustomerCohorts($p['cohortsLimit'])];
        case 'heatmap':
            return ['heatmap' => $db->getHourlyHeatmap($p['heatDays'])];
        case 'forecast':
            return ['forecast' => $db->forecastNextWeekRevenue()];
        case 'bundle':
            return [
                'margins'  => $db->getDishMargins($p['fromDt'], $p['toDt'], $p['marginsLimit']),
                'cohorts'  => $db->getCustomerCohorts($p['cohortsLimit']),
                'heatmap'  => $db->getHourlyHeatmap($p['heatDays']),
                'forecast' => $db->forecastNextWeekRevenue(),
            ];
    }
    return [];
}

if (!in_array($action, ['margins', 'cohorts', 'heatmap', 'forecast', 'bundle'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unknown_action']);
    exit;
}

$data = analyticsV2Run($action, $db, [
    'fromDt' => $fromDt,
    'toDt'   => $toDt,
    'heatDays' => $heatDays,
    'marginsLimit' => $marginsLimit,
    'cohortsLimit' => $cohortsLimit,
]);

echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
