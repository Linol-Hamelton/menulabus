<?php

final class CheckoutErrorLog
{
    /**
     * Emit a structured checkout error event to PHP error log.
     * Keep payload small and avoid PII-heavy fields.
     */
    public static function log(
        string $endpoint,
        string $category,
        string $reason,
        int $statusCode,
        array $context = []
    ): void {
        $payload = [
            'event' => 'checkout_error',
            'ts' => gmdate('c'),
            'request_id' => self::requestId(),
            'endpoint' => $endpoint,
            'category' => $category,
            'reason' => $reason,
            'status' => $statusCode,
            'idempotency_key_present' => self::idempotencyKeyPresent(),
            'user_id' => self::userId(),
            'context' => self::sanitizeContext($context),
        ];

        error_log('[checkout-error] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function requestId(): string
    {
        $id = (string)($GLOBALS['request_id'] ?? '');
        if ($id !== '') {
            return $id;
        }

        $header = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        if ($header !== '') {
            return substr($header, 0, 64);
        }

        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            return substr(sha1((string)microtime(true)), 0, 16);
        }
    }

    private static function idempotencyKeyPresent(): bool
    {
        return trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')) !== '';
    }

    private static function userId(): ?int
    {
        $uid = $_SESSION['user_id'] ?? null;
        if (!is_scalar($uid) || (string)$uid === '') {
            return null;
        }
        return (int)$uid;
    }

    private static function sanitizeContext(array $context): array
    {
        $result = [];
        $count = 0;
        foreach ($context as $key => $value) {
            if ($count >= 12) {
                break;
            }
            $k = (string)$key;
            if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
                $result[$k] = $value;
            } elseif (is_string($value)) {
                if (function_exists('mb_substr')) {
                    $result[$k] = mb_substr($value, 0, 160, 'UTF-8');
                } else {
                    $result[$k] = substr($value, 0, 160);
                }
            } elseif (is_array($value)) {
                $result[$k] = ['type' => 'array', 'count' => count($value)];
            } else {
                $result[$k] = ['type' => gettype($value)];
            }
            $count++;
        }

        return $result;
    }
}
