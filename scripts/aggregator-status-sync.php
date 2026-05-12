<?php
/**
 * scripts/aggregator-status-sync.php — Phase 36 (outbound status push).
 *
 * CLI worker invoked from cron (recommended: every minute). Picks up orders
 * with aggregator_source != NULL where local status drifts from
 * aggregator_status, maps via adapter, posts to provider's endpoint.
 *
 * Best-effort: failures are logged + skipped, sync resumes next tick.
 *
 * Suggested cron line (in `crontab -u labus_pro_usr`):
 *   * * * * * /usr/bin/php /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/aggregator-status-sync.php >/dev/null 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Aggregator/YandexEda.php';
require_once __DIR__ . '/../lib/Aggregator/DeliveryClub.php';

$db = Database::getInstance();
$pending = $db->getOrdersPendingAggregatorPush(50);
$adapters = [
    'yandex_eda'    => \Cleanmenu\Aggregator\YandexEda::class,
    'delivery_club' => \Cleanmenu\Aggregator\DeliveryClub::class,
];
$stats = ['scanned' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => 0];

foreach ($pending as $row) {
    $stats['scanned']++;
    $provider = (string)($row['aggregator_source'] ?? '');
    $external = (string)($row['aggregator_order_id'] ?? '');
    $internal = (string)($row['status'] ?? '');
    if ($provider === '' || $external === '' || !isset($adapters[$provider])) {
        $stats['skipped']++;
        continue;
    }
    $adapter = $adapters[$provider];
    $mapped = $adapter::mapStatusOutbound($internal);
    if ($mapped === null) {
        $stats['skipped']++;
        continue;
    }

    $settings = $db->getAggregatorSettings($provider);
    if (!$settings || (int)$settings['enabled'] !== 1 || ($settings['api_key'] ?? '') === '') {
        $stats['skipped']++;
        continue;
    }

    $url = $adapter::statusPushUrl($external);
    $body = json_encode(['status' => $mapped], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $settings['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($http >= 200 && $http < 300) {
        $newAgg = $internal === 'отказ' || $internal === 'cancelled' || $internal === 'cancelled_pushed'
            ? 'cancelled_pushed'
            : ($internal === 'завершён' || $internal === 'completed' ? 'delivered_pushed' : 'pushed');
        $db->setOrderAggregatorStatus((int)$row['id'], $newAgg);
        $db->touchAggregatorPushAt($provider);
        $stats['pushed']++;
    } else {
        error_log("aggregator status-sync failed ({$provider}#{$external}, HTTP {$http}): {$err}; body=" . substr((string)$resp, 0, 200));
        $stats['failed']++;
    }
}

echo json_encode(['ok' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE) . "\n";
