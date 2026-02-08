<?php

declare(strict_types=1);

final class OAuthGoogle
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * Verify Google id_token and return normalized claims needed for account linking.
     *
     * @param string[] $allowedClientIds
     * @return array{subject:string,email:string,name:?string,email_verified:bool}
     */
    public static function verifyIdToken(string $idToken, array $allowedClientIds): array
    {
        $allowedClientIds = array_values(array_filter(array_map('trim', $allowedClientIds), static fn($v) => $v !== ''));
        if ($allowedClientIds === []) {
            throw new RuntimeException('GOOGLE_OAUTH_CLIENT_IDS is not configured');
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT');
        }
        [$h64, $p64, $s64] = $parts;

        $headerJson = self::b64urlDecode($h64);
        $payloadJson = self::b64urlDecode($p64);
        $sig = self::b64urlDecode($s64);

        $header = json_decode($headerJson, true);
        $claims = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($claims)) {
            throw new RuntimeException('Invalid JWT JSON');
        }

        $alg = (string)($header['alg'] ?? '');
        $kid = (string)($header['kid'] ?? '');
        if (strtoupper($alg) !== 'RS256') {
            throw new RuntimeException('Unsupported alg');
        }
        if ($kid === '') {
            throw new RuntimeException('Missing kid');
        }

        $jwk = self::getKeyByKid($kid);
        $pem = self::rsaJwkToPem($jwk);

        $data = $h64 . '.' . $p64;
        $ok = openssl_verify($data, $sig, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('Invalid signature');
        }

        $now = time();
        $iss = (string)($claims['iss'] ?? '');
        $aud = $claims['aud'] ?? null;
        $sub = (string)($claims['sub'] ?? '');
        $exp = (int)($claims['exp'] ?? 0);
        $email = strtolower(trim((string)($claims['email'] ?? '')));
        $emailVerified = $claims['email_verified'] ?? false;
        $name = isset($claims['name']) ? trim((string)$claims['name']) : null;

        if (!in_array($iss, self::ISSUERS, true)) {
            throw new RuntimeException('Invalid issuer');
        }
        if ($sub === '') {
            throw new RuntimeException('Missing subject');
        }
        if ($exp !== 0 && $exp < ($now - 5)) {
            throw new RuntimeException('Token expired');
        }

        // aud can be string or array
        $audOk = false;
        if (is_string($aud)) {
            $audOk = in_array($aud, $allowedClientIds, true);
        } elseif (is_array($aud)) {
            foreach ($aud as $a) {
                if (is_string($a) && in_array($a, $allowedClientIds, true)) {
                    $audOk = true;
                    break;
                }
            }
        }
        if (!$audOk) {
            throw new RuntimeException('Invalid audience');
        }

        // Normalize email_verified to bool.
        if (is_string($emailVerified)) {
            $emailVerified = strtolower($emailVerified) === 'true' || $emailVerified === '1';
        } else {
            $emailVerified = (bool)$emailVerified;
        }

        if ($email === '') {
            throw new RuntimeException('Email is required');
        }

        return [
            'subject' => $sub,
            'email' => $email,
            'name' => $name !== '' ? $name : null,
            'email_verified' => (bool)$emailVerified,
        ];
    }

    private static function getCacheDir(): string
    {
        $dir = __DIR__ . '/../data/oauth';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * @return array<string,mixed>
     */
    private static function getKeyByKid(string $kid): array
    {
        $jwks = self::getCachedJwks();
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) {
            throw new RuntimeException('Invalid JWKS');
        }
        foreach ($keys as $k) {
            if (!is_array($k)) {
                continue;
            }
            if (($k['kid'] ?? null) === $kid && ($k['kty'] ?? null) === 'RSA') {
                return $k;
            }
        }
        throw new RuntimeException('Key not found');
    }

    /**
     * @return array{keys: array<int, array<string,mixed>>}
     */
    private static function getCachedJwks(): array
    {
        $dir = self::getCacheDir();
        $jsonPath = $dir . '/google-jwks.json';
        $metaPath = $dir . '/google-jwks.meta.json';

        $now = time();
        if (is_file($jsonPath) && is_file($metaPath)) {
            $meta = json_decode((string)@file_get_contents($metaPath), true);
            $expiresAt = is_array($meta) ? (int)($meta['expires_at'] ?? 0) : 0;
            if ($expiresAt > $now + 30) {
                $jwks = json_decode((string)@file_get_contents($jsonPath), true);
                if (is_array($jwks) && isset($jwks['keys']) && is_array($jwks['keys'])) {
                    return $jwks;
                }
            }
        }

        [$jwks, $expiresAt] = self::fetchJwksWithExpiry();
        @file_put_contents($jsonPath, json_encode($jwks, JSON_UNESCAPED_SLASHES));
        @file_put_contents($metaPath, json_encode(['expires_at' => $expiresAt], JSON_UNESCAPED_SLASHES));
        return $jwks;
    }

    /**
     * @return array{0:array{keys: array<int, array<string,mixed>>},1:int}
     */
    private static function fetchJwksWithExpiry(): array
    {
        $headers = [];
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents(self::JWKS_URL, false, $context);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Failed to fetch Google JWKS');
        }

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                $pos = strpos($line, ':');
                if ($pos === false) {
                    continue;
                }
                $k = strtolower(trim(substr($line, 0, $pos)));
                $v = trim(substr($line, $pos + 1));
                $headers[$k] = $v;
            }
        }

        $jwks = json_decode($raw, true);
        if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
            throw new RuntimeException('Invalid Google JWKS JSON');
        }

        // Default: cache 6h; prefer Cache-Control max-age when present.
        $maxAge = 6 * 3600;
        $cc = (string)($headers['cache-control'] ?? '');
        if ($cc !== '' && preg_match('/max-age=(\d+)/i', $cc, $m)) {
            $maxAge = (int)$m[1];
        }
        $expiresAt = time() + max(300, $maxAge);

        /** @var array{keys: array<int, array<string,mixed>>} $jwks */
        return [$jwks, $expiresAt];
    }

    private static function b64urlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($data, true);
        if ($out === false) {
            throw new RuntimeException('Invalid base64url');
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $jwk
     */
    private static function rsaJwkToPem(array $jwk): string
    {
        $n = (string)($jwk['n'] ?? '');
        $e = (string)($jwk['e'] ?? '');
        if ($n === '' || $e === '') {
            throw new RuntimeException('Invalid RSA JWK');
        }

        $modulus = self::b64urlDecode($n);
        $exponent = self::b64urlDecode($e);

        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
        $rsaPublicKey = self::asn1Sequence(
            self::asn1Integer($modulus) .
            self::asn1Integer($exponent)
        );

        // SubjectPublicKeyInfo ::= SEQUENCE {
        //   algorithm AlgorithmIdentifier (rsaEncryption),
        //   subjectPublicKey BIT STRING (RSAPublicKey)
        // }
        $algoId = self::asn1Sequence(
            self::asn1Oid("\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01") . // 1.2.840.113549.1.1.1
            self::asn1Null()
        );
        $spki = self::asn1Sequence(
            $algoId .
            self::asn1BitString($rsaPublicKey)
        );

        $pem = "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($spki), 64, "\n") .
            "-----END PUBLIC KEY-----\n";
        return $pem;
    }

    private static function asn1Len(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $out = '';
        while ($len > 0) {
            $out = chr($len & 0xFF) . $out;
            $len >>= 8;
        }
        return chr(0x80 | strlen($out)) . $out;
    }

    private static function asn1Integer(string $bytes): string
    {
        // Ensure positive integer (prepend 0x00 if MSB is set).
        if ($bytes !== '' && (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::asn1Len(strlen($bytes)) . $bytes;
    }

    private static function asn1Sequence(string $inner): string
    {
        return "\x30" . self::asn1Len(strlen($inner)) . $inner;
    }

    private static function asn1Null(): string
    {
        return "\x05\x00";
    }

    private static function asn1BitString(string $bytes): string
    {
        // 0 unused bits
        $inner = "\x00" . $bytes;
        return "\x03" . self::asn1Len(strlen($inner)) . $inner;
    }

    private static function asn1Oid(string $encodedOidBytes): string
    {
        return "\x06" . self::asn1Len(strlen($encodedOidBytes)) . $encodedOidBytes;
    }
}

