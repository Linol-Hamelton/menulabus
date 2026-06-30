# Phase L103 — Six-tier per-location pricing

**Status:** complete (commits `68dd5fd` → `20d8b8c`, branch `feature/l103-six-tier-pricing`).
**Released:** 2026-06-30, versions `3.17.0` → `3.18.1`.
**Supersedes:** the per-tenant Pro / Enterprise / Enterprise+ model from `PlanRegistry`.

## What changed

Pricing is now **per location, not per tenant**. A chain with 3 venues buys 3
subscriptions (one per location); each subscription unlocks a different feature
set based on its tier rank 1–6.

| Rank | Code         | Display name | Price | What unlocks (cumulative) |
|------|--------------|--------------|------:|---------------------------|
| 1 | `menu`         | Меню          |  2 490 ₽/мес | catalog display, search, QR landing, i18n |
| 2 | `order`        | Заказ+        |  3 990 ₽/мес | + cart, customer account, in-restaurant orders, reservations, loyalty, tips, online pay, reviews |
| 3 | `delivery`     | Доставка+     |  5 990 ₽/мес | + takeaway, delivery, courier assign, aggregators (Yandex.Еда / DC), waitlist |
| 4 | `control`      | Контроль+     |  7 990 ₽/мес | + analytics revenue + v2, 54-FZ fiscal, reviews moderation, marketing campaigns, webhooks |
| 5 | `shift`        | Смена+        |  9 990 ₽/мес | + staff CRUD, shifts, tip-pool, shift-swap, cashier shift (Z-report), KDS |
| 6 | `kitchen`      | Кухня+        | 11 990 ₽/мес | + inventory, recipes, COGS auto-deduct, stocktake, semi-finished, EGAIS, VSD/Меркурий, suppliers |
| —    | `menu_trial`   | Меню (пробник)| 0 ₽, 14 дней | trial of Меню features only, no card required |
| —    | `chain`        | Сеть+         | договорная   | sales-led, replaces deprecated `enterprise_plus` |

See `sql/l103-six-tier-migration.sql` for the canonical seed data.

## How feature gating works

```
┌─────────────────────────────────────────────────────────────────┐
│ Request hits a gated route                                       │
│   ↓                                                              │
│ partials/tier_paywall.php  OR  lib/Billing/TierGate.php          │
│   ↓                                                              │
│ Database::hasFeature($featureKey, $locationId)                   │
│   ↓                                                              │
│ Database::getActiveTariff($locationId)  [per-request cache]      │
│   ↓                                                              │
│ control-plane: SELECT FROM subscriptions JOIN tariffs            │
│   ↓                                                              │
│ Features::TIER_MATRIX[$featureKey] ≤ tariff_rank ?               │
│   ├─ yes → pass through                                          │
│   └─ no  → render paywall (HTML) or 402 JSON                     │
└─────────────────────────────────────────────────────────────────┘
```

**Legacy single-DB mode** (no control plane configured): `hasFeature()` returns
`true` — fail-open. Same fallback policy as the older `FeatureGate::state()`.

## Adding a new feature gate

1. Add the feature key to `lib/Billing/Features.php`:
   ```php
   public const ORDER_SCHEDULED_PICKUP = 'order.scheduled_pickup';
   // ...in TIER_MATRIX:
   self::ORDER_SCHEDULED_PICKUP => 3,  // tier 3+ «Доставка+»
   ```

2. Gate the HTML route (route-level early-exit):
   ```php
   $l103_feature = \Cleanmenu\Billing\Features::ORDER_SCHEDULED_PICKUP;
   $l103_label   = 'Запланированный самовывоз';
   require __DIR__ . '/partials/tier_paywall.php';
   ```

3. Gate the API endpoint (JSON 402):
   ```php
   require_once __DIR__ . '/../lib/Billing/TierGate.php';
   \Cleanmenu\Billing\TierGate::requireFeature(
       \Cleanmenu\Billing\Features::ORDER_SCHEDULED_PICKUP,
       'Запланированный самовывоз'
   );
   ```

4. Inline conditional render (e.g. hiding a button):
   ```php
   if (\Cleanmenu\Billing\TierGate::isAllowed(\Cleanmenu\Billing\Features::ORDER_SCHEDULED_PICKUP)) {
       echo '<button>Запланировать</button>';
   }
   ```

