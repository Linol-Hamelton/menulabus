<?php

/**
 * VK ID OAuth helper (Phase 33.2)
 *
 * VK migrated from the legacy oauth.vk.com flow to VK ID (id.vk.com) — the
 * new protocol returns an OIDC-style `id_token` JWT alongside the access
 * token. Email and basic profile claims are inside the JWT payload, so we
 * decode it directly. If the JWT for some reason lacks claims, we fall back
 * to the `/oauth2/user_info` endpoint with the access token.
 *
 * We do NOT verify the JWT signature: the token comes back over our own
 * server-to-server HTTPS POST to id.vk.com (not via the user agent), so
 * channel integrity is provided by TLS. Signature verification would only
 * add value if the token were forwarded through an untrusted hop.
 */
class OAuthVK
{
    /**
     * Decode a JWT and return its payload claims. No signature verification —
     * see class docblock.
     *
     * @throws RuntimeException on malformed JWT
     */
    public static function parseIdToken(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid JWT structure');
        }
        $payloadB64 = $parts[1];
        $pad = (4 - strlen($payloadB64) % 4) % 4;
        $padded = strtr($payloadB64, '-_', '+/') . str_repeat('=', $pad);
        $payload = base64_decode($padded, true);
        if ($payload === false) {
            throw new RuntimeException('Invalid JWT payload encoding');
        }
        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            throw new RuntimeException('Invalid JWT claims JSON');
        }
        return $claims;
    }

    /**
     * Fetch user info from id.vk.com/oauth2/user_info.
     *
     * @throws RuntimeException on transport/protocol error
     */
    public static function fetchUserInfo(string $accessToken, string $clientId): array
    {
        $body = http_build_query([
            'access_token' => $accessToken,
            'client_id' => $clientId,
        ], '', '&', PHP_QUERY_RFC3986);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $body,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents('https://id.vk.com/oauth2/user_info', false, $ctx);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('VK ID user_info request failed');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('VK ID user_info returned invalid JSON');
        }
        if (isset($data['error'])) {
            $msg = $data['error_description'] ?? $data['error'];
            throw new RuntimeException("VK ID user_info: {$msg}");
        }
        $user = $data['user'] ?? null;
        if (!is_array($user)) {
            // Some VK ID responses inline the user fields at top level.
            $user = $data;
        }
        return $user;
    }

    /**
     * Normalize claims from id_token (+ optional user_info fallback) into the
     * shape our callback expects:
     *   ['subject', 'email', 'email_verified', 'name', 'phone']
     *
     * @throws RuntimeException if neither source yields the required fields
     */
    public static function getUserInfo(?string $idToken, ?string $accessToken, string $clientId): array
    {
        $claims = [];
        if (is_string($idToken) && $idToken !== '') {
            try {
                $claims = self::parseIdToken($idToken);
            } catch (Throwable $e) {
                error_log('OAuthVK::parseIdToken failed: ' . $e->getMessage());
                $claims = [];
            }
        }

        $sub = (string)($claims['sub'] ?? '');
        $email = (string)($claims['email'] ?? '');
        $firstName = (string)($claims['given_name'] ?? $claims['first_name'] ?? '');
        $lastName = (string)($claims['family_name'] ?? $claims['last_name'] ?? '');

        // Fallback to /oauth2/user_info if id_token didn't carry what we need.
        if (($sub === '' || $email === '' || $firstName === '') && is_string($accessToken) && $accessToken !== '') {
            try {
                $extra = self::fetchUserInfo($accessToken, $clientId);
                if ($sub === '')       $sub       = (string)($extra['user_id'] ?? $extra['sub'] ?? $extra['id'] ?? '');
                if ($email === '')     $email     = (string)($extra['email'] ?? '');
                if ($firstName === '') $firstName = (string)($extra['first_name'] ?? $extra['given_name'] ?? '');
                if ($lastName === '')  $lastName  = (string)($extra['last_name']  ?? $extra['family_name'] ?? '');
            } catch (Throwable $e) {
                error_log('OAuthVK::fetchUserInfo fallback failed: ' . $e->getMessage());
            }
        }

        if ($sub === '') {
            throw new RuntimeException('VK ID: subject (user id) missing in both id_token and user_info');
        }
        if ($email === '') {
            throw new RuntimeException('VK ID: email is required but not provided. Grant email access in VK consent screen.');
        }

        $name = trim($firstName . ' ' . $lastName);
        if ($name === '') {
            $name = 'User';
        }

        return [
            'subject' => $sub,
            'email' => strtolower(trim($email)),
            // VK ID requires email verification at account creation, so we
            // treat all VK-returned emails as verified — matches the legacy
            // OAuthVK behaviour and the comment in the original implementation.
            'email_verified' => true,
            'name' => $name,
            'phone' => null,
        ];
    }
}
