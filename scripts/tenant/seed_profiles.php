<?php

if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/tenant_runtime.php';
require_once dirname(__DIR__, 2) . '/RedisCache.php';

function tenant_seed_profile_usage_list(): array
{
    return ['restaurant-demo'];
}

function tenant_seed_resolve_profile(string $profile): array
{
    $normalized = strtolower(trim($profile));
    if ($normalized !== 'restaurant-demo') {
        throw new InvalidArgumentException('Unsupported seed profile: ' . $profile);
    }

    return require __DIR__ . '/data/restaurant_demo.php';
}

function tenant_seed_json_value(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Failed to encode JSON value');
    }

    return $encoded;
}

function tenant_seed_upsert_setting(PDO $pdo, string $key, mixed $value, ?int $updatedBy = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO settings (`key`, value, updated_by)
         VALUES (:key, :value, :updated_by)
         ON DUPLICATE KEY UPDATE
           value = VALUES(value),
           updated_by = VALUES(updated_by),
           updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':key' => $key,
        ':value' => tenant_seed_json_value($value),
        ':updated_by' => $updatedBy,
    ]);
}

function tenant_seed_find_user_id(PDO $pdo, string $email): int
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('Failed to resolve user: ' . $email);
    }

    return $id;
}

function tenant_seed_reset_content(PDO $pdo): void
{
    $pdo->exec('DELETE FROM order_status_history');
    $pdo->exec('DELETE FROM order_items');
    $pdo->exec('DELETE FROM orders');
    $pdo->exec('DELETE FROM modifier_options');
    $pdo->exec('DELETE FROM modifier_groups');
    $pdo->exec('DELETE FROM menu_items');
}

function tenant_seed_assert_empty_or_force(PDO $pdo, bool $force): void
{
    $menuCount = (int)$pdo->query("SELECT COUNT(*) FROM menu_items")->fetchColumn();
    $ordersCount = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

    if (($menuCount > 0 || $ordersCount > 0) && !$force) {
        throw new RuntimeException(
            'Target tenant already has menu_items/orders. Re-run with --force to replace demo content.'
        );
    }

    if ($force) {
        tenant_seed_reset_content($pdo);
    }
}

