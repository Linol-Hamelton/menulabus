# Database Schema and Migrations

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-11`
- Verified against code: [sql/bootstrap-schema.sql](../sql/bootstrap-schema.sql), [sql/control-plane-schema.sql](../sql/control-plane-schema.sql), [sql/mobile-api-tables.sql](../sql/mobile-api-tables.sql), [sql/mobile-oauth-identities.sql](../sql/mobile-oauth-identities.sql), [sql/performance-indexes.sql](../sql/performance-indexes.sql), [sql/drop-duplicate-indexes.sql](../sql/drop-duplicate-indexes.sql), [sql/modifiers-migration.sql](../sql/modifiers-migration.sql), [sql/payment-migration.sql](../sql/payment-migration.sql), [sql/sbp-migration.sql](../sql/sbp-migration.sql), [sql/tips-migration.sql](../sql/tips-migration.sql), [sql/menu-archive-sync-migration.sql](../sql/menu-archive-sync-migration.sql), [scripts/tenant/launch.php](../scripts/tenant/launch.php).

## Purpose

Engineer-facing map of the database topology, the bootstrap flow, the ordered list of one-shot migrations, and the non-obvious rules (one DB per tenant, control-plane vs. tenant schemas, index hygiene). It is **not** an ORM reference — there is no ORM. All SQL goes through `db.php` / `lib/*.php` PDO prepared statements.

## Topology

There are **two kinds of database** in this system:

### 1. Control plane (one per deployment)

Holds the tenant registry. Schema: [sql/control-plane-schema.sql](../sql/control-plane-schema.sql).

| Table | Purpose |
|---|---|
| `tenants` | One row per tenant. Stores `brand_slug`, `db_name`, `db_user`, `db_pass_enc` (encrypted). `mode` distinguishes `provider` from `tenant`. |
| `tenant_domains` | Host → tenant mapping. Keyed by `host` (the fully-qualified custom domain). `is_primary` marks the canonical host when a tenant has multiple. Cascades on tenant delete. |

Lookup flow at request time: `session_init.php` reads `$_SERVER['HTTP_HOST']`, looks up `tenant_domains.host`, resolves the tenant id, pulls the tenant row, decrypts `db_pass_enc`, opens a PDO connection to the tenant DB. This is cached per-process in memory.

### 2. Tenant DB (one per tenant)

**Core rule: `1 клиент = 1 отдельная БД`**. Database name must contain the tenant's brand slug. Two tenants never share a tenant DB, and the control-plane DB never holds order or menu data. This is load-bearing for data isolation — see [product-model.md](./product-model.md).

Each tenant DB carries the same schema, laid down by [sql/bootstrap-schema.sql](../sql/bootstrap-schema.sql). 13 tables:

| Table | Role |
|---|---|
| `users` | Accounts. `role` ENUM is `customer`/`employee`/`admin`/`owner`/`guest`. Unique email. Reset/verification token columns. `menu_view` stores the per-user menu template preference. |
| `auth_tokens` | Remember-me / web session refresh. Selector + `hashed_validator` pair, FK to `users`. |
| `settings` | Key-value, `value` is JSON. Every read MUST `json_decode()`. Written by `save-*.php` admin endpoints. |
| `menu_items` | Catalog. Has `external_id` + `archived_at` from the menu-archive-sync migration. |
| `modifier_groups`, `modifier_options` | Per-item option groups (see [modifiers.md](./modifiers.md)). Both FK to parent on cascade delete. |
| `orders` | One row per order. Includes `items` JSON, `total`, `tips`, `delivery_type`, `delivery_details`, plus payment columns (`payment_method`, `payment_id`, `payment_status`) and modifier-annotated item names. |
| `order_status_history` | Append-only log of status transitions. |
| `order_items` | Normalized order line items. Added for mobile API + analytics; backfillable from `orders.items` JSON (see backfill note below). |
| `oauth_identities` | Third-party provider identities (VK, Yandex, mobile Google). One row per (`provider`, `provider_user_id`), FK to `users`. |
| `mobile_refresh_tokens` | Mobile JWT refresh-token store. Hashed tokens, device metadata, revocation timestamp. |
| `api_idempotency_keys` | Idempotency cache (scope + key → cached response JSON). See [api-smoke.md](./api-smoke.md) and [payments-integration.md](./payments-integration.md). |
| `push_subscriptions` | Web push subscriber directory. See [pwa-and-push.md](./pwa-and-push.md). |

All tables use `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.

## Bootstrap flow

New tenants are laid down by [scripts/tenant/launch.php](../scripts/tenant/launch.php) (called from [scripts/tenant/go-live.sh](../scripts/tenant/go-live.sh)). The script, in order:

1. Creates the MySQL database and the tenant user with a generated password.
2. Registers the tenant in the control-plane `tenants` + `tenant_domains` tables.
3. Runs **`sql/bootstrap-schema.sql`** against the new tenant DB. This file already contains the full, current table definitions including all columns added by the migrations below — `bootstrap-schema.sql` is the rolled-up source of truth for the schema shape.
4. Seeds demo content via `scripts/tenant/data/restaurant_demo.php` or a brand-specific seed profile (see [tenant-demo-seed.md](./tenant-demo-seed.md)).
5. Applies the performance index pack ([sql/performance-indexes.sql](../sql/performance-indexes.sql)) and drops known-duplicate indexes from older schemas ([sql/drop-duplicate-indexes.sql](../sql/drop-duplicate-indexes.sql)).

Because `bootstrap-schema.sql` is rolled-up, **new tenants do not need to replay the one-shot migrations below** — those apply only to existing tenants that were bootstrapped from an earlier version of the schema.

## One-shot migrations

Keep these in chronological order. Every existing tenant DB must have every migration applied at least once. New tenants get all of them as part of `bootstrap-schema.sql`.

| File | Applies to | What it does |
|---|---|---|
| [sql/menu-archive-sync-migration.sql](../sql/menu-archive-sync-migration.sql) | `menu_items` | Adds `external_id` (for POS sync) and `archived_at`, backfills `external_id` on existing rows, adds sync/active indexes. |
| [sql/mobile-api-tables.sql](../sql/mobile-api-tables.sql) | new tables | Creates `mobile_refresh_tokens`, `api_idempotency_keys`, `order_items`. Contains a commented-out backfill `INSERT ... SELECT` using `JSON_TABLE(orders.items)` — run this once per tenant in a maintenance window to populate `order_items` from existing `orders.items` JSON (see [db/backfill-order-items.md](./db/backfill-order-items.md)). |
| [sql/mobile-oauth-identities.sql](../sql/mobile-oauth-identities.sql) | new table | Creates `oauth_identities` for Google/VK/Yandex mobile token-based OAuth. |
| [sql/modifiers-migration.sql](../sql/modifiers-migration.sql) | new tables | Creates `modifier_groups` + `modifier_options`. Both FK to their parent with `ON DELETE CASCADE`. See [modifiers.md](./modifiers.md). |
| [sql/payment-migration.sql](../sql/payment-migration.sql) | `orders` | Adds `payment_method ENUM('cash','online')`, `payment_id VARCHAR(100)`, `payment_status ENUM(...)`, plus `idx_orders_payment_id`. |
| [sql/sbp-migration.sql](../sql/sbp-migration.sql) | `orders` | Extends the `payment_method` ENUM with `sbp`. Must run **after** `payment-migration.sql`. |
| [sql/tips-migration.sql](../sql/tips-migration.sql) | `orders` | Adds `tips DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total`. See [tips.md](./tips.md). |
| [sql/performance-indexes.sql](../sql/performance-indexes.sql) | many | Composite indexes for the hottest `db.php` queries. Safe to run on production — InnoDB creates indexes online. Idempotent via a pre-check of `information_schema.statistics`. |
| [sql/drop-duplicate-indexes.sql](../sql/drop-duplicate-indexes.sql) | many | Drops older indexes that are strictly subsumed by entries in `performance-indexes.sql` (e.g. `idx_status_created` is dropped in favor of `idx_orders_status_created (status, created_at DESC)`). Run **after** `performance-indexes.sql`. |

## Application order on an existing tenant DB

If you inherit a tenant DB from an older deployment and need to catch it up, run migrations in this exact order:

```bash
mysql -u <user> -p <db> < sql/menu-archive-sync-migration.sql
mysql -u <user> -p <db> < sql/mobile-api-tables.sql
mysql -u <user> -p <db> < sql/mobile-oauth-identities.sql
mysql -u <user> -p <db> < sql/modifiers-migration.sql
mysql -u <user> -p <db> < sql/payment-migration.sql
mysql -u <user> -p <db> < sql/sbp-migration.sql
mysql -u <user> -p <db> < sql/tips-migration.sql
mysql -u <user> -p <db> < sql/performance-indexes.sql
mysql -u <user> -p <db> < sql/drop-duplicate-indexes.sql
```

All individual files use `IF NOT EXISTS` / `IF EXISTS` guards where possible. `sbp-migration.sql` and `tips-migration.sql` both use `ALTER TABLE`; re-applying them will fail with a duplicate-column error on tenants that already have the change — treat a failure as "already applied" and move on.

Then run the `order_items` backfill from the commented block at the bottom of `mobile-api-tables.sql` in a maintenance window. The full runbook is in [db/backfill-order-items.md](./db/backfill-order-items.md).

## Rules that live outside the SQL files

These are conventions enforced by `db.php` / application code, not by the schema itself — know them before writing queries:

1. **Settings values are JSON.** `settings.value` is a `JSON` column. Reads must go through `json_decode($db->getSetting($key), true)`. Writes must wrap with `json_encode()`. A raw string write will produce a doubly-quoted value.
2. **Order status enum is canonical.** The full state machine lives in [order-lifecycle-contract.md](./order-lifecycle-contract.md). Do not invent new statuses without a migration + contract update.
3. **Two sources of truth for order lines.** `orders.items` (JSON) is the legacy source, `order_items` (normalized) is the newer one. Writes go to both. Reads mostly still go to `orders.items` for legacy menu views; the mobile API reads `order_items`. The backfill script keeps the JSON ↔ rows invariant for historical orders.
4. **Indexes on `orders` are performance-critical.** Do not drop any `idx_orders_*` index without cross-referencing `drop-duplicate-indexes.sql` first — the "duplicate" detector there is specifically for *subsumed* indexes; anything else is probably still load-bearing.
5. **No foreign keys from `orders.items`.** The `orders.items` JSON blob references `menu_items.id` by value but cannot be FK-enforced because it's inside JSON. If you need referential integrity, query against `order_items` instead, which does have proper indexes.
6. **Tenant isolation is at the database level, not the row level.** There is no `tenant_id` column anywhere in the tenant schema — a tenant DB contains only that tenant's data by construction. Do not add a shared multi-tenant DB pattern on top of this; the operational model assumes per-tenant isolation.

## Adding a new migration

1. Create `sql/<short-name>-migration.sql` with `IF NOT EXISTS` / idempotent DDL where possible. Include a header comment describing the "why" and the order dependency.
2. **Fold the change into `sql/bootstrap-schema.sql`** so new tenants get it automatically. This is not optional — forgetting this step is how tenants drift out of sync.
3. Update [project-reference.md](./project-reference.md) to list the new file.
4. Update this document to add a row to the "One-shot migrations" table and, if the migration has an order dependency (like `sbp` after `payment`), say so explicitly.
5. Apply to every existing tenant DB in a maintenance window, then run `scripts/tenant/smoke.php` on each to verify.
6. If the migration touches the hot path of `db.php`, re-run [api-smoke.md](./api-smoke.md)'s order flow to catch regressions.

## Related docs

- [product-model.md](./product-model.md) — why there is a control-plane / tenant split.
- [tenant-launch-checklist.md](./tenant-launch-checklist.md) — end-to-end new-tenant steps including schema bootstrap.
- [db/backfill-order-items.md](./db/backfill-order-items.md) — the one-shot `order_items` backfill runbook.
- [modifiers.md](./modifiers.md), [payments-integration.md](./payments-integration.md), [tips.md](./tips.md), [pwa-and-push.md](./pwa-and-push.md) — feature docs that reference specific tables in this schema.
- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — the `orders.status` state machine.
