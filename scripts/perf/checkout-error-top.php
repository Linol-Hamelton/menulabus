<?php

/**
 * Checkout error summary from PHP error log lines emitted by CheckoutErrorLog.
 *
 * Example:
 * php scripts/perf/checkout-error-top.php --log=/var/www/labus_pro_usr/data/logs/menu.labus.pro-php.error.log --hours=24 --top=3
 */

$opts = getopt('', ['log:', 'hours::', 'top::']);
$logPath = (string)($opts['log'] ?? '');
$hours = max(1, (int)($opts['hours'] ?? 24));
$top = max(1, (int)($opts['top'] ?? 3));

if ($logPath === '') {
    $defaultCandidates = [
        '/var/www/labus_pro_usr/data/logs/menu.labus.pro-php.log',
        dirname(__DIR__, 2) . '/data/logs/menu.labus.pro-php.log',
    ];
    foreach ($defaultCandidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $logPath = $candidate;
            break;
        }
    }
}

if ($logPath === '' || !is_file($logPath) || !is_readable($logPath)) {
    fwrite(STDERR, "Usage: php scripts/perf/checkout-error-top.php --log=/path/to/php-error.log [--hours=24] [--top=3]\n");
    exit(1);
}

$cutoffTs = time() - ($hours * 3600);
$fh = fopen($logPath, 'rb');
if ($fh === false) {
    fwrite(STDERR, "Cannot open log: {$logPath}\n");
    exit(1);
}

$byReason = [];
$byCategory = [];
$total = 0;

while (($line = fgets($fh)) !== false) {
    $markerPos = strpos($line, '[checkout-error] ');
    if ($markerPos === false) {
        continue;
    }

    $jsonPart = substr($line, $markerPos + strlen('[checkout-error] '));
    $payload = json_decode(trim($jsonPart), true);
    if (!is_array($payload)) {
        continue;
    }

    $eventTs = strtotime((string)($payload['ts'] ?? ''));
    if ($eventTs === false || $eventTs < $cutoffTs) {
        continue;
    }

    $category = (string)($payload['category'] ?? 'unknown');
    $reason = (string)($payload['reason'] ?? 'unknown');
    $key = $category . ' / ' . $reason;

    $byReason[$key] = ($byReason[$key] ?? 0) + 1;
    $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
    $total++;
}
fclose($fh);

arsort($byReason);
arsort($byCategory);

echo "Checkout error summary (last {$hours}h)\n";
echo "Log: {$logPath}\n";
echo "Total matched events: {$total}\n\n";

echo "Top {$top} reasons:\n";
if (empty($byReason)) {
    echo "  (no checkout-error events in selected window)\n";
} else {
    $i = 0;
    foreach ($byReason as $key => $count) {
        $i++;
        echo sprintf("  %d) %s => %d\n", $i, $key, $count);
        if ($i >= $top) {
            break;
        }
    }
}

echo "\nBy category:\n";
if (empty($byCategory)) {
    echo "  (no data)\n";
} else {
    foreach ($byCategory as $category => $count) {
        echo sprintf("  %s => %d\n", $category, $count);
    }
}
