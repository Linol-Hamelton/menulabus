# Documentation Index

## Implementation Status

- Status: `Implemented (Phases 0-8 + 14)`
- Last reviewed: `2026-05-03` (post Phase 14 SaaS Billing release v2.0.0)
- Current implementation notes:
  - This directory is the source of truth for active project documentation.
  - **Phases 6-8 + Phase 14 shipped** — see [feature-audit-matrix.md](./feature-audit-matrix.md) for the row-by-row status.
  - Phase 9 sub-tracks (AI recommendations, dev platform, compliance pack, multi-region HA, onboarding 2.0) + iiko adapter (Phase 7.1) — in `planned`.
  - `archive/2026/` exists for retired execution-guides; convention in `archive/2026/README.md`.

## Strategic

Start here for the «why»:

- [**Product Vision 2027**](./product-vision-2027.md) — North-star, sacred rules, anti-goals, success metrics. Revisit every 3 months.
- [**Architecture Map**](./architecture-map.md) — feature ⇆ files / API / DB / cron map. Onboarding entry point.
- [**UI Patterns**](./ui-patterns.md) — canonical block compositions for new UI work.

## Core Docs

Read these first and keep them current:

- [Product Model](./product-model.md)
- [Feature Audit Matrix](./feature-audit-matrix.md)
- [Project Reference](./project-reference.md)
- [Order Lifecycle Contract](./order-lifecycle-contract.md)
- [Project Improvement Roadmap](./project-improvement-roadmap.md) — tactical, Phases 0-14.
- [Tenant Launch Checklist](./tenant-launch-checklist.md)
- [Deployment Workflow](./deployment-workflow.md)
- [OpenAPI Contract](./openapi.yaml)

## Shipped Modules (reference)

Per-module reference after each track ships:

### Customer flow
- [Reservations](./reservations.md) — table booking end-to-end + 2h reminder + availability picker.
- [Group Orders + Split-Bill](./group-split-bill.md) — shared tab with QR + per-seat / equal-share payment.
- [Loyalty Program](./loyalty.md) — tiered cashback, promo codes, customer widget.
- [Reviews](./reviews.md) — feedback loop with Google review deep-link.
- [Modifiers](./modifiers.md) — modifier groups and options data model.
- [Tips](./tips.md) — tipping surface and persistence.
- [Payments Integration](./payments-integration.md) — YooKassa / T-Bank / SBP / cash.
- [54-ФЗ Fiscal](./fiscal-54fz.md) — АТОЛ Онлайн integration, receipt URL on `order.paid`.
- [PWA and Push](./pwa-and-push.md) — service worker, VAPID rotation.

### Operations
- [Kitchen Display System](./kds.md) — per-station live board, routing, `order.ready` trigger.
- [Inventory MVP](./inventory.md) — ingredient stock, recipes, auto-deduction, low-stock alerts.
- [Multi-location](./multi-location.md) — chain of restaurants within one tenant DB.
- [Staff Management v2](./staff-v2.md) — shifts, time clock, tip splits, swap requests, payroll CSV.
- [Enhanced Analytics (v2)](./analytics.md) — dish margins, cohorts, 7×24 heatmap, EWMA forecast.
- [Webhook Integration](./webhook-integration.md) — outgoing webhook platform.
- [Telegram Bot Setup](./telegram-bot-setup.md) — bot registration, webhook, inline keyboards.

### Platform / SaaS
- [**SaaS Billing Engine**](./billing.md) (Phase 14) — self-service signup, plan registry, recurring YK, soft dunning, provider admin.
- [i18n](./i18n.md) — `t()` helper, ru/en/kk locales, extract scanner.
- [Admin Menu UX](./admin-menu-ux.md) — drag-n-drop, bulk actions, hotkeys, filters, undo.

### Internal / dev
- [Schema and Migrations](./schema-and-migrations.md) — migration order and SQL contracts.
- [Testing Strategy](./testing-strategy.md) — PHPUnit + Playwright pyramid.

## UX and Public Layer

- [UI Patterns](./ui-patterns.md) — canonical block compositions for new UI.
- [Public Layer Guidelines](./public-layer-guidelines.md)
- [UX/UI Improvement Roadmap](./ux-ui-improvement-roadmap.md)
- [UX Walk-through Diagnostic](./ux-walkthrough-2026-04-28.md) — checklist of 14 surfaces × 3 viewports.
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
