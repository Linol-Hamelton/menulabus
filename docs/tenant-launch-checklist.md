# Tenant Launch Checklist

This document turns tenant onboarding into a repeatable runbook.

Use it when launching a new restaurant on the shared codebase.

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
- address and map link
- logo and favicon
- social links
- launch owner/admin email
- menu seed content source

Slug rule:

- lowercase
- latin only
- words joined by underscores

Example:

- brand: `Kultura Bar`
- slug: `kultura_bar`

## 2. Database Provisioning

Hard rule:

- one client = one separate database

Naming rule:

- database name must contain the client brand slug
- recommended format: `menu_<brand_slug>`

Examples:

- `menu_kultura_bar`
- `menu_bon_pizza`

Checklist:

- create the database
- import the current application schema
- verify DB user permissions
- point the tenant runtime config to the new database
- record DB name and domain mapping in the launch log

## 3. Tenant Runtime Setup

Configure:

- tenant domain / vhost
- SSL certificate
- runtime DB credentials
- cache/session settings if tenant-specific overrides exist

Expected routing:

- provider domain `/` => provider landing
- tenant domain `/` => tenant public entry
- tenant `menu.php` => transactional menu

## 4. Brand and Public Layer Setup

Set tenant branding before opening public access:

- app / restaurant name
- tagline
- meta description
- phone
- address / map link
- social links
- logo
- favicon
- colors
- fonts
- custom domain
- hide-provider-branding flag

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
- delivery/pickup/table settings
- payment-related configuration if used

Check:

- menu loads publicly
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
