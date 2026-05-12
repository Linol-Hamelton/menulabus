<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Aggregator\DeliveryClub;
use Cleanmenu\Aggregator\YandexEda;
use PHPUnit\Framework\TestCase;

/**
 * Phase 36 — pure-PHP unit tests for aggregator adapters. No DB required
 * for the signature/normalize tests below — they exercise HMAC and payload
 * mapping in isolation.
 *
 * Invariants locked here:
 *   - YandexEda::verifySignature accepts the exact HMAC-SHA256 hex of body+secret.
 *   - Wrong signature, empty signature, empty secret → all reject.
 *   - hash_equals timing-safe comparison (regression guard against == drift).
 *   - normalize() maps provider-specific fields to common shape.
 *   - mapStatusOutbound translates our internal codes correctly.
 */
final class AggregatorAdapterTest extends TestCase
{
    public function setUp(): void
    {
        require_once dirname(__DIR__) . '/lib/Aggregator/YandexEda.php';
        require_once dirname(__DIR__) . '/lib/Aggregator/DeliveryClub.php';
    }

    public function testYandexProviderKey(): void
    {
        $this->assertSame('yandex_eda', YandexEda::providerKey());
    }

    public function testDeliveryClubProviderKey(): void
    {
        $this->assertSame('delivery_club', DeliveryClub::providerKey());
    }

    public function testYandexSignatureValid(): void
    {
        $secret = 'test_secret_123';
        $payload = '{"order_id":"OY-1","total":500,"items":[]}';
        $sig = hash_hmac('sha256', $payload, $secret);
        $this->assertTrue(YandexEda::verifySignature($payload, $sig, $secret));
    }

    public function testYandexSignatureRejectsTampered(): void
    {
        $secret = 'test_secret_123';
        $payload = '{"order_id":"OY-1","total":500}';
        $sig = hash_hmac('sha256', $payload, $secret);
        // Flip one byte at the end.
        $tampered = substr($sig, 0, -1) . (chr(ord($sig[-1]) ^ 1));
        $this->assertFalse(YandexEda::verifySignature($payload, $tampered, $secret));
    }

    public function testYandexSignatureRejectsEmptyInputs(): void
    {
        $this->assertFalse(YandexEda::verifySignature('payload', '', 'secret'));
        $this->assertFalse(YandexEda::verifySignature('payload', 'somehex', ''));
        $this->assertFalse(YandexEda::verifySignature('payload', '', ''));
    }

    public function testYandexSignatureRejectsLengthAttack(): void
    {
        // Truncated signature should not pass — hash_equals checks length.
        $secret = 'test_secret_123';
        $payload = '{"order_id":"OY-1"}';
        $sig = hash_hmac('sha256', $payload, $secret);
        $truncated = substr($sig, 0, 32);
        $this->assertFalse(YandexEda::verifySignature($payload, $truncated, $secret));
    }

    public function testDeliveryClubSignatureRoundTrip(): void
    {
        $secret = 'dc_secret_456';
        $payload = '{"id":"DC-1","total_amount":1500}';
        $sig = hash_hmac('sha256', $payload, $secret);
        $this->assertTrue(DeliveryClub::verifySignature($payload, $sig, $secret));

        $sig2 = hash_hmac('sha256', $payload, 'wrong_secret');
        $this->assertFalse(DeliveryClub::verifySignature($payload, $sig2, $secret));
    }

    public function testYandexNormalizeMapsExpectedFields(): void
    {
        $stubDb = new class {
            public function findMenuItemByAggregatorId(string $provider, string $extId): ?array
            {
                return null;
            }
        };
        $raw = [
            'order_id' => 'OY-42',
            'total'    => 1250.50,
            'items'    => [
                ['product_id' => 'p1', 'name' => 'Маргарита', 'quantity' => 2, 'price' => 625.25],
            ],
            'customer'         => ['name' => 'Иван', 'phone' => '+79000000000'],
            'delivery_address' => 'Москва, Тверская 1',
        ];
        $out = YandexEda::normalize($raw, $stubDb);
        $this->assertSame('OY-42', $out['external_id']);
        $this->assertSame(1250.50, $out['total']);
        $this->assertCount(1, $out['items']);
        $this->assertSame('Маргарита', $out['items'][0]['name']);
        $this->assertSame(2, $out['items'][0]['quantity']);
        $this->assertSame(625.25, $out['items'][0]['price']);
        $this->assertNull($out['items'][0]['id'], 'unmapped product → id NULL');
        $this->assertSame('Иван', $out['customer_name']);
        $this->assertSame('+79000000000', $out['customer_phone']);
        $this->assertSame('Москва, Тверская 1', $out['delivery_address']);
    }

