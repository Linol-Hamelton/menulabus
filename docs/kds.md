# Kitchen Display System (KDS)

## Implementation Status

- Status: `Implemented` (Phase 6.1 MVP).
- Last reviewed: `2026-04-23`
- Current state:
  - **Storage:** [sql/kds-migration.sql](../sql/kds-migration.sql) — `kitchen_stations`, `menu_item_stations`, `order_item_status`.
  - **DB layer:** [db.php](../db.php) — `listKitchenStations`, `getKitchenStationById`, `saveKitchenStation`, `deleteKitchenStation`, `getMenuItemStations`, `setMenuItemStations`, `routeOrderItemsToStations`, `getKdsBoardForStation`, `advanceKdsItemStatus`, `isOrderFullyReady`, `getKdsLastUpdateTs`.
  - **Kitchen surface:** [/kds/index.php](../kds/index.php) — full-screen display with a one-shot station picker per session, real-time via SSE ([kds-sse.php](../kds/sse.php)), action endpoint [kds-action.php](../kds/action.php).
  - **Admin surface:** [/admin-kitchen.php](../admin-kitchen.php) — station CRUD + item-to-station routing matrix. CRUD endpoint [api/save-kitchen-station.php](../api/save-kitchen-station.php).
  - **Integration:** on `order.created` (both [create_new_order.php](../create_new_order.php) and [api/v1/orders/create.php](../api/v1/orders/create.php)) `routeOrderItemsToStations()` writes one `order_item_status` row per `(item slot × station)`. When every non-cancelled slot for an order lands in `ready`, [kds-action.php](../kds/action.php) dispatches the `order.ready` webhook and pings the tenant's Telegram chat.
  - **Tests:** [tests/KdsTest.php](../tests/KdsTest.php) — MySQL-gated, 8 cases: slug validation, per-slot × station routing, idempotent retry, status-machine transitions with timestamp stamps, unknown-status rejection, all-ready detection, cancelled-slot exclusion, setMenuItemStations replacement.

## Purpose

Before this module, the kitchen got new orders one of two ways:

1. Through the Telegram bot — fine for ≤10 orders/hour, quickly overwhelming at dinner rush.
2. By looking over the employee's shoulder at [/employee.php](../employee.php) — not a dedicated surface, not optimized for cooking flow.

The KDS is a dedicated surface per station: hot/cold/bar/pizza each get their own tablet, each only sees the items they actually cook, and each is responsible for flipping its own items from `queued` → `cooking` → `ready`. When every station signs off, the floor staff gets a ping: "order ready".

## Data Model

### `kitchen_stations`

| Column       | Type                  | Notes                                                               |
|--------------|-----------------------|---------------------------------------------------------------------|
| `id`         | `INT UNSIGNED AUTO_INCREMENT` | primary key                                                 |
| `label`      | `VARCHAR(64)`         | display name (e.g. «Горячий цех»)                                   |
| `slug`       | `VARCHAR(32) UNIQUE`  | `[a-z0-9_-]{1,32}` — machine id, used in URLs if we ever need them |
| `active`     | `TINYINT`             | soft on/off; retiring a station doesn't lose historical rows        |
| `sort_order` | `INT`                 | controls left-to-right order on the admin station picker            |
| `created_at` / `updated_at` | `DATETIME` | timestamps                                                    |

### `menu_item_stations` (many-to-many)

| Column        | Type                | Notes                                                                      |
|---------------|---------------------|----------------------------------------------------------------------------|
| `menu_item_id`| `INT`               | FK → `menu_items(id)` with `ON DELETE CASCADE`                             |
| `station_id`  | `INT UNSIGNED`      | FK → `kitchen_stations(id)` with `ON DELETE CASCADE`                       |
| `created_at`  | `DATETIME`          | when the mapping was set                                                   |

A dish can plate on multiple stations (pizza + drink); each station gets its own `order_item_status` row and its own "ready" flip. Items without *any* mapping still show up on the KDS — in the `unrouted` tab (`station=0` in the URL) — so nothing is silently lost while an operator forgets to route a new dish.

### `order_item_status` (one row per cook's checkbox)

