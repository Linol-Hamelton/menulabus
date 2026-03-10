<?php

require_once dirname(__DIR__, 2) . '/tenant_runtime.php';
require_once __DIR__ . '/smoke.php';
require_once __DIR__ . '/seed_profiles.php';

function tenant_seed_usage(): void
{
    $profiles = implode(', ', tenant_seed_profile_usage_list());
    $usage = <<<TXT
Usage:
  php scripts/tenant/seed.php --profile=restaurant-demo --brand-slug=test_milyidom [--force]
  php scripts/tenant/seed.php --profile=restaurant-demo --domain=test.milyidom.com [--force]

Options:
  --profile       Seed profile ({$profiles})
  --brand-slug    Resolve target tenant via control-plane brand_slug
  --domain        Resolve target tenant via control-plane host
  --force         Replace existing menu/orders demo content
  --skip-smoke    Skip HTTP smoke after seeding
TXT;
    fwrite(STDERR, $usage . PHP_EOL);
}

function tenant_seed_require_value(array $options, string $key): string
{
    $value = trim((string)($options[$key] ?? ''));
    if ($value === '') {
        throw new InvalidArgumentException("Missing required option --{$key}");
    }

    return $value;
}

function tenant_seed_resolve_target(array $options): array
{
    if (!tenant_control_configured()) {
        throw new RuntimeException('tenant control DB config is not available');
    }

    $brandSlug = trim((string)($options['brand-slug'] ?? ''));
    $domain = tenant_normalize_host((string)($options['domain'] ?? ''));
    if ($brandSlug === '' && $domain === '') {
        throw new InvalidArgumentException('Provide either --brand-slug or --domain');
    }

    $pdo = tenant_control_pdo();
    if ($brandSlug !== '') {
        $stmt = $pdo->prepare(
            "SELECT
                t.id AS tenant_id,
                t.mode,
                t.brand_slug,
                t.db_name,
                t.db_user,
                t.db_pass_enc,
                d.host AS domain
             FROM tenants t
             LEFT JOIN tenant_domains d ON d.tenant_id = t.id AND d.is_primary = 1
             WHERE t.brand_slug = :brand_slug
             LIMIT 1"
        );
        $stmt->execute([':brand_slug' => $brandSlug]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT
                t.id AS tenant_id,
                t.mode,
                t.brand_slug,
                t.db_name,
                t.db_user,
                t.db_pass_enc,
                d.host AS domain
             FROM tenant_domains d
             INNER JOIN tenants t ON t.id = d.tenant_id
             WHERE d.host = :host
             LIMIT 1"
        );
        $stmt->execute([':host' => $domain]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Target tenant not found in control-plane');
    }

    $resolvedDomain = tenant_normalize_host((string)($row['domain'] ?? ''));
    if ($resolvedDomain === '') {
        throw new RuntimeException('Target tenant does not have a primary domain');
    }

    return [
        'tenant_id' => (int)$row['tenant_id'],
        'mode' => (string)$row['mode'],
        'brand_slug' => (string)$row['brand_slug'],
        'db_name' => (string)$row['db_name'],
        'db_user' => (string)$row['db_user'],
        'db_pass' => tenant_registry_decrypt((string)$row['db_pass_enc']),
        'domain' => $resolvedDomain,
    ];
}

function tenant_seed_target_pdo(array $target): PDO
{
    $dbHost = defined('TENANT_DB_HOST') ? (string)TENANT_DB_HOST : '127.0.0.1';
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $dbHost,
        (string)$target['db_name']
    );

    return new PDO($dsn, (string)$target['db_user'], (string)$target['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]);
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $options = getopt('', [
        'profile:',
        'brand-slug::',
        'domain::',
        'force',
        'skip-smoke',
    ]);

    $profile = tenant_seed_require_value($options, 'profile');
    $target = tenant_seed_resolve_target($options);
    if (($target['mode'] ?? '') !== 'tenant') {
        throw new RuntimeException('Seed profiles are allowed only for tenant mode');
    }

    $tenantPdo = tenant_seed_target_pdo($target);
    $ownerStmt = $tenantPdo->prepare("SELECT email, name FROM users WHERE role = 'owner' AND is_active = 1 ORDER BY id ASC LIMIT 1");
    $ownerStmt->execute();
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$owner) {
        throw new RuntimeException('Active owner user was not found in target tenant DB');
    }

    $brandNameStmt = $tenantPdo->prepare("SELECT JSON_UNQUOTE(value) FROM settings WHERE `key` = 'app_name' LIMIT 1");
    $brandNameStmt->execute();
    $brandName = trim((string)$brandNameStmt->fetchColumn());

    $summary = tenant_seed_apply_profile($tenantPdo, [
        'tenant_id' => (int)$target['tenant_id'],
        'brand_slug' => (string)$target['brand_slug'],
        'brand_name' => $brandName,
        'owner_email' => (string)($owner['email'] ?? ''),
        'domain' => (string)$target['domain'],
    ], $profile, array_key_exists('force', $options));

    $smoke = ['ok' => false, 'skipped' => true];
    if (!array_key_exists('skip-smoke', $options)) {
        $smoke = tenant_smoke_run('https://' . $target['domain']);
    }

    echo json_encode([
        'ok' => true,
        'target' => [
            'tenant_id' => $target['tenant_id'],
            'brand_slug' => $target['brand_slug'],
            'domain' => $target['domain'],
            'db_name' => $target['db_name'],
            'db_user' => $target['db_user'],
        ],
        'seed' => $summary,
        'smoke' => $smoke,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    tenant_seed_usage();
    exit(1);
}
