<?php
/**
 * lib/Aggregator/DeliveryClub.php — Phase 36 (Delivery Club adapter).
 *
 * Same HMAC-SHA256 contract as YandexEda. DC's actual partner API uses
 * a similar JSON schema with slightly different field names.
 *
 *   POST /api/aggregator/webhook.php?provider=delivery_club
 *   X-DC-Signature: hex(hmac_sha256(payload, secret))
 *   Body: {
 *     "id": "string",
 *     "total_amount": 1234.50,
 *     "items": [{"sku": "string", "title": "string",
 *                "qty": 1, "amount": 100.00}, ...],
 *     "client": {"first_name": "string", "phone": "string"},
 *     "address": "string"
 *   }
 */

namespace Cleanmenu\Aggregator;

require_once __DIR__ . '/AggregatorAdapter.php';

final class DeliveryClub implements AggregatorAdapter
{
    public static function providerKey(): string
    {
        return 'delivery_club';
    }

    public static function verifySignature(string $payload, string $signatureHeader, string $secret): bool
    {
        $signatureHeader = trim($signatureHeader);
        if ($signatureHeader === '' || $secret === '') return false;
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    public static function normalize(array $raw, \Database $db): array
    {
        $items = [];
        foreach (($raw['items'] ?? []) as $i) {
            $sku       = (string)($i['sku'] ?? '');
            $name      = (string)($i['title'] ?? '');
            $quantity  = max(1, (int)($i['qty'] ?? 1));
            $price     = (float)($i['amount'] ?? 0);

            $localId = null;
            if ($sku !== '') {
                $local = $db->findMenuItemByAggregatorId(self::providerKey(), $sku);
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

        $client = $raw['client'] ?? [];
        return [
            'external_id'      => (string)($raw['id'] ?? ''),
            'total'            => (float)($raw['total_amount'] ?? 0),
            'items'            => $items,
            'customer_name'    => (string)($client['first_name'] ?? '') ?: null,
            'customer_phone'   => (string)($client['phone'] ?? '') ?: null,
            'delivery_address' => (string)($raw['address'] ?? '') ?: null,
        ];
    }

    public static function mapStatusOutbound(string $internalStatus): ?string
    {
        return match (strtolower($internalStatus)) {
            'приём', 'pending'         => 'accepted',
            'готовим', 'cooking'       => 'preparing',
            'доставляем', 'delivering' => 'shipping',
            'завершён', 'completed'    => 'delivered',
            'отказ', 'cancelled'       => 'cancelled',
            default                    => null,
        };
    }

    public static function statusPushUrl(string $externalOrderId): string
    {
        return 'https://partner.delivery-club.ru/api/v2/orders/' . rawurlencode($externalOrderId) . '/status';
    }
}