| Column         | Type                  | Notes                                                                     |
|----------------|-----------------------|---------------------------------------------------------------------------|
| `id`           | `BIGINT UNSIGNED AUTO_INCREMENT` | primary key                                                    |
| `order_id`     | `INT`                 | FK → `orders(id) ON DELETE CASCADE`                                        |
| `item_index`   | `SMALLINT UNSIGNED`   | position in the `orders.items` JSON array — the project does not normalize order lines into a dedicated table, so `(order_id, item_index, station_id)` is the unique key in practice |
| `menu_item_id` | `INT NULL`            | snapshotted reference; kept nullable for historical rows when a dish is later deleted |
| `item_name`    | `VARCHAR(255) NULL`   | denormalized name so the history line survives dish renames                |
| `quantity`     | `INT`                 | copied from the order line                                                  |
| `station_id`   | `INT UNSIGNED NULL`   | FK → `kitchen_stations(id) ON DELETE SET NULL`; `NULL` = unrouted tab      |
| `status`       | `VARCHAR(16)`         | `queued` / `cooking` / `ready` / `cancelled`, enforced by `CHECK`          |
| `started_at`   | `DATETIME NULL`       | stamped on first `cooking` transition                                       |
| `ready_at`     | `DATETIME NULL`       | stamped on `ready` transition                                               |
| `created_at` / `updated_at` | `DATETIME` | timestamps                                                            |

The nullable `station_id` with `ON DELETE SET NULL` means deleting a station does not throw away the historical KDS audit trail — rows for deleted stations migrate to the `unrouted` view, where an admin can see they exist and decide what to do.

## Status Machine

Allowed transitions:

```
queued  --> cooking  --> ready      (normal cook path)
queued  --> cancelled               (order was cancelled before kitchen started)
cooking --> cancelled               (kitchen aborts during cook)
cooking --> ready                   (direct path, if no explicit "start" click)
```

Attempting to set a status outside the allowlist returns `false` from `advanceKdsItemStatus()` (and `400 invalid_status` from the HTTP endpoint). Attempting to set the same status the row already has also returns `false` — this makes "no-op click" distinguishable from "successful flip" at the HTTP layer.

## Routing Rules

`routeOrderItemsToStations()` is the one-liner hook called after every successful `createOrder()`:

1. For each item slot `i` in the order:
   - Look up `menu_item_stations` for that dish.
   - If ≥ 1 station: write one `order_item_status` row per station with `status='queued'`.
   - If 0 stations: write one row with `station_id=NULL` (unrouted).
2. Existing `(order_id, item_index, station_id)` rows are skipped — so the hook is safe to call twice (handy when a retry path runs it again after idempotency replay).

## Surfaces

### `/kds/index.php` — the kitchen tablet

- Role gate: `employee` / `admin` / `owner`.
- Session picks a station once; switch with `?station=<id>` (or `?station=-` to reset the pick). `?station=0` = unrouted tab.
- Layout: one column per order, each order is a card with its items for *this* station.
- Per item: `Начать` button in `queued` state → `cooking`; `Готово` button in `cooking` state → `ready`.
- Real-time via SSE ([kds-sse.php](../kds/sse.php)). Falls back to long-poll via `fetch()` on browsers without `EventSource`.
- Header shows tenant clock + live counter of active items on the board.

### `/admin-kitchen.php` — the admin setup

- Two panels on one page:
  1. **Stations CRUD** — create / rename / toggle active / set sort_order / delete. The last row is a "new station" form.
  2. **Routing matrix** — each menu item × each station as a checkbox grid. Toggle checkbox → `setMenuItemStations()` via debounced API call (`250 ms`), flash row green on success.

### Mobile API

KDS is deliberately *not* exposed on `/api/v1/*` in this iteration — there is no standard pattern for auth on shared-tablet devices yet. Session-based auth over the browser is sufficient for the tablet on the kitchen wall; mobile API support lands in Phase 9 alongside the developer platform.

## Order-ready trigger

`kds-action.php` calls `isOrderFullyReady($orderId)` after every successful `ready` flip:

- **True** when: at least one non-cancelled row exists AND every non-cancelled row is `ready`.
- **False** otherwise — including "all cancelled" (nothing to serve), "nothing cooked yet", or "still in progress".

On `true`:

1. Dispatch `order.ready` via `WebhookDispatcher::dispatch()` — consumers can ping a buzzer, print a runner ticket, etc.
2. Send a one-line Telegram message: `🍽 Заказ #N готов — можно подавать`.

The trigger fires **at most once** per order-ready transition. Cancelling a slot after `order.ready` does not re-fire; the webhook consumer should treat `order.ready` as idempotent from its side.

## Wire Formats

