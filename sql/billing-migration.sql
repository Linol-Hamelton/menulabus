-- Billing engine migration (Phase 14.1, 2026-05-03).
--
-- Applies to the CONTROL-PLANE DB (not per-tenant). Adds SaaS subscription
-- billing on top of the existing `tenants` registry. Idempotent via
-- INFORMATION_SCHEMA guards (MySQL 8.0.45 < 8.0.29 lacks
-- ADD COLUMN IF NOT EXISTS).
--
-- Data model:
--   tenants                — extended with plan_id / subscription_status /
--                            trial_ends_at / current_period_end / owner_email /
--                            owner_user_id (link back to tenant DB).
--   subscription_invoices  — one row per billing-cycle attempt
--                            (period_start..period_end, amount_kop, status,
--                            yk_payment_id, retry_count, next_retry_at).
--   payment_methods        — saved YooKassa payment_method_id per tenant
--                            (last4 + brand for display, expires_at, is_default).
--   subscription_events    — audit log for charge_attempt / charge_success /
--                            charge_failed / status_changed / plan_changed.
--
-- All tables in control-plane DB; tenant DBs are not touched.

-- ─── tenants extensions ──────────────────────────────────────────────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME = 'plan_id');
SET @ddl := IF(@c = 0,
    "ALTER TABLE tenants ADD COLUMN plan_id VARCHAR(32) NOT NULL DEFAULT 'trial' AFTER is_active",
    'SELECT 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME = 'subscription_status');
SET @ddl := IF(@c = 0,
    "ALTER TABLE tenants ADD COLUMN subscription_status ENUM('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trial' AFTER plan_id",
    'SELECT 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME = 'trial_ends_at');
SET @ddl := IF(@c = 0,
    'ALTER TABLE tenants ADD COLUMN trial_ends_at DATETIME NULL DEFAULT NULL AFTER subscription_status',
    'SELECT 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME = 'current_period_end');
SET @ddl := IF(@c = 0,
    'ALTER TABLE tenants ADD COLUMN current_period_end DATETIME NULL DEFAULT NULL AFTER trial_ends_at',
    'SELECT 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME = 'owner_email');
SET @ddl := IF(@c = 0,
    'ALTER TABLE tenants ADD COLUMN owner_email VARCHAR(255) NULL DEFAULT NULL AFTER current_period_end',
    'SELECT 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME = 'owner_user_id');
SET @ddl := IF(@c = 0,
    'ALTER TABLE tenants ADD COLUMN owner_user_id INT NULL DEFAULT NULL AFTER owner_email',
    'SELECT 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index supports the billing-cycle worker query
-- (status='active' AND current_period_end <= NOW()+1d).
SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND INDEX_NAME = 'idx_tenants_status_period');
SET @ddl := IF(@i = 0,
    'ALTER TABLE tenants ADD INDEX idx_tenants_status_period (subscription_status, current_period_end)',
    'SELECT 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── subscription_invoices ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subscription_invoices (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  plan_id         VARCHAR(32) NOT NULL,
  period_start    DATETIME NOT NULL,
  period_end      DATETIME NOT NULL,
  amount_kop      INT UNSIGNED NOT NULL,
  currency        CHAR(3) NOT NULL DEFAULT 'RUB',
  status          ENUM('pending','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
  yk_payment_id   VARCHAR(64) DEFAULT NULL,
  retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at   DATETIME NULL DEFAULT NULL,
  failure_reason  VARCHAR(255) NULL DEFAULT NULL,
  paid_at         DATETIME NULL DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_invoices_tenant_period (tenant_id, period_end),
  KEY idx_invoices_status_retry (status, next_retry_at),
  KEY idx_invoices_yk (yk_payment_id),
  CONSTRAINT fk_invoices_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT chk_invoices_period
    CHECK (period_end > period_start),
  CONSTRAINT chk_invoices_amount
    CHECK (amount_kop >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── payment_methods ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payment_methods (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id              BIGINT UNSIGNED NOT NULL,
  provider               VARCHAR(32) NOT NULL DEFAULT 'yookassa',
  yk_payment_method_id   VARCHAR(64) NOT NULL,
  last4                  CHAR(4) NULL DEFAULT NULL,
  brand                  VARCHAR(32) NULL DEFAULT NULL,
  expires_month          TINYINT UNSIGNED NULL DEFAULT NULL,
  expires_year           SMALLINT UNSIGNED NULL DEFAULT NULL,
  is_default             TINYINT(1) NOT NULL DEFAULT 1,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payment_methods_tenant (tenant_id, is_default),
  UNIQUE KEY uniq_payment_methods_tenant_provider_token (tenant_id, provider, yk_payment_method_id),
  CONSTRAINT fk_payment_methods_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── subscription_events ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subscription_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  event_type  VARCHAR(64) NOT NULL,
  payload     JSON NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_subscription_events_tenant_time (tenant_id, created_at),
  KEY idx_subscription_events_type_time (event_type, created_at),
  CONSTRAINT fk_subscription_events_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
