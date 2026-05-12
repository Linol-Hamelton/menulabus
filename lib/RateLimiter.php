<?php
/**
 * lib/RateLimiter.php — file-based fixed-window per-bucket counter.
 *
 * Tiny abstraction designed to protect webhook + auth endpoints from flooding.
 * Stores rolling counters as JSON files under `data/cache/ratelimit/`.
 *
 * Usage:
 *   if (!RateLimiter::allow('webhook:yandex_eda:' . $clientIp, 60, 60)) {
 *       http_response_code(429);
 *       exit;
 *   }
 *
 * Designed to fail-open on cache write errors — never blocks legitimate
 * traffic due to disk problems. Audit-logs throttle violations.
 */

final class RateLimiter
{
    private static function dir(): string
    {
        $dir = __DIR__ . '/../data/cache/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Returns true if the bucket has capacity, false if rate limited.
     *
     * @param string $bucket     stable key (e.g. "webhook:yandex_eda:1.2.3.4")
     * @param int    $limit      max requests in window
     * @param int    $windowSec  window length in seconds
     */
    public static function allow(string $bucket, int $limit, int $windowSec): bool
    {
        $bucket = preg_replace('/[^a-zA-Z0-9_:.\-]/', '_', $bucket);
        $path = self::dir() . '/' . substr(sha1($bucket), 0, 16) . '.json';
        $now = time();
        $state = ['start' => $now, 'count' => 0];

        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
                    if (((int)$decoded['start'] + $windowSec) > $now) {
                        $state = $decoded;
                    }
                }
            }
        }
        $state['count'] = (int)$state['count'] + 1;
        $state['count_max'] = $limit;
        $state['window'] = $windowSec;
        @file_put_contents($path, json_encode($state), LOCK_EX);

        return (int)$state['count'] <= $limit;
    }

    public static function clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $value) {
            if (!is_string($value) || $value === '') continue;
            // Comma-separated list — take the first valid IP.
            foreach (preg_split('/\s*,\s*/', $value) as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        return '0.0.0.0';
    }
}
