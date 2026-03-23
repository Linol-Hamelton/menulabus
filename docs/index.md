# Documentation Index

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - This directory is the source of truth for active project documentation.
  - A full `repo + live` documentation audit was completed on `2026-03-23`.
  - No active `archive/` or quarantine layer is maintained in this branch.

## Core Docs

Read these first and keep them current:

- [Product Model](./product-model.md)
- [Feature Audit Matrix](./feature-audit-matrix.md)
- [Project Reference](./project-reference.md)
- [Project Improvement Roadmap](./project-improvement-roadmap.md)
- [Tenant Launch Checklist](./tenant-launch-checklist.md)
- [Deployment Workflow](./deployment-workflow.md)
- [OpenAPI Contract](./openapi.yaml)

## UX and Public Layer

- [Public Layer Guidelines](./public-layer-guidelines.md)
- [UX/UI Improvement Roadmap](./ux-ui-improvement-roadmap.md)
- [Tenant Demo Seed](./tenant-demo-seed.md)

## Training and Demo

- [Backoffice Role Helpers](./backoffice-role-helpers.md)
- [Menu Capabilities Presentation](./menu-capabilities-presentation.md)

## Security and Ops

- [Security Hardening Roadmap](./security-hardening-roadmap.md)
- [Security Smoke Checklist](./security-smoke-checklist.md)
- [Security Phase Commands](./security-phase-commands.md)
- [Security Phase 2 Inventory](./security-phase-2-inventory.md)
- [Security Change Log Template](./security-change-log-template.md)
- [API Smoke Checks](./api-smoke.md)
- [Nginx Pool Split Notes](./deploy/nginx-pool-split.md)
- [PHP-FPM Pool Split Notes](./deploy/php-fpm-pool-split.md)
- [Git Hooks](./dev/git-hooks.md)
- [DB Backfill: order_items](./db/backfill-order-items.md)

## Optional Integrations

- [Mobile Capacitor Wrapper](./mobile/capacitor-wrapper.md)
- [VK OAuth Setup](./vk-oauth-setup.md)
- [Yandex OAuth Setup](./yandex-oauth-setup.md)

## Rules

- Keep all active project documentation under `docs/`.
- Keep the audit baseline in `docs/feature-audit-matrix.md` current when major behavior changes land.
- API contract source of truth: `docs/openapi.yaml`.
