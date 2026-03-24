<?php

require_once __DIR__ . '/provision.php';

function tenant_launch_usage(): void
{
    $usage = <<<TXT
Usage:
  php scripts/tenant/launch.php --brand-name="Brand" --brand-slug=brand --domain=menu.brand.tld --mode=tenant --owner-email=owner@example.com --tenant-db-user=db_user --tenant-db-pass=db_pass [--owner-password=secret] [--seed-profile=restaurant-demo] [--contact-phone=+79000000000] [--contact-address="Москва, Цветной б-р, 24"] [--contact-map-url=https://yandex.ru/maps/... ] [--public-entry-mode=homepage] [--artifact-dir=scripts/tenant/data/launch-artifacts] [--skip-smoke]
TXT;
    fwrite(STDERR, $usage . PHP_EOL);
}

function tenant_launch_cli_options(): array
{
    return getopt('', [
        'brand-name:',
        'brand-slug:',
        'domain:',
        'mode:',
        'owner-email:',
        'owner-password::',
        'tenant-db-user:',
        'tenant-db-pass:',
        'seed-profile::',
        'contact-phone::',
        'contact-address::',
        'contact-map-url::',
        'public-entry-mode::',
        'artifact-dir::',
        'skip-smoke',
    ]);
}

function tenant_launch_artifact_directory(array $options): string
{
    $candidate = trim((string)($options['artifact-dir'] ?? ''));
    if ($candidate === '') {
        return __DIR__ . '/data/launch-artifacts';
    }

    if (preg_match('#^[A-Za-z]:\\\\#', $candidate) || str_starts_with($candidate, '\\\\') || str_starts_with($candidate, '/')) {
        return $candidate;
    }

    return dirname(__DIR__, 2) . '/' . ltrim(str_replace('\\', '/', $candidate), '/');
}

function tenant_launch_write_artifact(array $payload, string $artifactDir, string $brandSlug): string
{
    if (!is_dir($artifactDir) && !mkdir($artifactDir, 0775, true) && !is_dir($artifactDir)) {
        throw new RuntimeException('Failed to create artifact directory: ' . $artifactDir);
    }

    $filename = sprintf(
        'launch-%s-%s.json',
        preg_replace('/[^a-z0-9_]+/', '_', strtolower($brandSlug)) ?: 'tenant',
        gmdate('Ymd-His')
    );
    $path = rtrim($artifactDir, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $filename;
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || file_put_contents($path, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Failed to write launch artifact: ' . $path);
    }

    return $path;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $options = tenant_launch_cli_options();
    $result = provision_run($options);

    $artifactPayload = [
        'kind' => 'tenant_launch_artifact',
        'generated_at' => gmdate('c'),
        'launch' => [
            'mode' => $result['mode'],
            'brand_name' => $result['brand_name'],
            'brand_slug' => $result['brand_slug'],
            'domain' => $result['domain'],
            'base_url' => $result['base_url'],
            'db_name' => $result['db_name'],
            'db_user' => $result['db_user'],
        ],
        'brand_contract' => $result['brand_contract'],
        'launch_acceptance' => $result['launch_acceptance'] ?? null,
        'owner' => $result['owner'],
        'seed_profile' => $result['seed_profile'],
        'seed_summary' => $result['seed_summary'],
        'smoke' => $result['smoke'],
        'acceptance' => [
            'public_routes' => [
                $result['base_url'] . '/',
                $result['base_url'] . '/menu.php',
                $result['base_url'] . '/cart.php',
                $result['base_url'] . '/auth.php',
            ],
            'backoffice_routes' => [
                $result['base_url'] . '/account.php',
                $result['base_url'] . '/help.php',
                $result['base_url'] . '/admin-menu.php',
                $result['base_url'] . '/owner.php',
                $result['base_url'] . '/employee.php',
            ],
            'public_entry_mode' => $result['brand_contract']['public_entry_mode'] ?? 'homepage',
            'contact_address' => $result['brand_contract']['contact_address'] ?? '',
            'contact_map_url' => $result['brand_contract']['contact_map_url'] ?? '',
        ],
        'rollback_hint' => $result['rollback_hint'],
    ];

    $artifactPath = tenant_launch_write_artifact(
        $artifactPayload,
        tenant_launch_artifact_directory($options),
        (string)$result['brand_slug']
    );

    $result['launch_artifact'] = $artifactPath;
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    tenant_launch_usage();
    exit(1);
}
