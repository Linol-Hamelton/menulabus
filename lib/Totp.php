<?php

/**
 * Totp — RFC 6238 time-based one-time password helper (Phase 9.3).
 *
 * Pure-PHP HMAC-SHA1 implementation so we don't pull a Composer dependency.
 * Compatible with Google Authenticator / Authy / 1Password / Bitwarden.
 *
 * Secrets are stored base32-encoded in `user_2fa.secret`. Recovery codes are
 * stored hashed (password_hash) in `user_2fa.backup_codes_json` — raw codes
 * are shown to the user only on generation.
 */
final class Totp
{
    private const DIGITS    = 6;
    private const PERIOD_S  = 30;
    private const WINDOW    = 1; // accept previous + current + next 30s slot
    private const BACKUPS   = 10;
    private const ALPHABET  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generate a random 20-byte secret, base32-encoded. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function provisioningUri(string $secret, string $accountLabel, string $issuer): string
    {
        $issuer = rawurlencode($issuer);
        $label  = rawurlencode($issuer . ':' . $accountLabel);
        $qs = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD_S,
            'algorithm' => 'SHA1',
        ]);
        return 'otpauth://totp/' . $label . '?' . $qs;
    }

    public static function verify(string $secret, string $code, ?int $atTime = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $time = $atTime ?? time();
        $key = self::base32Decode($secret);
        if ($key === null) return false;
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $counter = intdiv($time, self::PERIOD_S) + $offset;
            if (hash_equals(self::generateCode($key, $counter), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function generateCode(string $key, int $counter): string
    {
        $binCounter = pack('J', $counter); // 64-bit big-endian
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0xf;
        $truncated = ((ord($hash[$offset])   & 0x7f) << 24)
                   | ((ord($hash[$offset+1]) & 0xff) << 16)
                   | ((ord($hash[$offset+2]) & 0xff) << 8)
                   |  (ord($hash[$offset+3]) & 0xff);
        $otp = $truncated % (10 ** self::DIGITS);
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Generate N human-friendly backup codes. Returns {raw, hashed} — caller
     * shows raw once and persists hashed.
     */
    public static function generateBackupCodes(int $n = self::BACKUPS): array
    {
        $raw = [];
        $hashed = [];
        for ($i = 0; $i < $n; $i++) {
            $code = self::randomBackupCode();
            $raw[] = $code;
            $hashed[] = password_hash($code, PASSWORD_DEFAULT);
        }
        return ['raw' => $raw, 'hashed' => $hashed];
    }

    /**
     * Verify a submitted backup code against the stored hashed set. Returns
     * the updated hashed array (with the used hash removed), or null if the
     * code didn't match anything. Caller must persist the returned array.
     */
    public static function consumeBackupCode(array $hashed, string $submitted): ?array
    {
        $submitted = preg_replace('/[^A-Z0-9-]/', '', strtoupper($submitted));
        if ($submitted === '') return null;
        foreach ($hashed as $i => $h) {
            if (is_string($h) && password_verify($submitted, $h)) {
                array_splice($hashed, $i, 1);
                return $hashed;
            }
        }
        return null;
    }

    private static function randomBackupCode(): string
    {
        // Format: XXXX-XXXX-XXXX (base32 letters, no confusing chars).
        $raw = '';
        for ($i = 0; $i < 12; $i++) {
            $raw .= self::ALPHABET[random_int(0, 31)];
        }
        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
    }

    public static function base32Encode(string $data): string
    {
        if ($data === '') return '';
        $bytes = array_values(unpack('C*', $data));
        $bits = '';
        foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        $pad = (5 - strlen($bits) % 5) % 5;
        $bits .= str_repeat('0', $pad);
        $out = '';
        for ($i = 0; $i < strlen($bits); $i += 5) {
            $out .= self::ALPHABET[bindec(substr($bits, $i, 5))];
        }
        return $out; // No "=" padding for TOTP secrets — GAuth ignores it anyway.
    }

    public static function base32Decode(string $s): ?string
    {
        $s = strtoupper(preg_replace('/\s+/', '', $s));
        $s = rtrim($s, '=');
        if ($s === '') return null;
        $bits = '';
        for ($i = 0; $i < strlen($s); $i++) {
            $pos = strpos(self::ALPHABET, $s[$i]);
            if ($pos === false) return null;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $chunks = str_split($bits, 8);
        $bytes = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
        }
        return $bytes;
    }
}
