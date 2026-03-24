<?php

require_once dirname(__DIR__, 2) . '/session_init.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/lib/orders/lifecycle.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'apply',
    'older-than::',
    'user-id::',
]);

$thresholdMinutes = max(1, (int)($options['older-than'] ?? cleanmenu_order_stale_threshold_minutes()));
$userId = isset($options['user-id']) ? (int)$options['user-id'] : null;
$apply = array_key_exists('apply', $options);

$db = Database::getInstance();

if (!$apply) {
    $staleOrders = $db->getStaleOrders($thresholdMinutes);
    $payload = [
        'ok' => true,
        'mode' => 'dry-run',
        'threshold_minutes' => $thresholdMinutes,
        'count' => count($staleOrders),
        'order_ids' => array_values(array_map(
            static fn(array $order): int => (int)($order['id'] ?? 0),
            $staleOrders
        )),
    ];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$result = $db->cleanupStaleOrders($thresholdMinutes, $userId);
$result['ok'] = empty($result['error']);
$result['mode'] = 'apply';

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(empty($result['error']) ? 0 : 1);
