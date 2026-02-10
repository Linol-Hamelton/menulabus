<?php

/**
 * VK ID OAuth helper
 *
 * Retrieves user info from VK API (users.get method).
 * VK does not provide JWT id_token — we must call API with access_token.
 */
class OAuthVK
{
    /**
     * Get user info from VK API users.get method
     *
     * @param string $accessToken OAuth access token
     * @param int $userId VK user ID from token response
     * @return array Normalized claims ['subject', 'email', 'email_verified', 'name', 'phone']
     * @throws RuntimeException if token is invalid or API fails
     */
    public static function getUserInfo(string $accessToken, int $userId, ?string $email = null): array
    {
        // Call VK API users.get to retrieve user profile
        $params = [
            'user_ids' => (string)$userId,
            'fields' => 'photo_200', // можно добавить другие поля если нужно
            'access_token' => $accessToken,
            'v' => '5.131',
        ];

        $url = 'https://api.vk.com/method/users.get?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('VK API users.get request failed');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('VK API returned invalid JSON');
        }

        // Check for error response
        if (isset($data['error'])) {
            $errMsg = $data['error']['error_msg'] ?? 'Unknown error';
            throw new RuntimeException("VK API error: {$errMsg}");
        }

        // Extract user data from response
        $response = $data['response'] ?? [];
        if (!is_array($response) || empty($response)) {
            throw new RuntimeException('VK API returned empty response');
        }

        $user = $response[0] ?? [];
        if (!is_array($user)) {
            throw new RuntimeException('VK API returned invalid user data');
        }

        $id = (int)($user['id'] ?? 0);
        $firstName = trim((string)($user['first_name'] ?? ''));
        $lastName = trim((string)($user['last_name'] ?? ''));

        if ($id === 0) {
            throw new RuntimeException('VK API missing user id');
        }

        // Combine first and last name
        $name = trim($firstName . ' ' . $lastName);
        if ($name === '') {
            $name = 'User';
        }

        // VK email comes from token response, not from users.get
        // If no email provided, we can't proceed (email is required for account linking)
        if (empty($email)) {
            throw new RuntimeException('VK OAuth: email is required but not provided');
        }

        // VK emails are always verified (required on signup)
        $emailVerified = true;

        return [
            'subject' => (string)$id,
            'email' => strtolower(trim($email)),
            'email_verified' => $emailVerified,
            'name' => $name,
            'phone' => null, // VK does not provide phone through basic OAuth scope
        ];
    }
}
