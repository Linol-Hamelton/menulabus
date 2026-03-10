# Project Improvement Roadmap

## Goal

Turn the current provider deployment into a repeatable product model where:

- `menu.labus.pro` stays the provider-owned B2B showcase
- new client restaurant launches are fast and predictable
- each new client gets their own domain, brand, and separate database

This roadmap is now portability-first and tenant-launch-first.

## Fixed Constraints

- one client = one separate database
- database name must contain the client brand slug
- provider and tenant public modes must be separated by domain behavior
- one production change per rollout step
- mandatory smoke after each rollout step
- API contract source of truth remains `docs/openapi.yaml`

## What Already Exists and Should Be Reused

- working order engine
- role-based backoffice
- mobile API
- repeat-order flow
- owner analytics foundation
- monitor and security smoke foundation
- white-label settings surface in admin

The roadmap below is therefore not about rebuilding the platform.
It is about making tenant launch clean, safe, and scalable.

## Success Metrics

- time to launch a new client from zero to production
- number of manual steps per new client launch
- number of provider-brand leaks on client domains
- number of tenant-specific overrides that still require code edits
- `5xx` / p95 on tenant public flows
- ordering conversion on tenant domains

## Phase 0: Documentation Reset

### 1) Freeze the product model

- Write one clear source of truth for `provider` vs `tenant` mode.
- Record the rule `1 client = 1 DB`.
- Record the routing rule for provider and client domains.

Done when:

- no core doc contradicts the two-mode model

### 2) Remove documentation noise

- keep a short core docs set
- move historical snapshots into `docs/archive/`
- stop using stale status snapshots as "current state"

Done when:

- a new engineer can understand the system from `docs/index.md` without reading historical documents first

## Phase 1: Domain-Aware Public Behavior

### 3) Introduce runtime mode switch by hostname

- provider domain `/` => B2B landing
- tenant domain `/` => restaurant public entry
- stop treating `menu.labus.pro` as the universal default for every deployment

Risk:

- low-medium

Done when:

- the same codebase behaves correctly depending on domain

### 4) Remove provider hard-coding from tenant fallback paths

- provider contacts, map links, CTA blocks, and sales copy must be shown only on provider domains
- tenant domains must use tenant settings or stay blank until configured

Risk:

- low

Done when:

- no public tenant page shows Labus-specific fallback content

## Phase 2: Database and Tenant Provisioning

### 5) Formalize DB naming convention

- recommended DB name: `menu_<brand_slug>`
- examples: `menu_kultura_bar`, `menu_bon_pizza`, `menu_labus_demo`

Done when:

- every new tenant launch follows one naming convention

### 6) Create tenant provisioning checklist

- create DB
- import schema
- create tenant admin user
- configure brand settings
- configure domain / SSL
- run smoke
- store the runbook in `docs/tenant-launch-checklist.md`

Risk:

- low

Done when:

- launching a new restaurant is a checklist, not tribal knowledge

## Phase 3: White-Label Completeness

### 7) Make tenant branding sufficient without code edits

- name
- tagline
- description
- contacts
- logo
- favicon
- colors
- fonts
- social links
- custom domain
- hide-provider-branding

Done when:

- a new restaurant can be branded fully from settings and content, not from PHP edits

### 8) Split provider demo content from tenant seed content

- provider deployment may use demo content
- tenant deployment must start from restaurant-friendly defaults

Done when:

- provider B2B content and tenant restaurant content are different by design, not by accident

## Phase 4: Restaurant-Ready UX Cleanup

### 9) Remove UI artifacts that block client rollout quality

- icon-font leakage into visible text
- raw coordinates and long metadata in order cards
- stale "active" orders that should be completed or archived

Done when:

- the client-facing and staff-facing experience looks like a real restaurant product, not an internal demo mixed with legacy data

### 10) Finish tenant-safe public entry UX

- decide tenant default entry: direct `menu.php` or tenant homepage
- ensure this choice is configurable per deployment without changing provider behavior

Done when:

- tenant public routing is predictable and documented

## Phase 5: Optional Automation

### 11) Add bootstrap automation for new tenants

- generate DB name from brand slug
- apply schema
- create initial brand settings
- output launch checklist

Done when:

- new tenant launch can be mostly scripted

## Rollout Rules

1. One change per release step.
2. Update docs before or with the change.
3. Run smoke and key business verification after each step.
4. Keep rollback simple.
5. Prefer settings-driven rollout over template duplication.

## Recommended Execution Order

1. Phase 0
2. Phase 1
3. Phase 2
4. Phase 3
5. Phase 4
6. Phase 5

This order minimizes rework: first define the model, then route by domain, then provision isolated tenants, then finish branding and UX.
