-- Phase L103 — 6-tier per-location pricing refactor (control-plane DB).
--
-- Adds three new tables:
--   tariffs            — reference catalog (8 seed rows: 6 tiers + trial + chain)
--   tenant_locations   — mirror of tenant-DB `locations` so subscriptions can FK
--                        on a valid id (cross-DB FKs are not supported in MySQL)
--   subscriptions      — one row per (tenant_id, location_id). Replaces the
--                        per-tenant `tenants.plan_id` model.
--
-- Extends three existing billing tables with `location_id` (nullable so legacy
-- per-tenant rows keep working until Phase 6 backfills):
--   subscription_invoices
--   addon_purchases
--   subscription_events
--
-- IMPORTANT: this migration ADDS infrastructure only. No application code
-- reads from `subscriptions` / `tariffs` until Phase 3. `tenants.plan_id`
-- remains the canonical source for `FeatureGate` until Phase 6 swap.
-- Therefore this file is SAFE to run in production at any time — zero
-- behaviour change.
--
-- User-approved naming (2026-06-30 confirmation):
--   1. Меню       — 2 490 ₽/мес   — code `menu`     — catalog only
--   2. Заказ+     — 3 990 ₽/мес   — code `order`    — + cart/customer/in-rest
--   3. Доставка+  — 5 990 ₽/мес   — code `delivery` — + delivery/couriers
--   4. Контроль+  — 7 990 ₽/мес   — code `control`  — + analytics/54-FZ
--   5. Смена+     — 9 990 ₽/мес   — code `shift`    — + staff/KDS/Z-report
--   6. Кухня+     — 11 990 ₽/мес  — code `kitchen`  — + inventory/recipes/EGAIS
--   + Меню (пробник) — bесплатный 14-дневный trial тарифа Меню
--   + Сеть+ — sales-led (replaces deprecated `enterprise_plus`)
--
-- Card NOT required at signup (user spec). Trial covers Tier-1 features only.
--
-- Idempotent via INFORMATION_SCHEMA + PREPARE guards (prod MySQL 8.0.45 < 8.0.29
-- lacks ADD COLUMN/INDEX IF NOT EXISTS).

