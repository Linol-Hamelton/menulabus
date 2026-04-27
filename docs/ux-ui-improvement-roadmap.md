# UX/UI Improvement Roadmap

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-04-27`
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
  - The cog/chart "quick actions" icons at the top of `/account.php`, `/owner.php` etc. now have 24px top padding (`.account-header-bar`) instead of crashing into the site header. Both icon `<a>` elements also carry a `title` attribute mirroring the `aria-label` so desktop hover surfaces the action name.
  - The 2FA card on `/account.php` no longer leaves ~200px of dead space below it. Root cause was `body.customer_orders-page .account-section { margin-bottom: 100px }` in `admin-menu-polish.css` — historical bottom-dock compensation that compounded with the next section's `margin-top: 25px` to ~125px between every consecutive `.account-section` (and ~200px on the 2FA card because `padding-bottom: 28px` stacked too). After Phase 9.2 the dock flows inline on desktop and `--bottom-tab-rail-clearance` handles mobile safe-area, so the 100px is reduced to a normal 24px inter-section gap.
  - The bottom-dock tab rail (Профиль/Безопасность/Меню/Обновления on `/account.php`, similar on `/owner.php` / `/customer_orders.php` / `/employee.php` / `/menu-catalog`) is now `position: fixed; bottom: 0` only at `(max-width: 1024px)`. On desktop (≥1025px) it falls through to the static-position rule already present (~line 170 of `ui-ux-polish.css`) and flows inline with the page content. Before, the rail floated over the profile form on `/account.php` and over the analytics card on `/owner.php`. The matching `--bottom-tab-rail-clearance` paddings on `.account-container` / `.account-section:last-child` / `body.menu-catalog-page #menu` / `.footer` and the `scroll-margin-bottom` on form CTAs are also wrapped in the same media query, so desktop doesn't carry 88px of dead space. Mobile/tablet behavior unchanged.
  - `/menu.php` no longer emits `Cache-Control: public, max-age=600, s-maxage=600`. The page depends on session state (cart count, user role, auto-fonts cookie), so a public cache stored 10-minute responses keyed without `PHPSESSID` — cookieless first-hits (cron-smoke / external monitors) raced `session_init.php` and got a 500, then nginx happily served that cached 500 to everyone, including authenticated browsers, until the cache TTL expired. `session_init.php` already emits `no-store, no-cache, must-revalidate` which is the correct posture for any session-bearing surface; the override on menu.php is removed. Real users are unaffected (their browsers were holding a cookied 200 already); cron-smoke `provider_tenant_regression` now stays green.
  - The "Ещё ▾" toggle initially looked dead even after the dropdown stayed closed — clicks did nothing. Root cause: `header-more.js` listened at `document` level, but some upstream global click handler (mobile burger / app.min.js) calls `stopPropagation()` so the document listener never saw the toggle event. Fixed by binding the toggle handler directly to the `.nav-more-toggle` button; outside-click + Escape stay on `document` but use the **capture** phase to win against the same upstream stoppers. Same commit also drops `nonce=""` from the script tag when `$GLOBALS['scriptNonce']` is empty (`isset()` → `!empty()`) so browsers don't downgrade-block it.
  - The shared `header.php` no longer wraps to a second row on desktop. After Phase 7 added reservation, group ordering, and a language picker, eight nav items × `margin: 0 50px` exceeded the desktop padding box and `flex-wrap: wrap` pushed half the items to a second line. The secondary items (reservation, group, language picker) now collapse into a single `<li class="nav-more">` whose `.nav-more-menu` is an absolute-positioned dropdown above 1251px and `display: contents` below it (so the existing burger menu still renders all items in its flat column with no DOM duplication). Toggle logic lives in `js/header-more.js` (external, defer, CSP-nonced); click-outside and Escape close the menu, `aria-expanded` reflects state. Dropdown chrome runs entirely on existing tokens (`--ui-surface`, `--ui-border`, `--shell-radius-soft`, `--shell-shadow-soft`); `lang-picker.css` is migrated off hardcoded `rgba(15,23,42,…)` to `--ui-surface-muted` / `--ui-border` / `--ui-text` for the same reason. New `nav.more` translation key added to `locales/{ru,en,kk}.json`. **Specificity gotcha caught in production audit:** the initial drop landed `.nav-more-menu { display: none }` at specificity 0,1,0 — but `.nav ul { display: flex }` higher up in the same file is 0,1,1 and won the cascade, so the dropdown rendered open by default. Every `.nav-more*` rule is now `.nav`-prefixed (0,2,0) and `flex-direction: column` is pinned on the dropdown explicitly.
  - All inline `style="width:XXpx"` on table inputs across the seven Phase 6/8 admin surfaces (`admin-inventory.php`, `admin-loyalty.php`, `admin-locations.php`, `admin-kitchen.php`, `admin-staff.php`, `admin-menu.php` recipe modal, `admin-marketing.php` segment threshold) — plus a one straggler in `owner.php` analytics heatmap days input found during the post-deploy audit — are replaced with `data-w="3xs|2xs|xs|sm|md|lg|xl"` attributes. The matching attribute selectors (`[data-w="…"] { width: … }`) live in `admin-menu-polish.css`, which is already loaded on every admin page. Rationale: even though strict CSP only blocks `<style>` blocks (not `style=""` attributes), every inline style is drift from the Phase 1.2 baseline and an obstacle to a future `style-src-attr 'none'` tightening. Visual width unchanged; the attribute approach was chosen over class merging because the latter required per-input regex that bled width into parent `<td>` elements on the first attempt.
  - Thirteen Phase 6–8 page-specific stylesheets (`kds.css`, `admin-kitchen/inventory/loyalty/locations/marketing/staff/webhooks/waitlist.css`, `loyalty-card.css`, `group-order.css`, `reviews.css`, `owner-analytics-v2.css`) are migrated off hardcoded hex/rgb to the existing design tokens (`--ui-surface`, `--ui-surface-muted`, `--ui-border`, `--ui-text`, `--ui-text-muted`, `--ui-accent`, `--ui-success`, `--ui-danger`, `--primary-color`, `--accent-color`). Net hardcoded color count across the thirteen files dropped from 248+ to ~83 — and most of the remainder is the KDS dark-theme token defaults consolidated into a single `:root`-equivalent block on `body.kds-page` (`--kds-bg`, `--kds-surface`, `--kds-accent`, etc.), so a future tenant-driven KDS recolor is one block of overrides instead of 41 scattered values. Other intentional literals: status pills in `admin-marketing.css` (queued/sending/sent/failed conventions), star color and Google CTA in `reviews.css`, soft amber low-stock banner in `admin-inventory.css`, success-tinted submit panel in `group-order.css`, save-state row tints in `admin-kitchen.css` — each annotated in-file. White-label brand changes now propagate to KDS, loyalty card, webhook delivery state, analytics cards, and review submit button without touching markup.
  - A Playwright visual-regression suite (`tests/visual/`) is wired in as the deterministic gate for all of the above. Three viewport projects (`desktop-1920`, `tablet-768`, `mobile-375`) exercise the same set of routes against a live tenant — owner login is performed once via `global.setup.ts` and the authenticated `storageState` is reused across every spec. Coverage: header layout (single-row desktop, "Ещё" dropdown open/close, mobile burger fallback), full-page snapshots of all 13 Phase 6-8 surfaces (`admin-kitchen/inventory/loyalty/locations/marketing/staff/webhooks/waitlist.php`, `kds.php`, `owner.php?tab=analytics-v2`, `account.php?tab=loyalty|security`, `group.php`), plus a CSP-violation guard that fails if any route logs a `Content Security Policy` console error. 55 tests total, run via `npm run visual` (headless), `npm run visual:headed` (browser visible), `npm run visual:update` (regenerate baselines after intentional change). Credentials live in `.env.local` (gitignored); see `tests/visual/README.md` for setup.

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
