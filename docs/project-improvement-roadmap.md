# Project Improvement Roadmap

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-17`
- Current implementation notes:
  - The core provider/tenant model is implemented.
  - Remaining work is mostly in launch automation, white-label completeness, and final UX/ops polish.

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
| Phase 3: White-label completeness | `Partial` | Branding surface, separate address/map-link model, and tenant seed exist, but per-deployment public-entry configuration and some completeness gaps remain. |
| Phase 4: Restaurant-ready UX cleanup | `Partial` | Major icon cleanup and order-card compression are done, but stale-order cleanup and full shell normalization remain. |
| Phase 5: Optional automation | `Partial` | Provisioning and seed automation exist, but launch is not fully one-click and does not emit a final launch artifact. |

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
- historical docs live under `docs/archive/`
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

Still partial because:

- stale active orders are not handled as a completed product cleanup track
- internal UI shell is improved but not fully normalized across every operational page

### Phase 5: Optional Automation

Implemented:

- tenant DB bootstrap and seed automation
- smoke script coverage for seeded tenant basics

Still partial because:

- launch still needs manual infra work
- there is no single end-to-end tenant launch artifact or one-command go-live flow
- regression smoke is scriptable, but still operator-triggered after release

## Recommended Next Execution Order

1. Finish internal-shell normalization on remaining operational pages.
2. Tighten launch automation around DNS/vhost/SSL runbooks.
3. Keep docs in lockstep with each release step.
