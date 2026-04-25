-- Webhooks migration: outgoing webhook subscriptions + delivery log.
-- Run once on each tenant DB (idempotent: CREATE TABLE IF NOT EXISTS).
--
-- Shape notes:
--   - outgoing_webhooks: per-tenant subscriptions to a domain event (e.g.
--     'order.created', 'reservation.confirmed'). The tenant DB IS the
--     tenant scope, so no tenant_id column is needed.
--   - secret is opaque and used to compute an HMAC-SHA256 over the JSON
--     payload; consumers verify the X-Webhook-Signature header before
--     trusting the body.
--   - event_type is a free-text string. Known values are documented in
--     docs/webhook-integration.md and produced by lib/WebhookDispatcher.php
--     hook points. Unknown values are still accepted so a tenant can
--     subscribe to events that ship later without a schema change.
--   - active is a soft on/off; deleting a row is fine but the soft flag
--     keeps the deliveries history meaningful.
--
--   - webhook_deliveries: one row per attempted delivery. The worker (see
--     scripts/webhook-worker.php) picks up rows with status='queued' or
--     status='failed' AND next_retry_at <= NOW() AND attempts < 5.
--   - response_code is the HTTP status returned by the consumer; null
--     while the row is still queued.
--   - attempts increments on every send try; next_retry_at is rescheduled
--     with exponential backoff on failure (1m, 5m, 30m, 2h).
--   - delivered_at is set only on success and freezes the row.
--   - payload_json is stored separately from the webhook config so the
--     historical body survives even if the subscription is deleted later.
CREATE TABLE IF NOT EXISTS outgoing_webhooks (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type      VARCHAR(64) NOT NULL,
    target_url      VARCHAR(512) NOT NULL,
    secret          VARCHAR(128) NOT NULL,
    active          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    description     VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_outgoing_webhooks_event_active (event_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    webhook_id      INT UNSIGNED NOT NULL,
    event_type      VARCHAR(64) NOT NULL,
    payload_json    JSON NOT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'queued',
    response_code   SMALLINT UNSIGNED DEFAULT NULL,
    response_excerpt VARCHAR(2048) DEFAULT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_retry_at   DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at    DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_webhook_deliveries_status_retry (status, next_retry_at),
    KEY idx_webhook_deliveries_webhook (webhook_id, created_at),
    CONSTRAINT chk_webhook_deliveries_status
        CHECK (status IN ('queued', 'sending', 'delivered', 'failed', 'dropped')),
    CONSTRAINT fk_webhook_deliveries_webhook
        FOREIGN KEY (webhook_id) REFERENCES outgoing_webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
