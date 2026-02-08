<?php

final class ApiResponse
{
    public static function json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(array $data = [], int $status = 200): void
    {
        self::json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    public static function error(string $message, int $status = 400, array $meta = []): void
    {
        self::json([
            'success' => false,
            'error' => $message,
            'meta' => $meta,
        ], $status);
    }

    public static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::error('Invalid JSON body', 400);
        }

        return $decoded;
    }
}

