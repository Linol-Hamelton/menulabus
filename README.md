# Menu Labus

White-label restaurant menu and ordering platform.

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-17`
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current implementation notes:
  - Provider and tenant runtime split is implemented and live.
  - Tenant homepage is implemented, but tenant public entry is not configurable per deployment yet.
  - Tenant provisioning and demo seed automation exist, but DNS, vhost, SSL, and final go-live remain manual ops steps.

## Current Product Model

- `menu.labus.pro` is the provider-owned B2B deployment.
- Client restaurant deployments run the same codebase on their own domains with separate databases.
- The ordering engine, roles, API, and most operational pages are shared in code and isolated by tenant data.

## Core Rules

- `1 client = 1 separate database`
- Database names should include the client brand slug.
- Provider marketing content must not appear on tenant public domains.
- Active documentation lives under [`docs/`](./docs/index.md).
- Historical snapshots live under `docs/archive/` and are not current-state source of truth.

## Key Docs

- Documentation map: [`docs/index.md`](./docs/index.md)
- Product model: [`docs/product-model.md`](./docs/product-model.md)
- Project reference: [`docs/project-reference.md`](./docs/project-reference.md)
- Priority roadmap: [`docs/project-improvement-roadmap.md`](./docs/project-improvement-roadmap.md)
- UX/UI roadmap: [`docs/ux-ui-improvement-roadmap.md`](./docs/ux-ui-improvement-roadmap.md)
- Tenant launch runbook: [`docs/tenant-launch-checklist.md`](./docs/tenant-launch-checklist.md)
- Deployment workflow: [`docs/deployment-workflow.md`](./docs/deployment-workflow.md)
- Security docs: [`docs/security-hardening-roadmap.md`](./docs/security-hardening-roadmap.md)
- OpenAPI contract: [`docs/openapi.yaml`](./docs/openapi.yaml)

## Local Quick Checks

```bash
npm ci
npm run openapi:validate
```

## Current Constraints

- Shared-host scope lock: menu-only changes must not modify Docker/ports for other sites on the same host.
- API contract source of truth: `docs/openapi.yaml`.
