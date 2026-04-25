# Inventory MVP

## Implementation Status

- Status: `Implemented` (Phase 6.2 MVP).
- Last reviewed: `2026-04-23`
- Current state:
  - **Storage:** [sql/inventory-migration.sql](../sql/inventory-migration.sql) — `suppliers`, `ingredients`, `recipes`, `stock_movements`.
  - **DB layer:** [db.php](../db.php) — `listIngredients`, `getIngredientById`, `saveIngredient`, `archiveIngredient`, `restoreIngredient`, `adjustIngredientStock`, `listSuppliers`, `saveSupplier`, `getRecipeForMenuItem`, `setRecipeForMenuItem`, `deductIngredientsForOrder`, `listLowStockIngredients`, `markIngredientsAlerted`, `getStockMovementsForIngredient`.
  - **Admin surfaces:** [/admin-inventory.php](../admin-inventory.php) — ingredients table (inline edit + `±` adjust + history drawer), suppliers mini-table, low-stock banner. Recipe editor embedded in [/admin-menu.php](../admin-menu.php) per-item (appears under modifiers block).
  - **CRUD endpoint:** [api/save-inventory.php](../api/save-inventory.php) — 10 actions (`list_ingredients`, `list_suppliers`, `save_ingredient`, `archive_ingredient`, `restore_ingredient`, `adjust_stock`, `list_movements`, `save_supplier`, `get_recipe`, `set_recipe`).
  - **Integration:** on `order.created` (both [create_new_order.php](../create_new_order.php) and [api/v1/orders/create.php](../api/v1/orders/create.php)) `deductIngredientsForOrder()` subtracts the recipe across all item slots, writes audit rows, and returns newly-low stock ids. Session path pings Telegram + webhook `inventory.stock_low`; API-v1 path sends only the webhook (same policy as `order.created` webhook event — no Telegram spam on mobile-created orders).
  - **Throttling:** `markIngredientsAlerted($ids, 60)` stamps `last_alerted_at` so the same ingredient does not fire an alert more than once per 60 minutes even under rapid dinner-rush deductions.
  - **Tests:** [tests/InventoryTest.php](../tests/InventoryTest.php) — MySQL-gated, 10 cases: input validation, duplicate-dish aggregation, low-stock detection, skipped items, transactional deduct, alert throttling, adjust_stock audit, zero-threshold exclusion, recipe replacement, archive/restore.

## Purpose

Turn the stop-list from a blunt "flip this dish off" toggle into a fine-grained ingredient-level model. The kitchen no longer has to guess when to un-flip "Пепперони" — it un-flips itself automatically when tomato paste is replenished, and the stop-list trigger becomes "какого ингредиента стало мало", not "какое блюдо закончилось".

Secondary goal: feed the Phase 6.4 analytics track with real cost-of-goods-sold data without asking owners to type in cost per dish by hand — cost × recipe quantity × order count is enough.

## Data Model

### `suppliers`

| Column       | Type                                | Notes                                                              |
|--------------|-------------------------------------|--------------------------------------------------------------------|
| `id`         | `INT UNSIGNED AUTO_INCREMENT`       | primary key                                                        |
| `name`       | `VARCHAR(255)`                      | display name                                                       |
| `contact`    | `VARCHAR(255) NULL`                 | freeform (phone / email / Telegram handle)                         |
| `notes`      | `TEXT NULL`                         | internal notes                                                      |
| `archived_at`| `DATETIME NULL`                     | soft-delete so ingredients retain the reference                    |

Ingredient ⇢ supplier is `ON DELETE SET NULL` — removing a supplier never orphans an ingredient.

### `ingredients`

| Column              | Type                  | Notes                                                           |
|---------------------|-----------------------|-----------------------------------------------------------------|
| `id`                | `INT UNSIGNED`        | primary key                                                      |
| `name`              | `VARCHAR(255)`        | display name                                                     |
| `unit`              | `VARCHAR(16)`         | free-form label (`г`, `мл`, `шт`). Recipes and movements share the same unit per ingredient. |
| `stock_qty`         | `DECIMAL(12,3)`       | authoritative current stock                                      |
| `reorder_threshold` | `DECIMAL(12,3)`       | `≤` triggers low-stock alert; `0` disables the alert             |
| `cost_per_unit`     | `DECIMAL(10,4)`       | used by Phase 6.4 margin reports                                  |
| `supplier_id`       | `INT UNSIGNED NULL`   | soft reference                                                    |
| `archived_at`       | `DATETIME NULL`       | soft-delete                                                        |
| `last_alerted_at`   | `DATETIME NULL`       | throttle field for low-stock alerts                               |

