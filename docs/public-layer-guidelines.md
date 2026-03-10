# Public Layer Guidelines

This document replaces the old mixed UX assumptions with one clear rule:

- provider domain = B2B marketing and demo
- tenant domain = real restaurant public experience

## 1. Source of Truth

Use this file for any public-surface decision that touches:

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

## 3. Tenant Domain Rules

Tenant examples:

- `menu.client-brand.ru`
- `app.restaurant-name.ru`

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

## 4. Stable UX Decisions

- `menu.php` remains the primary ordering surface in all modes.
- `menu.php` must not become a provider landing page.
- On tenant domains, `index.php` is optional. If used, it must be restaurant-facing only.
- On provider domains, `index.php` is the B2B entry point.
- Provider and tenant public content must be separated by runtime mode, not by manual code edits per launch.

## 5. Launch-Readiness Checklist for Tenant Public UI

- tenant brand name is visible everywhere public
- tenant logo and favicon replace provider defaults
- tenant phone and map link replace provider defaults
- no Labus sales copy remains in public pages
- public homepage and menu route behave correctly for the tenant domain
- cart, checkout, tracking, and account flows remain unchanged functionally

## 6. Current Cleanup Priorities

- remove icon-font text leakage from visible UI
- remove raw coordinates and overly long metadata from order cards
- remove provider fallback links from tenant public pages
- keep public entry behavior domain-aware and documented
