<?php
/**
 * lib/OData/BasicAuth.php — Phase 37 (1С OData).
 *
 * Decode HTTP Basic Authorization header + verify via Database::verifyOdataAuth().
 *
 * Usage at top of api/v1/odata/*.php:
 *   require_once __DIR__ . '/../../../lib/OData/BasicAuth.php';
 *   \Cleanmenu\OData\BasicAuth::require($db);
 */

namespace Cleanmenu\OData;

final class BasicAuth
{
    public static function require(\Database $db): void
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        $auth = trim((string)$auth);

        if ($auth === '' || !preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $auth, $m)) {
            self::fail();
        }
        $decoded = base64_decode($m[1], true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            self::fail();
        }
        [$user, $pass] = explode(':', $decoded, 2);
        if ($user === '' || $pass === '') {
            self::fail();
        }
        if (!$db->verifyOdataAuth($user, $pass)) {
            self::fail();
        }
    }

    private static function fail(): never
    {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="CleanMenu OData"');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => [
                'code' => 'unauthorized',
                'message' => ['lang' => 'ru', 'value' => 'Требуется HTTP Basic Authentication']
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
