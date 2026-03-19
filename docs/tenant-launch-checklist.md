# Tenant Launch Checklist

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-19`
- Current implementation notes:
  - Tenant provisioning and seeding are scriptable.
  - DNS, vhost, SSL, and final production go-live remain manual.
  - Brand settings now support separate address text and dedicated map URL.

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

- `scripts/tenant/provision.php`
- `scripts/tenant/seed.php`
- `scripts/tenant/smoke.php`

Recommended scripted flow:

1. `provision.php` creates tenant DB/runtime mapping and optional initial seed.
2. `seed.php` applies or refreshes the restaurant demo/content profile.
3. `smoke.php` runs short provider/tenant regression smoke before release sign-off.

## 3. Tenant Runtime Setup

Manual infra steps:

- tenant domain / DNS
- web server vhost
- SSL certificate
- runtime DB credentials
- cache/session overrides if needed

Expected routing:

- provider domain `/` => provider landing
- tenant domain `/` => tenant public entry
- tenant `/menu.php` => transactional menu

## 4. Brand and Public Layer Setup

Set tenant branding before opening public access:

- app / restaurant name
- tagline
- meta description
- phone
- address text
- social links
- logo
- favicon
- colors
- fonts
- custom domain
- hide-provider-branding flag

Current implementation note:

- if location CTA matters for launch quality, verify both the visible address text and the public map URL before go-live

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
- `owner.php`
- `admin-menu.php`
- `employee.php` if used

API smoke:

- `GET /api/v1/menu.php`
- auth login / me flow
- create order if mobile/API use is planned

Recommended release smoke:

- `php scripts/tenant/smoke.php --provider-domain=menu.labus.pro --tenant-domain=<tenant-domain>`
- production post-pull hook runs the same smoke automatically for `menu.labus.pro` + `test.milyidom.com`

## 8. Launch Acceptance

Tenant launch is complete only when:

- the tenant runs on its own domain
- the tenant uses its own database
- the database name follows the slug rule
- branding is tenant-specific everywhere public
- no provider content leaks into the tenant public layer
- key ordering flows pass smoke

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
- rollback note if any issue appears
