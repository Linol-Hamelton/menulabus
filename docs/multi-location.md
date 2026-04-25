# Multi-location

## Implementation Status

- Status: `Implemented (MVP)` — Phase 6.5.
- Last reviewed: `2026-04-23`
- Current state:
  - **Storage:** [sql/multi-location-migration.sql](../sql/multi-location-migration.sql) — new `locations` table; `location_id` column added to `orders`, `menu_items`, `reservations`.
  - **DB layer:** [db.php](../db.php) — `listLocations`, `getLocationById`, `saveLocation`, `deleteLocation` (soft), `getOrdersByLocationSummary`.
  - **Admin surface:** [/admin-locations.php](../admin-locations.php) — inline-editable CRUD, per-location 30-day revenue summary, legacy "Без локации" bucket for pre-migration history.
  - **API:** [api/save-location.php](../api/save-location.php) — `list` / `save` / `delete` actions.
  - **Tests:** [tests/MultiLocationTest.php](../tests/MultiLocationTest.php) — MySQL-gated, 4 cases.

## Model

`1 client = 1 database` is a **tenant** boundary, not a location boundary. A restaurant chain is one tenant; its locations live inside that tenant's DB. This keeps offboarding, backups, and cross-location reports simple, at the cost of "true isolation" between locations inside the chain (they share a DB, which is fine — they share an owner).

| Column/Table | Role | `location_id` semantic |
|---|---|---|
| `locations` | per-restaurant contact card + timezone + sort order | PK |
| `orders.location_id` | where the order was placed | `NULL` = pre-migration / legacy |
| `menu_items.location_id` | where the dish is available | `NULL` = chain-wide item |
| `reservations.location_id` | which restaurant the booking is for | `NULL` = rare pre-migration row |

Deleting a location is a **soft deactivation** (sets `active=0`). Historical rows that reference the location survive unchanged — the admin UI shows the location in a "legacy" bucket.

## Backward Compatibility

Every Phase 6.5 addition is non-breaking for pre-location code paths:

- Existing callers of `getMenuItems($category, $availableOnly)` are unchanged — no new argument is mandatory, and all current rows have `location_id=NULL` so they pass the default "no location filter" query.
- Existing admin UIs keep working. The admin-locations page is optional; a tenant that doesn't create any locations sees an empty table and nothing changes in order / menu / reservation flows.
- Follow-up tracks (owner per-location reporting, customer location picker on `/menu.php`, KDS per-location board) will thread the `location_id` through query paths gradually. The MVP is: schema is ready, CRUD works, and legacy reports include a `location_id=0` bucket for pre-migration history.

## Admin Surface

`/admin-locations.php`:

- Role: `admin` / `owner`.
- Table columns: id / name / address / phone / timezone / orders-30d / revenue-30d / sort_order / active / actions.
- The summary counts (30-day orders + revenue) come from `getOrdersByLocationSummary()` and exclude `status='отказ'` orders.
- A "Без локации" row is rendered if any pre-migration order has `location_id=NULL` — operators see the legacy revenue alongside active locations until those orders age out.
- "Деактивировать" flips `active=0` (soft delete) with a confirm. Reactivation is manual (un-check the active column and save).

## Known Gaps / Next Iteration

- **No location picker on `/menu.php`.** Customers currently browse one logical menu; a chain with a location-specific stop-list sees unified results. Adding a `?loc=<id>` query parameter + session sticky cookie is a follow-up.
- **No per-location KDS station lists.** [kds.md](./kds.md) stations are tenant-global. A chain with independent kitchens per location needs a `kitchen_stations.location_id` column — deferred.
- **No per-location loyalty tier overrides.** Tiers apply chain-wide; a "downtown VIP" tier isn't expressible.
- **No per-location owner-report filter.** `/owner.php?tab=stats` still aggregates chain-wide. `/owner.php?tab=analytics-v2` and `/admin-locations.php` both show location-scoped data; extending every legacy report to take an optional `location_id` is a follow-up.
- **`location_id` scope-filter on `getMenuItems` / `getOrders`.** Added as DB method parameters in a future track — the migration already ships the index `idx_menu_items_location_category` that will back those queries.
- **Cross-tenant chain.** If a chain spans franchises in separate jurisdictions that each want their own billing / data-residency, that's a tenant-level split (two databases, not one with many locations). Phase 9 multi-region can cover this.

## Test Flow

1. Apply `sql/multi-location-migration.sql` — idempotent.
2. Open `/admin-locations.php` as owner:
   - Create "Центр" (sort 0) and "Север" (sort 1), each with address/phone.
   - Inline table shows both; 30-day summary is zero until orders land.
3. Place a test order — orders still save with `location_id=NULL` (no picker yet). Row appears in the "Без локации" bucket in the admin summary.
4. Manually update the test order's `location_id` to point at "Центр":
   `UPDATE orders SET location_id = 1 WHERE id = <test>;`
5. Reload `/admin-locations.php` — "Центр" now shows 1 order, its revenue, and the legacy bucket shrinks accordingly.
6. Deactivate "Север" via the button — row stays, `active=0`, soft.

For MySQL-gated tests:

```bash
CLEANMENU_TEST_MYSQL_DSN=mysql:host=127.0.0.1;dbname=cleanmenu_test;charset=utf8mb4 \
  composer test
```

## Related Docs

- [product-model.md](./product-model.md) §4 — the `1 client = 1 DB` rule that Phase 6.5 deliberately preserves.
- [kds.md](./kds.md) — next iteration will join KDS stations to locations.
- [analytics.md](./analytics.md) — future version adds location filter.
- [product-vision-2027.md](./product-vision-2027.md) §3 — Phase 6.5 scope.
