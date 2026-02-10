<?php
// Backfill order_items from legacy orders.items JSON.
//
// Why:
// - JSON_TABLE/report queries are expensive under load.
// - order_items enables indexed analytics/reporting queries.
//
// Safety:
// - Default mode only fills orders that currently have 0 rows in order_items.
// - Use --dry-run first.
//
// Usage examples:
//   php scripts/db/backfill-order-items.php --dry-run --limit=100
//   php scripts/db/backfill-order-items.php --from-id=1 --to-id=50000 --chunk=200
//
// Exit codes:
//   0 success, 1 bad args, 2 runtime/db error

declare(strict_types=1);

function argValue(array $argv, string $name, ?string $default = null): ?string {
    foreach ($argv as $a) {
        if (strpos($a, $name . '=') === 0) {
            return substr($a, strlen($name) + 1);
        }
        if ($a === $name) {
            return "1";
        }
    }
    return $default;
}

function eprint(string $msg): void {
    fwrite(STDERR, $msg);
}

$dryRun = argValue($argv, '--dry-run', '0') === "1";
$limit = (int)(argValue($argv, '--limit', '0') ?? '0');
$fromId = (int)(argValue($argv, '--from-id', '0') ?? '0');
$toId = (int)(argValue($argv, '--to-id', '0') ?? '0');
$chunk = (int)(argValue($argv, '--chunk', '200') ?? '200');

if ($chunk <= 0 || $chunk > 5000) {
    eprint("Invalid --chunk (1..5000)\n");
    exit(1);
}

// Load config in a way compatible with the existing project layout.
$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    eprint("Could not resolve project root.\n");
    exit(2);
}
$configPath = realpath($root . '/../config_copy.php');
if ($configPath === false) {
    eprint("config_copy.php not found next to project root parent. Expected: " . ($root . '/../config_copy.php') . "\n");
    exit(2);
}
require_once $configPath;

if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
    eprint("DB constants missing. Check config_copy.php.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        defined('DB_PASS') ? DB_PASS : '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]
    );
    $pdo->exec("SET time_zone='+03:00'");
} catch (Throwable $e) {
    eprint("DB connect error: " . $e->getMessage() . "\n");
    exit(2);
}

$where = [];
$params = [];
if ($fromId > 0) {
    $where[] = "o.id >= :from_id";
    $params[':from_id'] = $fromId;
}
if ($toId > 0) {
    $where[] = "o.id <= :to_id";
    $params[':to_id'] = $toId;
}
$whereSql = $where ? ("AND " . implode(" AND ", $where)) : "";

// Only pick orders that have no rows in order_items (idempotent).
$sqlSelect = "
SELECT o.id, o.items, o.created_at
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
WHERE oi.id IS NULL
  AND JSON_LENGTH(o.items) > 0
  $whereSql
ORDER BY o.id
";
if ($limit > 0) {
    $sqlSelect .= " LIMIT " . (int)$limit;
} else {
    $sqlSelect .= " LIMIT " . (int)$chunk;
}

$stmtInsert = $pdo->prepare("
INSERT INTO order_items (order_id, item_id, item_name, quantity, price, created_at)
VALUES (:order_id, :item_id, :item_name, :quantity, :price, :created_at)
");

$totalOrders = 0;
$totalRows = 0;
$lastId = 0;

while (true) {
    // Use the last processed id to page forward (stable for large tables).
    $sqlPage = $sqlSelect;
    $pageParams = $params;
    if ($lastId > 0) {
        $sqlPage = preg_replace('/ORDER BY o\\.id/', "AND o.id > :after_id\nORDER BY o.id", $sqlPage, 1);
        $pageParams[':after_id'] = $lastId;
    }

    $stmt = $pdo->prepare($sqlPage);
    foreach ($pageParams as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) {
        break;
    }

    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    foreach ($rows as $row) {
        $orderId = (int)$row['id'];
        $createdAt = (string)$row['created_at'];
        $itemsRaw = $row['items'];
        $items = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;

        if (!is_array($items)) {
            eprint("WARN: order_id={$orderId} items JSON is not array, skipping\n");
            $lastId = $orderId;
            continue;
        }

        $insertedForOrder = 0;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $itemId = isset($it['id']) ? (int)$it['id'] : 0;
            if ($itemId <= 0) continue;

            $itemName = isset($it['name']) ? (string)$it['name'] : null;
            $qty = isset($it['quantity']) ? (int)$it['quantity'] : 1;
            if ($qty <= 0) $qty = 1;
            $price = isset($it['price']) ? (float)$it['price'] : 0.0;

            $totalRows++;
            $insertedForOrder++;

            if ($dryRun) {
                continue;
            }

            $stmtInsert->execute([
                ':order_id' => $orderId,
                ':item_id' => $itemId,
                ':item_name' => $itemName,
                ':quantity' => $qty,
                ':price' => $price,
                ':created_at' => $createdAt,
            ]);
        }

        $totalOrders++;
        $lastId = $orderId;

        if ($totalOrders % 100 === 0) {
            echo "progress orders={$totalOrders} rows={$totalRows} last_id={$lastId}\n";
        }
    }

    if (!$dryRun) {
        $pdo->commit();
    }

    // Stop if --limit reached.
    if ($limit > 0 && $totalOrders >= $limit) {
        break;
    }
}

echo "done dry_run=" . ($dryRun ? "1" : "0") . " orders={$totalOrders} rows={$totalRows} last_id={$lastId}\n";

