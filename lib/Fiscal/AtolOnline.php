<?php
/**
 * АТОЛ Онлайн fiscalisation adapter (Phase 7.2, 2026-04-27).
 *
 * Implements the minimal happy-path against АТОЛ Онлайн v4 API:
 *   1. POST /v4/{group_code}/getToken     — обмен login/password на JWT
 *   2. POST /v4/{group_code}/sell         — пробить чек прихода
 *   3. GET  /v4/{group_code}/report/{uuid} — опросить статус (опционально)
 *
 * Credentials live in the tenant settings table:
 *   fiscal_provider          = 'atol' | ''  (empty disables fiscalisation)
 *   fiscal_atol_login        = string (АТОЛ login)
 *   fiscal_atol_password     = string
 *   fiscal_atol_group_code   = string (касса в личном кабинете АТОЛ)
 *   fiscal_atol_inn          = string (ИНН организации, 10/12 цифр)
 *   fiscal_atol_payment_address = string (URL or address of the till)
 *   fiscal_atol_sno          = 'osn' | 'usn_income' | 'usn_income_outcome' | 'envd' | 'esn' | 'patent'
 *   fiscal_atol_sandbox      = '1' | '0'  (testonline.atol.ru vs online.atol.ru)
 *
 * The adapter does NOT touch the DB on its own — callers (typically
 * lib/OrderPaidHook.php) are responsible for persisting the returned
 * uuid + URL on the order row. This keeps the adapter testable in
 * isolation without a database fixture.
 *
 * Sandbox endpoint: https://testonline.atol.ru/possystem/v4/
 * Prod endpoint:    https://online.atol.ru/possystem/v4/
 */

namespace Cleanmenu\Fiscal;

use RuntimeException;

final class AtolOnline
{
    private const ENDPOINT_SANDBOX = 'https://testonline.atol.ru/possystem/v4';
    private const ENDPOINT_PROD    = 'https://online.atol.ru/possystem/v4';

    private string $login;
    private string $password;
    private string $groupCode;
    private string $inn;
    private string $paymentAddress;
    private string $sno;
    private bool   $sandbox;

    /** @var ?callable(string,array,?string,?array,int):array */
    private $httpClient = null;

    private ?string $cachedToken = null;
    private int $cachedTokenExpires = 0;

    /**
     * @param array{
     *   login: string,
     *   password: string,
     *   group_code: string,
     *   inn: string,
     *   payment_address: string,
     *   sno?: string,
     *   sandbox?: bool,
     * } $config
     */
    public function __construct(array $config)
    {
        foreach (['login', 'password', 'group_code', 'inn', 'payment_address'] as $req) {
            if (empty($config[$req])) {
                throw new RuntimeException("AtolOnline: missing required config key '{$req}'");
            }
        }
        $this->login          = (string)$config['login'];
        $this->password       = (string)$config['password'];
        $this->groupCode      = (string)$config['group_code'];
        $this->inn            = (string)$config['inn'];
        $this->paymentAddress = (string)$config['payment_address'];
        $this->sno            = (string)($config['sno'] ?? 'usn_income');
        $this->sandbox        = (bool)($config['sandbox'] ?? false);
    }

    /** Inject a custom HTTP client for unit tests. Signature: (method, url, body?, headers?, timeoutSec) -> ['code'=>int,'body'=>string]. */
    public function setHttpClient(callable $fn): void
    {
        $this->httpClient = $fn;
    }

