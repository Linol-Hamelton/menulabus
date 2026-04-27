<?php
/**
 * seed-data.php — populate a fresh tenant DB with realistic sample data
 * (Polish 12.3, 2026-04-27).
 *
 * Use case: a new tenant has just been provisioned and the operator wants
 * to demo the storefront, staff dashboard, and KDS without manually
 * creating 20 menu items by hand. Also useful for visual-regression
 * baseline runs (Lighthouse needs a real menu to render the heat map and
 * card layout) and for the KDS end-to-end smoke flow that exercises
 * order.created → routeOrderItemsToStations → kds.php → order.ready.
 *
 * Usage:
 *   php scripts/seed-data.php
 *   php scripts/seed-data.php --csv=tests/fixtures/menu-sample.csv
 *   php scripts/seed-data.php --csv=path.csv --skip-orders
 *   php scripts/seed-data.php --orders=15 --customers=5
 *   php scripts/seed-data.php --dry-run
 *
 * Flags:
 *   --csv=PATH         CSV with menu items (default: tests/fixtures/menu-sample.csv)
 *   --customers=N      seed N test customers (default: 5)
 *   --orders=N         seed N random orders (default: 10)
 *   --skip-menu        do not insert menu items
 *   --skip-customers   do not insert customers
 *   --skip-orders      do not insert orders
 *   --dry-run          parse and report, do not write
 *
 * Idempotency: customers are upserted by email
 * (`seed{1..N}@cleanmenu-seed.test`) so re-runs don't duplicate accounts;
 * menu items are inserted as-is (re-running creates duplicates by design,
 * since menu_items has no natural unique key beyond external_id which the
 * code generates fresh each insert).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', [
    'csv::', 'customers::', 'orders::',
    'skip-menu', 'skip-customers', 'skip-orders', 'dry-run', 'help'
]);

if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

$csvPath      = (string)($opts['csv'] ?? __DIR__ . '/../tests/fixtures/menu-sample.csv');
$customerCount = max(0, min(50, (int)($opts['customers'] ?? 5)));
$orderCount    = max(0, min(200, (int)($opts['orders'] ?? 10)));
$skipMenu      = array_key_exists('skip-menu', $opts);
$skipCustomers = array_key_exists('skip-customers', $opts);
$skipOrders    = array_key_exists('skip-orders', $opts);
$dryRun        = array_key_exists('dry-run', $opts);

require_once __DIR__ . '/../db.php';

$db  = Database::getInstance();
$pdo = (function () use ($db) {
    $r = new ReflectionClass($db);
    $p = $r->getProperty('connection');
    $p->setAccessible(true);
    return $p->getValue($db);
})();

fwrite(STDOUT, "[seed-data] CSV={$csvPath} customers={$customerCount} orders={$orderCount} dry-run="
    . ($dryRun ? '1' : '0') . "\n");

// ---- 1. Menu items --------------------------------------------------
$menuIds = [];
if (!$skipMenu) {
    if (!is_file($csvPath)) {
        fwrite(STDERR, "[seed-data] csv not found: {$csvPath}\n");
        exit(1);
    }
    $fh = fopen($csvPath, 'r');
    if (!$fh) {
        fwrite(STDERR, "[seed-data] cannot open csv\n");
        exit(1);
    }
    $headers = fgetcsv($fh);
    if (!$headers) {
        fwrite(STDERR, "[seed-data] empty csv\n");
        exit(1);
    }
    $required = ['name', 'description', 'composition', 'price', 'category', 'calories', 'protein', 'fat', 'carbs'];
    foreach ($required as $col) {
        if (!in_array($col, $headers, true)) {
            fwrite(STDERR, "[seed-data] csv missing column: {$col}\n");
            exit(1);
        }
    }
    $inserted = 0;
    while (($row = fgetcsv($fh)) !== false) {
        $r = array_combine($headers, $row);
        if (!$r) continue;
        if ($dryRun) {
            $inserted++;
            continue;
        }
        $ok = $db->addMenuItem(
            (string)$r['name'],
            (string)$r['description'],
            (string)$r['composition'],
            (float)$r['price'],
            '', // image — left blank; frontend falls back to its placeholder
            (int)$r['calories'],
            (int)$r['protein'],
            (int)$r['fat'],
            (int)$r['carbs'],
            (string)$r['category'],
            1
        );
        if ($ok) {
            $inserted++;
            // Retrieve the just-inserted id for later order references.
            $menuIds[] = (int)$pdo->lastInsertId();
        }
    }
    fclose($fh);
    fwrite(STDOUT, "[seed-data] menu items inserted={$inserted}\n");
} else {
    // Use whatever items the tenant already has.
    $rows = $db->getMenuItems();
    foreach ($rows as $r) { $menuIds[] = (int)$r['id']; }
    fwrite(STDOUT, "[seed-data] menu skipped — using existing " . count($menuIds) . " items\n");
}

if (count($menuIds) === 0 && !$skipOrders) {
    fwrite(STDOUT, "[seed-data] no menu items available — skipping orders\n");
    $skipOrders = true;
}

// ---- 2. Customers ---------------------------------------------------
$customerIds = [];
if (!$skipCustomers && $customerCount > 0) {
    for ($i = 1; $i <= $customerCount; $i++) {
        $email = "seed{$i}@cleanmenu-seed.test";
        $name  = "Тест-клиент #{$i}";
        $phone = "+7900000{$i}{$i}{$i}{$i}";

        // Upsert by email.
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
        $stmt->execute([':e' => $email]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $customerIds[] = $existingId;
            continue;
        }
        if ($dryRun) {
            $customerIds[] = -$i;
            continue;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO users (email, password, name, phone, role, created_at)
             VALUES (:e, :pw, :n, :ph, 'customer', NOW())"
        );
        $stmt->execute([
            ':e' => $email,
            ':pw' => password_hash('seed-password-do-not-use', PASSWORD_BCRYPT),
            ':n' => $name,
            ':ph' => $phone,
        ]);
        $customerIds[] = (int)$pdo->lastInsertId();
    }
    fwrite(STDOUT, "[seed-data] customers ready=" . count($customerIds) . "\n");
} else {
    fwrite(STDOUT, "[seed-data] customers skipped\n");
}

// ---- 3. Orders ------------------------------------------------------
if (!$skipOrders && $orderCount > 0) {
    $deliveryTypes = ['table', 'takeaway', 'bar'];
    $statuses      = ['paid', 'preparing', 'ready', 'delivered'];
    $created = 0;
    $failed  = 0;

    for ($i = 0; $i < $orderCount; $i++) {
        $userId = $customerIds[array_rand($customerIds)] ?? null;
        if ($userId === null || $userId < 0) continue;

        // 1-3 random items per order.
        $itemCount = random_int(1, 3);
        $items = [];
        $total = 0.0;
        for ($k = 0; $k < $itemCount; $k++) {
            $menuId = $menuIds[array_rand($menuIds)];
            $stmt = $pdo->prepare("SELECT id, name, price FROM menu_items WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $menuId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            $qty = random_int(1, 2);
            $items[] = [
                'id'       => (int)$row['id'],
                'name'     => (string)$row['name'],
                'price'    => (float)$row['price'],
                'quantity' => $qty,
            ];
            $total += (float)$row['price'] * $qty;
        }
        if (count($items) === 0) { $failed++; continue; }

        $type   = $deliveryTypes[array_rand($deliveryTypes)];
        $detail = $type === 'table' ? (string)random_int(1, 12) : '';

        if ($dryRun) { $created++; continue; }

        $orderId = $db->createOrder($userId, $items, $total, $type, $detail, 0.0);
        if (!$orderId) { $failed++; continue; }

        // Sprinkle realistic statuses (mostly paid/delivered, some in-flight).
        $status = $statuses[array_rand($statuses)];
        try {
            $stmt = $pdo->prepare("UPDATE orders SET status = :s WHERE id = :id");
            $stmt->execute([':s' => $status, ':id' => $orderId]);
        } catch (PDOException $_) { /* status column may differ across schemas */ }
        $created++;
    }
    fwrite(STDOUT, "[seed-data] orders created={$created} failed={$failed}\n");
} else {
    fwrite(STDOUT, "[seed-data] orders skipped\n");
}

fwrite(STDOUT, "[seed-data] done\n");
exit(0);
