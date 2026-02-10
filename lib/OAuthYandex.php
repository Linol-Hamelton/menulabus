<?php

/**
 * Yandex ID OAuth helper
 *
 * Validates OAuth tokens and retrieves user info from Yandex ID API.
 * Unlike Google, Yandex does not provide JWT id_token — we must call /info endpoint with access_token.
 */
class OAuthYandex
{
    /**
     * Get user info from Yandex /info endpoint
     *
     * @param string $accessToken OAuth access token
     * @return array Normalized claims ['subject', 'email', 'email_verified', 'name']
     * @throws RuntimeException if token is invalid or API fails
     */
    public static function getUserInfo(string $accessToken): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Authorization: OAuth {$accessToken}\r\nAccept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents('https://login.yandex.ru/info?format=json', false, $ctx);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Yandex /info request failed');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Yandex /info returned invalid JSON');
        }

        // Check for error response
        if (isset($data['error'])) {
            $errMsg = $data['error_description'] ?? $data['error'];
            throw new RuntimeException("Yandex /info error: {$errMsg}");
        }

        // Extract fields
        // Docs: https://yandex.ru/dev/id/doc/ru/user-information
        $id = (string)($data['id'] ?? '');
        $email = (string)($data['default_email'] ?? '');
        $displayName = (string)($data['display_name'] ?? '');
        $realName = (string)($data['real_name'] ?? '');
        $firstName = (string)($data['first_name'] ?? '');

        if ($id === '') {
            throw new RuntimeException('Yandex /info missing user id');
        }
        if ($email === '') {
            throw new RuntimeException('Yandex /info missing default_email');
        }

        // Email from Yandex is always verified (they require phone/email verification on signup)
        $emailVerified = true;

        // Determine best name: display_name > real_name > first_name > "User"
        $name = $displayName ?: ($realName ?: ($firstName ?: 'User'));

        return [
            'subject' => $id,
            'email' => $email,
            'email_verified' => $emailVerified,
            'name' => $name,
        ];
    }
}
