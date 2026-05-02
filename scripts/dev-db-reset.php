<?php
/**
 * scripts/dev-db-reset.php — wipe test transactional data (Phase 13C.3, 2026-04-28).
 *
 * Use case: project is in development, no real customers yet. The DB
 * has accumulated test orders, reservations, group bookings, loyalty
 * transactions, marketing sends, webhook delivery rows, stock
 * movements, shifts/time-entries/tip-splits etc. that were created to
 * exercise the feature flows during build-out. None of it represents
 * real money or real users. Wiping it returns the tenant to a clean
 * "showcase only" state where the only persistent data is the
 * configured menu (= the live storefront).
 *
 * The script is intentionally noisy and gated behind an explicit flag
 * so it cannot run by accident:
 *
 *   # dry-run — list what WOULD be truncated, no writes
 *   php scripts/dev-db-reset.php
 *
 *   # actually do it
 *   php scripts/dev-db-reset.php --really-truncate-test-data
 *
 *   # specify a non-default DSN (defaults to whatever Database::getInstance gives)
 *   CLEANMENU_RESET_DSN=mysql:host=...;dbname=... \
 *   CLEANMENU_RESET_USER=root  CLEANMENU_RESET_PASS=...  \
 *   php scripts/dev-db-reset.php --really-truncate-test-data
 *
 * Tables NOT touched (configuration / showcase data):
 *   menu_items, menu_categories, modifier_groups, modifier_options,
 *   users, settings, kitchen_stations, menu_item_stations,
 *   ingredients, suppliers, recipes, locations, loyalty_tiers,
 *   promo_codes, marketing_segments, webhook_subscriptions,
 *   tips_distribution_rules.
 *
 * Tables truncated (test transactional data):
 *   orders, order_item_status, reservations, group_orders,
 *   group_order_items, group_payment_intents, loyalty_accounts,
 *   loyalty_transactions, webhook_deliveries, marketing_sends,
 *   stock_movements, shifts, time_entries, tip_splits,
 *   shift_swap_requests, tips_manual_overrides, idempotency_keys.
 *
 * Output: per-table row count BEFORE and AFTER, plus a final summary.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['really-truncate-test-data', 'help']);
if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2200));
    exit(0);
}

$really = array_key_exists('really-truncate-test-data', $opts);

$truncateTables = [
    'orders',
    'order_item_status',
    'reservations',
    'group_orders',
    'group_order_items',
    'group_payment_intents',
    'loyalty_accounts',
    'loyalty_transactions',
    'webhook_deliveries',
    'marketing_sends',
    'stock_movements',
    'shifts',
    'time_entries',
    'tip_splits',
    'shift_swap_requests',
    'tips_manual_overrides',
    'idempotency_keys',
];

require_once __DIR__ . '/../db.php';
$db = Database::getInstance();

// Reflect into Database to grab the PDO. Same trick used elsewhere
// in lib/OrderPaidHook.php.
$r = new ReflectionClass($db);
$p = $r->getProperty('connection');
$p->setAccessible(true);
$pdo = $p->getValue($db);
/** @var PDO $pdo */

fwrite(STDOUT, "[dev-db-reset] mode=" . ($really ? 'EXECUTE' : 'DRY-RUN') . "\n\n");

// Pre-counts
$pre = [];
foreach ($truncateTables as $t) {
    try {
        $row = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetch(PDO::FETCH_NUM);
        $pre[$t] = (int)($row[0] ?? 0);
    } catch (PDOException $e) {
        $pre[$t] = -1; // table doesn't exist on this tenant
    }
}

fwrite(STDOUT, sprintf("%-32s %12s %12s\n", 'TABLE', 'BEFORE', 'AFTER'));
fwrite(STDOUT, str_repeat('-', 60) . "\n");

if (!$really) {
    foreach ($pre as $t => $n) {
        $shown = $n < 0 ? 'n/a' : (string)$n;
        fwrite(STDOUT, sprintf("%-32s %12s %12s\n", $t, $shown, '— (dry-run) —'));
    }
    fwrite(STDOUT, "\n[dev-db-reset] dry-run complete. Re-run with --really-truncate-test-data to wipe.\n");
    exit(0);
}

// Execute truncates inside FK-disabled scope.
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($truncateTables as $t) {
        if ($pre[$t] < 0) continue; // table absent
        $pdo->exec("TRUNCATE TABLE `{$t}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
} catch (Throwable $e) {
    fwrite(STDERR, "[dev-db-reset] FAILED: " . $e->getMessage() . "\n");
    // Best effort to re-enable FKs even on failure.
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $_) {}
    exit(1);
}

// Post-counts
$post = [];
foreach ($truncateTables as $t) {
    if ($pre[$t] < 0) { $post[$t] = -1; continue; }
    try {
        $row = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetch(PDO::FETCH_NUM);
        $post[$t] = (int)($row[0] ?? 0);
    } catch (PDOException $e) {
        $post[$t] = -1;
    }
}

$totalBefore = 0;
$totalAfter  = 0;
foreach ($pre as $t => $n) {
    if ($n < 0) {
        fwrite(STDOUT, sprintf("%-32s %12s %12s\n", $t, 'n/a', 'n/a'));
        continue;
    }
    fwrite(STDOUT, sprintf("%-32s %12d %12d\n", $t, $n, $post[$t]));
    $totalBefore += $n;
    $totalAfter  += $post[$t];
}

fwrite(STDOUT, str_repeat('-', 60) . "\n");
fwrite(STDOUT, sprintf("%-32s %12d %12d\n", 'TOTAL', $totalBefore, $totalAfter));
fwrite(STDOUT, "\n[dev-db-reset] done. Removed " . ($totalBefore - $totalAfter) . " rows.\n");
fwrite(STDOUT, "[dev-db-reset] Configuration tables (menu_items, settings, users, etc.) untouched.\n");
exit(0);