5. Add a unit test row in `tests/L103FeaturesTest.php`.

## Deploy steps

```bash
PROJECT=/var/www/labus_pro_usr/data/www/menu.labus.pro
WEBUSER=...

# 1. Pull the branch
runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout feature/l103-six-tier-pricing

# 2. Run the schema migration (idempotent)
CP_DB=$(php -r "require '$PROJECT/tenant_control_config.php'; echo CONTROL_DB_NAME;")
mysql -u root "$CP_DB" < "$PROJECT/sql/l103-six-tier-migration.sql"
mysql -u root "$CP_DB" -e "SELECT code, display_name, tier_rank, price_kop FROM tariffs ORDER BY sort_order;"

# 3. Dry-run the backfill, review output
php "$PROJECT/scripts/l103-backfill-subscriptions.php" --dry-run

# 4. Apply the backfill
php "$PROJECT/scripts/l103-backfill-subscriptions.php"

# 5. Restart PHP-FPM (CSP-nonce + autoloader caches)
systemctl restart php8.1-fpm
```

## Rollback

Each of phases L103.1 → L103.8 is a single squash-mergable commit, so any
phase can be reverted independently via `git revert <commit>` followed by
deploy. Phase L103.6 (billing flow) is the only commit where revert is
non-trivial — the webhook handler retains the legacy path even after L103.6
lands, so `git revert` on L103.6 is safe but reverts ALL per-location billing.

To roll the whole branch back: `git checkout main && git branch -D feature/l103-six-tier-pricing`.
The schema migration is additive (no DROP) — `subscriptions` / `tariffs` /
`tenant_locations` tables stay in place but are simply unread.

## Deferred work (Phase L104+)

- `signup.php` plan picker — still hardcoded `PlanRegistry::selfServiceIds()`.
- `partials/owner_billing_section.php` — still reads `PlanRegistry::byId($planId)`.
- `scripts/billing-cycle-worker.php` — still iterates `tenants` table; new
  per-location iteration via `SubscriptionStore::getLocationsDueForCharge`
  needs to be added.
- `owner.php` analytics/fiscal/integrations tab-pane conditional rendering.
- Per-location aggregator credentials (Yandex.Еда / DC).
- Annual tariff variants (schema supports them, no rows seeded).

`PlanRegistry` is `@deprecated` but stays alive until all of the above migrate.

## File map

```
Phase L103.1 — Schema
  sql/l103-six-tier-migration.sql

Phase L103.2 — Session plumbing
  api/set-active-location.php
  account-header.php  (location switcher)
  css/location-switcher.css
  js/location-switcher.js
  db.php  (activeLocationId, syncLocationToControlPlane)

Phase L103.3 — Helpers
  lib/Billing/Features.php       (atomic feature keys + TIER_MATRIX)
  lib/Billing/TariffRegistry.php (read-only DAO over tariffs table)
  db.php  (getActiveTariff, hasFeature, clearTariffCache)
  tests/L103FeaturesTest.php

Phase L103.4 — Backfill
  scripts/l103-backfill-subscriptions.php

Phase L103.5 — 40 callsite gates
  partials/tier_paywall.php          (HTML paywall)
  lib/Billing/TierGate.php           (JSON 402 helper)
  css/tier-paywall.css
  cart.php, reservation.php, account.php, customer_orders.php,
  admin/{waitlist,staff,kitchen,inventory,egais,stocktake,semi-finished,vsd}.php,
  kds/{index,action}.php,
  api/{save-courier,save-aggregator,analytics-v2,save-fiscal-settings,
       save-webhook,save-campaign,moderate-review,save-staff,
       save-cashier-shift,save-kitchen-station,shift-swap-action,
       save-inventory,save-egais,save-vsd,save-stocktake,
       save-semi-finished,import-recipes}.php,
  create_new_order.php, create_guest_order.php, api/v1/orders/create.php

Phase L103.6 — Billing flow per-location (additive)
  lib/Billing/YookassaRecurring.php  (optional location_id + tariff_code in metadata)
  lib/Billing/SubscriptionStore.php  (3 new methods + onWebhook hook)

Phase L103.7 — Marketing surface
  index.php (pricing section data-driven)
  css/index-landing.css (6-tier responsive layout)

Phase L103.8 — Cleanup
  lib/Billing/PlanRegistry.php  (@deprecated annotation)
  docs/billing-l103.md          (this file)
```
