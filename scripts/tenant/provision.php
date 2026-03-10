<?php

require_once dirname(__DIR__, 2) . '/tenant_runtime.php';
require_once __DIR__ . '/smoke.php';
require_once __DIR__ . '/seed_profiles.php';

function provision_usage(): void
{
    $usage = <<<TXT
Usage:
  php scripts/tenant/provision.php --brand-name="Brand" --brand-slug=brand --domain=menu.brand.tld --mode=tenant --owner-email=owner@example.com --tenant-db-user=db_user --tenant-db-pass=db_pass [--owner-password=secret] [--seed-profile=restaurant-demo] [--skip-smoke]
TXT;
    fwrite(STDERR, $usage . PHP_EOL);
}

function provision_require_arg(array $options, string $key): string
{
    $value = trim((string)($options[$key] ?? ''));
    if ($value === '') {
        throw new InvalidArgumentException("Missing required option --{$key}");
    }

    return $value;
}

function provision_quote_ident(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function provision_normalize_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');
    if ($slug === '' || !preg_match('/^[a-z0-9_]+$/', $slug)) {
        throw new InvalidArgumentException('brand-slug must contain only a-z, 0-9 and underscore');
    }

    return $slug;
}

function provision_sql_statements(string $sql): array
{
    $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
    $buffer = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $buffer[] = $line;
    }

    $sql = implode("\n", $buffer);
    $parts = preg_split('/;\s*(?:\n|$)/', $sql) ?: [];
    $statements = [];
    foreach ($parts as $part) {
        $stmt = trim($part);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
    }

    return $statements;
}

function provision_exec_sql_file(PDO $pdo, string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException("SQL file not found: {$path}");
    }

    $sql = (string)file_get_contents($path);
    foreach (provision_sql_statements($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function provision_mysql_user(string $user, string $host, string $password): string
{
    return sprintf("'%s'@'%s'", str_replace("'", "''", $user), str_replace("'", "''", $host));
}

function provision_upsert_setting(PDO $pdo, string $key, mixed $value, ?int $updatedBy = null): void
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
        ':value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':updated_by' => $updatedBy,
    ]);
}

