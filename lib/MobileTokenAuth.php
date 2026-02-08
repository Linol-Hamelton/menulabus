<?php

final class MobileTokenAuth
{
    private const ACCESS_TTL = 3600;
    private const REFRESH_TTL = 2592000;
    private const REFRESH_PREFIX = 'mobile_refresh_';

    private static function secret(): string
    {
        $envSecret = getenv('MOBILE_TOKEN_SECRET');
        if (is_string($envSecret) && $envSecret !== '') {
            return $envSecret;
        }

        return hash('sha256', DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS);
    }

    private static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string|false
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 ? strlen($data) + 4 - strlen($data) % 4 : strlen($data), '=', STR_PAD_RIGHT);
        return base64_decode($padded, true);
    }

    private static function sign(string $payload): string
    {
        return self::b64urlEncode(hash_hmac('sha256', $payload, self::secret(), true));
    }

    private static function now(): int
    {
        return time();
    }

    private static function ensureRefreshTable(PDO $pdo): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS mobile_refresh_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                device_name VARCHAR(120) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                ip_address VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_mobile_refresh_hash (token_hash),
                KEY idx_mobile_refresh_user (user_id),
                KEY idx_mobile_refresh_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $initialized = true;
    }

    private static function issueAccessToken(array $user): string
    {
        $payload = [
            'typ' => 'access',
            'sub' => (int)$user['id'],
            'role' => (string)$user['role'],
            'iat' => self::now(),
            'exp' => self::now() + self::ACCESS_TTL,
        ];
        $encoded = self::b64urlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $encoded . '.' . self::sign($encoded);
    }

    private static function issueRefreshToken(int $userId): string
    {
        $payload = [
            'typ' => 'refresh',
            'sub' => $userId,
            'iat' => self::now(),
            'exp' => self::now() + self::REFRESH_TTL,
            'rnd' => bin2hex(random_bytes(16)),
        ];
        $encoded = self::b64urlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $encoded . '.' . self::sign($encoded);
    }

    public static function verifyToken(string $token, string $type = 'access'): ?array
    {
        if (strpos($token, '.') === false) {
            return null;
        }

        [$encoded, $signature] = explode('.', $token, 2);
        if (!hash_equals(self::sign($encoded), $signature)) {
            return null;
        }

        $decoded = self::b64urlDecode($encoded);
        if (!is_string($decoded)) {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return null;
        }

        if (($payload['typ'] ?? null) !== $type) {
            return null;
        }

        if ((int)($payload['exp'] ?? 0) < self::now()) {
            return null;
        }

        return $payload;
    }

    public static function issueTokenPair(PDO $pdo, array $user, ?string $deviceName = null): array
    {
        self::ensureRefreshTable($pdo);

        $refreshToken = self::issueRefreshToken((int)$user['id']);
        $refreshHash = hash('sha256', $refreshToken);

        $stmt = $pdo->prepare(
            "INSERT INTO mobile_refresh_tokens
             (user_id, token_hash, device_name, user_agent, ip_address, expires_at)
             VALUES (:user_id, :token_hash, :device_name, :user_agent, :ip_address, :expires_at)"
        );
        $stmt->execute([
            ':user_id' => (int)$user['id'],
            ':token_hash' => $refreshHash,
            ':device_name' => $deviceName,
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            ':expires_at' => date('Y-m-d H:i:s', self::now() + self::REFRESH_TTL),
        ]);

        return [
            'access_token' => self::issueAccessToken($user),
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
        ];
    }

    public static function rotateRefreshToken(PDO $pdo, string $refreshToken, ?string $deviceName = null): ?array
    {
        self::ensureRefreshTable($pdo);
        $payload = self::verifyToken($refreshToken, 'refresh');
        if (!$payload) {
            return null;
        }

        $refreshHash = hash('sha256', $refreshToken);
        $stmt = $pdo->prepare(
            "SELECT id, user_id
             FROM mobile_refresh_tokens
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL
               AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([':token_hash' => $refreshHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $revokeStmt = $pdo->prepare("UPDATE mobile_refresh_tokens SET revoked_at = NOW() WHERE id = :id");
        $revokeStmt->execute([':id' => (int)$row['id']]);

        $db = Database::getInstance();
        $user = $db->getUserById((int)$row['user_id']);
        if (!$user || empty($user['is_active'])) {
            return null;
        }

        return self::issueTokenPair($pdo, $user, $deviceName);
    }

    public static function revokeRefreshToken(PDO $pdo, string $refreshToken): bool
    {
        self::ensureRefreshTable($pdo);
        $refreshHash = hash('sha256', $refreshToken);
        $stmt = $pdo->prepare(
            "UPDATE mobile_refresh_tokens
             SET revoked_at = NOW()
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL"
        );
        $stmt->execute([':token_hash' => $refreshHash]);
        return $stmt->rowCount() > 0;
    }

    public static function extractBearerToken(): ?string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }

        return trim($m[1]);
    }
}

