# UX/UI Improvement Roadmap

This document is the active source of truth for UX/UI improvements.

It complements:

- `docs/product-model.md`
- `docs/public-layer-guidelines.md`
- `docs/project-improvement-roadmap.md`

## Goal

Improve quality, clarity, and conversion without breaking the current ordering engine or mixing provider marketing with tenant public UX.

## Fixed Product Decisions

- `menu.php` is the primary transactional menu and ordering surface in both deployment modes.
- On provider domains, `index.php` is the B2B marketing and demo entry point.
- On tenant domains, `index.php` is optional and must be restaurant-facing if used.
- Provider marketing content must never appear on tenant domains.
- `menu.php` must not become a provider landing page.
- Tenant public UX must feel like a real restaurant product, not a provider demo.

## Public-Surface Rules

### Provider mode

- use `index.php` for B2B positioning, lead generation, and demo entry
- allow provider storytelling, consultation CTA, and demo content
- route `/` to the provider landing

### Tenant mode

- use `/` for the real restaurant public entry
- keep public content restaurant-specific
- route customers into `menu.php` fast
- never show provider contacts, provider map links, or provider CTA blocks

## Page Priorities

### `index.php`

Provider mode:

- strengthen first-screen value proposition
- keep clear CTA split between learning about the product and trying the demo flow
- avoid turning the provider landing into a full catalog clone

Tenant mode:

- use only if the restaurant needs a homepage before the menu
- keep it restaurant-facing only
- do not reuse provider sections mechanically

### `menu.php`

- preserve the familiar category-driven ordering flow
- improve first-screen scanning, search/filter discoverability, and cart visibility
- keep ordering faster than browsing

### `cart.php`

- improve empty-state recovery back to the menu
- make action hierarchy clearer on mobile
- keep checkout guidance explicit without crowding the screen

### `customer_orders.php`

- split active orders from history
- reduce repeated metadata and long raw addresses
- highlight tracking and repeat-order actions

### `order-track.php`

- make the active status and ETA easier to understand at a glance
- adapt status wording to delivery, pickup, and table scenarios

### `employee.php`

- optimize for triage speed, not decoration
- surface urgency, next action, and order type faster
- compress secondary metadata

### `admin-menu.php`

- simplify navigation layers
- keep menu/content management visually primary
- separate diagnostics from everyday catalog work

### `owner.php`

- treat analytics as one coherent workspace
- keep raw numbers visible
- improve interpretation without hiding operational detail

## Cross-Cutting Cleanup Priorities

- remove icon-font leakage from visible text
- remove raw coordinates and long metadata strings from order cards
- remove provider fallback links from tenant public pages
- clean up stale "active" orders that should be completed or archived

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
- do not turn a staged improvement into a big-bang redesign
