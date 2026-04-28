# UI Patterns Catalog (Phase 13B.2)

Canonical block compositions used across the project. Use these instead of inventing new structure when adding new pages — they're already loaded into the global stylesheets, already covered by visual-regression tests, and already locale-/contrast-/keyboard-tested.

## Page chrome

### Top navigation
- File: [`header.php`](../header.php)
- Loaded by: every customer + operator surface (cart, account, owner, employee, admin-*, kds, group, reservation).
- Contains: logo, mobile burger button (Phase 12.6.1 — real `<button>`), main nav links, "Ещё ▾" dropdown for secondary items, language picker.
- Loads `js/header-more.js` (dropdown keyboard nav) + `js/focus-trap.js` (global helper).

### Account / Admin shell header
- File: [`account-header.php`](../account-header.php)
- Loaded by: account.php, owner.php, employee.php, customer_orders.php, AND all 9 admin-* pages.
- Contains: cog (admin shortcut) + chart (owner shortcut) icons in `.section-header-quick-actions`, plus the bottom-dock tab rail (mobile/tablet) / inline tabs (desktop).
- Owner / admin shortcuts already have `aria-label` + `title`.

## Page-internal sections

### Section header
Use everywhere a page has a top-level container needing a title + optional action button on the right.

```php
<section class="account-section">
    <div class="section-header-menu">
        <h2>Заголовок раздела</h2>
        <a href="..." class="back-to-menu-btn">Действие</a>
    </div>
    <!-- section content -->
</section>
```

For sub-sections within the same `.account-section`, use `<h3>` instead of `<h2>` and optionally `<small>` for descriptive copy:

```php
<div class="section-header-menu">
    <h3>Подраздел</h3>
    <small>Короткое пояснение, что тут происходит.</small>
</div>
```

### Owner workspace header
- Used by: owner.php tab panes (stats, fiscal, etc.).
- Structure:

```php
<div class="owner-workspace-stack">
    <div class="owner-workspace-header">
        <div>
            <p class="owner-workspace-kicker">Категория</p>
            <h2>Заголовок таба</h2>
        </div>
        <p class="owner-workspace-copy">Описание раздела одной строкой.</p>
        <div class="account-section-actions">
            <!-- primary CTA -->
        </div>
    </div>
    <!-- tab body -->
</div>
```

## Form patterns

### Inline action row
Used for save/test/cancel button clusters (modifier modal, fiscal config, etc.):

```php
<div class="fiscal-actions">
    <button type="button" class="checkout-btn" id="primaryAction">Сохранить</button>
    <button type="button" class="admin-checkout-btn" id="secondaryAction">Тест</button>
    <span class="fiscal-action-feedback" id="feedback" hidden></span>
</div>
```

`feedback` span is updated via `showFeedback(el, ok, message)` JS helper (Phase 13A.3 fiscal pattern).

### Numeric / short input
Use the data-w attribute pattern (Phase 8.2 — replaces inline `style="width:Xpx"`):

```html
<input type="number" data-w="sm" min="1" max="20" value="1">
<!-- data-w values: 3xs, 2xs, xs, sm, md, lg, xl -->
<!-- defined in css/admin-menu-polish.css -->
```

## Modals

Use the `window.FocusTrap` helper (Phase 12.6) on every dialog. Markup needs `role="dialog" aria-modal="true" aria-labelledby="<heading-id>"`:

```html
<div id="someModal" class="delivery" role="dialog" aria-modal="true" aria-labelledby="someModalTitle" hidden>
    <div class="delivery-content">
        <h3 id="someModalTitle">Заголовок</h3>
        <!-- content -->
        <button type="button" class="checkout-btn" id="someModalConfirm">Готово</button>
        <button type="button" class="checkout-btn cancel-btn" id="someModalCancel">Отмена</button>
    </div>
</div>
```

```js
function open() {
    modal.hidden = false;
    if (window.FocusTrap) window.FocusTrap.activate(modal, { onEscape: close });
}
function close() {
    modal.hidden = true;
    if (window.FocusTrap) window.FocusTrap.deactivate(modal);
}
```

## CSS tokens

Always use tokens, never hex literals. Reference list:

| Token | Purpose |
|---|---|
| `--ui-surface` | Card / dialog background |
| `--ui-surface-muted` | Secondary card background, table headers, hint blocks |
| `--ui-border` | All hairline borders |
| `--ui-text` | Primary text |
| `--ui-text-muted` | Secondary text, hints, labels |
| `--ui-accent` | Brand accent (= --primary-color) |
| `--ui-success` | Status green |
| `--ui-danger` | Status red |
| `--ui-focus-ring` / `--ui-focus-shadow` | Focus-visible ring |
| `--shell-radius-soft` | All "soft" rounded corners (cards, modals) |
| `--shell-shadow-soft` | Cards / modal shadow |

`auto-colors.php` emits `--{key}-rgb` triplets so `rgba()` literals can use the tokens too: `rgba(var(--primary-color-rgb), 0.06)`.

## A11y helpers (loaded globally via header.php)

- `.sr-only` — screen-reader-only text, visually hidden.
- `.skip-link` — keyboard-jump-to-main link, surfaces on focus.
- `[data-motion-respect]` — respects `prefers-reduced-motion: reduce` for any element opt-in.
- `[role="dialog"]` — auto-gets focus boundary outline if focused without focusable child.

## i18n

Wrap user-visible text in `t()`:

```php
<h2><?= htmlspecialchars(t('account.tab_security')) ?></h2>
```

If the key is missing, define it in all three of `locales/{ru,en,kk}.json` first. Run `php scripts/i18n-extract-strings.php` to find candidates for migration.

## When to deviate

Don't. If the existing pattern truly doesn't fit, open an issue or update this doc + `docs/architecture-map.md` first, then introduce the new pattern. The visual-regression suite assumes consistency.
