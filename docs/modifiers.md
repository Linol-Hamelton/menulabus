# Menu Item Modifiers

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-11`
- Verified against code: [sql/modifiers-migration.sql](../sql/modifiers-migration.sql), [api/save-modifiers.php](../api/save-modifiers.php), [js/admin-modifiers.js](../js/admin-modifiers.js), [js/menu-modifiers.js](../js/menu-modifiers.js), [db.php](../db.php), [menu-content.php](../menu-content.php), [menu-content-info.php](../menu-content-info.php), [menu-alt.php](../menu-alt.php).

## Purpose

"Modifiers" are per-item option groups — pizza size, bread type, sauce pick, side-salad add-ons — that the customer configures at the moment of adding an item to cart. They change the line price but never create a different product row. This document describes the data model, the admin editor, the customer-facing modal, and how modifier selections flow into the cart and the order.

## Data model

Applied via [sql/modifiers-migration.sql](../sql/modifiers-migration.sql). Two linked tables:

| Table | Columns | Notes |
|---|---|---|
| `modifier_groups` | `id`, `item_id` (FK `menu_items.id` ON DELETE CASCADE), `name` (≤100), `type` ENUM(`radio`,`checkbox`), `required` TINYINT, `sort_order` INT | One group = one decision the customer makes (e.g. "Размер"). `radio` = pick exactly one; `checkbox` = pick any number including zero. |
| `modifier_options` | `id`, `group_id` (FK `modifier_groups.id` ON DELETE CASCADE), `name` (≤100), `price_delta` DECIMAL(10,2), `sort_order` INT | One option = one choice inside a group. `price_delta` is **added** to the base item price and can be `0.00`, positive, or negative (for "без сыра −50" style discounts). |

FKs cascade on delete, so removing a menu item cleans up its groups and options in one go.

## Admin editor

Exposed in `admin-menu.php` under each item's edit panel. The UI is driven by [js/admin-modifiers.js](../js/admin-modifiers.js) and talks to [api/save-modifiers.php](../api/save-modifiers.php) over JSON.

### API contract ([api/save-modifiers.php](../api/save-modifiers.php))

- Method: `POST`
- Auth: `$required_role = 'admin'` → admin or owner only.
- CSRF: `X-CSRF-Token` header or `csrf_token` body field, compared with `hash_equals()`.
- Body: JSON with an `action` discriminator.

| `action` | Required fields | Behavior |
|---|---|---|
| `save_group` | `item_id`, `name`, `type`, `required`, `sort_order`, optional `group_id` | Upsert. `group_id: null` creates; numeric id updates. Returns `{success, group_id}`. |
| `delete_group` | `group_id` | Deletes the group; cascades to its options. |
| `save_option` | `group_id`, `name`, `price_delta`, `sort_order`, optional `option_id` | Upsert option inside a group. |
| `delete_option` | `option_id` | Deletes a single option. |
| `get` | `item_id` | Returns `{success, groups: [...]}` for initial hydration. |

Validation: `name` is trimmed and clipped to 100 chars, `type` falls back to `radio` if not in the whitelist, numeric fields are cast. HTTP 400 on missing `item_id` / `group_id` / `name`. HTTP 403 on CSRF mismatch. HTTP 405 on non-POST.

### Database methods ([db.php](../db.php))

- `saveModifierGroup(int $itemId, ?int $groupId, string $name, string $type, bool $required, int $sortOrder): int|false`
- `deleteModifierGroup(int $groupId): bool`
- `saveModifierOption(int $groupId, ?int $optionId, string $name, float $priceDelta, int $sortOrder): int|false`
- `deleteModifierOption(int $optionId): bool`
- `getModifiersByItemId(int $itemId): array` — used both by the admin `get` action and by menu-rendering PHP for hydrating the `data-modifiers` attribute.

## Customer flow — add-to-cart with modifiers

### Render path (menu pages)

Three menu templates hydrate modifiers onto buy buttons: [menu-content.php](../menu-content.php), [menu-content-info.php](../menu-content-info.php), and [menu-alt.php](../menu-alt.php). Each iterates items, fetches `$itemMods = $db->getModifiersByItemId($item['id'])`, and — only if non-empty — serializes the groups+options array into a `data-modifiers` attribute on the `.buy` element:

```php
<?php if ($itemMods): ?>
    data-modifiers="<?= htmlspecialchars(json_encode($itemMods, JSON_UNESCAPED_UNICODE)) ?>"
