# Admin Menu UX

## Implementation Status

- Status: `Implemented` (5.1–5.5 + Phase 16 modal editor + image picker).
- Last reviewed: `2026-05-04`

## Phase 16 — modal editor + image picker (v2.1.0, 2026-05-04)

### What changed

- **Inline-форма редактирования вынесена в модальное окно.** Раньше форма «Обновление» жила прямо на `/admin/menu.php` под таблицей; чтобы отредактировать блюдо, нужно было скроллить вниз. Теперь — `<dialog id="dishEditorModal">` с 5 табами (Основное / Изображение / Модификаторы / Рецепт / Превью). Открывается по клику «Редактировать» (`.js-edit-dish`) или «+ Создать блюдо» (`.js-new-dish`).
- **AJAX submit** через новый [api/save-menu-item.php](../api/save-menu-item.php). Actions: `get` (загрузить блюдо), `save` (create / update — flat fields), `archive`, `restore`, `inline_save` (single field — used by inline price edit).
- **Image picker с миниатюрами** ([js/admin-image-picker.js](../js/admin-image-picker.js)) — обходит все подпапки `/images/*` через `/file-manager.php?action=list`, рендерит grid миниатюр с lazy-load, click → set value, search-by-filename, drag-drop upload через `/file-manager.php?action=upload`. Кнопка «Без изображения» для очистки.
- **Live preview карточки** на отдельном табе и как side-rail в режиме «Основное» — dish-card в той же DOM-структуре что customer-facing menu, обновляется на каждый input event.
- **Миниатюры в таблице** — рядом с названием блюда показывается `<img class="dish-thumb">` 32×32 (или [/images/icons/dish-placeholder.svg](../images/icons/dish-placeholder.svg) если image не задан). Помогает сканировать каталог глазами.
- **Inline-edit цены** — клик по ячейке `.js-inline-price` → input → Enter/blur сохраняет через `inline_save`, Escape отменяет. Endpoint валидирует price ≥ 0, возвращает обновлённое значение.
- **Modifiers / Recipe lazy-init** — при первом открытии соответствующего таба модалки. `window.AdminModifiers.init(root, itemId)` и `window.AdminRecipe.init(root, itemId)` — публичный API, переиспользуется существующая CRUD-логика.
- **db.php `addMenuItem`** теперь возвращает `int|false` вместо `bool` — нужно endpoint'у для отдачи нового id.

### Files (Phase 16)

- New: [api/save-menu-item.php](../api/save-menu-item.php), [js/admin-menu-modal.js](../js/admin-menu-modal.js), [js/admin-image-picker.js](../js/admin-image-picker.js), [css/admin-menu-modal.css](../css/admin-menu-modal.css), [images/icons/dish-placeholder.svg](../images/icons/dish-placeholder.svg).
- Modified: [admin/menu.php](../admin/menu.php) (modal markup, table thumb + inline-price classes, button replacements, removed inline editor), [js/admin-modifiers.js](../js/admin-modifiers.js) (public init API), [js/admin-recipe.js](../js/admin-recipe.js) (public init API), [js/admin-menu-page.js](../js/admin-menu-page.js) (inline-price handler), [db.php](../db.php) (`addMenuItem` returns id).

### Phase 17.1 — fix layout collapse (v2.2.1, 2026-05-05)

Сразу после релиза 2.2.0 на проде обнаружено: parent `.admin-design-panel` это 12-column grid (admin-menu-polish.css), и Phase 17 ввёл прямых детей (`.admin-design-plates`, `.design-reset-row`, project-name, 5 `<dialog>`) без явного `grid-column` → auto-place в одну 1/12 ячейку (~95px). Plates стекались 1-колонкой, reset disclosure плавал сбоку. Одно CSS-правило `grid-column: 1 / -1` для всех Phase 17 прямых детей `.admin-design-panel` фиксит layout. Plate min-width поднят 220→240px для читаемости.

### Phase 17 — Дизайн tab modal-editor overhaul (v2.2.0, 2026-05-05)

