<?php

require_once __DIR__ . '/../config_copy.php';

$tenantControlConfig = dirname(__DIR__) . '/tenant_control_config.php';
if (is_file($tenantControlConfig)) {
    require_once $tenantControlConfig;
}

function tenant_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function tenant_normalize_host(?string $host): string
{
    $host = trim((string)$host);
    if ($host === '') {
        return '';
    }

    if (strpos($host, '://') !== false) {
        $parsedHost = (string)(parse_url($host, PHP_URL_HOST) ?? '');
        if ($parsedHost !== '') {
            $host = $parsedHost;
        }
    }

    if (strpos($host, '/') !== false) {
        $host = strtok($host, '/');
    }

    if (substr_count($host, ':') === 1) {
        [$hostOnly, $port] = explode(':', $host, 2);
        if ($port !== '' && ctype_digit($port)) {
            $host = $hostOnly;
        }
    }

    return strtolower(trim($host));
}

function tenant_current_host(): string
{
    static $host = null;
    if ($host !== null) {
        return $host;
    }

    $candidates = [
        $_SERVER['HTTP_HOST'] ?? null,
        $_SERVER['SERVER_NAME'] ?? null,
        getenv('APP_HOST') ?: null,
    ];

    foreach ($candidates as $candidate) {
        $normalized = tenant_normalize_host($candidate);
        if ($normalized !== '') {
            $host = $normalized;
            return $host;
        }
    }

    $host = '';
    return $host;
}

function tenant_current_scheme(): string
{
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto === 'https') {
        return 'https';
    }

    $https = $_SERVER['HTTPS'] ?? null;
    if (!empty($https) && strtolower((string)$https) !== 'off') {
        return 'https';
    }

    return 'http';
}

function tenant_control_configured(): bool
{
    return defined('CONTROL_DB_HOST')
        && defined('CONTROL_DB_NAME')
        && defined('CONTROL_DB_USER')
        && defined('CONTROL_DB_PASS');
}

function tenant_provider_hosts(): array
{
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }

    $configured = [];
    if (defined('CONTROL_PROVIDER_HOSTS') && is_array(CONTROL_PROVIDER_HOSTS)) {
        $configured = CONTROL_PROVIDER_HOSTS;
    } else {
        $env = trim((string)(getenv('CONTROL_PROVIDER_HOSTS') ?: ''));
        if ($env !== '') {
            $configured = preg_split('/\s*,\s*/', $env) ?: [];
        }
    }

    if ($configured === []) {
        $configured = ['menu.labus.pro', 'www.menu.labus.pro', 'localhost', '127.0.0.1'];
    }

    $normalized = [];
    foreach ($configured as $host) {
        $value = tenant_normalize_host((string)$host);
        if ($value !== '') {
            $normalized[$value] = true;
        }
    }

    $hosts = array_keys($normalized);
    return $hosts;
}

function tenant_registry_secret_key(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }

    $fromEnv = trim((string)(getenv('TENANT_REGISTRY_SECRET_KEY') ?: ''));
    if ($fromEnv !== '') {
        $secret = hash('sha256', $fromEnv, true);
        return $secret;
    }

    if (defined('TENANT_REGISTRY_SECRET_KEY')) {
        $configured = trim((string)TENANT_REGISTRY_SECRET_KEY);
        if ($configured !== '') {
            $secret = hash('sha256', $configured, true);
            return $secret;
        }
    }

    $fallbackMaterial = implode('|', [
        defined('CONTROL_DB_HOST') ? (string)CONTROL_DB_HOST : '',
        defined('CONTROL_DB_NAME') ? (string)CONTROL_DB_NAME : '',
        defined('CONTROL_DB_USER') ? (string)CONTROL_DB_USER : '',
        defined('CONTROL_DB_PASS') ? (string)CONTROL_DB_PASS : '',
    ]);
    $secret = hash('sha256', $fallbackMaterial, true);
    return $secret;
}

