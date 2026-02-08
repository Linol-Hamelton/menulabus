<?php

$logFile = __DIR__ . '/../data/logs/api-performance.log';
if (!file_exists($logFile)) {
    fwrite(STDERR, "Log file not found: {$logFile}\n");
    exit(1);
}

$durations = [];
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (preg_match('/duration_ms=([0-9.]+)/', $line, $m)) {
        $durations[] = (float)$m[1];
    }
}

if (!$durations) {
    echo "No duration data found\n";
    exit(0);
}

sort($durations);
$count = count($durations);
$p = function (float $pct) use ($durations, $count): float {
    $idx = (int)max(0, min($count - 1, ceil(($pct / 100) * $count) - 1));
    return $durations[$idx];
};

$avg = array_sum($durations) / $count;

echo "API metrics from {$logFile}\n";
echo "count={$count}\n";
echo "avg_ms=" . number_format($avg, 2, '.', '') . "\n";
echo "p50_ms=" . number_format($p(50), 2, '.', '') . "\n";
echo "p95_ms=" . number_format($p(95), 2, '.', '') . "\n";
echo "p99_ms=" . number_format($p(99), 2, '.', '') . "\n";

