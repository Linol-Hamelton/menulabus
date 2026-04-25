# Documentation Index

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-23`
- Current implementation notes:
  - This directory is the source of truth for active project documentation.
  - Last strategic refresh: `2026-04-23` — added `product-vision-2027.md`, expanded roadmap with Phases 6-9, documented reservations / webhooks / admin UX tracks.
  - A full `repo + live` documentation audit was completed on `2026-04-11`.
  - No active `archive/` or quarantine layer is maintained in this branch.

## Strategic

Start here for the «why»:

- [**Product Vision 2027**](./product-vision-2027.md) — North-star, Phases 6-9 roadmap, sacred rules, anti-goals, success metrics. Revisit every 3 months.

## Core Docs

Read these first and keep them current:

- [Product Model](./product-model.md)
- [Feature Audit Matrix](./feature-audit-matrix.md)
- [Project Reference](./project-reference.md)
- [Order Lifecycle Contract](./order-lifecycle-contract.md)
- [Project Improvement Roadmap](./project-improvement-roadmap.md) — tactical, Phases 0-9.
- [Tenant Launch Checklist](./tenant-launch-checklist.md)
- [Deployment Workflow](./deployment-workflow.md)
- [OpenAPI Contract](./openapi.yaml)

## Shipped Modules (reference)

Per-module reference after each track ships:

- [Reservations](./reservations.md) — table booking end-to-end.
- [Webhook Integration](./webhook-integration.md) — outgoing webhook platform.
- [Admin Menu UX](./admin-menu-ux.md) — drag-n-drop, bulk actions, hotkeys, filters, undo.
- [Kitchen Display System](./kds.md) — per-station live board, routing, order-ready trigger.
- [Inventory MVP](./inventory.md) — ingredient stock, recipes, auto-deduction, low-stock alerts.
- [Loyalty Program](./loyalty.md) — tiered cashback, promo codes, customer widget, `payment.received` webhook.
- [Enhanced Analytics (v2)](./analytics.md) — dish margins, cohorts, 7×24 heatmap, EWMA forecast.
- [Multi-location](./multi-location.md) — chain of restaurants within one tenant DB.
- [Reviews](./reviews.md) — feedback loop with Google review deep-link.
- [Modifiers](./modifiers.md) — modifier groups and options data model.
- [Tips](./tips.md) — tipping surface and persistence.
- [Payments Integration](./payments-integration.md) — YooKassa / T-Bank / SBP / cash.
- [Telegram Bot Setup](./telegram-bot-setup.md) — bot registration, webhook, inline keyboards.
- [PWA and Push](./pwa-and-push.md) — service worker, VAPID rotation.
- [Schema and Migrations](./schema-and-migrations.md) — migration order and SQL contracts.
- [Testing Strategy](./testing-strategy.md) — PHPUnit + Playwright pyramid.

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
- Release-bearing contract changes are expected to pass the docs-drift guard in `scripts/docs/check-doc-drift.sh`.
- API contract source of truth: `docs/openapi.yaml`.
