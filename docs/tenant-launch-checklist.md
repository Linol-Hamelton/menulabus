# Tenant Launch Checklist

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-24`
- Current implementation notes:
  - Tenant provisioning and seeding are scriptable.
  - Launch artifact generation is now scriptable through `scripts/tenant/launch.php`.
  - One-command server-side go-live is now available through `scripts/tenant/go-live.sh`.
  - DNS ownership remains an external prerequisite before server-side go-live.
  - Brand settings now support separate address text, dedicated map URL, and explicit tenant public-entry mode.
  - Browser sign-off now includes a mandatory desktop/mobile visual screenshot set and checklist through `scripts/perf/post-release-regression.sh`.

## Purpose

Use this runbook when launching a new restaurant tenant on the shared codebase.

Related source-of-truth documents:

- `docs/product-model.md`
- `docs/project-reference.md`
- `docs/project-improvement-roadmap.md`
- `docs/public-layer-guidelines.md`

## 1. Input Required Before Launch

Collect and freeze:

- restaurant brand name
- brand slug
- target domain or subdomain
- primary contact phone
- address text
- map link, if you plan to expose a real map destination
- tenant public-entry mode: `homepage` or `menu`
- logo and favicon
- social links
- launch owner/admin email
- menu seed content source

Slug rule:

- lowercase
- latin only
- words joined by underscores

## 2. Database Provisioning

Hard rule:

- one client = one separate database

Naming rule:

- database name must contain the client brand slug
- recommended format: `menu_<brand_slug>`

Checklist:

- create the database
- import the current application schema
- verify DB user permissions
- point tenant runtime config to the new database
- record DB name and domain mapping in the launch log

Current automation available:

- `scripts/tenant/launch.php`
- `scripts/tenant/go-live.sh`
- `scripts/tenant/provision.php`
- `scripts/tenant/seed.php`
- `scripts/tenant/smoke.php`
- `scripts/perf/post-release-regression.sh`

Recommended scripted flow:

1. `launch.php` is the preferred path: it provisions the tenant, applies the frozen launch contract, runs smoke, and writes a launch artifact.
2. `provision.php` remains the lower-level entrypoint for DB/runtime mapping and optional initial seed.
3. `seed.php` applies or refreshes the restaurant demo/content profile.
4. `go-live.sh` is the production path on the target server: it captures a baseline, renders the tenant vhost, obtains or renews SSL, restarts PHP-FPM, reruns smoke, and writes a go-live artifact.
5. `smoke.php` remains the short provider/tenant regression smoke before release sign-off.
6. `post-release-regression.sh` is the browser-based sign-off layer: safe mode is suitable for every release, writes the mandatory visual screenshot set, and `--orders` is reserved for mutating order-lifecycle coverage.

## 3. Tenant Runtime Setup

Infra ownership split:

- external prerequisite: tenant domain / DNS
- automated on target server: web server vhost, SSL certificate, PHP-FPM restart, smoke, and go-live artifact
- runtime DB credentials are handled by provisioning scripts
- cache/session overrides remain optional only when the target host needs them

Expected routing:

- provider domain `/` => provider landing
- tenant domain `/` => tenant public entry
- tenant `/menu.php` => transactional menu

Tenant public-entry contract:

- `public_entry_mode=homepage` => tenant `/` renders the restaurant homepage
- `public_entry_mode=menu` => tenant `/` redirects to `/menu.php`

## 4. Brand and Public Layer Setup

Set tenant branding before opening public access:

- app / restaurant name
- tagline
- meta description
- phone
- address text
- dedicated map URL
- social links
- logo
- favicon
- colors
- fonts
- custom domain
- hide-provider-branding flag
- public-entry mode

Current implementation note:

- if location CTA matters for launch quality, verify both the visible address text and the public map URL before go-live
- if `public_entry_mode=menu`, verify that tenant `/` redirects to `/menu.php` and that branding still is correct there

Public verification:

- no provider sales copy
- no provider contacts
- no provider map links
- no Labus fallback branding in tenant public pages

## 5. Access Setup

Create and verify:

- tenant owner/admin account
- optional employee accounts
- test customer account if needed for smoke

Check:

- login works
- owner/admin pages open
- tenant roles are isolated to the tenant database

## 6. Data and Menu Setup

Load or verify:

- categories
- dishes
- prices
- availability flags
- delivery / pickup / table settings
- payment-related configuration if used

Check:

- tenant homepage loads
- public menu loads
- cart accepts items
- order creation works

## 7. Mandatory Smoke Before Go-Live

Public smoke:

- `/` opens the correct tenant-facing public entry
- `/menu.php` works
- cart and checkout path work
- order tracking works

Backoffice smoke:

- `auth.php`
- `account.php`
- `help.php`
- `owner.php`
- `admin-menu.php`
- `employee.php` if used

API smoke:

- `GET /api/v1/menu.php`
- auth login / me flow
- create order if mobile/API use is planned

Recommended release smoke:

- `php scripts/tenant/launch.php --brand-name="Brand" --brand-slug=brand --domain=<tenant-domain> --mode=tenant --owner-email=owner@example.com --tenant-db-user=db_user --tenant-db-pass=db_pass --seed-profile=restaurant-demo --public-entry-mode=homepage --contact-phone=+79000000000 --contact-address="Москва, Цветной б-р, 24" --contact-map-url=https://yandex.ru/maps/...`
- `php scripts/tenant/smoke.php --provider-domain=menu.labus.pro --tenant-domain=<tenant-domain>`
- production post-pull hook runs the same smoke automatically for `menu.labus.pro` + `test.milyidom.com`
- `CLEANMENU_TENANT_DOMAIN=<tenant-domain> bash scripts/perf/post-release-regression.sh`
- `CLEANMENU_TENANT_DOMAIN=<tenant-domain> CLEANMENU_RUN_ORDER_REGRESSION=1 bash scripts/perf/post-release-regression.sh --orders`

Visual acceptance is part of launch acceptance:

- review the generated desktop/mobile screenshots
- confirm there is no overlap or clipped sticky/tab behavior
- confirm the tenant homepage, menu, account surfaces, and employee/admin shells read clearly on mobile

## 8. Launch Acceptance

Tenant launch is complete only when:

- the tenant runs on its own domain
- the tenant uses its own database
- the database name follows the slug rule
- branding is tenant-specific everywhere public
- no provider content leaks into the tenant public layer
- key ordering flows pass smoke
- browser regression sign-off is green for the target tenant
- visual screenshot sign-off is reviewed for both desktop and mobile
- launch artifact is recorded and stored

## 9. Suggested Launch Log Record

Record at minimum:

- launch date
- operator
- tenant brand
- tenant slug
- domain
- database name
- owner/admin email
- smoke result
- launch artifact path
- go-live artifact path
- rollback note if any issue appears
