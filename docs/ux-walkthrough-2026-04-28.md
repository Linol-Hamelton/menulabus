# UX walk-through diagnostic — 2026-04-28

## Goal

Diagnostic snapshot of the project's customer + operator surfaces after Phase 7 + Phase 13A scaffolding/UI. Each row is something to verify / fix in a follow-up commit. Severity: `blocker` (broken/inaccessible), `major` (degraded UX), `minor` (cosmetic), `nit` (polish).

## Code-scan findings (concrete, file:line)

| Severity | Where | Finding | Fix path |
|---|---|---|---|
| minor | [group.php:132](d:/cleanmenu/group.php#L132) | `style="width: 70px"` on `#gQty` — last inline style in PHP markup outside of dynamic-value cases | replace with `data-w="sm"` (matches Phase 8.2 cleanup pattern) |
| nit | [partials/account_loyalty_card.php:75](d:/cleanmenu/partials/account_loyalty_card.php#L75) | `style="width: <%>%"` on `.loyalty-progress-fill` — dynamic value, can be migrated to CSS custom property `--progress` | optional (CSP allows `style=""` attr; no security regression) |
| minor | `css/admin-menu-polish.css` | 35 hex literals remain — most are status colors (success greens / danger reds / amber low-stock), but a brand-color audit might reveal more migratable ones | grep `#[0-9a-fA-F]{6}` and reclassify |
| major | repo-root | 92 PHP files at top level (admin-*, save-*, confirm-*, *-oauth, kds-*, plus singletons) — навигация затруднена | 13B.3 — real `git mv` into subdirs |

## Per-surface walk-through checklist

The following 14 surfaces × 3 viewports (desktop 1920, tablet 768, mobile 375) need manual visual review under owner login. Each cell: ✓ pass / ⚠ minor / ✗ blocker. Rows are listed in priority order (customer-facing first).

| # | Surface | desktop 1920 | tablet 768 | mobile 375 | Notes |
|---|---|:---:|:---:|:---:|---|
| 1 | `/` (homepage) |   |   |   |   |
| 2 | `/menu.php` |   |   |   |   |
| 3 | `/cart.php` |   |   |   |   |
| 4 | `/reservation.php` |   |   |   | check availability picker visible |
| 5 | `/group.php?code=XXX` (submitted) |   |   |   | new split-mode picker (13A.1) |
| 6 | `/account.php?tab=profile` |   |   |   |   |
| 7 | `/account.php?tab=loyalty` |   |   |   |   |
| 8 | `/owner.php?tab=stats` |   |   |   |   |
| 9 | `/owner.php?tab=fiscal` |   |   |   | new (13A.3) |
| 10 | `/owner.php?tab=analytics-v2` |   |   |   | known canvas-jitter (visual-test tolerance widened) |
| 11 | `/admin-menu.php` |   |   |   |   |
| 12 | `/admin-staff.php` |   |   |   | new swap UI (13A.2) |
| 13 | `/admin-kitchen.php` |   |   |   |   |
| 14 | `/kds.php` |   |   |   |   |

For each ✗ or ⚠: open a follow-up commit `fix(<surface>): <short-description>` referencing this row.

## Walk-through method

```
1. Open https://menu.labus.pro/<surface> under owner login (use browser
   DevTools to switch viewport sizes via the device-toolbar dropdown).
2. Console: 0 errors expected. CSP-violation noise = blocker.
3. Layout: no horizontal scroll on mobile, no white margins on desktop.
4. Interactive: every button reachable via Tab, Esc closes modals/menus,
   focus-visible ring present on focus.
5. Brand: change tenant primary color in /owner.php?tab=brand; the surface
   should re-tint without manual reload.
6. i18n: switch ?lang=en and ?lang=kk; nav header strings flip; tenant-
   custom strings stay in default locale (acceptable until Phase 7.3
   surface migration completes).
```

## Known carryover items

- `owner-analytics-v2-desktop-1920` visual baseline has 1.5% tolerance — accepted as canvas-render jitter, not a defect.
- Some tenant-customizable strings (menu item names, descriptions) are RU-only by design — i18n only covers UI chrome.
- Hardcoded color literals in `css/admin-menu-polish.css` are mostly intentional status pills (✅ success green, ❌ error red).

## Status

Walk-through cells above are **empty pending operator review** — fill in a follow-up commit once a manual pass is done. Code-scan section is actionable now.