<?php endif; ?>
```

Items without modifiers get no attribute and skip the modal entirely — no overhead on simple items.

### Modal interception ([js/menu-modifiers.js](../js/menu-modifiers.js))

`js/menu-modifiers.js` installs a **capture-phase** click listener on `.buy-text` to run *before* the existing cart-add handler. On click:

1. It reads `data-modifiers` from the nearest `.buy` ancestor.
2. If absent → returns immediately, letting the normal add-to-cart fire.
3. If present → calls `event.stopPropagation()` + `preventDefault()` and opens the modifier modal.
4. The modal renders each group: `radio` groups pre-select the first option, `checkbox` groups start empty. Selecting options updates a live price readout.
5. On `Добавить`: validates that every `required: true` group has at least one option selected (shows inline error if not), computes `totalPrice = basePrice + sum(price_delta)`, and calls `addToCartWithModifiers(item, totalPrice, selectedNames)`.
6. `addToCartWithModifiers` forwards into the existing cart API with the chosen names appended to the item name (e.g. `Пицца Маргарита (30 см, без сыра)`) so the line appears as a distinct cart entry.

Because selections are merged into the **cart item name**, two identical adds with different modifier combos stay as two separate cart lines — no quantity collapse.

### Visual notes

- Modal reuses the `.delivery` / `.delivery-content` / `.tips-options` classes from the cart/tips stylesheet to keep styling consistent and avoid new CSS.
- Group label: `<div class="payment-label">{name}{ required ? ' *' : '' }</div>`.
- Each option button is a `.tips-option` with `.active` when selected.

## Order storage

Modifier selections are **not** stored as a structured field on `orders.items`. They are embedded into the item's `name` column as a parenthesized suffix at add-to-cart time. Consequences:

- The Telegram order card (see [telegram-bot-setup.md](./telegram-bot-setup.md)) shows the modifier text inline, no extra work needed.
- The employee order view, email receipts, and mobile API `order_items.name` all already carry the modifier info.
- Revenue reports aggregate by item name including modifiers — if you need to answer "how many pizzas were sold regardless of size", you need to substring-match the base name or add a structured column.
- Price reconciliation relies on the line's `price` field (already inclusive of `price_delta`), not on any server-side re-computation of the modifier total. This means the client is trusted for the price at the moment of cart add; the server does not re-verify the modifier math on order create.

## Common failure modes

| Symptom | Likely cause | Where to look |
|---|---|---|
| Modal never opens; item is added directly with base price | `data-modifiers` attribute not rendered because `$itemMods` is empty, or because the template being used does not hydrate it | `getModifiersByItemId()` result for the item; confirm the page is one of the three known templates |
| Modal opens but `Добавить` button does nothing | A `required: true` group has no selection — validation error toast is shown; for radio groups this can happen if the first option was deselected by a bug | js/menu-modifiers.js → `collectSelections()` |
| Price in modal differs from cart line price | Client-side price math in `menu-modifiers.js` disagrees with the browser cart state. Usually caused by double-applied `price_delta` if someone wires a second listener | menu-modifiers.js → `updatePrice()` / `addToCartWithModifiers()` |
| Admin UI saves a group but options fail | Two separate endpoints — group save succeeded, option save failed. Check CSRF and the exact HTTP status of the second call | api/save-modifiers.php; network panel |
| "Похожий" cart items merge into one | Modifier selections were empty strings so the suffix did not differentiate the lines | add-to-cart payload in devtools; confirm non-empty `selectedNames` |

## Related docs

- [telegram-bot-setup.md](./telegram-bot-setup.md) — the Telegram order card that renders modifier-annotated line names.
- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — how orders with modifier lines move through the state machine (they don't behave differently from plain orders).
- [openapi.yaml](./openapi.yaml) — mobile `POST /api/v1/orders/create.php` schema; modifier-annotated names are accepted as normal item names.
