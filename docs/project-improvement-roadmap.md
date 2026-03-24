# Project Improvement Roadmap

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-24`
- Current implementation notes:
  - The core provider/tenant model is implemented.
  - A full `repo + live` documentation audit resynchronized active docs on `2026-03-23`.
  - Shared stale-order lifecycle and tenant launch contracts are now explicit in code and operator flow.
  - Release discipline now includes docs-drift checks, baseline capture, provider/tenant smoke, and provider security smoke.
  - Tenant go-live is now scriptable end-to-end on the target host via `scripts/tenant/go-live.sh`, with DNS remaining an external prerequisite.

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
| Phase 0: Documentation reset | `Implemented` | Core docs are synchronized and release/main pushes now include a docs-drift guard. |
| Phase 1: Domain-aware public behavior | `Implemented` | Hostname-aware runtime, provider landing, tenant homepage, and separate public menu behavior are implemented. |
| Phase 2: Database and tenant provisioning | `Implemented` | Provisioning, seed, launch artifact generation, and server-side go-live automation now exist; DNS ownership remains an external input. |
| Phase 3: White-label completeness | `Implemented` | Branding surface, address/map split, public-entry mode, validation, and launch-acceptance summary are implemented. |
| Phase 4: Restaurant-ready UX cleanup | `Implemented` | Shared shell primitives, lifecycle badges, help surface, and stale-order cleanup flow are now centralized across operational pages. |
| Phase 5: Optional automation | `Implemented` | Provisioning, launch artifact generation, one-command go-live, and automatic post-merge smoke/baseline/security checks now exist. |

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

- keep docs synchronized with future releases through the release hook
- avoid bypassing the docs-drift guard on release-bearing changes

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

Operational note:

- external DNS ownership still must be confirmed before running go-live on the target host

### Phase 3: White-Label Completeness

Implemented:

- settings-driven name, tagline, description, phone, logo, favicon, colors, fonts, social links
- separate restaurant-friendly demo seed

Operational note:

- launch acceptance is now explicit and artifact-driven; remaining operator duty is final live sign-off after deploy

### Phase 4: Restaurant-Ready UX Cleanup

Implemented:

- tenant restaurant homepage
- critical icon-font cleanup in visible public/account surfaces
- order-card metadata compression in key staff/customer views
- shared help surface for privileged roles

Operational note:

- non-critical visual polish may still continue, but the shared shell contract and stale-order operator flow are in place

### Phase 5: Optional Automation

Implemented:

- tenant DB bootstrap and seed automation
- smoke script coverage for seeded tenant basics
- production `post-merge` hook runs provider/tenant regression smoke automatically on the production checkout path

Operational note:

- go-live is one-command on the target host once DNS is ready; production acceptance still requires a human release owner

## Recommended Next Execution Order

1. Keep provider/tenant non-regression smoke green on every release.
2. Execute host-level security rollout for firewall, SSH/fail2ban, and patch cadence on the production host.
3. Continue only low-risk UI polish and integration hardening, not foundational shell or launch rewrites.
