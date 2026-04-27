<?php
/**
 * payroll-export.php — CSV export of staff payroll data (Phase 7.4 v2, 2026-04-28).
 *
 * Aggregates per-user totals for a pay period:
 *   * hours        : SUM of clocked time across time_entries
 *   * base_pay     : hours × user.hourly_rate (or 0 if not set)
 *   * tips_share   : amount allocated by tip_splits / tips_manual_overrides
 *   * total        : base_pay + tips_share
 *
 * Output: CSV to stdout (or --out=PATH file) so accounting can import.
 *
 * Usage:
 *   php scripts/payroll-export.php --period=2026-04
 *   php scripts/payroll-export.php --from=2026-04-01 --to=2026-04-30
 *   php scripts/payroll-export.php --period=2026-04 --out=/tmp/payroll-2026-04.csv
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['period::', 'from::', 'to::', 'out::', 'help']);

if (array_key_exists('help', $opts) || (empty($opts['period']) && (empty($opts['from']) || empty($opts['to'])))) {
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php scripts/payroll-export.php --period=YYYY-MM [--out=PATH]\n");
    fwrite(STDOUT, "  php scripts/payroll-export.php --from=YYYY-MM-DD --to=YYYY-MM-DD [--out=PATH]\n");
    exit(0);
}

if (!empty($opts['period']) && preg_match('/^\d{4}-\d{2}$/', (string)$opts['period'])) {
    $month = (string)$opts['period'];
    $from = $month . '-01';
    $to   = date('Y-m-t', strtotime($from));
} else {
    $from = (string)$opts['from'];
    $to   = (string)$opts['to'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        fwrite(STDERR, "Invalid date format. Use YYYY-MM-DD.\n");
        exit(1);
    }
}

require_once __DIR__ . '/../db.php';
$db = Database::getInstance();

$r = new ReflectionClass($db);
$p = $r->getProperty('connection');
$p->setAccessible(true);
$pdo = $p->getValue($db);

$rows = $pdo->prepare("
    SELECT
        u.id              AS user_id,
        u.name            AS name,
        u.email           AS email,
        u.role            AS role,
        u.hourly_rate     AS hourly_rate,
        COALESCE(SUM(
            TIMESTAMPDIFF(SECOND, te.clocked_in_at, COALESCE(te.clocked_out_at, te.clocked_in_at))
        ), 0) / 3600.0    AS hours,
        COALESCE(SUM(ts.amount), 0)    AS pooled_tips,
        COALESCE(SUM(tmo.amount), 0)   AS manual_tips
    FROM users u
    LEFT JOIN time_entries te
      ON te.user_id = u.id
     AND te.clocked_in_at >= :from
     AND te.clocked_in_at <= CONCAT(:to, ' 23:59:59')
    LEFT JOIN tip_splits ts
      ON ts.user_id = u.id
     AND ts.period_start >= :from
     AND ts.period_end <= :to
    LEFT JOIN tips_manual_overrides tmo
      ON tmo.user_id = u.id
     AND tmo.rule_id IN (
         SELECT id FROM tips_distribution_rules
         WHERE period_start >= :from AND period_end <= :to
     )
    WHERE u.role IN ('employee', 'admin', 'owner')
      AND u.is_active = 1
    GROUP BY u.id, u.name, u.email, u.role, u.hourly_rate
    ORDER BY u.role DESC, u.name ASC
");
$rows->execute([':from' => $from, ':to' => $to]);
$data = $rows->fetchAll(PDO::FETCH_ASSOC);

$outPath = $opts['out'] ?? null;
$fh = $outPath ? fopen($outPath, 'w') : fopen('php://stdout', 'w');
if (!$fh) {
    fwrite(STDERR, "Cannot open output: " . ($outPath ?: 'stdout') . "\n");
    exit(1);
}

// Header
fputcsv($fh, [
    'period_from', 'period_to',
    'user_id', 'name', 'email', 'role',
    'hours', 'hourly_rate', 'base_pay',
    'tips_pooled', 'tips_manual', 'tips_total',
    'total',
]);

foreach ($data as $row) {
    $hours       = round((float)$row['hours'], 2);
    $rate        = (float)($row['hourly_rate'] ?? 0);
    $base        = round($hours * $rate, 2);
    $tipsPooled  = round((float)$row['pooled_tips'], 2);
    $tipsManual  = round((float)$row['manual_tips'], 2);
    $tipsTotal   = $tipsPooled + $tipsManual;
    $total       = $base + $tipsTotal;

    fputcsv($fh, [
        $from, $to,
        (int)$row['user_id'],
        (string)$row['name'],
        (string)$row['email'],
        (string)$row['role'],
        $hours, $rate, $base,
        $tipsPooled, $tipsManual, $tipsTotal,
        $total,
    ]);
}

fclose($fh);
fwrite(STDERR, "[payroll-export] period={$from}..{$to} rows=" . count($data) . " out=" . ($outPath ?: '<stdout>') . "\n");
