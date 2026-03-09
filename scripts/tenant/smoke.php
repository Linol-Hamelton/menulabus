<?php

function tenant_smoke_fetch(string $url): array
{
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

function tenant_smoke_run(string $baseUrl): array
{
    $baseUrl = rtrim($baseUrl, '/');
    $checks = [];
    foreach (['/', '/menu.php', '/api/v1/menu.php'] as $path) {
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

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $options = getopt('', ['url::', 'domain::', 'scheme::']);
    $url = trim((string)($options['url'] ?? ''));
    if ($url === '') {
        $domain = trim((string)($options['domain'] ?? ''));
        if ($domain === '') {
            fwrite(STDERR, "Usage: php scripts/tenant/smoke.php --url=https://example.com\n");
            exit(1);
        }
        $scheme = trim((string)($options['scheme'] ?? 'https'));
        $url = $scheme . '://' . $domain;
    }

    $result = tenant_smoke_run($url);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}