    public function testYandexNormalizeFallsBackToUnknownDishName(): void
    {
        $stubDb = new class {
            public function findMenuItemByAggregatorId(string $provider, string $extId): ?array
            {
                return null;
            }
        };
        $raw = [
            'order_id' => 'OY-EMPTY-NAME',
            'items'    => [['product_id' => 'p1', 'quantity' => 1, 'price' => 100]],
        ];
        $out = YandexEda::normalize($raw, $stubDb);
        $this->assertSame('Неопознанное блюдо', $out['items'][0]['name']);
    }

    public function testYandexNormalizeBackfillsFromLocalMenuItem(): void
    {
        $stubDb = new class {
            public function findMenuItemByAggregatorId(string $provider, string $extId): ?array
            {
                return ['id' => 7, 'name' => 'Локальная пицца', 'price' => 800.00];
            }
        };
        $raw = [
            'order_id' => 'OY-MAP-1',
            'items'    => [['product_id' => 'yandex_pizza_1', 'quantity' => 1]],
        ];
        $out = YandexEda::normalize($raw, $stubDb);
        $this->assertSame(7, $out['items'][0]['id']);
        $this->assertSame('Локальная пицца', $out['items'][0]['name']);
        $this->assertSame(800.00, $out['items'][0]['price']);
    }

    public function testDeliveryClubNormalizeMapsExpectedFields(): void
    {
        $stubDb = new class {
            public function findMenuItemByAggregatorId(string $provider, string $extId): ?array
            {
                return null;
            }
        };
        $raw = [
            'id'           => 'DC-99',
            'total_amount' => 750.00,
            'items'        => [['sku' => 's1', 'title' => 'Ролл', 'qty' => 1, 'amount' => 750]],
            'client'       => ['first_name' => 'Анна', 'phone' => '+79111111111'],
            'address'      => 'СПб, Невский',
        ];
        $out = DeliveryClub::normalize($raw, $stubDb);
        $this->assertSame('DC-99', $out['external_id']);
        $this->assertSame(750.00, $out['total']);
        $this->assertSame('Ролл', $out['items'][0]['name']);
        $this->assertSame('Анна', $out['customer_name']);
        $this->assertSame('СПб, Невский', $out['delivery_address']);
    }

    public function testStatusMappingYandex(): void
    {
        $this->assertSame('NEW',         YandexEda::mapStatusOutbound('Приём'));
        $this->assertSame('COOKING',     YandexEda::mapStatusOutbound('готовим'));
        $this->assertSame('IN_DELIVERY', YandexEda::mapStatusOutbound('доставляем'));
        $this->assertSame('DELIVERED',   YandexEda::mapStatusOutbound('завершён'));
        $this->assertSame('CANCELLED',   YandexEda::mapStatusOutbound('отказ'));
        $this->assertNull(YandexEda::mapStatusOutbound('unknown_status'));
    }

    public function testStatusMappingDeliveryClub(): void
    {
        $this->assertSame('accepted',  DeliveryClub::mapStatusOutbound('Приём'));
        $this->assertSame('preparing', DeliveryClub::mapStatusOutbound('готовим'));
        $this->assertSame('shipping',  DeliveryClub::mapStatusOutbound('доставляем'));
        $this->assertSame('delivered', DeliveryClub::mapStatusOutbound('завершён'));
        $this->assertSame('cancelled', DeliveryClub::mapStatusOutbound('отказ'));
        $this->assertNull(DeliveryClub::mapStatusOutbound('unknown_status'));
    }

    public function testStatusPushUrlsAreHttpsAndContainOrderId(): void
    {
        $yUrl = YandexEda::statusPushUrl('OY-42');
        $dcUrl = DeliveryClub::statusPushUrl('DC-99');
        $this->assertStringStartsWith('https://', $yUrl);
        $this->assertStringStartsWith('https://', $dcUrl);
        $this->assertStringContainsString('OY-42', $yUrl);
        $this->assertStringContainsString('DC-99', $dcUrl);
    }
}