Раньше таб «Дизайн» был один длинный scroll: Project name → Files browser → Brand (13 inputs) → Fonts → Colors (12 пикеров) → Save buttons. На мобильном — 4-5 экранов прокрутки. Phase 17 разбивает это на 5 plate-карточек:

- **🏷️ Бренд** — модалка с 3 sub-tabs (Identity / Контакты / Домен) + side-rail live preview карточки бренда (logo + название + слоган + телефон + адрес + map-link). Reuses `/api/save/brand.php`.
- **Aa Шрифты** — модалка с 3 selects + override-checkboxes; side-rail показывает 3 строки в выбранных шрифтах (Логотип / Текст / Заголовок). Reuses `/api/save/fonts.php`.
- **■ Цвета** — модалка с 4 preset palette swatches («Классический / Тёмный / Свежий / Свой»), 3 ключевых цвета (Primary/Secondary/Accent) видны сразу, остальные 9 в `<details>` «Дополнительные цвета». Side-rail — палитра + sample card. Click preset → все 12 пикеров обновляются. Manual edit → active swatch становится «Свой». Reuses `/api/save/colors.php`.
- **📁 Файлы** — модалка с browse Images/Fonts/Icons, file list, upload. Markup перенесён 1-в-1, file-manager.min.js handlers работают по тем же IDs.
- **🚀 Launch readiness** — read-only modal с checklist (OK/Check badges).

«Название проекта» остаётся inline над plates — это 1-line input, не нуждается в модалке.

**Plate status-summary:**
- Бренд → «labus · Меню ресторана» + warning-dot если launch checklist нашёл проблемы.
- Шрифты → «Magistral · Proxima · Inter».
- Цвета → primary hex + плитка-иконка с linear-gradient двух цветов (primary+accent) через JS-set CSS-vars.
- Файлы → «Images · Fonts · Icons».
- Launch → «N предупреждений» / «Всё в порядке».

Summary-spans обновляются через JS после каждого save без reload.

**Reuse:**
- Phase 16 `<dialog>` shell + FocusTrap + `[data-modal-close]` паттерн.
- `tenantLaunchAudit()` (lib/tenant/launch-contract.php) рендерит checklist дважды — на plate (count) и в Launch modal (full list).
- Все save-endpoints без изменений: `/api/save/brand.php`, `/api/save/fonts.php`, `/api/save/colors.php`, `/api/save/project-name.php`.

**CSP-cleanliness:**
- 0 inline `style="..."` атрибутов.
- 0 inline event-handlers.
- Plate icon swatch + preset swatches рендерятся через JS из `data-color-primary`/`data-color-accent`/`COLOR_PRESETS` (CSS-variables через `element.style.setProperty()`).

### Phase 16.2 — mobile fixes (v2.1.2, 2026-05-04)

- **Tabs скрывались на mobile.** Modal-card имел `grid-template-rows: auto 1fr auto` — 3 явных строки для 4 детей (head, tabs, body, foot). Body уходил в неявную auto-row и брал всю высоту контента; tabs (1fr) сжимались до ~10px. Исправлено: `grid-template-rows: auto auto 1fr auto` — body становится scroll-container, tabs всегда видны.
- **Footer cancel button не закрывал модалку.** В JS использовался `querySelector('.modal-close')` (single result) — возвращал X-кнопку в header, footer button с тем же классом оставался без listener. Перенесли trigger на `[data-modal-close]` + delegated click handler ловит и `.modal-close`, и `[data-modal-close]`.
- **Cancel button стиль.** Прежний `.admin-checkout-btn cancel modal-close` накладывал `.modal-close` (круглый icon-button 36x36, border-radius 50%) на text label — выходила странная гибридная кнопка. Заменён на отдельный класс `.btn-cancel-modal` (outline, transparent bg, border, матчит высоту `.checkout-btn`).

### Phase 16.1 — fixups (v2.1.1, 2026-05-04)