### `recipes` (menu_item ↔ ingredient, many-to-many)

| Column        | Type                  | Notes                                              |
|---------------|-----------------------|----------------------------------------------------|
| `menu_item_id`| `INT`                 | FK to `menu_items(id) ON DELETE CASCADE`          |
| `ingredient_id`| `INT UNSIGNED`       | FK to `ingredients(id) ON DELETE CASCADE`         |
| `quantity`    | `DECIMAL(10,3)`       | enforced `> 0` via `CHECK`                         |

Primary key `(menu_item_id, ingredient_id)` — one dish never has two rows for the same ingredient.

### `stock_movements` (append-only audit)

| Column        | Type                  | Notes                                                                     |
|---------------|-----------------------|---------------------------------------------------------------------------|
| `id`          | `BIGINT UNSIGNED`     | primary key                                                                |
| `ingredient_id`| `INT UNSIGNED`       | FK                                                                          |
| `delta`       | `DECIMAL(12,3)`       | positive = in, negative = out                                              |
| `reason`      | `VARCHAR(32)`         | CHECK allowlist: `order`, `adjustment`, `receipt`, `waste`, `stocktake`, `undo` |
| `note`        | `VARCHAR(255) NULL`   | free text                                                                   |
| `order_id`    | `INT NULL`            | FK to `orders(id) ON DELETE SET NULL` — survives order cleanup             |
| `menu_item_id`| `INT NULL`            | which dish triggered an `order` deduction                                  |
| `created_by`  | `INT NULL`            | user_id of the operator who applied the adjustment                         |

## Deduction Contract

`deductIngredientsForOrder($orderId, $items)` is the one hook called after every successful `createOrder()`:

1. Build a **per-ingredient aggregate** across all item slots: if "Pizza ×2" and "Pizza ×1" appear in the same order, the ingredient subtract is summed to 3× recipe before the UPDATE fires.
2. Wrap the whole write in one transaction. Either every ingredient for every dish gets deducted, or nothing does. A partial deduct would poison the audit log — it would show half the movements with no way to reconcile against the order total.
3. After each ingredient UPDATE, re-select stock_qty and compare to reorder_threshold. Ingredients that *just* crossed into low-stock are returned to the caller so the alert path runs outside the transaction.

Items with `menu_item_id` but no recipe are silently ignored — no movement rows written. This keeps a "just-added dish without a recipe yet" from polluting the audit log with empty deductions.

## Low-Stock Alerting

The alert pipeline has two stages, mirroring the same pattern [webhook-integration.md](./webhook-integration.md) uses for delivery retries:

1. **Detection** — `deductIngredientsForOrder()` returns the newly-low ids. (Ingredients that were *already* below threshold before this order do not re-fire — "newly" means the transition happened in this transaction.)
2. **Throttling** — `markIngredientsAlerted($ids, 60)` updates `last_alerted_at = NOW()` but only for ingredients where it was NULL or older than 60 minutes. It returns the subset of ids that actually passed. Callers send alerts only for the returned subset.

This keeps Telegram and webhook consumers quiet during a dinner rush: if "Мука" is at 50g below a 100g threshold, we alert once, not every pizza.

## Admin Surfaces

### `/admin-inventory.php`

- Role: `admin` / `owner`.
- Header banner when ≥1 ingredient is low-stock — a chip for each one so the issue is visible without scanning the table.
- Ingredients table: inline-editable name / unit / threshold / cost / supplier. Save persists the row; the stock column is read-only — changes go through the `±` adjust field (+ note).
- History drawer: per-ingredient, last 100 movements. In/out colored differently. Order id and menu item id are linked when present.
- Suppliers mini-table below.

