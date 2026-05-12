<?php
/**
 * lib/Aggregator/YandexEda.php — Phase 36 (Яндекс.Еда adapter).
 *
 * Real Yandex.Еда partner-API requires partner-channel access; this MVP
 * implements an HMAC-SHA256 contract sufficient for end-to-end testing
 * via curl and proxy-based partner integrations (signed payloads).
 *
 * Production webhook format (per Yandex.Еда v2 spec, simplified):
 *   POST /api/aggregator/webhook.php?provider=yandex_eda
 *   X-YandexEda-Signature: hex(hmac_sha256(payload, secret))
 *   Body: {
 *     "order_id": "string",
 *     "total": 1234.50,
 *     "items": [{"product_id": "string", "name": "string",
 *                "quantity": 1, "price": 100.00}, ...],
 *     "customer": {"name": "string", "phone": "string"},
 *     "delivery_address": "string"
 *   }
 */

namespace Cleanmenu\Aggregator;

require_once __DIR__ . '/AggregatorAdapter.php';

final class YandexEda implements AggregatorAdapter
{
    public static function providerKey(): string
    {
        return 'yandex_eda';
    }

    public static function verifySignature(string $payload, string $signatureHeader, string $secret): bool
    {
        $signatureHeader = trim($signatureHeader);
        if ($signatureHeader === '' || $secret === '') return false;
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    public static function normalize(array $raw, object $db): array
    {
        $items = [];
        foreach (($raw['items'] ?? []) as $i) {
            $productId = (string)($i['product_id'] ?? '');
            $name      = (string)($i['name'] ?? '');
            $quantity  = max(1, (int)($i['quantity'] ?? 1));
            $price     = (float)($i['price'] ?? 0);

            $localId = null;
            if ($productId !== '') {
                $local = $db->findMenuItemByAggregatorId(self::providerKey(), $productId);
                if ($local) {
                    $localId = (int)$local['id'];
                    if ($name === '') $name = (string)$local['name'];
                    if ($price <= 0)  $price = (float)$local['price'];
                }
            }
            if ($name === '') $name = 'Неопознанное блюдо';
            $items[] = [
                'id'       => $localId,
                'name'     => $name,
                'quantity' => $quantity,
                'price'    => $price,
            ];
        }

        $customer = $raw['customer'] ?? [];
        return [
            'external_id'      => (string)($raw['order_id'] ?? ''),
            'total'            => (float)($raw['total'] ?? 0),
            'items'            => $items,
            'customer_name'    => (string)($customer['name']  ?? '') ?: null,
            'customer_phone'   => (string)($customer['phone'] ?? '') ?: null,
            'delivery_address' => (string)($raw['delivery_address'] ?? '') ?: null,
        ];
    }

    public static function mapStatusOutbound(string $internalStatus): ?string
    {
        // Our internal statuses (см. lib/orders/lifecycle.php) → Yandex.Еда's expected codes.
        return match (strtolower($internalStatus)) {
            'приём', 'pending'         => 'NEW',
            'готовим', 'cooking'       => 'COOKING',
            'доставляем', 'delivering' => 'IN_DELIVERY',
            'завершён', 'completed'    => 'DELIVERED',
            'отказ', 'cancelled'       => 'CANCELLED',
            default                    => null,
        };
    }

    public static function statusPushUrl(string $externalOrderId): string
    {
        // Production: PATCH https://partner-api.yandex.eda.ru/orders/{id}/status
        // (адрес может меняться — поставщик предоставит при выдаче API key).
        return 'https://partner-api.yandex.eda.ru/v1/orders/' . rawurlencode($externalOrderId) . '/status';
    }
}