- **Preview rail bug.** `updatePreview()` had `querySelector('.preview-card')` (first match), but the modal renders TWO preview cards (side-rail aside + «Превью» tab pane). On desktop tab=main the visible one was the side-rail, but JS was updating the hidden tab-pane copy. Switched to `querySelectorAll` + iterate.
- **Cancel button.** Footer cancel button now uses `admin-checkout-btn cancel` (outline + muted) instead of filled.
- **CSP cleanliness — inline styles/scripts removed.** `onerror="this.src='…'"` on `.dish-thumb` → `data-fallback-src` + delegated capture-phase listener in `js/admin-menu-page.js` (`bindThumbFallback()`). `style="display:none"` everywhere → `hidden` HTML attribute. `element.style.display = '…'` in `updatePreview` / `updateImageSummary` / picker-filter → `element.hidden = bool`.
- **Side-rail visibility.** Hidden on mobile (<1024px) — avoids duplicate preview below form. Hidden on non-main tabs on desktop — picker/modifiers/recipe/preview need full width.
- **`[hidden]` defensive rule.** Added `.dish-editor-modal [hidden] { display: none !important; }` to ensure hidden beats grid/flex parents.

### Out of scope (Phase 16)

- Bulk image assignment (выбрать N items → массовая установка одной картинки).
- Categories chip manager.
- Composition autocomplete from inventory ingredients.
- Mobile swipe-to-archive.
- Auto-save draft при закрытии модалки.
- Audit log изменений позиции.

### Reference



This document captures the UX niceties shipped on top of [admin-menu.php](../admin-menu.php). It's a living doc — each sub-track (bulk actions, hotkeys, richer filters, soft-delete undo) gets a section as it lands.

## 5.1 Drag-n-drop item ordering

### What changed
- New column `sort_order INT NOT NULL DEFAULT 0` on `menu_items` with a composite index `(category, sort_order, name)`. Migration in [sql/menu-sort-order-migration.sql](../sql/menu-sort-order-migration.sql).
- The listing query (`Database::getMenuItems`, `Database::getArchivedMenuItems`) now orders by `category, sort_order, name` — so an untouched menu still reads alphabetically (all rows start at `sort_order=0`), and any explicit reorder wins.
- New desktop-only drag handle (`⋮⋮`) in the first column of the admin menu table. Rows are `draggable="true"`; dropping a row above or below another row in the **same category** rewrites positions.
- New method `Database::updateMenuItemsOrder(array $idToPosition): bool` — transactional batch UPDATE, invalidates the Redis menu cache via `invalidateMenuCache()` on success.
- New endpoint [api/save/menu-order.php](../api/save/menu-order.php) — admin/owner role gate + CSRF via [lib/Csrf.php](../lib/Csrf.php), refuses cross-category reorders by checking each submitted id's `category` against the request `category` field.
- New UI: [js/admin-menu-sort.js](../js/admin-menu-sort.js) (vanilla HTML5 DnD, 500 ms debounce on save), [css/admin-menu-sort.css](../css/admin-menu-sort.css) (drag handle, drop indicator, toast).

### What is explicitly out of scope in this iteration
- **Cross-category drops.** Dragging a Pizza row into the Drinks category does nothing (the `dragover` handler bails when categories differ). Moving items between categories is the job of track 5.2 (bulk actions → "move to category").
- **Mobile drag.** The handle is hidden under 600 px — HTML5 DnD does not fire on touch reliably, and retrofitting a touch fallback isn't worth the code for the one tablet use case. Mobile operators edit order on desktop.
- **Archived items.** Archived rows stay sorted by `(category, name)` — `.sortable-row` is not applied when `$showArchived` is true. Restoring an item from the archive lands it at its stored `sort_order`.

### Request shape

```
POST /api/save/menu-order.php
Content-Type: application/json
X-CSRF-Token: <token>

{
  "category": "Пицца",
  "order": [
    { "id": 12, "position": 0 },
    { "id":  7, "position": 1 },
    { "id": 19, "position": 2 }
  ],
  "csrf_token": "<token>"
}
```