    /**
     * Send a "приход" (sale) receipt.
     *
     * @param array $order  Internal order row. Required keys:
     *   - id          int
     *   - total_price float
     *   - items       array<array{name:string,price:float,quantity:int}>
     *   - payment_method string ('card'|'cash'|'sbp'|...)
     * @param string $customerEmail customer email or empty string
     * @param string $idempotencyKey unique per (provider, request); reuse on retry
     *
     * @return array{uuid:string,status:string} where status is one of
     *   'wait', 'done', 'fail'. Caller should persist uuid; URL is fetched
     *   via fetchReceiptUrl() once status becomes 'done'.
     */
    public function emitSaleReceipt(array $order, string $customerEmail, string $idempotencyKey): array
    {
        $token = $this->ensureToken();

        $items = [];
        foreach (($order['items'] ?? []) as $i) {
            $name = (string)($i['name'] ?? '');
            $price = round((float)($i['price'] ?? 0), 2);
            $qty   = max(1, (int)($i['quantity'] ?? 1));
            if ($name === '' || $price <= 0) continue;
            $items[] = [
                'name'           => mb_substr($name, 0, 128),
                'price'          => $price,
                'quantity'       => $qty,
                'sum'            => round($price * $qty, 2),
                'payment_method' => 'full_payment',
                'payment_object' => 'commodity',
                'vat'            => ['type' => 'none'],
            ];
        }

        if (empty($items)) {
            throw new RuntimeException('AtolOnline: cannot fiscalize empty order');
        }

        $total = round((float)($order['total_price'] ?? 0), 2);
        if ($total <= 0) {
            throw new RuntimeException('AtolOnline: order total must be > 0');
        }

        $paymentType = match ((string)($order['payment_method'] ?? '')) {
            'cash' => 0,             // тип 0 = наличные
            default => 1,            // тип 1 = безналичные (включая СБП, карта)
        };

        $receipt = [
            'external_id' => 'order-' . (int)($order['id'] ?? 0) . '-' . substr($idempotencyKey, 0, 16),
            'receipt' => [
                'client'   => array_filter([
                    'email' => $customerEmail !== '' ? $customerEmail : null,
                ], static fn ($v) => $v !== null),
                'company'  => [
                    'inn'              => $this->inn,
                    'sno'              => $this->sno,
                    'payment_address'  => $this->paymentAddress,
                ],
                'items'    => $items,
                'payments' => [[
                    'type' => $paymentType,
                    'sum'  => $total,
                ]],
                'total'    => $total,
            ],
            'timestamp' => date('d.m.Y H:i:s'),
        ];

        $url = $this->endpoint() . '/' . rawurlencode($this->groupCode) . '/sell';
        $resp = $this->http(
            'POST',
            $url,
            $receipt,
            ['Token: ' . $token, 'Content-Type: application/json; charset=utf-8'],
            15
        );

        if ($resp['code'] !== 200) {
            throw new RuntimeException("AtolOnline sell failed (HTTP {$resp['code']}): {$resp['body']}");
        }
        $json = json_decode($resp['body'], true);
        if (!is_array($json) || empty($json['uuid'])) {
            throw new RuntimeException("AtolOnline sell returned malformed body: {$resp['body']}");
        }
        return [
            'uuid'   => (string)$json['uuid'],
            'status' => (string)($json['status'] ?? 'wait'),
        ];
    }

    /**
     * Poll receipt status. Returns ['status' => string, 'url' => ?string].
     * Status meanings:
     *   wait — provider has accepted and is processing
     *   done — fiscalised; url contains the OFD link
     *   fail — provider rejected; payload['error'] holds the reason
     */
    public function fetchReceiptUrl(string $uuid): array
    {
        $token = $this->ensureToken();
        $url = $this->endpoint() . '/' . rawurlencode($this->groupCode) . '/report/' . rawurlencode($uuid);
        $resp = $this->http('GET', $url, null, ['Token: ' . $token], 10);
        if ($resp['code'] !== 200) {
            return ['status' => 'wait', 'url' => null];
        }
        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            return ['status' => 'wait', 'url' => null];
        }
        return [
            'status' => (string)($json['status'] ?? 'wait'),
            'url'    => isset($json['payload']['ofd_receipt_url'])
                ? (string)$json['payload']['ofd_receipt_url']
                : null,
        ];
    }

    private function ensureToken(): string
    {
        if ($this->cachedToken !== null && $this->cachedTokenExpires > time() + 60) {
            return $this->cachedToken;
        }
        $url = $this->endpoint() . '/getToken';
        $resp = $this->http('POST', $url, [
            'login' => $this->login,
            'pass'  => $this->password,
        ], ['Content-Type: application/json'], 10);
        if ($resp['code'] !== 200) {
            throw new RuntimeException("AtolOnline getToken failed (HTTP {$resp['code']}): {$resp['body']}");
        }
        $json = json_decode($resp['body'], true);
        if (!is_array($json) || empty($json['token'])) {
            throw new RuntimeException("AtolOnline getToken returned malformed body: {$resp['body']}");
        }
        $this->cachedToken = (string)$json['token'];
        // АТОЛ tokens are valid 24h; refresh after 23h to be safe.
        $this->cachedTokenExpires = time() + 23 * 3600;
        return $this->cachedToken;
    }

    private function endpoint(): string
    {
        return $this->sandbox ? self::ENDPOINT_SANDBOX : self::ENDPOINT_PROD;
    }

    /** @return array{code:int,body:string} */
    private function http(string $method, string $url, ?array $body, array $headers, int $timeoutSec): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($method, $url, $body, $headers, $timeoutSec);
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
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
        $bodyResp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($bodyResp === false) {
            throw new RuntimeException("AtolOnline curl error on {$method} {$url}: {$err}");
        }
        return ['code' => $code, 'body' => (string)$bodyResp];
    }
}