function provision_seed_users(PDO $pdo, string $brandName, string $ownerEmail, string $ownerPassword): array
{
    $ownerName = $brandName;
    $ownerHash = password_hash($ownerPassword, PASSWORD_DEFAULT);

    $guestStmt = $pdo->prepare(
        "INSERT INTO users
         (id, email, password_hash, name, phone, is_active, email_verified_at, role, menu_view, created_at, updated_at)
         VALUES (999999, 'guest@system.local', '', 'Guest', NULL, 1, NOW(), 'guest', 'default', NOW(), NOW())
         ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP"
    );
    $guestStmt->execute();

    $ownerStmt = $pdo->prepare(
        "INSERT INTO users
         (email, password_hash, name, phone, is_active, email_verified_at, role, menu_view, created_at, updated_at)
         VALUES (:email, :password_hash, :name, NULL, 1, NOW(), 'owner', 'default', NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           password_hash = VALUES(password_hash),
           name = VALUES(name),
           is_active = 1,
           email_verified_at = NOW(),
           role = 'owner',
           updated_at = CURRENT_TIMESTAMP"
    );
    $ownerStmt->execute([
        ':email' => $ownerEmail,
        ':password_hash' => $ownerHash,
        ':name' => $ownerName,
    ]);

    $ownerIdStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $ownerIdStmt->execute([':email' => $ownerEmail]);
    $ownerId = (int)$ownerIdStmt->fetchColumn();
    if ($ownerId <= 0) {
        throw new RuntimeException('Failed to resolve owner user id after insert');
    }

    return [
        'owner_id' => $ownerId,
        'owner_password' => $ownerPassword,
    ];
}

function provision_seed_settings(PDO $pdo, string $brandName, string $domain, string $mode, int $ownerId): void
{
    $hideBranding = $mode === 'tenant' ? 'true' : 'false';
    $settings = [
        'app_name' => $brandName,
        'app_tagline' => '',
        'app_description' => '',
        'custom_domain' => $domain,
        'hide_labus_branding' => $hideBranding,
        'contact_phone' => '',
        'contact_address' => '',
        'logo_url' => '',
        'favicon_url' => '/icons/favicon.ico',
        'social_tg' => '',
        'social_vk' => '',
        'onboarding_done' => 'false',
    ];

    foreach ($settings as $key => $value) {
        provision_upsert_setting($pdo, $key, $value, $ownerId);
    }
}

function provision_tenant_registry(PDO $controlPdo, array $tenant): int
{
    $stmt = $controlPdo->prepare(
        "INSERT INTO tenants
         (mode, brand_slug, db_name, db_user, db_pass_enc, is_active)
         VALUES (:mode, :brand_slug, :db_name, :db_user, :db_pass_enc, 1)
         ON DUPLICATE KEY UPDATE
           mode = VALUES(mode),
           db_name = VALUES(db_name),
           db_user = VALUES(db_user),
           db_pass_enc = VALUES(db_pass_enc),
           is_active = VALUES(is_active),
           updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':mode' => $tenant['mode'],
        ':brand_slug' => $tenant['brand_slug'],
        ':db_name' => $tenant['db_name'],
        ':db_user' => $tenant['db_user'],
        ':db_pass_enc' => $tenant['db_pass_enc'],
    ]);

    $tenantIdStmt = $controlPdo->prepare("SELECT id FROM tenants WHERE brand_slug = :brand_slug LIMIT 1");
    $tenantIdStmt->execute([':brand_slug' => $tenant['brand_slug']]);
    $tenantId = (int)$tenantIdStmt->fetchColumn();
    if ($tenantId <= 0) {
        throw new RuntimeException('Failed to resolve tenant id after registry upsert');
    }

    $domainStmt = $controlPdo->prepare(
        "INSERT INTO tenant_domains (host, tenant_id, is_primary)
         VALUES (:host, :tenant_id, 1)
         ON DUPLICATE KEY UPDATE
           tenant_id = VALUES(tenant_id),
           is_primary = VALUES(is_primary),
           updated_at = CURRENT_TIMESTAMP"
    );
    $domainStmt->execute([
        ':host' => $tenant['domain'],
        ':tenant_id' => $tenantId,
    ]);

    return $tenantId;
}