Responses:
- `200 { success: true, updated: N }` — all rows updated.
- `400 cross_category_reorder_refused` — at least one id does not belong to `category`. `meta` includes the offending id and its actual category.
- `400 invalid_params` / `invalid_body` / `no_valid_entries` — malformed request.
- `403` — CSRF mismatch or insufficient role.
- `500 db_failure` — transaction rolled back; position unchanged.

### Applying the migration

MySQL 8.0+:

```sql
-- from repo root
mysql -u tenant_user -p tenant_db < sql/menu-sort-order-migration.sql
```

MySQL 5.7 does not support `IF NOT EXISTS` on `ADD INDEX` / `ADD COLUMN`. On 5.7 run the two statements manually and wrap with `SHOW COLUMNS`/`SHOW INDEX` guards if a second run is possible.

### Test flow

1. Open `/admin-menu.php` as owner.
2. Switch to a category with ≥ 3 items.
3. Drag the bottom item to the top. Toast "Порядок сохранён (N)" appears within ~500 ms.
4. Reload the page. The dragged item remains at the top.
5. Open the public `/menu.php` — the ordering carries through (same `ORDER BY` clause).
6. In DevTools → Network → POST `api/save/menu-order.php`:
   - Strip the CSRF header and body → expect `403`.
   - Send a valid request but with a mismatched `category` → expect `400 cross_category_reorder_refused`.

### Rollback

- UI-only rollback: comment out the two `<link>` / `<script>` tags for `admin-menu-sort.css` / `admin-menu-sort.js` in [admin-menu.php](../admin-menu.php) and the listing reverts to static rendering.
- Full rollback: revert the `ORDER BY category, sort_order, name` clauses in [db.php](../db.php) back to `ORDER BY category, name`. The `sort_order` column stays put — it's harmless without the ORDER BY referencing it.
- Schema rollback: `ALTER TABLE menu_items DROP COLUMN sort_order, DROP INDEX idx_menu_items_category_sort;` (only if the column is never queried again).

## 5.2 Bulk actions