### Recipe editor (inside `/admin-menu.php`)

Appears in the per-item editing panel (only when editing an existing item, same UX rule as the modifiers section). Inline list of `{ингредиент, количество, ед}` + "+" row to add, per-row "×" to remove. Save sends the full set — any row missing from the payload is removed from the recipe (same replacement contract as `setMenuItemStations` in KDS).

## Mobile API

Not exposed in this iteration. Same rationale as KDS: there's no authenticated tablet flow to target. Ingredient CRUD for headless integrations goes through the provider-only `api/save-inventory.php` for now; a real `/api/v1/inventory/*` surface lands alongside the Phase 9 developer platform.

## Ops

### Applying the migration

```sql
mysql -u tenant_user -p tenant_db < sql/inventory-migration.sql
```

Idempotent via `CREATE TABLE IF NOT EXISTS`. After the migration:

1. Open `/admin-inventory.php`.
2. Optionally create suppliers first.
3. Create ingredients (name + unit + current stock + threshold + cost).
4. For each menu item, open the edit view in `/admin-menu.php` and fill the "Рецепт" block.
5. Confirm Telegram + webhook settings include the tenant chat id and (optionally) a subscription for `inventory.stock_low`.

### Rollback

- UI/integration rollback: comment out the `deductIngredientsForOrder()` block in [create_new_order.php](../create_new_order.php) and [api/v1/orders/create.php](../api/v1/orders/create.php). The `/admin-inventory.php` page and recipe editor remain functional; orders just stop touching the stock column.
- Full rollback: `DROP TABLE stock_movements, recipes, ingredients, suppliers` (in that order — FKs matter).

## Test Flow

1. Apply `sql/inventory-migration.sql`.
2. Create an ingredient «Мука», stock 1000 г, threshold 800 г, cost 0.5 ₽/г.
3. Open a menu item «Пицца Маргарита» in `/admin-menu.php`, set the recipe to 250 г муки; save.
4. Place a single «Маргарита» order from `/menu.php`.
5. Reopen `/admin-inventory.php`:
   - «Мука» shows `750 г` (1000 − 250), no low-stock banner yet.
6. Place another order of 2× Margherita.
7. `/admin-inventory.php`:
   - «Мука» shows `250 г`, banner lights up (below 800 threshold).
   - Telegram receives: `⚠️ Низкий остаток: Мука — 250 г (порог 800)`.
   - Webhook subscribers to `inventory.stock_low` get the ingredient payload.
8. Place a 3rd order within the hour — Telegram does NOT re-fire (60-min cooldown).
9. In `/admin-inventory.php` click «+500» for Мука with reason `receipt` → stock 750, movement row written.

## Known Gaps / Future Work

- **No multi-recipe dish variants.** A "Pizza with extra cheese" modifier option does not change the recipe — we deduct the base recipe regardless. A future iteration can extend `recipes` with `modifier_option_id NULL` for option-specific deductions.
- **No receipt/purchase UI.** Increasing stock today goes through the `±` adjust field. A proper "Приёмка" screen with supplier + multiple ingredients in one transaction is Phase 6.2+ follow-up.
- **No forecast.** Phase 6.4 analytics will do the weekly "ingredient X will run out in Y days" prediction.
- **No composite ingredients.** If "Тесто" is made in-house from muck + water + yeast, we'd want to deduct those at prep time, not at order time. Out of scope for the MVP.
- **No snapshot on order.** `stock_movements.delta` is trusted as the truth. A parallel `orders.items_cost` column would let owner margin reports freeze COGS even after ingredient cost edits — Phase 6.4 decision.
- **Telegram pings land in the single `telegram_chat_id` setting.** A dedicated "склад" chat is not supported; operators self-organize via a group chat.

## Related Docs

- [webhook-integration.md](./webhook-integration.md) — the `inventory.stock_low` event surface.
- [kds.md](./kds.md) — kitchen display, fires the companion `order.ready` event.
- [admin-menu-ux.md](./admin-menu-ux.md) — admin UI conventions the recipe editor reuses.
- [product-vision-2027.md](./product-vision-2027.md) §3 — Phase 6 scope.
