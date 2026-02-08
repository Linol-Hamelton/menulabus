<?php

final class Idempotency
{
    private const TTL_SECONDS = 900;

    private static function ensureTable(PDO $pdo): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS api_idempotency_keys (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                idempotency_key VARCHAR(128) NOT NULL,
                scope VARCHAR(64) NOT NULL,
                request_hash CHAR(64) NOT NULL,
                response_json JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_idempotency_scope_key (scope, idempotency_key),
                KEY idx_idempotency_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $initialized = true;
    }

    public static function getHeaderKey(): ?string
    {
        $key = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if ($key === '') {
            return null;
        }
        if (strlen($key) > 128) {
            return substr($key, 0, 128);
        }
        return $key;
    }

    public static function hashPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            // Fallback to deterministic hash even for malformed input bytes.
            $json = serialize($payload);
        }
        return hash('sha256', $json);
    }

    public static function find(PDO $pdo, string $scope, string $key, string $requestHash): ?array
    {
        self::ensureTable($pdo);

        $cleanup = $pdo->prepare("DELETE FROM api_idempotency_keys WHERE expires_at <= NOW()");
        $cleanup->execute();

        $stmt = $pdo->prepare(
            "SELECT request_hash, response_json
             FROM api_idempotency_keys
             WHERE scope = :scope
               AND idempotency_key = :idempotency_key
               AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([
            ':scope' => $scope,
            ':idempotency_key' => $key,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        if (!hash_equals((string)$row['request_hash'], $requestHash)) {
            return [
                'conflict' => true,
                'response' => null,
            ];
        }

        return [
            'conflict' => false,
            'response' => json_decode((string)$row['response_json'], true),
        ];
    }

    public static function store(PDO $pdo, string $scope, string $key, string $requestHash, array $response): void
    {
        self::ensureTable($pdo);

        $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($responseJson === false) {
            $responseJson = json_encode(['success' => false, 'error' => 'idempotency_encode_failed'], JSON_UNESCAPED_UNICODE);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO api_idempotency_keys
             (idempotency_key, scope, request_hash, response_json, expires_at)
             VALUES (:idempotency_key, :scope, :request_hash, :response_json, :expires_at)
             ON DUPLICATE KEY UPDATE
               request_hash = VALUES(request_hash),
               response_json = VALUES(response_json),
               expires_at = VALUES(expires_at)"
        );
        $stmt->execute([
            ':idempotency_key' => $key,
            ':scope' => $scope,
            ':request_hash' => $requestHash,
            ':response_json' => $responseJson,
            ':expires_at' => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ]);
    }
}