function tenant_registry_encrypt(string $plainText): string
{
    if ($plainText === '') {
        return '';
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        tenant_registry_secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if (!is_string($cipherText) || $cipherText === '') {
        throw new RuntimeException('Failed to encrypt tenant credential');
    }

    return base64_encode($iv . $tag . $cipherText);
}

function tenant_registry_decrypt(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || strlen($raw) <= 28) {
        return $encoded;
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipherText = substr($raw, 28);
    if ($iv === false || $tag === false || $cipherText === false) {
        return $encoded;
    }

    $plainText = openssl_decrypt(
        $cipherText,
        'aes-256-gcm',
        tenant_registry_secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return is_string($plainText) ? $plainText : $encoded;
}

function tenant_control_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!tenant_control_configured()) {
        throw new RuntimeException('Tenant control DB is not configured');
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        (string)CONTROL_DB_HOST,
        (string)CONTROL_DB_NAME
    );

    $pdo = new PDO($dsn, (string)CONTROL_DB_USER, (string)CONTROL_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);

    return $pdo;
}

function tenant_build_base_url(string $host): string
{
    if ($host === '') {
        return '';
    }

    return tenant_current_scheme() . '://' . $host;
}

function tenant_legacy_context(): array
{
    $host = tenant_current_host();
    $providerHost = $host !== '' ? $host : (tenant_provider_hosts()[0] ?? 'menu.labus.pro');
    $tenantDbHost = defined('TENANT_DB_HOST') ? (string)TENANT_DB_HOST : (defined('DB_HOST') ? (string)DB_HOST : '127.0.0.1');

    return [
        'state' => 'resolved',
        'mode' => 'provider',
        'tenant_id' => 0,
        'brand_slug' => 'labus',
        'tenant_db_host' => $tenantDbHost,
        'tenant_db_name' => (string)DB_NAME,
        'tenant_db_user' => (string)DB_USER,
        'tenant_db_pass' => (string)DB_PASS,
        'current_host' => $providerHost,
        'primary_host' => $providerHost,
        'base_url' => tenant_build_base_url($providerHost),
        'primary_base_url' => tenant_build_base_url($providerHost),
        'cookie_domain' => '',
        'is_provider' => true,
        'control_db_enabled' => false,
        'resolved_from' => 'legacy',
    ];
}

function tenant_error_context(int $status, string $reason, string $message): array
{
    $host = tenant_current_host();

    return [
        'state' => 'error',
        'http_status' => $status,
        'reason' => $reason,
        'message' => $message,
        'current_host' => $host,
        'primary_host' => '',
        'base_url' => tenant_build_base_url($host),
        'primary_base_url' => '',
        'cookie_domain' => '',
        'is_provider' => false,
        'control_db_enabled' => tenant_control_configured(),
        'resolved_from' => 'control',
    ];
}

function tenant_runtime(): array
{
    static $context = null;
    if ($context !== null) {
        return $context;
    }

    if (!tenant_control_configured()) {
        $context = tenant_legacy_context();
        return $context;
    }

    $host = tenant_current_host();
    if ($host === '') {
        if (tenant_is_cli()) {
            $context = tenant_legacy_context();
            return $context;
        }

        $context = tenant_error_context(400, 'missing_host', 'Tenant host was not provided');
        return $context;
    }

    try {
        $pdo = tenant_control_pdo();
        $stmt = $pdo->prepare(
            "SELECT
                t.id AS tenant_id,
                t.mode,
                t.brand_slug,
                t.db_name,
                t.db_user,
                t.db_pass_enc,
                t.is_active,
                d.host AS matched_host,
                p.host AS primary_host
             FROM tenant_domains d
             INNER JOIN tenants t ON t.id = d.tenant_id
             LEFT JOIN tenant_domains p ON p.tenant_id = t.id AND p.is_primary = 1
             WHERE d.host = :host
             LIMIT 1"
        );
        $stmt->execute([':host' => $host]);
        $row = $stmt->fetch();

        if (!$row) {
            if (tenant_is_cli()) {
                $context = tenant_legacy_context();
                return $context;
            }

            $context = tenant_error_context(404, 'tenant_not_configured', 'Tenant is not configured for this host');
            return $context;
        }

        if (!(bool)($row['is_active'] ?? false)) {
            $context = tenant_error_context(404, 'tenant_inactive', 'Tenant is inactive');
            return $context;
        }

        $primaryHost = tenant_normalize_host((string)($row['primary_host'] ?? ''));
        if ($primaryHost === '') {
            $primaryHost = $host;
        }

        $tenantDbHost = defined('TENANT_DB_HOST') ? (string)TENANT_DB_HOST : '127.0.0.1';
        $mode = (string)($row['mode'] ?? 'tenant');
        $context = [
            'state' => 'resolved',
            'mode' => $mode,
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'brand_slug' => (string)($row['brand_slug'] ?? ''),
            'tenant_db_host' => $tenantDbHost,
            'tenant_db_name' => (string)($row['db_name'] ?? ''),
            'tenant_db_user' => (string)($row['db_user'] ?? ''),
            'tenant_db_pass' => tenant_registry_decrypt((string)($row['db_pass_enc'] ?? '')),
            'current_host' => $host,
            'primary_host' => $primaryHost,
            'base_url' => tenant_build_base_url($host),
            'primary_base_url' => tenant_build_base_url($primaryHost),
            'cookie_domain' => '',
            'is_provider' => $mode === 'provider',
            'control_db_enabled' => true,
            'resolved_from' => 'control',
        ];
        return $context;
    } catch (Throwable $e) {
        error_log('Tenant runtime resolve failed: ' . $e->getMessage());

        if (tenant_is_cli()) {
            $context = tenant_legacy_context();
            return $context;
        }

        $context = tenant_error_context(503, 'tenant_registry_unavailable', 'Tenant registry is unavailable');
        return $context;
    }
}

