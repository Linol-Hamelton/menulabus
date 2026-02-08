<?php

/**
 * Simple API smoke runner.
 *
 * Usage:
 * php scripts/api-smoke-runner.php --base=https://menu.labus.pro --email=user@example.com --password=secret --run-order=1
 */

$opts = getopt('', [
    'base::',
    'email::',
    'password::',
    'run-order::',
    'insecure::',
]);

$base = rtrim((string)($opts['base'] ?? 'https://menu.labus.pro'), '/');
$email = (string)($opts['email'] ?? '');
$password = (string)($opts['password'] ?? '');
$runOrder = ((string)($opts['run-order'] ?? '0')) === '1';
$insecure = ((string)($opts['insecure'] ?? '0')) === '1';

$failures = 0;
$passes = 0;

function http_json(string $method, string $url, array $headers = [], ?array $body = null, bool $insecure = false): array
{
    $ch = curl_init($url);
    $payload = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null;
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    if ($payload !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($insecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = null;
    if (is_string($raw) && $raw !== '') {
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $normalized = ltrim($normalized, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F");

        $decoded = json_decode($normalized, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_array($decoded)) {
            $start = strpos($normalized, '{');
            $end = strrpos($normalized, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $slice = substr($normalized, $start, $end - $start + 1);
                $decoded = json_decode($slice, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'status' => $status,
        'json' => $json,
        'raw' => $raw,
        'errno' => $errno,
        'error' => $error,
    ];
}

function check_case(string $name, bool $ok, string $details = ''): bool
{
    $status = $ok ? 'PASS' : 'FAIL';
    echo sprintf("[%s] %s%s\n", $status, $name, $details !== '' ? " - {$details}" : '');
    return $ok;
}

function details_from_response(array $res): string
{
    $parts = ['status=' . (int)($res['status'] ?? 0)];
    $errno = (int)($res['errno'] ?? 0);
    if ($errno !== 0) {
        $parts[] = 'curl_errno=' . $errno;
    }
    $error = trim((string)($res['error'] ?? ''));
    if ($error !== '') {
        $parts[] = 'curl_error=' . $error;
    }
    if (is_array($res['json'])) {
        if (isset($res['json']['error']) && is_string($res['json']['error'])) {
            $parts[] = 'api_error=' . $res['json']['error'];
        }
    } else {
        $raw = trim((string)($res['raw'] ?? ''));
        if ($raw !== '') {
            $snippet = preg_replace('/\s+/', ' ', mb_substr($raw, 0, 160, 'UTF-8'));
            $parts[] = 'body=' . $snippet;
        }
    }
    return implode(', ', $parts);
}

// 1) Menu
$res = http_json('GET', $base . '/api/v1/menu.php', [], null, $insecure);
$ok = $res['errno'] === 0 && $res['status'] === 200 && is_array($res['json']) && (($res['json']['success'] ?? false) === true);
if (check_case('GET /api/v1/menu.php', $ok, details_from_response($res))) {
    $passes++;
} else {
    $failures++;
}

$tokens = null;

// 2) Login (optional if creds provided)
if ($email !== '' && $password !== '') {
    $res = http_json('POST', $base . '/api/v1/auth/login.php', [], [
        'email' => $email,
        'password' => $password,
        'device_name' => 'smoke-runner',
    ], $insecure);
    $ok = $res['errno'] === 0 && $res['status'] === 200 && is_array($res['json']) && (($res['json']['success'] ?? false) === true);
    if (check_case('POST /api/v1/auth/login.php', $ok, details_from_response($res))) {
        $passes++;
        $tokens = $res['json']['data']['tokens'] ?? null;
    } else {
        $failures++;
    }

    if (is_array($tokens) && !empty($tokens['access_token']) && !empty($tokens['refresh_token'])) {
        // 3) Me
        $res = http_json('GET', $base . '/api/v1/auth/me.php', [
            'Authorization: Bearer ' . $tokens['access_token'],
        ], null, $insecure);
        $ok = $res['errno'] === 0 && $res['status'] === 200 && is_array($res['json']) && (($res['json']['success'] ?? false) === true);
        if (check_case('GET /api/v1/auth/me.php', $ok, details_from_response($res))) {
            $passes++;
        } else {
            $failures++;
        }

        // 4) Refresh
        $res = http_json('POST', $base . '/api/v1/auth/refresh.php', [], [
            'refresh_token' => $tokens['refresh_token'],
            'device_name' => 'smoke-runner',
        ], $insecure);
        $ok = $res['errno'] === 0 && $res['status'] === 200 && is_array($res['json']) && (($res['json']['success'] ?? false) === true);
        if (check_case('POST /api/v1/auth/refresh.php', $ok, details_from_response($res))) {
            $passes++;
            $newTokens = $res['json']['data']['tokens'] ?? [];
            if (!empty($newTokens['access_token'])) {
                $tokens['access_token'] = $newTokens['access_token'];
            }
            if (!empty($newTokens['refresh_token'])) {
                $tokens['refresh_token'] = $newTokens['refresh_token'];
            }
        } else {
            $failures++;
        }

        // 5) Push subscribe
        $res = http_json('POST', $base . '/api/v1/push/subscribe.php', [
            'Authorization: Bearer ' . $tokens['access_token'],
            'Idempotency-Key: smoke-push-' . time(),
        ], [
            'subscription' => [
                'endpoint' => 'https://example.invalid/smoke-' . time(),
                'keys' => [
                    'p256dh' => 'smoke-p256dh',
                    'auth' => 'smoke-auth',
                ],
            ],
        ], $insecure);
        $ok = $res['errno'] === 0 && in_array($res['status'], [200, 201], true) && is_array($res['json']) && (($res['json']['success'] ?? false) === true);
        if (check_case('POST /api/v1/push/subscribe.php', $ok, details_from_response($res))) {
            $passes++;
        } else {
            $failures++;
        }

        // 6) Optional order create + idempotency replay
        if ($runOrder) {
            $idemKey = 'smoke-order-' . time();
            $orderPayload = [
                'items' => [
                    ['id' => 1, 'name' => 'Smoke item', 'price' => 1, 'quantity' => 1],
                ],
                'total' => 1,
                'delivery_type' => 'bar',
            ];

            $first = http_json('POST', $base . '/api/v1/orders/create.php', [
                'Authorization: Bearer ' . $tokens['access_token'],
                'Idempotency-Key: ' . $idemKey,
            ], $orderPayload, $insecure);

            $second = http_json('POST', $base . '/api/v1/orders/create.php', [
                'Authorization: Bearer ' . $tokens['access_token'],
                'Idempotency-Key: ' . $idemKey,
            ], $orderPayload, $insecure);

            $firstId = $first['json']['data']['order_id'] ?? null;
            $secondId = $second['json']['data']['order_id'] ?? null;
            $ok = $first['status'] === 201 && in_array($second['status'], [200, 201], true) && $firstId && $secondId && ((int)$firstId === (int)$secondId);
            if (check_case('POST /api/v1/orders/create.php (idempotency)', $ok, 'first=' . $first['status'] . ', second=' . $second['status'])) {
                $passes++;
            } else {
                $failures++;
            }
        }
    }
} else {
    echo "[SKIP] Auth-dependent tests skipped (provide --email and --password)\n";
}

echo "\nSummary: pass={$passes}, fail={$failures}\n";
exit($failures > 0 ? 1 : 0);
