# UX/UI Improvement Roadmap

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current implementation notes:
  - Provider and tenant public UX are now clearly split.
  - Critical visible icon-font leakage was removed from key public and account-facing surfaces.
  - Order-card metadata compression is implemented in the main customer and employee views.
  - Shared help and operational shell improvements are live, but the shell contract is still not fully centralized.

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

## What Is Still Open

### 1. Brand/contact validation and launch QA

- address text and dedicated map link are now separate settings in runtime and public UI
- remaining work is launch-time QA and stronger validation around the public location CTA

### 2. Internal shell normalization

- several operational pages already share the same shell direction
- the shell contract is not fully centralized yet, so some page-specific overrides remain

### 3. Stale-order cleanup

- the roadmap still expects cleanup of stale active orders
- this is not yet documented as a fully implemented product or ops mechanic

### 4. Legacy non-critical icon debt

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