function tenant_runtime_require_resolved(): array
{
    $context = tenant_runtime();
    if (($context['state'] ?? '') === 'resolved') {
        return $context;
    }

    if (!headers_sent()) {
        http_response_code((int)($context['http_status'] ?? 503));
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo (string)($context['message'] ?? 'Tenant resolution failed');
    exit;
}

function tenant_is_provider_mode(): bool
{
    return !empty(tenant_runtime()['is_provider']);
}

function tenant_is_tenant_mode(): bool
{
    return !tenant_is_provider_mode();
}

function tenant_base_url(bool $usePrimary = false): string
{
    $context = tenant_runtime();
    if ($usePrimary && !empty($context['primary_base_url'])) {
        return (string)$context['primary_base_url'];
    }

    return (string)($context['base_url'] ?? '');
}

function tenant_url(string $path = '/', array $query = [], bool $usePrimary = false): string
{
    $path = trim($path);
    if ($path === '') {
        $path = '/';
    } elseif ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $url = rtrim(tenant_base_url($usePrimary), '/') . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function tenant_default_allowed_origin(): string
{
    $primary = tenant_base_url(true);
    if ($primary !== '') {
        return $primary;
    }

    return tenant_base_url();
}

function tenant_allowed_web_origins(): array
{
    $origins = [
        tenant_base_url(),
        tenant_base_url(true),
        'capacitor://localhost',
        'ionic://localhost',
        'http://localhost',
        'http://127.0.0.1',
    ];

    $result = [];
    foreach ($origins as $origin) {
        $origin = trim((string)$origin);
        if ($origin !== '') {
            $result[$origin] = true;
        }
    }

    return array_keys($result);
}

function tenant_is_allowed_origin(string $origin): bool
{
    foreach (tenant_allowed_web_origins() as $allowed) {
        if ($origin === $allowed) {
            return true;
        }
        if (
            ($allowed === 'http://localhost' || $allowed === 'http://127.0.0.1')
            && strpos($origin, $allowed . ':') === 0
        ) {
            return true;
        }
    }

    return false;
}

function tenant_connect_src_origins(): array
{
    $sources = [
        "'self'",
        tenant_base_url(),
        tenant_base_url(true),
        'https://nominatim.openstreetmap.org',
    ];

    $result = [];
    foreach ($sources as $source) {
        $value = trim((string)$source);
        if ($value !== '') {
            $result[$value] = true;
        }
    }

    return array_keys($result);
}

function tenant_host_only_cookie_options(array $overrides = []): array
{
    $options = [
        'expires' => 0,
        'path' => '/',
        'secure' => tenant_current_scheme() === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($options[$key]);
            continue;
        }
        $options[$key] = $value;
    }

    if (empty($options['domain'])) {
        unset($options['domain']);
    }

    return $options;
}

function tenant_apply_session_cookie_params(int $lifetime = 0, string $sameSite = 'Strict'): void
{
    session_set_cookie_params(tenant_host_only_cookie_options([
        'expires' => $lifetime > 0 ? time() + $lifetime : 0,
        'samesite' => $sameSite,
    ]));
}

function tenant_secret_material(): string
{
    $context = tenant_runtime();

    return implode('|', [
        (string)($context['tenant_db_host'] ?? DB_HOST),
        (string)($context['tenant_db_name'] ?? DB_NAME),
        (string)($context['tenant_db_user'] ?? DB_USER),
        (string)($context['tenant_db_pass'] ?? DB_PASS),
    ]);
}