### What changed
- New checkbox column in the admin menu table head and body (only in the active view — archived rows don't get bulk actions).
- Sticky action bar above the table (`#bulkActionBar`) appears as soon as ≥1 row is selected. Buttons: **Показать / Скрыть (стоп) / Архивировать / Сбросить**, plus a **Перенести в категорию** `<select>` pre-filled with existing categories.
- New endpoint [bulk-menu-action.php](../bulk-menu-action.php) — admin/owner gate + `Csrf::requireValid()`, action allowlist (`hide` / `show` / `archive` / `move`), 500-row cap per request.
- Three new DB methods: `Database::bulkSetMenuItemsAvailable(ids, bool)`, `Database::bulkMoveMenuItemsToCategory(ids, category)` (also resets `sort_order` to 0 on move so the item lands at the top of the new category and can be drag-repositioned immediately), `Database::bulkArchiveMenuItems(ids)`. Each runs inside a single transaction and invalidates the Redis menu cache on success. All three skip `archived_at IS NOT NULL` rows — archive state is managed by archive/restore only.
- UI: [js/admin-menu-bulk.js](../js/admin-menu-bulk.js) (select-all, indeterminate checkbox state, confirm for destructive actions, page reload on success with a short delay so the toast is visible). CSS: [css/admin-menu-bulk.css](../css/admin-menu-bulk.css).

### Request shape

```
POST /bulk-menu-action.php
Content-Type: application/json
X-CSRF-Token: <token>

{
  "action":   "hide",
  "ids":      [12, 17, 42],
  "category": "Десерты",         // required only when action === "move"
  "csrf_token": "<token>"
}
```

Responses:
- `200 { success: true, action, affected }` — `affected` is rows actually changed; may be smaller than `ids.length` when some rows were already in the target state or archived.
- `400 invalid_action` / `empty_ids` / `no_valid_ids` / `too_many_ids` (>500) / `missing_or_invalid_category`.
- `403` — CSRF or role.
- `500 db_failure` — transaction rolled back.

### Test flow
1. Open `/admin-menu.php` as owner, category with ≥3 items.
2. Tick the top-of-table checkbox — all rows select, action bar appears, counter shows the total.
3. Click **Скрыть** → toast "Готово. Затронуто: N" → page reloads → all rows have `available=0`.
4. Select 2 rows, use **Перенести в категорию** → pick another category → confirm → rows move and their `sort_order` resets to 0 (verify via drag ordering or `SELECT`).
5. Select 1 row, **Архивировать** → confirm → row disappears from the active view and shows up at `?view=archived`.
6. DevTools: strip the CSRF header → expect `403`. Submit 501 ids → expect `400 too_many_ids`.

### Rollback
- UI-only rollback: comment out the `<link>` / `<script>` tags for `admin-menu-bulk.*` in [admin-menu.php](../admin-menu.php).
- Endpoint rollback: delete [bulk-menu-action.php](../bulk-menu-action.php). The three DB methods stay harmless.

## 5.3 Hotkeys

### What changed
- New shared module [js/hotkeys.js](../js/hotkeys.js) and overlay styles [css/hotkeys.css](../css/hotkeys.css).
- Loaded on [admin-menu.php](../admin-menu.php), [owner.php](../owner.php), [employee.php](../employee.php) — the three admin surfaces where daily editing happens.
- Default bindings (a key is ignored while typing in an `<input>` / `<textarea>` / `[contenteditable]`, unless the binding opts in with `allowInField: true`):

| Key       | Action                                                                     | `allowInField` |
|-----------|----------------------------------------------------------------------------|----------------|
| `?`       | Toggle the hotkeys help overlay                                             | no             |
| `Esc`     | Close any open modal (help overlay, `#payLinkModal`, `#webhookHistoryPanel`, `.modal.open`, `.modifier-modal.open`) | yes |
| `/`       | Focus the first `input[type="search"] / [name="search"] / [name="q"] / .search-input` | no |
| `n`       | Focus the element with `[data-hotkey-new]`, falling back to `#addItemForm input[name="name"]` | no |

### Extension API

Pages can register their own bindings before any keypress fires:

```js
window.CleanmenuHotkeys.register({
    key: 'r',
    keyLabel: 'r',
    description: 'Обновить список заказов',
    allowInField: false,
    handler: function (event) {
        document.getElementById('refreshOrdersBtn')?.click();
        return true; // true = preventDefault
    },
});
```

Bindings with a `description` show up in the `?` overlay automatically.

### Discovery for users

- `data-hotkey-new` has been added to the "Название" input of the manual-add form in [admin-menu.php](../admin-menu.php), so pressing `n` lands the cursor there immediately.
- Any page that already has a search field (most admin views eventually will) gets `/` for free — no per-page wiring.
- `Esc` works out of the box for every modal in the project that follows the documented selectors.

### Test flow

1. Open `/admin-menu.php` as admin.
2. Press `?` → overlay with the four default bindings appears. Press `?` or `Esc` → overlay closes.
3. Type into the "Название" input, then press `/` — cursor must stay in the input (binding ignored while typing).
4. Click outside any field, press `n` → focus jumps to "Название" input and the form scrolls into view.
5. Open the pay-link modal (checkout flow from `employee.php`) → press `Esc` → modal closes.
6. Hold `Ctrl`/`Cmd`/`Alt` with `?`/`n` — bindings must NOT fire (modifier keys are filtered out so they don't hijack browser shortcuts).

## 5.4 Richer search, filters, and sort

### What changed
- New filter bar above the menu table (active view only) in [admin-menu.php](../admin-menu.php): search box (by name or id), availability filter (`all`/`available`/`stop`), sort select (`default`/`name_asc`/`name_desc`/`price_asc`/`price_desc`), reset button, live count badge.
- Client-side filter engine: [js/admin-menu-filters.js](../js/admin-menu-filters.js). Reads row metadata once (`id`, `name.toLowerCase()`, `priceNum`, stop-button state), toggles `.filter-hidden` on unmatched rows and reorders visible rows via `insertBefore` for sort. The `last-row` separator is respected so decorative DOM doesn't reorder.
- Styles: [css/admin-menu-filters.css](../css/admin-menu-filters.css).
- **Everything is client-side.** No network calls, no schema changes, no new endpoints. The menu is bounded (typically ≤ 500 rows per tenant) so this is fast and avoids the cache-invalidation cost of a server filter.

### Interaction with 5.1 drag-n-drop
Custom sort (`name_*`, `price_*`) semantically conflicts with explicit `sort_order` drag-n-drop — if you drag a row while sorted by name, the DOM position you dropped into has nothing to do with the persisted position. Defensive handling:
- Under any non-default sort, rows drop their `draggable="true"` attribute and the drag handles fade to 25 % opacity (`.drag-disabled`).
- Resetting to "По умолчанию" (or clicking "Сбросить") puts the table back in `sort_order, name` order and re-enables drag.

### Persistence
Filter state is stored in `localStorage` under `cleanmenu:admin-menu-filters:v1`. That's per-browser-per-tenant, so an owner's desktop session remembers "show only stop-listed items" across reloads. URL query parameters are not used in this iteration — if we later want deep-linkable filter state, add a URL-sync layer on top of the same `state` object without rewriting the core.

### Stop-button reactive recalculation
The existing "СТОП" button toggles `stop-btn--active` live via [js/admin-menu-page.js](../js/admin-menu-page.js). The filter module listens for clicks on `.stop-btn` and re-applies the filter after a 50 ms tick, so an item that's just been stop-listed disappears from the "В продаже" view without a page reload.

### What's explicitly out of scope
- ABC-class filter, has-modifiers filter, column sort by clicking headers — deferred until there's a concrete ask. The `state` object and `apply()` pipeline make these a minor addition (~10 LoC each).
- Server-side search. Revisit if the row count crosses 1,000 per tenant.
- URL-sync for filters. See "Persistence" above.

### Test flow
1. Open `/admin-menu.php` as admin.
2. Type `piz` in search → only rows containing "piz" in name remain. Counter shows "Показано N из M".
3. Change Availability → **Стоп** → only rows whose "СТОП"-button has `stop-btn--active` class remain.
4. Click "СТОП" on a visible stopped row to un-stop it → after 50 ms the row hides (it no longer matches the "Стоп" filter).
5. Change Sort → **Цена ↑** → rows reorder ascending. Drag handles become semi-transparent and rows lose `draggable`.
6. Click "Сбросить" → controls reset, sort "default" restores original DOM order, drag is re-enabled.
7. Reload the page → filters persist.

## 5.5 Undo for destructive actions (modifiers)

### What changed
- New `deleted_at DATETIME NULL` column + index on `modifier_groups` and `modifier_options`. Migration in [sql/modifiers-soft-delete-migration.sql](../sql/modifiers-soft-delete-migration.sql).
- `Database::deleteModifierGroup()` and `Database::deleteModifierOption()` now issue `UPDATE … SET deleted_at = NOW()` instead of hard `DELETE`.
- `Database::getModifiersByItemId()` filters `g.deleted_at IS NULL` and `o.deleted_at IS NULL`, so soft-deleted rows disappear from the customer menu and the admin editor immediately.
- Two new DB helpers:
    - `Database::undoModifierDelete(table, id)` — restores a row only if it was deleted within the last 30 seconds. Past that, undo is a no-op (prevents resurrecting rows deleted hours ago and since forgotten).
    - `Database::purgeSoftDeletedModifiers($daysOld = 7)` — hard-DELETEs soft-deleted rows older than N days.
- New shared UI components:
    - [js/undo-toast.js](../js/undo-toast.js) / [css/undo-toast.css](../css/undo-toast.css) — `window.CleanmenuUndoToast.show({ text, timeoutMs, onUndo, onExpire })`. Only one toast at a time; the progress bar animates the timeout.
    - [js/admin-modifiers.js](../js/admin-modifiers.js) now calls `showUndoToast()` after every `delete_group` / `delete_option` success. Clicking **Отменить** posts to [undo-delete.php](../undo-delete.php).
- New endpoint [undo-delete.php](../undo-delete.php) — admin/owner + `Csrf::requireValid()`, table allowlist (`modifier_groups` | `modifier_options`), returns `{ success: true, restored: bool }` — `restored=false` when the 30 s window has passed.
- Cron script [scripts/orders/purge-soft-deleted.php](../scripts/orders/purge-soft-deleted.php) — dry-run by default, `--apply` to commit. Recommended daily schedule:

    ```cron
    0 4 * * *  cd /var/www/cleanmenu && php scripts/orders/purge-soft-deleted.php --days=7 --apply >> data/logs/purge-soft-deleted.log 2>&1
    ```

### What is explicitly NOT soft-deleted in this iteration
- `menu_items` — already has its own `archived_at` column with a full restore flow (archive/restore), so the UX pattern is "Архивировать" + separate archived view rather than "Удалено. Отменить (5s)". Track 5.2 bulk archive already benefits from this pattern without additional work.
- `reviews`, `reservations`, `orders` — delete is rare-to-never in the admin UI; no soft-delete layer is justified yet.
- `webhook_deliveries` / `outgoing_webhooks` — deliberate hard delete; operators expect the paper trail to be final.

### Request shape (undo)

```
POST /undo-delete.php
Content-Type: application/json
X-CSRF-Token: <token>

{ "table": "modifier_groups", "id": 42, "csrf_token": "<token>" }
```

Responses:
- `200 { success: true, restored: true }` — row came back.
- `200 { success: true, restored: false }` — row exists but is outside the 30 s window, or was already restored.
- `400 unknown_table` / `invalid_id`.
- `403` — CSRF or role.

### Test flow
1. Open the edit form of any menu item with modifiers.
2. Delete a modifier option → toast "Вариант удалён · Отменить" appears with a 5-second progress bar.
3. Click **Отменить** → the option reappears in the editor within a second.
4. Delete another option → wait 5 seconds → toast fades out → the option is gone from the editor and from `getModifiersByItemId`.
5. Run `php scripts/orders/purge-soft-deleted.php --days=7` — dry-run shows the count.
6. Temporarily run `php scripts/orders/purge-soft-deleted.php --days=0 --apply` on a test DB — rows from step 4 are hard-deleted.
7. DevTools: strip CSRF → `POST /undo-delete.php` returns `403`.

### Rollback
- UI-only: remove the two `<link>` / `<script>` tags for `undo-toast.*` and the `showUndoToast(...)` lines in [js/admin-modifiers.js](../js/admin-modifiers.js) — delete reverts to the immediate-confirmation flow without undo.
- Behavior rollback to hard-delete: replace the two `UPDATE ... deleted_at` statements in `deleteModifierGroup` / `deleteModifierOption` back to the original `DELETE FROM ... WHERE id`. The `deleted_at` column becomes inert.
- Schema rollback: `ALTER TABLE modifier_groups DROP COLUMN deleted_at; ALTER TABLE modifier_options DROP COLUMN deleted_at;` after the above reverts.

## Cross-cutting test flow for track 5

Run through these after a deploy that touches any of 5.1–5.5:

1. Apply both migrations: `sql/menu-sort-order-migration.sql`, `sql/modifiers-soft-delete-migration.sql`.
2. `/admin-menu.php` → drag a row inside one category, reload — order persists.
3. Same page → multi-select three rows → bulk archive → rows disappear, `?view=archived` shows them.
4. Press `?` on any admin page → help overlay lists at least the four default bindings.
5. Filter bar: search "piz" + availability "Стоп" — only matching stop-listed rows remain; the counter reads "Показано N из M".
6. Open a menu item editor → delete a modifier group → undo toast → click "Отменить" within 5 s → group restored.
7. `php scripts/orders/purge-soft-deleted.php --days=7` reports a non-error JSON in dry-run mode.
