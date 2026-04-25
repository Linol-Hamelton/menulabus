<?php

/**
 * purge-soft-deleted.php — hard-delete soft-deleted modifier rows
 * older than N days. Paired with track 5.5 (undo for destructive
 * actions in the admin UI).
 *
 * Usage:
 *   php scripts/orders/purge-soft-deleted.php --days=7
 *   php scripts/orders/purge-soft-deleted.php --days=7 --apply
 *
 * Default is dry-run: it shows how many rows *would* be removed without
 * touching the database. Pass --apply to commit.
 *
 * Recommended cron (daily at 04:00 local):
 *   0 4 * * *  cd /var/www/cleanmenu && php scripts/orders/purge-soft-deleted.php --days=7 --apply >> data/logs/purge-soft-deleted.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['days::', 'apply']);
$days  = max(1, min(365, (int)($options['days'] ?? 7)));
$apply = array_key_exists('apply', $options);

require_once dirname(__DIR__, 2) . '/db.php';

$db  = Database::getInstance();
$pdo = $db->getConnection();

$tables = ['modifier_groups', 'modifier_options'];
$report = [];

if (!$apply) {
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM `{$table}`
                WHERE deleted_at IS NOT NULL
                  AND deleted_at < (NOW() - INTERVAL :days DAY)
            ");
            $stmt->execute([':days' => $days]);
            $report[$table] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $report[$table] = 'error:' . $e->getMessage();
        }
    }
    fwrite(STDOUT, json_encode([
        'mode' => 'dry-run',
        'days' => $days,
        'would_delete' => $report,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

$deleted = $db->purgeSoftDeletedModifiers($days);

fwrite(STDOUT, json_encode([
    'mode'    => 'apply',
    'days'    => $days,
    'deleted' => $deleted,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
exit(0);