function tenant_seed_upsert_users(PDO $pdo, array $accounts, string $ownerEmail): array
{
    $stmt = $pdo->prepare(
        "INSERT INTO users
         (email, password_hash, name, phone, is_active, email_verified_at, role, menu_view, created_at, updated_at)
         VALUES (:email, :password_hash, :name, NULL, 1, NOW(), :role, 'default', NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           password_hash = VALUES(password_hash),
           name = VALUES(name),
           is_active = 1,
           email_verified_at = NOW(),
           role = VALUES(role),
           updated_at = CURRENT_TIMESTAMP"
    );

    $resolved = [];
    foreach ($accounts as $account) {
        $email = trim((string)($account['email'] ?? ''));
        if ($email === '' || $email === $ownerEmail) {
            continue;
        }

        $password = (string)($account['password'] ?? 'DemoTenant2026!');
        $stmt->execute([
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':name' => (string)($account['name'] ?? $email),
            ':role' => (string)($account['role'] ?? 'customer'),
        ]);

        $resolved[$email] = [
            'email' => $email,
            'name' => (string)($account['name'] ?? $email),
            'role' => (string)($account['role'] ?? 'customer'),
            'password' => $password,
            'id' => tenant_seed_find_user_id($pdo, $email),
        ];
    }

    return $resolved;
}

function tenant_seed_apply_settings(PDO $pdo, array $profile, array $tenantContext, int $ownerId): array
{
    $defaults = $profile['defaults'] ?? [];
    $brandName = trim((string)($tenantContext['brand_name'] ?? ''));
    if ($brandName === '') {
        $brandName = (string)($defaults['brand_name'] ?? 'Aster Bistro');
    }

    $settings = [
        'app_name' => $brandName,
        'app_tagline' => (string)($tenantContext['tagline'] ?? ($defaults['tagline'] ?? '')),
        'app_description' => (string)($tenantContext['description'] ?? ($defaults['description'] ?? '')),
        'custom_domain' => (string)($tenantContext['domain'] ?? ''),
        'hide_labus_branding' => (string)($defaults['hide_labus_branding'] ?? 'true'),
        'contact_phone' => (string)($defaults['contact_phone'] ?? ''),
        'contact_address' => (string)($defaults['contact_address'] ?? ''),
        'contact_map_url' => (string)($defaults['contact_map_url'] ?? ''),
        'logo_url' => (string)($defaults['logo_url'] ?? ''),
        'favicon_url' => (string)($defaults['favicon_url'] ?? '/icons/favicon.ico'),
        'social_tg' => (string)($defaults['social_tg'] ?? ''),
        'social_vk' => (string)($defaults['social_vk'] ?? ''),
        'onboarding_done' => (string)($defaults['onboarding_done'] ?? 'true'),
    ];

    foreach ($settings as $key => $value) {
        tenant_seed_upsert_setting($pdo, $key, $value, $ownerId);
    }

    return $settings;
}

function tenant_seed_upsert_menu_items(PDO $pdo, array $items): array
{
    $stmt = $pdo->prepare(
        "INSERT INTO menu_items
         (external_id, name, description, composition, price, image, calories, protein, fat, carbs, category, available, archived_at, created_at, updated_at)
         VALUES
         (:external_id, :name, :description, :composition, :price, :image, :calories, :protein, :fat, :carbs, :category, :available, NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           name = VALUES(name),
           description = VALUES(description),
           composition = VALUES(composition),
           price = VALUES(price),
           image = VALUES(image),
           calories = VALUES(calories),
           protein = VALUES(protein),
           fat = VALUES(fat),
           carbs = VALUES(carbs),
           category = VALUES(category),
           available = VALUES(available),
           archived_at = NULL,
           updated_at = CURRENT_TIMESTAMP"
    );

    foreach ($items as $item) {
        $stmt->execute([
            ':external_id' => (string)$item['external_id'],
            ':name' => (string)$item['name'],
            ':description' => (string)$item['description'],
            ':composition' => (string)$item['composition'],
            ':price' => (float)$item['price'],
            ':image' => (string)$item['image'],
            ':calories' => (int)$item['calories'],
            ':protein' => (int)$item['protein'],
            ':fat' => (int)$item['fat'],
            ':carbs' => (int)$item['carbs'],
            ':category' => (string)$item['category'],
            ':available' => (int)$item['available'],
        ]);
    }

    $mapStmt = $pdo->query("SELECT id, external_id, name, price, image, calories, protein, fat, carbs, category, available FROM menu_items");
    $byExternalId = [];
    foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byExternalId[(string)$row['external_id']] = $row;
    }

    return $byExternalId;
}

function tenant_seed_insert_modifiers(PDO $pdo, array $modifierGroups, array $itemsByExternalId): int
{
    $groupStmt = $pdo->prepare(
        "INSERT INTO modifier_groups (item_id, name, type, required, sort_order)
         VALUES (:item_id, :name, :type, :required, :sort_order)"
    );
    $optionStmt = $pdo->prepare(
        "INSERT INTO modifier_options (group_id, name, price_delta, sort_order)
         VALUES (:group_id, :name, :price_delta, :sort_order)"
    );

    $count = 0;
    foreach ($modifierGroups as $groupIndex => $group) {
        $externalId = (string)($group['item_external_id'] ?? '');
        if ($externalId === '' || !isset($itemsByExternalId[$externalId])) {
            throw new RuntimeException('Modifier target item not found: ' . $externalId);
        }

        $groupStmt->execute([
            ':item_id' => (int)$itemsByExternalId[$externalId]['id'],
            ':name' => (string)$group['name'],
            ':type' => (string)$group['type'],
            ':required' => !empty($group['required']) ? 1 : 0,
            ':sort_order' => $groupIndex,
        ]);
        $groupId = (int)$pdo->lastInsertId();

        foreach (($group['options'] ?? []) as $optionIndex => $option) {
            $optionStmt->execute([
                ':group_id' => $groupId,
                ':name' => (string)$option['name'],
                ':price_delta' => (float)$option['price_delta'],
                ':sort_order' => $optionIndex,
            ]);
            $count++;
        }
    }

    return $count;
}

function tenant_seed_order_item_payload(array $itemRow, int $quantity): array
{
    return [
        'id' => (int)$itemRow['id'],
        'name' => (string)$itemRow['name'],
        'price' => (float)$itemRow['price'],
        'quantity' => $quantity,
        'image' => (string)$itemRow['image'],
        'calories' => (int)$itemRow['calories'],
        'protein' => (int)$itemRow['protein'],
        'fat' => (int)$itemRow['fat'],
        'carbs' => (int)$itemRow['carbs'],
    ];
}

function tenant_seed_insert_orders(PDO $pdo, array $orders, array $accountsByEmail, array $itemsByExternalId): int
{
    $orderStmt = $pdo->prepare(
        "INSERT INTO orders
         (user_id, items, total, tips, status, delivery_type, delivery_details, payment_method, payment_status, last_updated_by, created_at, updated_at)
         VALUES
         (:user_id, :items, :total, :tips, :status, :delivery_type, :delivery_details, :payment_method, :payment_status, :last_updated_by, :created_at, :updated_at)"
    );
    $orderItemStmt = $pdo->prepare(
        "INSERT INTO order_items
         (order_id, item_id, item_name, quantity, price, created_at)
         VALUES
         (:order_id, :item_id, :item_name, :quantity, :price, :created_at)"
    );
    $historyStmt = $pdo->prepare(
        "INSERT INTO order_status_history (order_id, status, changed_by, changed_at)
         VALUES (:order_id, :status, :changed_by, :changed_at)"
    );

    $count = 0;
    foreach ($orders as $orderIndex => $order) {
        $customerEmail = (string)$order['customer_email'];
        $updaterEmail = (string)$order['last_updated_by_email'];
        if (!isset($accountsByEmail[$customerEmail], $accountsByEmail[$updaterEmail])) {
            throw new RuntimeException('Order references unknown account: ' . $customerEmail . ' / ' . $updaterEmail);
        }

        $payloadItems = [];
        $total = 0.0;
        foreach (($order['items'] ?? []) as $line) {
            $externalId = (string)($line['external_id'] ?? '');
            $quantity = max(1, (int)($line['quantity'] ?? 1));
            if (!isset($itemsByExternalId[$externalId])) {
                throw new RuntimeException('Order item not found: ' . $externalId);
            }

            $itemRow = $itemsByExternalId[$externalId];
            $payloadItems[] = tenant_seed_order_item_payload($itemRow, $quantity);
            $total += (float)$itemRow['price'] * $quantity;
        }

        $createdAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))
            ->modify('-' . max(1, (int)$order['created_minutes_ago']) . ' minutes');
        $updatedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))
            ->modify('-' . max(0, (int)$order['updated_minutes_ago']) . ' minutes');
        if ($updatedAt < $createdAt) {
            $updatedAt = $createdAt;
        }

        $orderStmt->execute([
            ':user_id' => (int)$accountsByEmail[$customerEmail]['id'],
            ':items' => tenant_seed_json_value($payloadItems),
            ':total' => $total,
            ':tips' => (float)($order['tips'] ?? 0),
            ':status' => (string)$order['status'],
            ':delivery_type' => (string)$order['delivery_type'],
            ':delivery_details' => (string)($order['delivery_details'] ?? ''),
            ':payment_method' => (string)($order['payment_method'] ?? 'cash'),
            ':payment_status' => (string)($order['payment_status'] ?? 'not_required'),
            ':last_updated_by' => (int)$accountsByEmail[$updaterEmail]['id'],
            ':created_at' => $createdAt->format('Y-m-d H:i:s'),
            ':updated_at' => $updatedAt->format('Y-m-d H:i:s'),
        ]);
        $orderId = (int)$pdo->lastInsertId();

        foreach ($payloadItems as $payloadItem) {
            $orderItemStmt->execute([
                ':order_id' => $orderId,
                ':item_id' => (int)$payloadItem['id'],
                ':item_name' => (string)$payloadItem['name'],
                ':quantity' => (int)$payloadItem['quantity'],
                ':price' => (float)$payloadItem['price'],
                ':created_at' => $createdAt->format('Y-m-d H:i:s'),
            ]);
        }

        $history = $order['history'] ?? [(string)$order['status']];
        $historySteps = count($history);
        foreach (array_values($history) as $historyIndex => $status) {
            $ratio = $historySteps > 1 ? $historyIndex / ($historySteps - 1) : 1;
            $historyTime = $createdAt->getTimestamp() + (int)(($updatedAt->getTimestamp() - $createdAt->getTimestamp()) * $ratio);
            $historyStmt->execute([
                ':order_id' => $orderId,
                ':status' => (string)$status,
                ':changed_by' => (int)$accountsByEmail[$updaterEmail]['id'],
                ':changed_at' => date('Y-m-d H:i:s', $historyTime),
            ]);
        }

        $count++;
    }

    return $count;
}

