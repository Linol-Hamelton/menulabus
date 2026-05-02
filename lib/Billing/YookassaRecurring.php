<?php
/**
 * YookassaRecurring — SaaS subscription payment helper (Phase 14.3, 2026-05-03).
 *
 * Two-step recurring flow:
 *   1. createInitialPayment() — first charge or "card change". Sends
 *      save_payment_method=true + recurrent=true. Returns confirmation_url
 *      to redirect the customer to YK. After success the YK webhook
 *      delivers the saved payment_method_id (stored in payment_methods).
 *   2. chargeStored() — subsequent monthly charges. Sends
 *      payment_method_id from the stored token, capture=true, no
 *      customer interaction needed.
 *
 * YK credentials (shop_id + secret_key) live in the control-plane settings
 * — they belong to labus.pro itself, not to a tenant. To keep the moving
 * parts small we read them from constants `BILLING_YK_SHOP_ID` /
 * `BILLING_YK_SECRET_KEY` (defined in config_copy.php on prod). Falls back
 * to per-tenant `yookassa_shop_id`/`yookassa_secret_key` if set on the
 * provider tenant (i.e. menu.labus.pro itself).
 *
 * All amounts are kopecks. YK API uses RUB strings with 2 decimal places.
 *
 * Idempotency-Key: opaque per-attempt token. Reuse for retries of the
 * same logical charge so YK returns the original payment instead of
 * creating a duplicate.
 */

namespace Cleanmenu\Billing;

use RuntimeException;

final class YookassaRecurring
{
    private const ENDPOINT = 'https://api.yookassa.ru/v3/payments';

    /** @var ?callable HTTP client override for tests */
    private static $httpClient = null;

    /**
     * Inject a fake HTTP client for unit tests.
     * Signature: fn(string $method, string $url, ?array $body, array $headers, int $timeout): array{code:int,body:string}
     */
    public static function setHttpClient(callable $fn): void
    {
        self::$httpClient = $fn;
    }

