# Project Improvement Roadmap

## Implementation Status

- Status: `Implemented (Phases 0-5) + Planned (Phases 6-9)`
- Last reviewed: `2026-04-23`
- Current implementation notes:
  - Phases 0-5 (white-label foundation, launch automation, UX polish, release automation) — `Implemented`.
  - Additional foundational work shipped since 2026-04-11: `lib/Csrf.php`, table reservations end-to-end, outgoing webhook platform, admin UX track (drag-n-drop / bulk / hotkeys / filters / undo).
  - Strategic vision for the next 12 months captured separately in [product-vision-2027.md](./product-vision-2027.md).
  - Phases 6-9 below are the tactical projection of that vision onto release trains.
  - The core provider/tenant model is implemented.
  - Release discipline now includes docs-drift checks, baseline capture, provider/tenant smoke, and provider security smoke.
  - Tenant go-live is now scriptable end-to-end on the target host via `scripts/tenant/go-live.sh`, with DNS remaining an external prerequisite.

## Goal

**Short-term (Phases 0-5):** Keep `menu.labus.pro` as the provider-owned B2B showcase while making restaurant tenant launches predictable, low-risk, and repeatable.

**Long-term (Phases 6-9):** Grow from «white-label menu + orders» into a full restaurant management SaaS on par with iiko / R_Keeper / Poster, but guest-centric, SaaS-native, and integration-friendly. See [product-vision-2027.md](./product-vision-2027.md) for the strategic «north star».

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
| Phase 6: Restaurant Operations Core | `Planned (Q2 2026)` | KDS, inventory MVP, loyalty program, enhanced analytics, multi-location. |
| Phase 7: Platform & Integration | `Planned (Q3 2026)` | iiko adapter, 54-ФЗ fiscal, full i18n, staff management, advanced payments (split bill). |
| Phase 8: Growth & Retention | `Planned (Q4 2026)` | Marketing automation, AI recommendations, group ordering, waitlists, review moderation. |
| Phase 9: Enterprise & Platform | `Planned (Q1 2027)` | SaaS billing engine, developer platform (public API + SDK + marketplace), compliance pack, multi-region, onboarding 2.0. |

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

### Near-term (closing deferred debt)

1. Keep provider/tenant non-regression smoke green on every release.
2. Execute host-level security rollout for firewall, SSH/fail2ban, and patch cadence on the production host.
3. Apply pending migrations on live tenants: `menu-sort-order-migration.sql`, `modifiers-soft-delete-migration.sql`, `webhooks-migration.sql`, `reservations-migration.sql`.
4. Wire cron jobs: `webhook-worker.php`, `scripts/orders/purge-soft-deleted.php`, `scripts/security/monthly-review.sh`.
5. Close remaining CSRF gaps on `api/save/project-name.php` and `send_message.php` (either update minified JS callers or deprecate).
6. Finish Mobile Capacitor tenant-aware rework (preferences-driven `server.url`).

### Medium-term — Phase 6 (Restaurant Operations Core)

| Track | Key deliverables |
|---|---|
| 6.1 Kitchen Display System | New `/kds.php` surface, per-station routing (hot/cold/bar/pizza), drag-to-acknowledge, SSE or WS live updates. |
| 6.2 Inventory MVP | Tables `ingredients`, `recipes`, `stock_movements`; auto-deduction on `order.created`; admin UI for recipes; low-stock alerts via Telegram. |
| 6.3 Loyalty Program | Points engine with tier levels (Bronze/Silver/Gold), cashback in points, promo codes v2, birthday bonuses. |
| 6.4 Enhanced Analytics | Per-item margin (uses existing `cost`), cohort analysis, day×hour heatmap, weekly revenue forecast, funnel view. |
| 6.5 Multi-location | `location_id` on orders/menu/reservations; cross-location owner reports; chain-wide stop-list sync. |

### Medium-term — Phase 7 (Platform & Integration)

| Track | Key deliverables |
|---|---|
| 7.1 iiko adapter | `lib/integrations/Iiko.php`, two-way sync (menu, orders, stop-list, stock), cron-based reconciliation. |
| 7.2 54-ФЗ fiscal | Integration with Atol-Online or Evotor Chek Online, electronic receipt emission on `order.paid`. |
| 7.3 Full i18n | `/locales/{ru,en,kk}.json`, `lib/I18n.php`, migration of customer-facing pages first, then admin. |
| 7.4 Staff Management | `shifts`, `time_entries`, `tip_splits`; distribution of pooled tips by role/hours; KPI dashboard for each role. |
| 7.5 Advanced Payments | Split bill per seat / per item, pay-per-person QR links, delayed/scheduled payments. |

### Long-term — Phase 8 (Growth & Retention)

| Track | Key deliverables |
|---|---|
| 8.1 Marketing Automation | Email / SMS / Push campaigns, trigger scenarios (abandoned cart, birthday, win-back). |
| 8.2 AI Recommendations | Smart upsell in the cart, menu optimization hints in owner reports, demand forecast. |
| 8.3 Group Ordering | Multiple guests at one table each adding items via QR, consolidated or split bill. |
| 8.4 Waitlists | "Встать в очередь" form when fully booked, SMS ping on seat available. |
| 8.5 Review Moderation | Owner replies to reviews, publication of best reviews on the tenant site, moderation via Telegram. |

### Long-term — Phase 9 (Enterprise & Platform)

| Track | Key deliverables |
|---|---|
| 9.1 SaaS Billing Engine | Plans (Starter/Pro/Enterprise), usage-based billing, Stripe/Paddle, 14-day free trial, promo codes. |
| 9.2 Developer Platform | Public API with rate-limits and API keys, JS/Python SDKs, extension marketplace, Zapier integration. |
| 9.3 Compliance Pack | GDPR + 152-ФЗ (data export, right to delete), ЕГАИС for alcohol, Меркурий for meat/fish, 2FA for admin/owner, audit log. |
| 9.4 Multi-region / HA | DB replication, read replicas for analytics, CDN for assets, automatic failover. |
| 9.5 Onboarding 2.0 | Template library (fast-food / fine-dining / cafe / bar / pizzeria), interactive demo, in-app help center, chat support. |

### Cross-cutting investments

These tracks do not belong to a single phase — they accelerate everything downstream:

- **Observability.** Monolog + Sentry / Glitchtip, Prometheus metrics, Grafana dashboards.
- **Test coverage.** Raise `lib/*` coverage to ≥ 60%; extend Playwright smoke to KDS, inventory, loyalty.
- **Developer experience.** Docker Compose for local dev, PSR-4 autoload, `CHANGELOG.md`, semantic versioning.
- **Performance.** AssetPipeline in production, lazy-loading + WebP, cache warm-up after menu writes.
- **Accessibility.** ARIA on icon-only buttons, `role="dialog"` + focus-trap for modals, WCAG AA contrast.

See [product-vision-2027.md §7](./product-vision-2027.md) for the rationale.
