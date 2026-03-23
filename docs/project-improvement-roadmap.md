# Project Improvement Roadmap

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - The core provider/tenant model is implemented.
  - A full `repo + live` documentation audit resynchronized active docs on `2026-03-23`.
  - Remaining work is mostly in launch automation, full shell normalization, and explicit operational cleanup mechanics.

## Goal

Keep `menu.labus.pro` as the provider-owned B2B showcase while making restaurant tenant launches predictable, low-risk, and repeatable.

## Fixed Constraints

- one client = one separate database
- database name must contain the client brand slug
- provider and tenant public modes must be separated by domain behavior
- one production change per rollout step
- mandatory smoke after each rollout step
- API contract source of truth remains `docs/openapi.yaml`

## Phase Status

| Phase | Status | Current state |
|---|---|---|
| Phase 0: Documentation reset | `Partial` | Core docs exist and are now synchronized, but documentation discipline is still manual and must be maintained. |
| Phase 1: Domain-aware public behavior | `Implemented` | Hostname-aware runtime, provider landing, tenant homepage, and separate public menu behavior are implemented. |
| Phase 2: Database and tenant provisioning | `Partial` | Checklist and provisioning scripts exist, but DNS, vhost, SSL, and final go-live remain manual. |
| Phase 3: White-label completeness | `Partial` | Branding surface, separate address/map-link model, and tenant seed exist; remaining gaps are per-deployment public-entry configuration and launch-time validation. |
| Phase 4: Restaurant-ready UX cleanup | `Partial` | Major icon cleanup, help surface, and broad shell improvements are done, but stale-order cleanup and full shell normalization remain. |
| Phase 5: Optional automation | `Partial` | Provisioning, seed automation, and automatic provider/tenant post-merge smoke exist, but launch is not fully one-click and does not emit a final launch artifact. |

## What Already Exists and Should Be Reused

- working order engine
- role-based backoffice
- mobile API
- repeat-order flow
- owner analytics foundation
- monitor and security smoke foundation
- white-label settings surface in admin
- tenant provisioning and demo seed scripts

## Phase Details

### Phase 0: Documentation Reset

Current depth:

- active docs live under `docs/`
- active docs now describe provider vs tenant explicitly

Remaining work:

- keep docs synchronized with future releases
- avoid new drift between repo state and public behavior

### Phase 1: Domain-Aware Public Behavior

Implemented:

- provider `/` => B2B landing
- tenant `/` => restaurant-facing homepage
- tenant `/menu.php` => restaurant transactional menu
- provider and tenant public content separated by runtime mode

### Phase 2: Database and Tenant Provisioning

Implemented:

- separate tenant databases
- control-plane runtime lookup
- tenant provisioning script
- tenant launch checklist
- tenant demo seed script

Still partial because:

- DNS setup remains manual
- vhost setup remains manual
- SSL issuance remains manual
- final production smoke remains operator-driven

### Phase 3: White-Label Completeness

Implemented:

- settings-driven name, tagline, description, phone, logo, favicon, colors, fonts, social links
- separate restaurant-friendly demo seed

Still partial because:

- some white-label surfaces still require launch-time QA, not just settings
- tenant public-entry mode is still not configurable per deployment

### Phase 4: Restaurant-Ready UX Cleanup

Implemented:

- tenant restaurant homepage
- critical icon-font cleanup in visible public/account surfaces
- order-card metadata compression in key staff/customer views
- shared help surface for privileged roles

Still partial because:

- stale active orders are not handled as a completed product cleanup track
- internal UI shell is improved but not fully normalized across every operational page

### Phase 5: Optional Automation

Implemented:

- tenant DB bootstrap and seed automation
- smoke script coverage for seeded tenant basics
- production `post-merge` hook runs provider/tenant regression smoke automatically on the production checkout path

Still partial because:

- launch still needs manual infra work
- there is no single end-to-end tenant launch artifact or one-command go-live flow
- production smoke is automated after pull, but final launch acceptance is still operator-driven

## Recommended Next Execution Order

1. Finish internal-shell normalization on remaining operational pages.
2. Convert stale-order cleanup into an explicit product + ops mechanic.
3. Tighten launch automation around DNS/vhost/SSL and final launch artifact generation.