-- ─── tariffs reference catalog ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tariffs (
  code              VARCHAR(32) NOT NULL,
  display_name      VARCHAR(64) NOT NULL,
  tier_rank         TINYINT UNSIGNED NULL,
  price_kop         INT UNSIGNED NULL,
  currency          CHAR(3) NOT NULL DEFAULT 'RUB',
  billing_period    ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
  trial_days        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_self_service   TINYINT(1) NOT NULL DEFAULT 1,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  sort_order        SMALLINT NOT NULL DEFAULT 0,
  description       VARCHAR(500) NULL DEFAULT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (code),
  KEY idx_tariffs_active_rank (is_active, tier_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: 6 confirmed tiers + 14-day trial + sales-led chain.
-- Re-runs UPDATE on duplicate (safe to re-execute when pricing changes).
INSERT INTO tariffs (code, display_name, tier_rank, price_kop, billing_period, trial_days, is_self_service, sort_order, description) VALUES
  ('menu',       'Меню',            1,   249000, 'monthly',  0, 1, 10, 'Базовый: только публичное меню + QR-ссылки. Без корзины, без личного кабинета клиента.'),
  ('order',      'Заказ+',          2,   399000, 'monthly',  0, 1, 20, '+ Корзина, кабинет клиента, заказы в зале, бронь столов, чаевые, онлайн-оплата, отзывы, лояльность.'),
  ('delivery',   'Доставка+',       3,   599000, 'monthly',  0, 1, 30, '+ Самовывоз, доставка, курьер, агрегаторы (Яндекс.Еда, Delivery Club), waitlist.'),
  ('control',    'Контроль+',       4,   799000, 'monthly',  0, 1, 40, '+ Аналитика выручки и когорт, фискальные отчёты 54-ФЗ, модерация отзывов, маркетинг-кампании, webhooks.'),
  ('shift',      'Смена+',          5,   999000, 'monthly',  0, 1, 50, '+ Управление штатом, смены, тип-пул, кассовая смена с Z-отчётом, KDS (kitchen display).'),
  ('kitchen',    'Кухня+',          6,  1199000, 'monthly',  0, 1, 60, '+ Склад, ингредиенты, рецепты, авто-списание COGS, инвентаризация, полуфабрикаты, ЕГАИС, ВСД Меркурий, поставщики.'),
  ('menu_trial', 'Меню (пробник)',  1,        0, 'monthly', 14, 1,  5, 'Бесплатный 14-дневный пробник тарифа Меню. Без обязательной привязки карты при регистрации.'),
  ('chain',      'Сеть+',        NULL,     NULL, 'monthly',  0, 0, 99, 'Сети 10+ локаций: SSO/SAML, выделенная БД, custom SLA, выделенный success-manager. Договорная. sales@labus.pro')
ON DUPLICATE KEY UPDATE
  display_name    = VALUES(display_name),
  tier_rank       = VALUES(tier_rank),
  price_kop       = VALUES(price_kop),
  currency        = VALUES(currency),
  billing_period  = VALUES(billing_period),
  trial_days      = VALUES(trial_days),
  is_self_service = VALUES(is_self_service),
  is_active       = VALUES(is_active),
  sort_order      = VALUES(sort_order),
  description     = VALUES(description);

-- ─── tenant_locations mirror ────────────────────────────────────────────
-- Subscriptions need to FK on a (tenant_id, location_id) pair. Tenant DBs
-- have their own `locations` table, but MySQL doesn't support cross-DB FKs.
-- This mirror is populated by Database::syncLocationsToControlPlane() on
-- every location CRUD inside a tenant DB (added in Phase 2).
CREATE TABLE IF NOT EXISTS tenant_locations (
  tenant_id    BIGINT UNSIGNED NOT NULL,
  location_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(255) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  synced_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, location_id),
  KEY idx_tl_tenant_active (tenant_id, is_active),
  CONSTRAINT fk_tl_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── subscriptions (one per tenant+location) ────────────────────────────
-- After Phase 6 this replaces tenants.plan_id as the source of truth for
-- billing state. tenants.plan_id is kept as a denormalized audit column
-- (see risk #9 in the L103 plan).
CREATE TABLE IF NOT EXISTS subscriptions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id             BIGINT UNSIGNED NOT NULL,
  location_id           INT UNSIGNED NOT NULL,
  tariff_code           VARCHAR(32) NOT NULL,
  status                ENUM('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trial',
  trial_ends_at         DATETIME NULL DEFAULT NULL,
  current_period_start  DATETIME NULL DEFAULT NULL,
  current_period_end    DATETIME NULL DEFAULT NULL,
  cancelled_at          DATETIME NULL DEFAULT NULL,
  custom_price_kop      INT UNSIGNED NULL DEFAULT NULL,
  legacy_grandfather    TINYINT(1) NOT NULL DEFAULT 0,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_tenant_location (tenant_id, location_id),
  KEY idx_sub_status_period (status, current_period_end),
  KEY idx_sub_tariff (tariff_code),
  KEY idx_sub_tenant (tenant_id),
  CONSTRAINT fk_sub_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_tariff FOREIGN KEY (tariff_code) REFERENCES tariffs(code) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── extend subscription_invoices with location_id ──────────────────────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_invoices'
             AND COLUMN_NAME = 'location_id');
SET @ddl := IF(@c = 0,
    'ALTER TABLE subscription_invoices ADD COLUMN location_id INT UNSIGNED NULL DEFAULT NULL AFTER tenant_id',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_invoices'
             AND INDEX_NAME = 'idx_invoices_tenant_loc_period');
SET @ddl := IF(@i = 0,
    'ALTER TABLE subscription_invoices ADD KEY idx_invoices_tenant_loc_period (tenant_id, location_id, period_end)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── extend addon_purchases with location_id ────────────────────────────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'addon_purchases'
             AND COLUMN_NAME = 'location_id');
SET @ddl := IF(@c = 0,
    'ALTER TABLE addon_purchases ADD COLUMN location_id INT UNSIGNED NULL DEFAULT NULL AFTER tenant_id',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'addon_purchases'
             AND INDEX_NAME = 'idx_addon_tenant_loc');
SET @ddl := IF(@i = 0,
    'ALTER TABLE addon_purchases ADD KEY idx_addon_tenant_loc (tenant_id, location_id)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── extend subscription_events with location_id ────────────────────────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_events'
             AND COLUMN_NAME = 'location_id');
SET @ddl := IF(@c = 0,
    'ALTER TABLE subscription_events ADD COLUMN location_id INT UNSIGNED NULL DEFAULT NULL AFTER tenant_id',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
