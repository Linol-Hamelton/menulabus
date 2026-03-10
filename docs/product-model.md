# Product Model

This document is the source of truth for how the product should exist as both:

- a provider-owned B2B showcase on the company domain
- a white-label restaurant site on client domains

## 1. Core Product Definition

`Menu Labus` is a white-label restaurant menu and ordering platform.

The same codebase must support two deployment modes:

- `provider` mode: company-owned B2B domain used for product promotion, demo flows, and lead generation
- `tenant` mode: client-owned restaurant domain used as the real public menu and ordering surface

The engine is restaurant-first.
The B2B layer exists only to promote the solution from the provider side and must not leak into client deployments.

## 2. Non-Negotiable Rules

1. One client = one separate database.
2. The database name must contain the client brand slug.
3. One client = one domain (or subdomain) + one isolated brand configuration.
4. Provider marketing content must never appear on client domains.
5. The ordering engine, roles, API, and backoffice stay shared at code level but isolated at data level.

## 3. Deployment Modes

### 3.1 Provider mode

Example:

- `menu.labus.pro`

Purpose:

- explain the product
- collect leads
- demonstrate the ordering experience
- show the provider brand (`Labus`)

Allowed public content:

- B2B positioning
- promo blocks
- consultation / lead form
- demo menu or demo restaurant content

Default route rule:

- `/` should open the provider landing (`index.php`)

### 3.2 Tenant mode

Examples:

- `menu.client-brand.ru`
- `app.restaurant-name.ru`

Purpose:

- act as the real restaurant menu and ordering surface

Allowed public content:

- restaurant brand
- restaurant menu
- delivery / pickup / table ordering
- restaurant contacts, address, hours, social links

Forbidden on tenant domains:

- provider sales copy
- provider contacts
- provider map links
- provider CTA blocks like "consultation", "become a client", or similar

Default route rule:

- `/` should open the tenant public entry
- that entry can be either `menu.php` directly or a tenant-specific restaurant homepage
- `index.php` on tenant domains must be restaurant-facing only if it is used at all

## 4. Database Isolation Model

Recommended convention:

- database name: `menu_<brand_slug>`

Examples:

- `menu_kultura_bar`
- `menu_bon_pizza`
- `menu_labus_demo`

Where `brand_slug` is:

- lowercase
- latin only
- words joined by underscores
- derived from the restaurant brand

Isolation rule:

- no shared production database for multiple clients
- each client has their own schema, settings, orders, users, and content
- cross-client analytics must not depend on tenant data being stored in one DB

This keeps:

- data isolation simple
- backup/restore simple
- domain-to-tenant mapping explicit
- client offboarding and migration realistic

## 5. White-Label Surface

The public tenant experience must be controlled by tenant settings, not by hard-coded provider defaults.

At minimum, tenant branding must cover:

- app / restaurant name
- tagline
- meta description
- phone
- address/map link
- social links
- logo
- favicon
- colors
- fonts
- custom domain
- hide-provider-branding flag

## 6. What Must Stay Shared Across All Modes

- codebase
- order engine
- customer auth flows
- staff/admin/owner roles
- mobile API
- realtime order updates
- deployment/security runbooks

## 7. Immediate Documentation Consequence

Every core project document must now assume:

- `menu.labus.pro` is the provider deployment, not the universal default model for all tenants
- tenant onboarding means provisioning a new domain + a new database + tenant branding
- the roadmap is about making tenant launch predictable and low-risk, not about turning the provider demo into the permanent default state