### SSE — `/kds/sse.php?station=<id>&t=<last_known_ts>`

Events:

- `event: update` — the full current board for that station. JSON payload: `{ timestamp, station_id, items }` where `items` is the array of `order_item_status` rows with their joined order info.
- `event: ping` — keep-alive every ~10 s. JSON payload: `{ timestamp }`.

Client passes `t` back on reconnect so the server can emit the next frame only when the board actually changed.

### Action — `POST /kds/action.php`

Request:

```json
{ "status_row_id": 12345, "status": "ready", "csrf_token": "<token>" }
```

Response:

```json
{ "success": true, "changed": true, "status": "ready", "order_ready": true }
```

`order_ready=true` tells the client that this was the last piece of the order — useful if it wants to flash the card or clear it aggressively without waiting for SSE.

## Security Notes

- Same CSRF contract as the rest of the admin stack — `lib/Csrf.php::requireValid()`.
- Role gate is `employee` / `admin` / `owner`. No bearer-token flow.
- `station_id=0` is the "unrouted" tab — no writes can land there from the UI; it's a read-only slice of `order_item_status WHERE station_id IS NULL`.
- The per-slot action endpoint does not allow arbitrary transitions — only `cooking` / `ready` / `cancelled` (no manual revert to `queued`). Reverting a mistakenly-flipped row requires admin DB access on purpose.

## Ops

### Applying the migration

```sql
mysql -u tenant_user -p tenant_db < sql/kds-migration.sql
```

Idempotent via `CREATE TABLE IF NOT EXISTS`. After the migration:

1. Open `/admin-kitchen.php`.
2. Create at least one station (e.g. `hot` with label «Горячий»).
3. Attach existing menu items via the routing matrix.
4. Open `/kds/index.php` on the kitchen tablet, pick the station once.

### Rollback

- UI-only rollback: comment out the `routeOrderItemsToStations()` calls in [create_new_order.php](../create_new_order.php) and [api/v1/orders/create.php](../api/v1/orders/create.php). New orders stop populating `order_item_status`; the `/kds/index.php` board becomes permanently empty but the schema and admin UI still work.
- Full rollback: `DROP TABLE order_item_status, menu_item_stations, kitchen_stations` (in that order — FKs matter).

## Test Flow

1. Apply `sql/kds-migration.sql`.
2. Create three stations in `/admin-kitchen.php`: `hot`, `cold`, `bar`.
3. Assign existing menu items — pizza → hot, salad → cold, drinks → bar. Leave one dish unrouted.
4. Open `/kds/index.php?station=<hot_id>` on one browser tab and `/kds/index.php?station=0` on another.
5. Place an order from `/menu.php` that mixes a pizza + a salad + the unrouted dish.
6. Within ~2 s:
   - Hot board shows the pizza slot.
   - Cold board shows the salad slot (open a third tab to confirm).
   - Unrouted tab shows the third dish.
7. On the hot tab click `Начать` → slot becomes `cooking`, clock turns yellow.
8. Click `Готово` → slot moves to `ready` and fades.
9. Flip the salad and the unrouted slot too → the final click fires `order.ready` webhook + Telegram ping.

## Known Gaps / Future Work

- **No multi-dish grouping.** One `queued` card per (order × slot × station). If the kitchen prefers "all slots for this order together, regardless of station", that is a Phase 6.2+ UX revision.
- **No cook-time metrics.** `started_at` / `ready_at` are stamped, but no report uses them yet. Phase 6.4 analytics surfaces per-dish avg cook-time.
- **No ack-by-cook.** The tablet has one identity per station; we don't know *which* cook flipped which slot. Phase 7.4 staff management adds per-user tracking.
- **No printer bridge.** Many kitchens want paper tickets in addition to the display. A webhook consumer can bridge `order.created` to an ESC/POS printer, but a first-party printer module is Phase 6.2 inventory-adjacent.
- **No WebSocket option.** SSE is simpler and good enough for the one-tablet-per-station model. If we ever need bidirectional push (e.g. floor → kitchen "hurry"), revisit.

## Related Docs

- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — order statuses that the KDS overlays on top of.
- [webhook-integration.md](./webhook-integration.md) — the `order.ready` event fired when the board is fully green.
- [admin-menu-ux.md](./admin-menu-ux.md) — admin UX conventions the admin-kitchen page reuses.
- [product-vision-2027.md](./product-vision-2027.md) §3 — Phase 6 scope.
