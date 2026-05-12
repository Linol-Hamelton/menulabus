<?php
/**
 * lib/Aggregator/AggregatorAdapter.php — Phase 36 base contract.
 *
 * Concrete adapters (YandexEda, DeliveryClub) implement HMAC-signature
 * verification + payload normalization to a common shape consumed by
 * Database::createOrderFromAggregator().
 */

namespace Cleanmenu\Aggregator;

interface AggregatorAdapter
{
    /** Return the provider key used in DB: 'yandex_eda' | 'delivery_club'. */
    public static function providerKey(): string;

    /**
     * Verify HMAC signature against secret. Returns true on match.
     * $signatureHeader = value of the provider's signature header
     * (e.g. X-YandexEda-Signature). Should be HMAC-SHA256 hex.
     */
    public static function verifySignature(string $payload, string $signatureHeader, string $secret): bool;

    /**
     * Normalize raw payload to the common shape:
     *   {
     *     external_id: string,
     *     total: float,
     *     items: [{id?: int, name: string, quantity: int, price: float}, ...],
     *     customer_name?: string,
     *     customer_phone?: string,
     *     delivery_address?: string,
     *   }
     *
     * @param object $db  any object with a `findMenuItemByAggregatorId(string, string): ?array`
     *                    method (typically \Database, but duck-typed for unit tests).
     */
    public static function normalize(array $raw, object $db): array;

    /**
     * Map our internal status → aggregator's status string for outbound push.
     * Returns null if no mapping (skip push for this internal status).
     */
    public static function mapStatusOutbound(string $internalStatus): ?string;

    /** Aggregator's API endpoint for outbound status push. */
    public static function statusPushUrl(string $externalOrderId): string;
}