function tenant_seed_invalidate_cache(array $tenantContext): void
{
    if (!class_exists('RedisCache')) {
        return;
    }

    $tenantId = (int)($tenantContext['tenant_id'] ?? 0);
    $namespace = $tenantId > 0 ? 'tenant:' . $tenantId : 'tenant:' . preg_replace('/[^a-z0-9._-]+/i', '_', (string)($tenantContext['brand_slug'] ?? 'legacy'));
    $redis = RedisCache::getInstance();
    $redis->invalidate($namespace . ':menu_items_*');
    $redis->invalidate($namespace . ':menu_items_all_*');
    $redis->invalidate($namespace . ':menu_items_archived_*');
    $redis->invalidate($namespace . ':product_*');
    $redis->invalidate($namespace . ':categories_*');
    $redis->set($namespace . ':orders_last_update_ts', time(), 86400);
}

function tenant_seed_apply_profile(PDO $pdo, array $tenantContext, string $profile, bool $force = false): array
{
    $data = tenant_seed_resolve_profile($profile);
    tenant_seed_assert_empty_or_force($pdo, $force);

    $ownerEmail = trim((string)($tenantContext['owner_email'] ?? ''));
    if ($ownerEmail === '') {
        throw new RuntimeException('owner_email is required to apply tenant seed');
    }

    $ownerId = tenant_seed_find_user_id($pdo, $ownerEmail);

    $pdo->beginTransaction();
    try {
        $settings = tenant_seed_apply_settings($pdo, $data, $tenantContext, $ownerId);
        $accounts = tenant_seed_upsert_users($pdo, (array)($data['accounts'] ?? []), $ownerEmail);
        $accounts[$ownerEmail] = [
            'email' => $ownerEmail,
            'name' => (string)($tenantContext['brand_name'] ?? $settings['app_name']),
            'role' => 'owner',
            'password' => '[existing]',
            'id' => $ownerId,
        ];
        $itemsByExternalId = tenant_seed_upsert_menu_items($pdo, (array)($data['menu_items'] ?? []));
        $modifierCount = tenant_seed_insert_modifiers($pdo, (array)($data['modifier_groups'] ?? []), $itemsByExternalId);
        $orderCount = tenant_seed_insert_orders($pdo, (array)($data['orders'] ?? []), $accounts, $itemsByExternalId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    tenant_seed_invalidate_cache($tenantContext);

    $categories = [];
    foreach ($data['menu_items'] as $item) {
        $categories[(string)$item['category']] = true;
    }

    return [
        'profile' => $profile,
        'brand_name' => $settings['app_name'],
        'domain' => (string)($tenantContext['domain'] ?? ''),
        'menu_items' => count((array)($data['menu_items'] ?? [])),
        'categories' => array_keys($categories),
        'modifier_groups' => count((array)($data['modifier_groups'] ?? [])),
        'modifier_options' => $modifierCount,
        'orders' => $orderCount,
        'accounts' => array_values($accounts),
        'forced_reset' => $force,
    ];
}
