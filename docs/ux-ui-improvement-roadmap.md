# UX/UI Improvement Roadmap

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-04-11`
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current implementation notes:
  - Provider and tenant public UX are now clearly split.
  - Critical visible icon-font leakage was removed from key public and account-facing surfaces.
  - Order-card metadata compression is implemented in the main customer and employee views.
  - Shared help and operational shell improvements are live and now extend through the common shell contract.
  - Shared stale-order lifecycle badges, thresholds, and cleanup actions now exist in customer, employee, and owner-facing operational views.
  - The final shared polish layer now covers provider and tenant shell density, public menu rails, help/QR/cart rhythm, and a deterministic visual release gate.
  - The current shell contract uses a persistent edge-to-edge bottom-docked tab rail for menu and internal navigation surfaces: full-width, no rail rounding, centered on desktop, and horizontally scrollable on tablet/mobile. Owner analytics keep a distinct report-toolbar treatment with clearer hierarchy between report tabs and period controls.
  - Narrow-screen account headers no longer force quick actions into a horizontal scroller when that harms readability; the shell now allows them to settle into the normal vertical rhythm.
  - Menu catalog pages now use a tighter closing rhythm so the last cards transition into footer copy without an oversized dead zone above the bottom dock.
  - In `admin-menu.php`, the desktop catalog actions column now uses a dedicated intermediate-width layout from `769px` to `978px`, turning action links into a centered vertical stack instead of a cramped inline pair.
  - The modifiers editor on `admin-menu.php?edit=*` now uses the same CSRF token fallback chain as the other admin JS modules, so edit-mode API calls no longer depend on a page-level meta tag being present.
  - The `admin-modifiers.js` asset is now filemtime-versioned from `admin-menu.php`, so deploys invalidate stale immutable browser cache for the edit-mode modifiers UI.
  - The `index.php` first screen (provider and tenant hero) is rebuilt around a calmer, breathier layout: the translucent card, eyebrow label, and static provider quick-points are removed, H1/subtitle/CTAs fade up with a short stagger, and the background uses a slow ken-burns loop. Motion is fully suppressed under `prefers-reduced-motion: reduce`. All new rules live in a scoped `css/index-hero.css` so existing hero consumers and later landing sections are untouched. Broken `HDR_1024`/`HDR_1440` picture sources were also dropped to eliminate desktop 404s after the earlier asset cleanup.

## Goal

Improve quality, clarity, and conversion without breaking the ordering engine or mixing provider marketing with tenant public UX.

## Fixed Product Decisions

- `menu.php` is the primary transactional menu and ordering surface in both deployment modes.
- On provider domains, `index.php` is the B2B marketing and demo entry point.
- On tenant domains, `index.php` is optional and must be restaurant-facing if used.
- Provider marketing content must never appear on tenant domains.
- `menu.php` must not become a provider landing page.
- Tenant public UX must feel like a real restaurant product, not a provider demo.

## What Is Already Implemented

### Public split

- provider `/` is a B2B landing
- tenant `/` can render a restaurant-facing homepage
- tenant `/menu.php` stays the transactional menu

### Public and account cleanup

- critical cart/header icon leakage is fixed in the main public flow
- customer and employee order cards now keep long details in expanded sections instead of the first visible row
- tenant public pages no longer mirror provider B2B catalog content

### Internal page improvements

- major layout regressions on `admin-menu.php`, `owner.php`, `employee.php`, `cart.php`, and `qr-print.php` have been reduced
- scroll retention on `admin-menu.php` interactions is implemented
- `help.php` now provides a shared role helper and product walkthrough surface
- shared shell polish and desktop/mobile visual sign-off now protect provider and tenant account/admin flows from overlap and fixed-rail regressions
- the owner report toolbar now separates report switching from period filtering with a stronger card hierarchy and cleaner spacing
- the bottom tab rail now uses one shared geometry contract across provider/tenant menu, account, owner, employee, and admin-menu surfaces instead of page-specific fixed-bottom overrides
- `admin-menu.php` now also keeps one shared inline width for the section header actions, top admin tabs, and main editor/catalog cards, so the working column stays aligned on desktop, tablet, and mobile
- in the `dishes` tab, the operator flow now shows the catalog surface before the update/editor surface, matching the primary browse-then-edit workflow

## What Is Still Open

### 1. Legacy non-critical icon debt

- critical visible cases are fixed
- legacy Font Awesome usage still exists in some non-critical parts of the codebase and should not silently grow again

## Validation Metrics

Customer-facing:

- public entry -> `menu.php` click-through
- `menu_view -> add_to_cart`
- `add_to_cart -> order_create_success`
- repeat-order usage

Staff/owner-facing:

- time to first action on new order
- clicks to common admin tasks
- time to find key owner insight

## Non-Regression Rules

- do not worsen p95 or perceived performance
- do not hide critical actions behind extra taps
- do not add heavy visual complexity to staff flows
- do not break the familiar checkout path
- do not ship provider marketing into tenant public pages
- do not turn staged improvements into a big-bang redesign
