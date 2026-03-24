# Product Model

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-24`
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`
- Current implementation notes:
  - Provider and tenant public modes are implemented and separated by hostname-aware runtime.
  - Tenant homepage is live on `test.milyidom.com`.
  - The branding model now separates address text and dedicated map URL in code and public UI.
  - Tenant public entry is now configurable per deployment via `public_entry_mode`.
  - Auth-gated backoffice and ops surfaces were rechecked live as part of the `2026-03-23` audit cycle.
  - Launch validation and go-live automation now exist in repo; external DNS ownership remains a deployment prerequisite.

## 1. Core Product Definition

`Menu Labus` is a white-label restaurant menu and ordering platform.

The same codebase supports two deployment modes:

- `provider` mode: company-owned domain used for product promotion, demo flows, and lead generation
- `tenant` mode: client-owned restaurant domain used as the real public menu and ordering surface

The engine is restaurant-first. The provider layer exists to promote the product and must not leak into tenant deployments.

## 2. Non-Negotiable Rules

1. One client = one separate database.
2. The database name must contain the client brand slug.
3. One client = one domain (or subdomain) + one isolated brand configuration.
4. Provider marketing content must never appear on tenant public domains.
5. Ordering, roles, API, and backoffice stay shared at code level but isolated at data level.

## 3. Deployment Modes

### 3.1 Provider mode

Example:

- `menu.labus.pro`

Purpose:

- explain the product
- collect leads
- demonstrate the ordering experience
- show the provider brand

Allowed public content:

- B2B positioning
- promo blocks
- consultation / lead form
- demo restaurant content

Current implementation:

- `/` opens the provider landing page
- `/menu.php` stays the provider demo / transactional surface

### 3.2 Tenant mode

Example:

- `test.milyidom.com`

Purpose:

- act as the real restaurant menu and ordering surface

Allowed public content:

- restaurant brand
- restaurant homepage and menu
- delivery / pickup / table ordering
- restaurant contacts, address, hours, and socials

Forbidden on tenant domains:

- provider sales copy
- provider contacts
- provider map links
- provider CTA blocks such as consultation / become-a-client messaging

Current implementation:

- `/` can render a restaurant-facing homepage
- `/menu.php` is the primary transactional menu
- the public-entry choice can now be frozen per deployment as `homepage` or `menu`

## 4. Database Isolation Model

Hard rule:

- no shared production database for multiple restaurant tenants

Recommended naming:

- `menu_<brand_slug>`

Examples:

- `menu_kultura_bar`
- `menu_bon_pizza`
- `menu_labus_demo`

Why this model stays in place:

- data isolation stays simple
- backup / restore stays simple
- domain-to-tenant mapping stays explicit
- client migration and offboarding remain realistic

## 5. White-Label Surface

Target tenant branding model:

- app / restaurant name
- tagline
- meta description
- phone
- address
- map link
- social links
- logo
- favicon
- colors
- fonts
- custom domain
- hide-provider-branding flag
- public-entry mode

Current implementation gap:

- the runtime supports the brand fields and public-entry contract
- launch quality is now backed by validation, acceptance summary, and go-live artifacts
- remaining live sign-off depends on release ownership after deploy, not on missing product model pieces

## 6. What Must Stay Shared Across All Modes

- codebase
- order engine
- customer auth flows
- staff / admin / owner roles
- mobile API
- realtime order updates
- deployment and security runbooks

## 7. Current Verified Facts

- Provider `/` is live as a B2B landing on `menu.labus.pro`.
- Tenant `/` is live as a restaurant-facing homepage on `test.milyidom.com`.
- Tenant `/menu.php` is live as restaurant catalog and ordering surface.
- Provider and tenant data are isolated by tenant-aware runtime and separate databases.
