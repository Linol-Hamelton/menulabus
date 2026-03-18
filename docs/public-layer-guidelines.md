# Public Layer Guidelines

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-17`
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current implementation notes:
  - Provider and tenant public surfaces are separated in runtime and live.
  - Tenant homepage is restaurant-facing and no longer redirects by default to `/menu.php`.
  - White-label branding is mostly settings-driven, including separate address text and map-link fields.

## 1. Source of Truth

Use this document for public-surface decisions that touch:

- landing behavior
- homepage behavior
- menu entry
- CTA logic
- branding visibility

## 2. Provider Domain Rules

Provider example:

- `menu.labus.pro`

Allowed:

- B2B landing
- product positioning
- consultation / lead form
- case-study style messaging
- demo restaurant content

Expected routing:

- `/` => provider landing
- `/menu.php` => demo transactional flow

Current state:

- implemented and verified live on `2026-03-17`

## 3. Tenant Domain Rules

Tenant example:

- `test.milyidom.com`

Allowed:

- restaurant-facing homepage
- restaurant menu
- ordering flows
- restaurant contacts and socials
- reservation only if it belongs to the restaurant itself

Forbidden:

- provider lead forms
- provider map links
- provider support contacts in public UI
- provider sales copy on customer-facing pages

Expected routing:

- `/` => tenant public entry
- `/menu.php` => primary transactional menu

Current state:

- tenant homepage is live and restaurant-facing
- transactional menu remains on `/menu.php`
- per-deployment entry configuration is still missing

## 4. Stable UX Decisions

- `menu.php` remains the primary ordering surface in all modes.
- `menu.php` must not become a provider landing page.
- On tenant domains, `index.php` is optional. If used, it must be restaurant-facing only.
- On provider domains, `index.php` is the B2B entry point.
- Provider and tenant public content must be separated by runtime mode, not by manual code edits per launch.

## 5. Launch-Readiness Checklist for Tenant Public UI

- tenant brand name is visible everywhere public
- tenant logo and favicon replace provider defaults
- tenant phone replaces provider fallback contacts
- no Labus sales copy remains in tenant public pages
- tenant homepage and `/menu.php` both behave correctly
- cart, checkout, tracking, and account flows remain functional

Current implementation note:

- tenant public QA must verify both address text and map CTA
- if no map URL is configured, the location CTA should stay hidden instead of falling back to provider or raw address links

## 6. Current Cleanup Priorities

- finish internal-shell normalization on remaining operational pages
- keep provider fallback links out of tenant public pages
- keep tenant public entry behavior documented and predictable