function provision_tenant_pdo(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['tenant_db_host'],
        $config['tenant_db_name']
    );

    return new PDO($dsn, $config['tenant_db_user'], $config['tenant_db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]);
}

function provision_random_password(int $length = 24): string
{
    return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    if (!tenant_control_configured()) {
        throw new RuntimeException('tenant control DB config is not available');
    }

    $options = getopt('', [
        'brand-name:',
        'brand-slug:',
        'domain:',
        'mode:',
        'owner-email:',
        'owner-password::',
        'tenant-db-user:',
        'tenant-db-pass:',
        'seed-profile::',
        'skip-smoke',
    ]);

    $brandName = provision_require_arg($options, 'brand-name');
    $brandSlug = provision_normalize_slug(provision_require_arg($options, 'brand-slug'));
    $domain = tenant_normalize_host(provision_require_arg($options, 'domain'));
    $mode = strtolower(provision_require_arg($options, 'mode'));
    $ownerEmail = provision_require_arg($options, 'owner-email');
    $tenantDbUser = provision_require_arg($options, 'tenant-db-user');
    $tenantDbPass = provision_require_arg($options, 'tenant-db-pass');
    $ownerPassword = trim((string)($options['owner-password'] ?? ''));
    $seedProfile = trim((string)($options['seed-profile'] ?? ''));
    $skipSmoke = array_key_exists('skip-smoke', $options);

    if (!in_array($mode, ['provider', 'tenant'], true)) {
        throw new InvalidArgumentException('--mode must be provider or tenant');
    }
    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('--owner-email must be a valid email');
    }
    if ($domain === '') {
        throw new InvalidArgumentException('--domain is not a valid host');
    }
    if ($ownerPassword === '') {
        $ownerPassword = provision_random_password();
    }

    $dbName = 'menu_' . $brandSlug;
    $tenantDbHost = defined('TENANT_DB_HOST') ? (string)TENANT_DB_HOST : '127.0.0.1';
    $controlPdo = tenant_control_pdo();
    provision_exec_sql_file($controlPdo, dirname(__DIR__, 2) . '/sql/control-plane-schema.sql');

    $quotedDb = provision_quote_ident($dbName);
    $user127 = provision_mysql_user($tenantDbUser, '127.0.0.1', $tenantDbPass);
    $userLocal = provision_mysql_user($tenantDbUser, 'localhost', $tenantDbPass);
    $quotedPass = $controlPdo->quote($tenantDbPass);

    $controlPdo->exec("CREATE DATABASE IF NOT EXISTS {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $controlPdo->exec("CREATE USER IF NOT EXISTS {$user127} IDENTIFIED BY {$quotedPass}");
    $controlPdo->exec("CREATE USER IF NOT EXISTS {$userLocal} IDENTIFIED BY {$quotedPass}");
    $controlPdo->exec("GRANT ALL PRIVILEGES ON {$quotedDb}.* TO {$user127}");
    $controlPdo->exec("GRANT ALL PRIVILEGES ON {$quotedDb}.* TO {$userLocal}");
    $controlPdo->exec("FLUSH PRIVILEGES");

    $tenantPdo = provision_tenant_pdo([
        'tenant_db_host' => $tenantDbHost,
        'tenant_db_name' => $dbName,
        'tenant_db_user' => $tenantDbUser,
        'tenant_db_pass' => $tenantDbPass,
    ]);
    provision_exec_sql_file($tenantPdo, dirname(__DIR__, 2) . '/sql/bootstrap-schema.sql');

    $tenantPdo->beginTransaction();
    $seeded = provision_seed_users($tenantPdo, $brandName, $ownerEmail, $ownerPassword);
    provision_seed_settings($tenantPdo, $brandName, $domain, $mode, (int)$seeded['owner_id']);
    $tenantPdo->commit();

    $seedProfileSummary = null;
    if ($seedProfile !== '') {
        $seedProfileSummary = tenant_seed_apply_profile($tenantPdo, [
            'tenant_id' => 0,
            'brand_slug' => $brandSlug,
            'brand_name' => $brandName,
            'owner_email' => $ownerEmail,
            'domain' => $domain,
        ], $seedProfile, false);
    }

    $tenantId = provision_tenant_registry($controlPdo, [
        'mode' => $mode,
        'brand_slug' => $brandSlug,
        'db_name' => $dbName,
        'db_user' => $tenantDbUser,
        'db_pass_enc' => tenant_registry_encrypt($tenantDbPass),
        'domain' => $domain,
    ]);

    $baseUrl = 'https://' . $domain;
    $smoke = ['ok' => false, 'skipped' => true];
    if (!$skipSmoke) {
        $smoke = tenant_smoke_run($baseUrl);
    }

    echo json_encode([
        'ok' => true,
        'tenant_id' => $tenantId,
        'mode' => $mode,
        'brand_name' => $brandName,
        'brand_slug' => $brandSlug,
        'domain' => $domain,
        'db_name' => $dbName,
        'db_user' => $tenantDbUser,
        'owner_email' => $ownerEmail,
        'owner_password' => $ownerPassword,
        'seed_profile' => $seedProfile === '' ? null : $seedProfile,
        'seed_summary' => $seedProfileSummary,
        'smoke' => $smoke,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    provision_usage();
    exit(1);
}
