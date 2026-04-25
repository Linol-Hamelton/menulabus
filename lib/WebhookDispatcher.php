<?php

/**
 * WebhookDispatcher — fire-and-forget outbound webhook hub.
 *
 * Two responsibilities:
 *   1. dispatch(event, payload) — enqueue one delivery row per active
 *      subscription matching the event. Cheap; called from hot paths
 *      (order create, reservation create) without blocking the caller.
 *   2. send(deliveryRow) — actually POSTs the JSON payload to the
 *      consumer URL with an HMAC signature. Called from the worker
 *      (scripts/webhook-worker.php), not from request handlers.
 *
 * Signature header layout (X-Webhook-Signature):
 *   "v1=" + hex(hmac_sha256(secret, "{timestamp}.{raw_body}"))
 * The timestamp ships separately as X-Webhook-Timestamp so consumers can
 * replay-protect (reject anything older than ~5 minutes). See
 * docs/webhook-integration.md for the verification recipe.
 *
 * Failure handling lives in Database::markWebhookFailed() (exponential
 * backoff: 60s, 300s, 1800s, 7200s, then status='dropped' after 5 attempts).
 */
final class WebhookDispatcher
{
    public const HEADER_SIGNATURE = 'X-Webhook-Signature';
    public const HEADER_TIMESTAMP = 'X-Webhook-Timestamp';
    public const HEADER_EVENT     = 'X-Webhook-Event';
    public const HEADER_DELIVERY  = 'X-Webhook-Delivery';

    private const HTTP_TIMEOUT = 5;
    private const MAX_EXCERPT  = 2000;

    public static function dispatch(string $eventType, array $payload, ?Database $db = null): int
    {
        $eventType = trim($eventType);
        if ($eventType === '') {
            return 0;
        }
        if ($db === null) {
            if (!class_exists('Database', false)) {
                return 0;
            }
            $db = Database::getInstance();
        }

        $subscriptions = $db->getActiveWebhooksForEvent($eventType);
        if (empty($subscriptions)) {
            return 0;
        }

        $envelope = [
            'event'      => $eventType,
            'created_at' => date('c'),
            'data'       => $payload,
        ];

        $enqueued = 0;
        foreach ($subscriptions as $sub) {
            $id = $db->enqueueWebhookDelivery((int)$sub['id'], $eventType, $envelope);
            if ($id !== null) {
                $enqueued++;
            }
        }
        return $enqueued;
    }

    /**
     * Send one queued/failed delivery row. The row is expected to be the
     * shape returned by Database::claimDueWebhookDeliveries() — already
     * marked 'sending' and joined with the parent subscription.
     *
     * Returns true on 2xx response, false otherwise. Either way the row
     * is updated via markWebhookDelivered/markWebhookFailed.
     */
    public static function send(array $delivery, ?Database $db = null): bool
    {
        if ($db === null) {
            $db = Database::getInstance();
        }

        $deliveryId = (int)($delivery['id'] ?? 0);
        if ($deliveryId <= 0) {
            return false;
        }

        $targetUrl = (string)($delivery['target_url'] ?? '');
        $secret    = (string)($delivery['secret'] ?? '');
        $rawBody   = (string)($delivery['payload_json'] ?? '');
        $eventType = (string)($delivery['event_type'] ?? '');

        if ($targetUrl === '' || $secret === '' || $rawBody === '') {
            $db->markWebhookFailed($deliveryId, null, 'invalid_delivery_row');
            return false;
        }

        $timestamp = (string)time();
        $signature = 'v1=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            self::HEADER_EVENT     . ': ' . $eventType,
            self::HEADER_TIMESTAMP . ': ' . $timestamp,
            self::HEADER_SIGNATURE . ': ' . $signature,
            self::HEADER_DELIVERY  . ': ' . $deliveryId,
        ];

        $ch = curl_init($targetUrl);
        if ($ch === false) {
            $db->markWebhookFailed($deliveryId, null, 'curl_init_failed');
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $excerpt = '';
        if ($errno !== 0) {
            $excerpt = 'curl_errno=' . $errno . ' ' . $error;
        } elseif (is_string($body)) {
            $excerpt = mb_substr($body, 0, self::MAX_EXCERPT);
        }

        if ($errno === 0 && $code >= 200 && $code < 300) {
            $db->markWebhookDelivered($deliveryId, $code, $excerpt);
            return true;
        }

        $db->markWebhookFailed($deliveryId, $errno === 0 ? $code : null, $excerpt);
        return false;
    }

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(24));
    }
}