    /**
     * First charge for a tenant — gets the customer to enter card details
     * and saves the payment_method_id for future autocharges.
     *
     * @param int    $tenantId        control-plane tenants.id
     * @param int    $amountKop       amount in kopecks
     * @param string $description     visible to customer in YK
     * @param string $returnUrl       where YK sends the customer back
     * @param string $idempotencyKey  unique-per-attempt opaque key
     * @return array{id:string, confirmation_url:string, status:string}
     */
    public static function createInitialPayment(
        int $tenantId,
        int $amountKop,
        string $description,
        string $returnUrl,
        string $idempotencyKey
    ): array {
        if ($amountKop <= 0) {
            throw new RuntimeException('YookassaRecurring: amount must be positive');
        }
        [$shopId, $secretKey] = self::credentials();

        $payload = [
            'amount' => [
                'value'    => self::formatKopAsRub($amountKop),
                'currency' => 'RUB',
            ],
            'capture' => true,
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $returnUrl,
            ],
            'save_payment_method' => true,
            'description' => $description,
            'metadata' => [
                'kind'      => 'subscription_invoice',
                'tenant_id' => (string)$tenantId,
                'phase'     => 'initial',
            ],
        ];
        return self::createPayment($payload, $idempotencyKey, $shopId, $secretKey);
    }

    /**
     * Subsequent autocharge using a previously-saved payment_method_id.
     * No customer interaction. Returns immediately with status='succeeded'
     * if the card was charged or 'canceled' on decline; YK webhook also
     * fires asynchronously.
     *
     * @param int    $tenantId
     * @param int    $invoiceId        control-plane subscription_invoices.id
     * @param string $paymentMethodId  YK token from payment_methods.yk_payment_method_id
     * @param int    $amountKop
     * @param string $description
     * @param string $idempotencyKey
     * @return array{id:string, status:string}
     */
    public static function chargeStored(
        int $tenantId,
        int $invoiceId,
        string $paymentMethodId,
        int $amountKop,
        string $description,
        string $idempotencyKey
    ): array {
        [$shopId, $secretKey] = self::credentials();

        $payload = [
            'amount' => [
                'value'    => self::formatKopAsRub($amountKop),
                'currency' => 'RUB',
            ],
            'capture'           => true,
            'payment_method_id' => $paymentMethodId,
            'description'       => $description,
            'metadata' => [
                'kind'       => 'subscription_invoice',
                'tenant_id'  => (string)$tenantId,
                'invoice_id' => (string)$invoiceId,
                'phase'      => 'recurring',
            ],
        ];
        return self::createPayment($payload, $idempotencyKey, $shopId, $secretKey);
    }

    /**
     * Verify a webhook payload by re-fetching the payment from YK
     * (matches the existing payment-webhook.php pattern — never trust
     * the body alone).
     *
     * @return array{id:string, status:string, payment_method:?array, metadata:array}|null
     */
    public static function fetchPayment(string $paymentId): ?array
    {
        [$shopId, $secretKey] = self::credentials();
        $resp = self::http('GET', self::ENDPOINT . '/' . rawurlencode($paymentId), null, [
            'Authorization: Basic ' . base64_encode("{$shopId}:{$secretKey}"),
            'Content-Type: application/json',
        ], 10);
        if ($resp['code'] !== 200) {
            return null;
        }
        $json = json_decode($resp['body'], true);
        return is_array($json) ? $json : null;
    }

    /** @return array{id:string, confirmation_url:string, status:string} */
    private static function createPayment(array $payload, string $idemKey, string $shopId, string $secretKey): array
    {
        $resp = self::http(
            'POST',
            self::ENDPOINT,
            $payload,
            [
                'Authorization: Basic ' . base64_encode("{$shopId}:{$secretKey}"),
                'Idempotence-Key: ' . $idemKey,
                'Content-Type: application/json',
            ],
            15
        );
        if ($resp['code'] !== 200) {
            throw new RuntimeException("YookassaRecurring: payment creation failed (HTTP {$resp['code']}): {$resp['body']}");
        }
        $json = json_decode($resp['body'], true);
        if (!is_array($json) || empty($json['id'])) {
            throw new RuntimeException("YookassaRecurring: malformed response: {$resp['body']}");
        }
        return [
            'id'               => (string)$json['id'],
            'confirmation_url' => (string)($json['confirmation']['confirmation_url'] ?? ''),
            'status'           => (string)($json['status'] ?? 'pending'),
        ];
    }

    /** @return array{0:string,1:string} [shopId, secretKey] */
    private static function credentials(): array
    {
        if (defined('BILLING_YK_SHOP_ID') && defined('BILLING_YK_SECRET_KEY')) {
            return [(string)BILLING_YK_SHOP_ID, (string)BILLING_YK_SECRET_KEY];
        }
        // Fallback: read from provider-tenant settings (menu.labus.pro itself).
        try {
            require_once __DIR__ . '/../../db.php';
            $db = \Database::getInstance();
            $shopId    = (string)json_decode($db->getSetting('yookassa_shop_id') ?? '""', true);
            $secretKey = (string)json_decode($db->getSetting('yookassa_secret_key') ?? '""', true);
            if ($shopId === '' || $secretKey === '') {
                throw new RuntimeException('YooKassa credentials not configured (define BILLING_YK_SHOP_ID / BILLING_YK_SECRET_KEY in config or set yookassa_shop_id / yookassa_secret_key in provider settings)');
            }
            return [$shopId, $secretKey];
        } catch (\Throwable $e) {
            throw new RuntimeException('YookassaRecurring: ' . $e->getMessage());
        }
    }

    private static function formatKopAsRub(int $kop): string
    {
        return number_format($kop / 100, 2, '.', '');
    }

    /** @return array{code:int,body:string} */
    private static function http(string $method, string $url, ?array $body, array $headers, int $timeoutSec): array
    {
        if (self::$httpClient !== null) {
            return (self::$httpClient)($method, $url, $body, $headers, $timeoutSec);
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $bodyResp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($bodyResp === false) {
            throw new RuntimeException("YookassaRecurring curl error on {$method} {$url}: {$err}");
        }
        return ['code' => $code, 'body' => (string)$bodyResp];
    }
}
