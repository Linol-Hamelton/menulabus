-- Marketing automation migration (Phase 8.1).
-- Run once on each tenant DB. Idempotent.
--
-- Data model:
--   marketing_campaigns: a one-shot or recurring email/push send. status
--     lifecycle: draft → queued → sending → sent | failed | cancelled.
--     segment_json holds a tiny rule DSL: {"type":"all"} or
--     {"type":"min_orders","threshold":3} or {"type":"birthday_today"}.
--   marketing_sends: per-recipient row. Lets us count opens/clicks later
--     without re-resolving the segment, and is the dedup key (campaign_id, user_id).

CREATE TABLE IF NOT EXISTS marketing_campaigns (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(255) NOT NULL,
    channel         VARCHAR(16) NOT NULL DEFAULT 'email',
    subject         VARCHAR(255) DEFAULT NULL,
    body_text       TEXT NOT NULL,
    body_html       TEXT DEFAULT NULL,
    segment_json    JSON NOT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'draft',
    scheduled_at    DATETIME DEFAULT NULL,
    started_at      DATETIME DEFAULT NULL,
    finished_at     DATETIME DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_marketing_campaigns_status (status, scheduled_at),
    CONSTRAINT chk_marketing_channel CHECK (channel IN ('email', 'push', 'telegram')),
    CONSTRAINT chk_marketing_status  CHECK (status IN ('draft', 'queued', 'sending', 'sent', 'failed', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_sends (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id     INT UNSIGNED NOT NULL,
    user_id         INT NOT NULL,
    channel         VARCHAR(16) NOT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'queued',
    error_excerpt   VARCHAR(500) DEFAULT NULL,
    queued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at         DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_marketing_send (campaign_id, user_id),
    KEY idx_marketing_send_status (status, queued_at),
    CONSTRAINT chk_marketing_send_status CHECK (status IN ('queued', 'sent', 'failed', 'skipped')),
    CONSTRAINT fk_marketing_send_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_send_user     FOREIGN KEY (user_id)     REFERENCES users(id)               ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
