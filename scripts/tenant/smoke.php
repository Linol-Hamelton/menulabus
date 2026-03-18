<?php

function tenant_smoke_fetch(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'cleanmenu-tenant-smoke',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw !== false) {
            $headerBlob = substr((string) $raw, 0, $headerSize);
            $headers = preg_split('/\r\n|\r|\n/', trim($headerBlob)) ?: [];

            return [
                'url' => $url,
                'status' => $status,
                'ok' => $status >= 200 && $status < 400,
                'error' => null,
                'headers' => array_values(array_filter($headers, static fn($line) => $line !== '')),
            ];
        }

        return [
            'url' => $url,
            'status' => $status,
            'ok' => false,
            'error' => $error !== '' ? $error : 'request_failed',
            'headers' => [],
        ];
    }

    $curlBinary = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'curl.exe' : 'curl';
    $nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
    $command = sprintf(
        '%s -sS -D - -o %s --max-time 10 %s',
        escapeshellcmd($curlBinary),
        escapeshellarg($nullDevice),
        escapeshellarg($url)
    );
    $headerBlob = shell_exec($command);
    if (is_string($headerBlob) && trim($headerBlob) !== '') {
        $headers = preg_split('/\r\n|\r|\n/', trim($headerBlob)) ?: [];
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return [
            'url' => $url,
            'status' => $status,
            'ok' => $status >= 200 && $status < 400,
            'error' => $status > 0 ? null : 'request_failed',
            'headers' => array_values(array_filter($headers, static fn($line) => $line !== '')),
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => "User-Agent: cleanmenu-tenant-smoke\r\nAccept: text/html,application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string)$headers[0], $m)) {
        $status = (int)$m[1];
    }

    return [
        'url' => $url,
        'status' => $status,
        'ok' => $status >= 200 && $status < 400,
        'error' => $body === false ? 'request_failed' : null,
        'headers' => $headers,
    ];
}

function tenant_smoke_run(string $baseUrl, ?array $paths = null): array
{
    $baseUrl = rtrim($baseUrl, '/');
    $checks = [];
    foreach (($paths ?? ['/', '/menu.php', '/api/v1/menu.php']) as $path) {
        $checks[] = tenant_smoke_fetch($baseUrl . $path);
    }

    $ok = true;
    foreach ($checks as $check) {
        if (empty($check['ok'])) {
            $ok = false;
            break;
        }
    }

    return [
        'ok' => $ok,
        'base_url' => $baseUrl,
        'checks' => $checks,
    ];
}

function tenant_smoke_regression_run(array $targets): array
{
    $results = [];
    $ok = true;
    foreach ($targets as $name => $baseUrl) {
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            continue;
        }
        $result = tenant_smoke_run($baseUrl);
        $results[] = [
            'name' => $name,
            'result' => $result,
        ];
        if (empty($result['ok'])) {
            $ok = false;
        }
    }

    return [
        'ok' => $ok && $results !== [],
        'kind' => 'provider_tenant_regression',
        'targets' => $results,
    ];
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $options = getopt('', [
        'url::',
        'domain::',
        'scheme::',
        'provider-url::',
        'provider-domain::',
        'tenant-url::',
        'tenant-domain::',
    ]);
    $scheme = trim((string)($options['scheme'] ?? 'https'));
    $providerUrl = trim((string)($options['provider-url'] ?? ''));
    $providerDomain = trim((string)($options['provider-domain'] ?? ''));
    $tenantUrl = trim((string)($options['tenant-url'] ?? ''));
    $tenantDomain = trim((string)($options['tenant-domain'] ?? ''));
    if ($providerUrl === '' && $providerDomain !== '') {
        $providerUrl = $scheme . '://' . $providerDomain;
    }
    if ($tenantUrl === '' && $tenantDomain !== '') {
        $tenantUrl = $scheme . '://' . $tenantDomain;
    }
    if ($providerUrl !== '' || $tenantUrl !== '') {
        $result = tenant_smoke_regression_run([
            'provider' => $providerUrl,
            'tenant' => $tenantUrl,
        ]);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit($result['ok'] ? 0 : 1);
    }

    $url = trim((string)($options['url'] ?? ''));
    if ($url === '') {
        $domain = trim((string)($options['domain'] ?? ''));
        if ($domain === '') {
            fwrite(STDERR, "Usage: php scripts/tenant/smoke.php --url=https://example.com\n");
            fwrite(STDERR, "   or: php scripts/tenant/smoke.php --provider-domain=menu.labus.pro --tenant-domain=test.milyidom.com\n");
            exit(1);
        }
        $url = $scheme . '://' . $domain;
    }

    $result = tenant_smoke_run($url);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}
