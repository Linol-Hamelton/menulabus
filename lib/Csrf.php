<?php

final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['csrf_token'];
    }

    public static function verify(?string $submitted): bool
    {
        $expected = (string)($_SESSION['csrf_token'] ?? '');
        $submitted = (string)($submitted ?? '');

        if ($expected === '' || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    public static function submitted(): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        if (!empty($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
            return $_POST['csrf_token'];
        }

        $raw = @file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['csrf_token']) && is_string($decoded['csrf_token'])) {
                return $decoded['csrf_token'];
            }
        }

        return null;
    }

    public static function requireValid(): void
    {
        if (!self::verify(self::submitted())) {
            http_response_code(403);
            if (self::wantsJson()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'csrf_mismatch'], JSON_UNESCAPED_UNICODE);
            } else {
                echo 'Forbidden: CSRF token mismatch';
            }
            exit;
        }
    }

    private static function wantsJson(): bool
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $ctype  = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($accept, 'application/json') !== false) {
            return true;
        }
        if (stripos($ctype, 'application/json') !== false) {
            return true;
        }
        return false;
    }
}
