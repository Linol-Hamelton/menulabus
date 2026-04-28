# Project Audit & Strategic Review — Menu Labus

- **Date:** 2026-04-11
- **Auditor:** Claude Opus 4.6
- **Scope:** full `docs/` ↔ implementation cross-check, stale-doc sweep, competitive benchmark (global top 10 + Russian top 3), product-level recommendations for reaching a top-1 "customized restaurant menu + delivery + sales-and-ops" SaaS.
- **Method:** read every active doc under `docs/`, walked the repository (PHP files, `api/v1/*`, `lib/*`, `scripts/*`, `sql/*`, `deploy/*`, `mobile/*`, `js/`, `css/`), ran spot-checks on concrete files, researched the current market with targeted web searches.
- **Not executed in this audit:** live HTTP checks against `menu.labus.pro` / `test.milyidom.com`, load tests, security penetration. All live-behavior claims carried over from the docs are marked as such.

---

## 0. Executive summary

Menu Labus today is a **solid, production-grade white-label restaurant menu and ordering engine**, much larger than a "simple online menu". The code already includes a provider/tenant runtime split, per-tenant databases, REST API v1 for mobile, PWA shell with push, an owner analytics surface, staff/admin backoffice, Telegram bot notifications, YooKassa + T-Bank payment integrations, SBP support, modifiers, tips, ABC analysis, QR-table ordering, an onboarding wizard, and a fully automated git-based deploy with pre-push docs/OpenAPI/mojibake gates and post-merge browser regression.

The docs are well structured and mostly honest — but they are also **noticeably behind the code**. The last full audit cycle landed on `2026-03-23 / 2026-03-25 / 2026-03-26` and almost every doc carries that timestamp. Since then the code has gained at minimum: the hero redesign (today), modifiers UI polish, admin CSRF fallback chain, admin catalog reorder, tablet-width table stacking, and admin-modifiers asset versioning — only the UX/UI roadmap actually reflects the last chunk, and no other doc has been refreshed.

The bigger doc gap is **whole feature areas that exist in code but are not described anywhere**: the payment layer (YooKassa + T-Bank + SBP + cash), Telegram bot, modifiers, tips, PWA push notifications, ABC analysis, stop-list Telegram alerts, onboarding wizard, custom-domain nginx templating, and the entire `sql/` migration directory. Several of these are documented indirectly (README of the feature matrix says "implemented"), but there is no surface doc an operator or new engineer could read to understand the mechanics.

Competitively, the product is closer to **Zenky / Restik / Foodeon** (Russian SaaS menu-site builders with delivery + POS integrations) than to iiko / r_keeper / Toast (full POS ecosystems). To land top-1 in its real segment — "kastomized customer-facing restaurant site with online ordering and light ops layer" — Menu Labus already has most of the building blocks; what it **does not yet have** is: deep CRM / customer segmentation, automated marketing (email/SMS push flows), reviews & reputation, reservations, kitchen display / KDS contract, iiko/r_keeper adapters, and an AI layer (recommendations, demand forecasting, review sentiment, upsell prompts). Filling those, together with a small QA-first investment, is the straightest path to dominance.

This document is split into:

1. **Section A** — Docs vs. implementation: concrete mismatches, contradictions, and drift.
2. **Section B** — Feature areas that live in code but are not (or barely) documented.
3. **Section C** — Stale / outdated documents, with a recommended action per file.
4. **Section D** — Competitive benchmark: global top 10 + Russian top 3, scored against Menu Labus.
5. **Section E** — Strategic improvement roadmap to reach a top-1 position in the target segment.
6. **Section F** — Recommended near-term execution order (what to do next Monday).

---

## Section A — Documentation vs. Implementation

### A.1. `README.md` is the most out-of-date file in the repo

`README.md` still says (last-reviewed `2026-03-23`):

> Tenant homepage is implemented, but **tenant public entry is not configurable per deployment yet**.
> Tenant provisioning and demo seed automation exist, but **DNS, vhost, SSL, and final go-live remain manual ops steps**.

Both statements are contradicted by newer docs:

- `docs/feature-audit-matrix.md` (`2026-03-25`): "public entry config — tenant `/` is configurable per deployment: `homepage` keeps the restaurant landing, `menu` redirects straight to `/menu.php`". Listed as `implemented`.
- `docs/tenant-launch-checklist.md` (`2026-03-24`): "One-command server-side go-live is now available through `scripts/tenant/go-live.sh`… DNS ownership remains an external prerequisite."
- `docs/project-improvement-roadmap.md` (`2026-03-24`): "Phase 2: Database and tenant provisioning — Implemented… Phase 5: Optional automation — Implemented."

And the code supports them: `scripts/tenant/go-live.sh`, `lib/tenant/launch-contract.php`, and `public_entry_mode` handling in `session_init.php` + `api/save/brand.php` + `index.php` all exist.

**Recommendation:** rewrite `README.md` in one pass to match the current feature matrix — it is the file a new engineer or potential customer sees first, and it is currently underselling the product.

### A.2. UX/UI roadmap drift (minor, now closing)

`docs/ux-ui-improvement-roadmap.md` was last bumped today (`2026-04-11`) with the hero redesign note. Before today it was still stamped `2026-03-26`. The rest of the roadmap reads correctly against the code — shared shell polish, bottom-dock tab rail, admin catalog reorder, modifiers CSRF fallback, etc. are all real and verifiable with `Grep`. **No action needed.**

### A.3. Docs-drift guard trips on purely visual hero changes

`scripts/docs/check-doc-drift.sh` classifies `index.php` and `css/*` as the `launch` / `shell` "contract-bearing" surfaces. That fired today on a purely visual hero redesign, forcing a one-line doc update. The guard is working as designed, but the heuristic is broader than "contract". Two options:

- **Keep as-is** — small tax, encourages doc hygiene.
- **Tighten the classifier** — exclude `css/*` from the `shell` surface, or require that `.php` changes actually modify business logic (not just markup). The doc drift guard would then stop blocking pure CSS/markup polish.

**Recommendation:** keep the guard, but extract the "contract" definition into a short note at the top of `check-doc-drift.sh` and in `docs/dev/git-hooks.md`, and consider moving `css/*` to a softer warning tier instead of a hard block. Not urgent.

### A.4. Security hardening roadmap is frozen at Phase 2/3/5 = Partial

`docs/security-hardening-roadmap.md` has been `Partial` for Phase 2 (network hardening), Phase 3 (SSH/fail2ban), and Phase 5 (monthly review cadence) since `2026-03-23`. The repo-owned scripts for all three (`apply-network-policy.sh`, `harden-ssh-fail2ban.sh`, `monthly-review.sh`) exist. What is missing is **evidence of execution on the production host**: there is no change-log entry (`docs/security-change-log-template.md` is still an empty template), no recorded allowlist, no fail2ban confirmation.

Gap type: **execution drift, not code drift.** The code is ready; the ops cadence has not picked it up.

**Recommendation:** do one complete security phase end-to-end on the next quiet window (Phase 2 is the safest starting point), record the result in `docs/security-change-log-<date>.md` (rename the template), and update the roadmap status to `Implemented` for that phase. The rest follows the same pattern.

### A.5. Capacitor mobile wrapper is still provider-pinned

`docs/mobile/capacitor-wrapper.md` explicitly says: "Current `server.url` is provider-centric and points to `https://menu.labus.pro/menu.php`. This is not yet a tenant-aware mobile strategy." The code in `mobile/capacitor.config.ts` reflects that. This is an **honestly documented** gap, not drift — it just has not been closed.

For a white-label product, a per-tenant mobile story is important. See Section E.3 for the recommended approach.

### A.6. `docs/order-lifecycle-contract.md` has an open follow-up that is now actionable

The doc lists:

> - Decide whether stale thresholds need per-tenant configurability.
> - Extend post-release smoke to assert at least one lifecycle-aware render on internal order pages.

Both are still genuinely open. The stale threshold is hard-coded at 45 minutes in `lib/orders/lifecycle.php`. Per-tenant configurability is a ~1-day change (add a `settings` key, fall back to default). Smoke assertion is a ~1-hour change in `scripts/perf/post-release-regression.sh`.

### A.7. `docs/api-smoke.md` doc is accurate, but smoke runner protection is by nginx + PHP guard

`scripts/api-smoke-runner.php` correctly refuses web access (`PHP_SAPI !== 'cli'` guard + 404). The nginx-level block also exists in `nginx-optimized.conf` and `deploy/nginx/custom-domain-template.conf` (`location ^~ /scripts/ { return 404; }`). Both layers are in place. This is a good example where the docs, the script, and the nginx config are all consistent. **No action.**

### A.8. OpenAPI covers only a slice of the real API surface

`docs/openapi.yaml` is the authoritative mobile API contract, and the pre-push hook gates it for pushes to `main`. But many endpoints that the app actually uses in the browser (CSRF-protected POSTs from the backoffice and customer flows) are **not** modeled in OpenAPI, because OpenAPI is scoped to `api/v1/*` only. Legitimate scoping decision — but it means the docs' frequent phrase "API contract source of truth: `docs/openapi.yaml`" is slightly misleading: it is the **mobile API contract**, not the overall API contract. Web endpoints (`api/save/brand.php`, `api/save/colors.php`, `toggle-available.php`, `update_order_status.php`, `update_user_role.php`, `confirm-cash-payment.php`, `generate-payment-link.php`, etc.) have **no schema**.

**Recommendation:** either (a) rename the OpenAPI file to `mobile-api.yaml` and say so in docs, or (b) add a second, slimmer schema `docs/web-endpoints.yaml` that enumerates the web POSTs, their CSRF expectations, and response shapes. Option (a) is the cheap fix; option (b) pays off during QA and external audits.

### A.9. Minor naming drift

- Feature matrix references `api/v1/bootstrap.php` "as an internal helper include, not a public contract endpoint" — this is accurate and matches the code.
- `docs/project-reference.md` lists `scripts/perf/post-release-regression.cjs` and `scripts/perf/post-release-regression.sh` — both exist.
- `docs/deployment-workflow.md` uses `BRANCH="main"` and `release/<name>` as examples — consistent with the actual release branch pattern (current branch is `release/bottom-dock-owner-toolbar-2026-03-26`). **No drift.**

---

## Section B — Features that live in code but are not (or barely) documented

These are genuine **doc gaps**, not bugs — the features work, they are just invisible to anyone reading `docs/`.

### B.1. Payment layer (YooKassa + T-Bank + SBP + cash)

**What exists in code:**

- [lib/TBank.php](lib/TBank.php) — full T-Bank (Тинькофф) payment provider integration class.
- [generate-payment-link.php](generate-payment-link.php), [payment-return.php](payment-return.php), [payment-webhook.php](payment-webhook.php), [confirm-cash-payment.php](confirm-cash-payment.php) — YooKassa + cash + link-generation flow.
- [sql/payment-migration.sql](sql/payment-migration.sql) — payment columns schema migration.
- [sql/sbp-migration.sql](sql/sbp-migration.sql) — SBP (Система быстрых платежей) support.
- [api/save/payment-settings.php](api/save/payment-settings.php) — admin UI backend for provider selection.
- [js/employee-payments.js](js/employee-payments.js) — employee-side cash/link confirmation.

**What docs say:** `docs/project-reference.md` section 10 lists the payment routes as "present in repo, repo-first audited only". There is **no integration guide, no credential variable list, no fail/rollback narrative, no SBP note, no T-Bank note, no idempotency note** (despite `lib/Idempotency.php` being a real class used by `api/v1/orders/create.php`).

**Recommendation:** create `docs/payments-integration.md` covering: supported providers (YooKassa, T-Bank, SBP, cash), required env vars / settings keys, webhook URL registration, idempotency behavior, admin test flow, common failure modes, and where each piece lives in code. This is a top-3 priority doc for external operator trust.

### B.2. Telegram bot integration

**What exists in code:**

- [telegram-notifications.php](telegram-notifications.php) + [telegram-webhook.php](telegram-webhook.php) — bot with inline keyboard accept/reject callbacks.
- [send_message.php](send_message.php) — separate send entry point.
- Tie-ins from [toggle-available.php](toggle-available.php), [admin-menu.php](admin-menu.php), and [api/save/brand.php](api/save/brand.php).

**What docs say:** the feature matrix mentions "Telegram webhook / notifications — present in repo, repo-first audited only". **No setup doc exists.** Operators cannot onboard a bot without reading PHP source.

**Recommendation:** `docs/telegram-bot-setup.md` covering: bot registration in `@BotFather`, the `TELEGRAM_BOT_TOKEN` constant in `config.php`, the `telegram_chat_id` setting, webhook URL registration command, what each notification type contains, how stop-list alerts trigger, and how to test end-to-end.

### B.3. Modifier groups and options

**What exists in code:**

- [sql/modifiers-migration.sql](sql/modifiers-migration.sql) — tables.
- [api/save-modifiers.php](api/save-modifiers.php) — REST CRUD (noting: this sits under `api/` not `api/v1/`, which is its own inconsistency — see Section A.8).
- [js/admin-modifiers.js](js/admin-modifiers.js), [js/menu-modifiers.js](js/menu-modifiers.js) — admin editor and customer-side modal.
- `db.php::getModifiersByItemId()` and related methods.

**What docs say:** `docs/backoffice-role-helpers.md` mentions "modifiers management" under the admin focus list. **That is the total documentation.** No schema, no operator instructions, no data flow.

**Recommendation:** `docs/modifiers.md` covering: the data model (`modifier_groups`, `modifier_options`), the `data-modifiers` JSON attribute on `.buy` elements, the modal interception flow, and the admin editor UX (CSV shape, drag-reorder, pricing rules).

### B.4. Tips system

**What exists in code:**

- [sql/tips-migration.sql](sql/tips-migration.sql) — `tips` column on `orders`.
- [js/cart-tips.js](js/cart-tips.js) — UI.
- `db.php::createOrder($tips)` signature.
- YooKassa amount includes tips (per auto-memory).

**What docs say:** nothing.

**Recommendation:** short section in `docs/product-model.md` (or a dedicated `docs/tips.md`) noting that tips are stored separately, not as a line item, and are included in the payment total.

### B.5. PWA push notifications

**What exists in code:**

- [api/v1/push/subscribe.php](api/v1/push/subscribe.php) — documented in OpenAPI ✓.
- [js/push-notifications.min.js](js/push-notifications.min.js) — subscription lifecycle.
- [data/vapid-keys.json](data/vapid-keys.json) — VAPID key pair.
- [offline.html](offline.html), [js/offline-queue.js](js/offline-queue.js) — offline-first queue.
- [manifest.php](manifest.php), [manifest.json](manifest.json), [manifest.webmanifest](manifest.webmanifest) — PWA manifest with dynamic tenant branding.

**What docs say:** OpenAPI documents the subscription endpoint. Nothing explains VAPID key rotation, service-worker lifetimes, offline-queue semantics, or the three-manifest situation (why there are both a static and dynamic manifest).

**Recommendation:** `docs/pwa-and-push.md` covering: VAPID key rotation, service worker scope, offline queue contract (what flushes when), and the reason for three manifest entry points.

### B.6. Onboarding wizard

**What exists in code:** [onboarding.php](onboarding.php) — 5-step wizard with dedicated [css/onboarding.css](css/onboarding.css).

**What docs say:** nothing.

**Recommendation:** note in `docs/tenant-launch-checklist.md` that `onboarding.php` is the preferred first-login experience for a new tenant owner, and describe the 5 steps.

### B.7. Custom-domain nginx templating for tenant go-live

**What exists in code:**

- [deploy/nginx/custom-domain-template.conf](deploy/nginx/custom-domain-template.conf)
- [deploy/nginx/custom-domain-http-bootstrap.conf](deploy/nginx/custom-domain-http-bootstrap.conf)
- [scripts/tenant/go-live.sh](scripts/tenant/go-live.sh) consumes them.

**What docs say:** `docs/tenant-launch-checklist.md` mentions `go-live.sh` and `docs/deploy/nginx-pool-split.md` covers pool split, but the custom-domain HTTP bootstrap + Let's Encrypt dance is not explained.

**Recommendation:** short operator-facing section in `docs/tenant-launch-checklist.md` or a new `docs/deploy/custom-domain-go-live.md` walking through the bootstrap → certbot → final vhost swap.

### B.8. `sql/` migration directory

**What exists:** 25 SQL files in `sql/`. 12 are real project migrations:

- `bootstrap-schema.sql`, `control-plane-schema.sql`
- `menu-archive-sync-migration.sql`, `modifiers-migration.sql`, `tips-migration.sql`, `payment-migration.sql`, `sbp-migration.sql`, `mobile-api-tables.sql`, `mobile-oauth-identities.sql`
- `drop-duplicate-indexes.sql`, `performance-indexes.sql`, `modx-env-check.sql`

**What is junk:** 13 files starting with `modx-` (ModX CMS redirect cleanup, MSOP plugin work, two-phase apply/rollback) — these are **from a different project** and should not be in this repo at all.

**What docs say:** nothing. There is no `docs/schema-and-migrations.md`. The only SQL-adjacent doc is `docs/db/backfill-order-items.md`, which is a one-off runbook.

**Recommendations:**

- **Immediate:** delete the 13 `modx-*.sql` files. They are stale cargo, they confuse `grep`, and they ship in every release clone. (Confirm they are not referenced first — a `Grep` for `modx-plugin-13` across `.php` and `.sh` shows zero callers.)
- **Soon:** write `docs/schema-and-migrations.md` listing real migrations in apply-order, with a short description and the feature they unlock. Link it from `docs/index.md` under "Core Docs".

### B.9. Undocumented operational endpoints and support files

The following files exist but are not even enumerated in `docs/project-reference.md`:

- [api/save/colors.php](api/save/colors.php), [api/save/fonts.php](api/save/fonts.php), [api/save/project-name.php](api/save/project-name.php) — admin settings endpoints, should appear under "branding surface".
- [auto-colors.php](auto-colors.php), [auto-fonts.php](auto-fonts.php), [dynamic-fonts.php](dynamic-fonts.php) — dynamic CSS generation endpoints.
- [download-sample.php](download-sample.php) — CSV template for bulk menu import.
- [verify.php](verify.php) — email verification entry point.
- [update_user_role.php](update_user_role.php), [update_order_status.php](update_order_status.php), [toggle-available.php](toggle-available.php) — mutation endpoints.
- [qr.php](qr.php) vs. [qr-print.php](qr-print.php) — there are two QR surfaces and the distinction is not documented.
- [menu-alt.php](menu-alt.php), [menu-public.php](menu-public.php) — alternate menu views; purpose unclear from docs.
- [send_message.php](send_message.php) — purpose unclear, overlaps with Telegram.

**Recommendation:** do a one-pass "file inventory" update of `docs/project-reference.md` section 5–7 so that every top-level `.php` is either listed or explicitly marked as internal/deprecated. This is the single highest-value doc update in the whole audit — it takes ~2 hours and resolves ~60% of all the "what is this file?" confusion.

### B.10. No automated unit or integration tests

**What exists:** Playwright-based browser regression via `scripts/perf/post-release-regression.{sh,cjs}` and `scripts/perf/full-ui-audit.cjs`. These are **system tests**, not unit tests. Security smoke via `scripts/perf/security-smoke.sh`. Provider/tenant smoke via `scripts/tenant/smoke.php`.

**What does NOT exist:**

- No `phpunit.xml`, no `composer.json` — no PHP unit test framework is installed.
- No `tests/` directory.
- No per-function test coverage of critical paths (`lib/orders/lifecycle.php`, `lib/Idempotency.php`, `db.php::createOrder`, `lib/TBank.php`, `lib/OAuthVK.php`, etc.).

This is **the single largest technical risk** in the project. A payment or order-lifecycle regression has zero safety net below the Playwright layer, which is slow and end-to-end only.

**Recommendation:** add a minimal PHPUnit setup (`composer.json` + `tests/` directory + one test class per `lib/*` file), start with `lib/orders/lifecycle.php`, `lib/Idempotency.php`, and `db.php::createOrder()`. This is a ~2-day investment with a massive long-term payoff. See Section E.5.

---

## Section C — Stale / outdated documents

Every active doc under `docs/` carries a `Last reviewed` stamp. As of today (`2026-04-11`), all of them are at least 16 days old. That is not "rotten", but it is a coherent signal that the doc cycle froze on `2026-03-23..26` and has not been restarted.

### C.1. Clearly stale (contradict newer docs or reality)

| File | Last reviewed | Problem | Recommended action |
|---|---|---|---|
| [README.md](README.md) | `2026-03-23` | Says `public_entry_mode` not configurable yet and DNS/vhost/SSL are manual — both contradict `feature-audit-matrix.md` and `tenant-launch-checklist.md`. | **Rewrite in one pass.** Pull the current truth from the feature matrix. |

### C.2. Frozen at `Partial` despite repo-ready scripts

| File | Last reviewed | Problem | Recommended action |
|---|---|---|---|
| [docs/security-hardening-roadmap.md](docs/security-hardening-roadmap.md) | `2026-03-23` | Phase 2, 3, 5 still `Partial`; scripts exist but no rollout evidence. | Execute Phase 2 end-to-end, record evidence via `security-change-log-template.md`, promote status. |
| [docs/security-phase-2-inventory.md](docs/security-phase-2-inventory.md) | `2026-03-23` | Inventory table still empty (yes/no cells unfilled). | Fill after running Phase 2 commands on the production host. |
| [docs/security-phase-commands.md](docs/security-phase-commands.md) | `2026-03-23` | Still flagged "runbook material, not proof of rollout". | Same as above — trigger a real run, append outcome. |

### C.3. Correct content, stale timestamp (cosmetic)

These docs are **accurate** but are visibly old. Each one just needs a timestamp refresh and a one-line "no changes since" note:

| File | Last reviewed | Notes |
|---|---|---|
| [docs/index.md](docs/index.md) | `2026-03-24` | Index is correct; add `css/index-hero.css` is outside scope but the index itself is fine. |
| [docs/product-model.md](docs/product-model.md) | `2026-03-24` | Content correct. |
| [docs/project-reference.md](docs/project-reference.md) | `2026-03-26` | Content correct, but missing the payment/Telegram/modifier/PWA push areas called out in Section B. Treat as **needs-expansion**, not stale. |
| [docs/feature-audit-matrix.md](docs/feature-audit-matrix.md) | `2026-03-25` | Accurate but same gaps as project-reference. |
| [docs/project-improvement-roadmap.md](docs/project-improvement-roadmap.md) | `2026-03-24` | Accurate. |
| [docs/tenant-launch-checklist.md](docs/tenant-launch-checklist.md) | `2026-03-24` | Accurate; would benefit from the custom-domain step (B.7) and onboarding note (B.6). |
| [docs/public-layer-guidelines.md](docs/public-layer-guidelines.md) | `2026-03-24` | Accurate. |
| [docs/deployment-workflow.md](docs/deployment-workflow.md) | `2026-03-25` | Accurate; the `BRANCH="main"` example could note the current convention of long-running `release/*` branches. |
| [docs/api-smoke.md](docs/api-smoke.md) | `2026-03-23` | Accurate; would benefit from listing the `Idempotency-Key` semantics more explicitly. |
| [docs/backoffice-role-helpers.md](docs/backoffice-role-helpers.md) | `2026-03-23` | Accurate. |
| [docs/menu-capabilities-presentation.md](docs/menu-capabilities-presentation.md) | `2026-03-23` | Accurate as a sales doc, but understates: no mention of payment providers, Telegram bot, push, onboarding, modifiers, ABC analysis, tips. For a demo/sales artifact this is leaving value on the table. |
| [docs/tenant-demo-seed.md](docs/tenant-demo-seed.md) | `2026-03-23` | Accurate. |
| [docs/order-lifecycle-contract.md](docs/order-lifecycle-contract.md) | `2026-03-24` | Accurate; open follow-ups from A.6. |
| [docs/security-smoke-checklist.md](docs/security-smoke-checklist.md) | `2026-03-23` | Accurate. |
| [docs/security-change-log-template.md](docs/security-change-log-template.md) | `2026-03-23` | Template; no entries yet — that is the problem (see C.2). |
| [docs/ux-ui-improvement-roadmap.md](docs/ux-ui-improvement-roadmap.md) | `2026-04-11` | Just refreshed today. ✓ |
| [docs/vk-oauth-setup.md](docs/vk-oauth-setup.md) | `2026-03-23` | Accurate. |
| [docs/yandex-oauth-setup.md](docs/yandex-oauth-setup.md) | `2026-03-23` | Accurate. |
| [docs/mobile/capacitor-wrapper.md](docs/mobile/capacitor-wrapper.md) | `2026-03-23` | Accurate, gap honestly called out. |
| [docs/dev/git-hooks.md](docs/dev/git-hooks.md) | `2026-03-25` | Accurate. |
| [docs/db/backfill-order-items.md](docs/db/backfill-order-items.md) | `2026-03-23` | Accurate runbook. |
| [docs/deploy/nginx-pool-split.md](docs/deploy/nginx-pool-split.md) | `2026-03-23` | Accurate template note. |
| [docs/deploy/php-fpm-pool-split.md](docs/deploy/php-fpm-pool-split.md) | `2026-03-23` | Accurate template note. |

### C.4. Missing docs that should exist (gap, not drift)

These deserve new files (see Section B for details):

1. `docs/payments-integration.md`
2. `docs/telegram-bot-setup.md`
3. `docs/modifiers.md`
4. `docs/tips.md` **or** a section inside `product-model.md`
5. `docs/pwa-and-push.md`
6. `docs/schema-and-migrations.md`
7. `docs/deploy/custom-domain-go-live.md`
8. `docs/testing-strategy.md` — covering Playwright + (future) PHPUnit layers.

### C.5. Files that should be deleted

- All 13 `sql/modx-*.sql` files — unrelated to this project, see B.8.
- `AGENTS.md` at the project root — this is a **personal Codex MCP policy** belonging to the developer's local tooling, not project documentation. It should be in `~/.codex/` or at worst `.agent/AGENTS.md`, not shipped in the public repo. Right now `grep` for "MCP memory stack" will confuse anyone reading the repo fresh.

---

## Section D — Competitive benchmark

### D.1. Who Menu Labus actually competes with

Menu Labus is **not** a POS. It does not own the cash drawer, the KDS, the inventory ledger, the payroll, or the ingredient-level cost tracking. What it **does** own is the customer-facing website, the online ordering flow, the guest PWA experience, the tenant-branded mobile API, a light employee/admin/owner back-office, and the tenant-launch operator tooling.

That means the honest peer group is:

- **Russian segment:** Zenky, Restik, Foodeon. (These are SaaS menu-site builders with ordering + POS integration adapters.)
- **Global segment:** ChowNow, Flipdish, BentoBox, Popmenu, UpMenu, Slice, Lunchbox. (Website + direct ordering + some marketing layer.)

The giants (iiko, r_keeper, Toast, Square for Restaurants, Lightspeed, Clover, TouchBistro) are **adjacent**, not direct competitors. Menu Labus should not try to become iiko; it should **integrate with iiko** the same way Zenky does, and own the customer-facing and tenant-launch layers that iiko is weak at.

### D.2. Global top 10 — features and where Menu Labus stands

| # | Platform | Core positioning | Where Menu Labus already matches | Where Menu Labus is behind |
|---|---|---|---|---|
| 1 | **Toast** | All-in-one POS + online ordering + loyalty + website, tight POS↔online integration, enterprise hardware | Online ordering, cart, delivery/takeaway/table, admin catalog, owner analytics | Hardware POS, KDS, inventory, payroll, enterprise loyalty, email marketing automation, native card reader |
| 2 | **Square for Restaurants** | Free tier, very low onboarding friction, Square Online site builder | Provider-tenant split, white-label tenant sites, settings-driven branding | Free self-serve signup flow, in-product billing, hardware, instant Apple/Google Pay |
| 3 | **Lightspeed Restaurant** | Multi-location focus, centralized reporting depth, strong online ordering | Owner reports, per-tenant data isolation | Multi-location aggregation within a single tenant, inventory, vendor management |
| 4 | **Clover** | Customizable payments, broad payment options, app marketplace | YooKassa / T-Bank / SBP / cash (Russia-first parity) | App/extension marketplace, broad global payment providers |
| 5 | **TouchBistro** | iPad-first, offline-capable, reliability when internet fails | PWA offline queue for the customer flow | No tablet-first staff app, no offline-capable order queue for employees |
| 6 | **ChowNow** | Commission-free direct ordering, 22k+ restaurants, Order Throttling, branded mobile app | Commission-free ordering, branded tenant sites, Capacitor wrapper | Branded per-tenant mobile app build pipeline, order throttling (prep-time capacity slots), marketing email campaigns |
| 7 | **Flipdish** | Marketing automation (SMS/email/push campaigns), self-branded mobile apps | Push via VAPID on PWA | SMS layer, marketing-grade email, campaign segmentation, A/B testing on promotions |
| 8 | **BentoBox** | Website + online ordering + reservations + gift cards, high-end visual design | Tenant homepage + online ordering | **No reservations module**, no gift cards, no advanced design templates |
| 9 | **Popmenu / UpMenu** | Menu-driven SEO (menu items indexed as text), loyalty cross-channel | Menu as HTML text (SEO-friendly), basic brand surface | Structured schema.org Menu markup, AI-powered menu descriptions, cross-channel loyalty, automated review collection |
| 10 | **Lunchbox** | Distinctive digital brand experience, personalization, tailored promotions | Per-tenant branding (colors, fonts, logo, custom domain) | Personalization engine (per-guest recommendations), guest segmentation, behavioral loyalty |

**Extra mentions** (listed often but not in the top 10): **Olo** (enterprise-only, out of scope for a tenant-first product), **Slice** (pizza-vertical specialist), **Otter** (delivery aggregator unifier).

### D.3. Russian top 3 — direct competitors in Menu Labus' segment

| # | Platform | Positioning | Strengths | Where Menu Labus can win |
|---|---|---|---|---|
| 1 | **Zenky.io** | SaaS constructor for delivery sites + iOS/Android apps + VK App, integrates with iiko / 1C / Frontpad / Bitrix. From ~1000₽/month. | Fastest onboarding ("15 minutes"), native iiko/Frontpad adapters, in-product courier/picker apps, VK App, no-code constructor, weekly releases | Depth of white-label branding (custom domain, CSP, font pipeline), better SEO (server-rendered PHP vs. client-heavy constructors), direct T-Bank/YooKassa/SBP integration, better owner analytics |
| 2 | **Restik** | "Full website for café with food delivery" | Restaurant-specific templates, delivery focus, relatively mature in the Russian market | Custom-domain white label, multi-role backoffice depth, PWA + push, Telegram bot control |
| 3 | **Foodeon** | Delivery site + QR-code menu launcher | Fast QR-menu setup, delivery-focused | Same as Restik; plus Menu Labus' modifier system, tips, ABC analysis, onboarding wizard |

And for reference, the **giants Menu Labus should integrate with, not compete against:**

- **iiko** — cloud ERP, the dominant Russian restaurant automation system, POS + warehouse + reports + couriers.
- **r_keeper** — legacy modular POS, still widely deployed.
- **Frontpad** — budget-friendly delivery CRM (from 449₽/month), strong in small cafés and new delivery-only shops.
- **Yandex.Eda / Delivery Club (VK)** — aggregators; `iiko` already has adapters. An iiko adapter in Menu Labus would transitively get the aggregator reach.

### D.4. Structural advantages Menu Labus already has

Based on the code walk, Menu Labus is **stronger than most of the peer group** on:

1. **Hard data isolation.** "One tenant = one DB" is rare in this segment. Zenky/Restik/Foodeon are multi-tenant within a single DB. For privacy-sensitive clients (chains, franchises, premium restaurants), this is a genuine differentiator.
2. **Strict CSP + no-inline policy.** Most restaurant website builders ship inline styles and evals. Menu Labus has a working nonce-based CSP, which is an enterprise security story.
3. **Real tenant-launch automation** (`scripts/tenant/go-live.sh`, launch artifacts, smoke, security smoke, browser regression) — most competitors have no equivalent; tenant onboarding is manual ops.
4. **PHP source-rendered menus, good for SEO.** Menu text is server-rendered HTML, not JS-hydrated. This is a real SEO advantage over Zenky.
5. **Payment provider depth for Russia**: YooKassa + T-Bank + SBP + cash is best-in-class for Russian market. Most global platforms do not support SBP at all.
6. **Git-based deploy with docs-drift + OpenAPI + mojibake gates.** Rare even at iiko/Toast level.

### D.5. Structural gaps Menu Labus has today

1. **No reservations module.** This is table-stakes for BentoBox, Popmenu, Toast, Lightspeed. Menu Labus has "Оставить заявку" but it is a contact form, not a table reservation with seat/time slotting.
2. **No email / SMS marketing layer.** Push exists, but promotional segmentation does not.
3. **No reviews / reputation module.** No guest feedback collection, no Google Reviews link flow, no sentiment surface for owners.
4. **No loyalty / points / referral system.** Basic repeat-order exists, but not points, tiers, or referrals.
5. **No iiko / r_keeper / Frontpad adapter.** This is the single biggest gap for Russian market expansion — the giants own the kitchen, and Menu Labus cannot currently sell into a kitchen that already runs iiko.
6. **No KDS (kitchen display) output.** The employee queue is browser-based, not tablet-first.
7. **No AI layer.** No recommendation engine, no demand forecasting, no review sentiment, no auto-generated menu descriptions, no upsell prompts, no reservation chatbot. 2026 competitors treat this as table-stakes.
8. **No in-product billing / self-serve signup.** New tenants need an operator to run `launch.php`. Competitors let the restaurant owner sign up and pay themselves.
9. **No per-tenant mobile app build pipeline.** Capacitor wrapper is provider-pinned.
10. **No unit test coverage** (see B.10). This is a **development-velocity** gap, not a user-visible one, but it caps the speed at which any of the above can be added safely.

---

## Section E — Strategic roadmap to top-1 in the target segment

The segment is **"kastomized customer-facing restaurant website with online ordering, delivery, and a light operations layer"**, targeting independent restaurants and small chains in Russia + export-ready for CIS / Europe.

The following is ordered by impact/effort ratio, not by pure impact.

### E.1. Immediate (weeks 1–2): close the visible doc gaps and delete cargo

Zero code risk, high trust payoff.

1. Rewrite `README.md` so it matches the feature matrix. (~1 hour)
2. Update `docs/project-reference.md` sections 5–7 with the full file inventory from B.9. (~2 hours)
3. Create the 8 missing docs listed in C.4. (~1 day total)
4. Delete 13 `sql/modx-*.sql` files and `AGENTS.md` from the repo root. (~10 minutes, confirm via `grep` first)
5. Extract a `docs/feature-map.md` showing every feature → code path → owning doc. This is the doc a sales lead reads first.

### E.2. Short term (weeks 3–6): fill the table-stakes product gaps

1. **Reservations module.** Table + seat count + time slot + guest contact. Minimal version: one settings key per tenant (enable/disable), one DB table, one public widget on the tenant homepage, one owner view. BentoBox-level basics.
2. **Reviews / feedback loop.** After order completion: a one-question feedback step in the PWA (1–5 stars + free text), an owner surface showing the last 50, a "post to Google" deep link for 5-star reviews.
3. **Loyalty points v1.** "1₽ = 1 point" model. Redeem at checkout. One DB table, one settings toggle, one admin report. Enough to matter without becoming a full loyalty platform.
4. **Email/SMS campaign layer v1.** One "broadcast to all customers" button in the admin with a simple template. Don't build segmentation yet. Use the existing `mailer.php` and add an SMS adapter (one provider, e.g., SMS.ru or MTS Exolve).
5. **Reservation/feedback/loyalty docs.** Ship each with its own `docs/*.md`.

### E.3. Mid term (weeks 7–12): integrations that unlock Russian market

1. **iiko adapter.** Read menu from iiko, push orders to iiko, mirror item availability, sync stop-list. This single integration opens the door to every restaurant that already runs iiko — and there are thousands.
2. **Frontpad adapter.** Same but for the budget segment. Faster than iiko to integrate.
3. **r_keeper adapter.** Legacy but still large installed base. Deprioritized vs iiko/Frontpad.
4. **Yandex.Eda / Delivery Club pass-through.** Most restaurants with iiko already route through these aggregators via iiko. If Menu Labus sits in front, it just needs to not break the flow. Document explicitly.
5. **Per-tenant Capacitor build.** A small CLI (`scripts/mobile/build-tenant-app.sh`) that takes a tenant slug and outputs an Android APK + iOS archive with the tenant brand assets injected. No App Store submission automation in v1, but having the artifact is half the battle.
6. **KDS v1.** A second URL the kitchen tablet can pin — same data as the employee queue, but reformatted for kitchen reading distance, with audio alert on new order and auto-advance on status change.

### E.4. Mid-long term (months 4–6): AI layer and retention

1. **Menu-description AI generator.** Admin clicks a button, sees three proposed descriptions for an item (short, medium, enticing), picks one. Uses Claude or a local LLM. Massive time-saver for onboarding a new tenant's catalog.
2. **Recommendation engine v1.** "Customers who ordered X also ordered Y" on the product page. Simple co-occurrence query against `order_items`. Starts working after a few hundred orders and improves naturally.
3. **Demand forecasting for owners.** Given the last 90 days of orders + day-of-week + a weather feed, predict tomorrow's expected order count per category. Owners love this. Cheap to build with a linear model.
4. **Review sentiment for owners.** Cluster the last 100 free-text feedback entries by sentiment and topic, surface in the owner dashboard.
5. **Upsell prompts at checkout.** "Add fries for 150₽?" — rules-first, ML-second.

### E.5. Ongoing: engineering hygiene and developer velocity

1. **PHPUnit + tests/** (B.10). Start with `lib/orders/lifecycle.php`, `lib/Idempotency.php`, `db.php::createOrder()`. Then `lib/TBank.php` and `lib/OAuthVK.php`. Goal: 50% line coverage on `lib/*` within a quarter.
2. **Composer**. Introduce `composer.json`, move `phpmailer` out of the repo vendor copy into a real dependency. (This also means `composer audit` catches CVEs automatically.)
3. **CI.** GitHub Actions on every PR: PHP lint, PHPUnit, OpenAPI validate, mojibake scan, PostCSS build. Right now all of this runs only in local pre-push, which is bypassable.
4. **Structured logging.** Already partly there (`lib/CheckoutErrorLog.php`). Extend to all payment, OAuth, and Telegram paths, with a single `data/logs/*` sink and log-rotation doc.
5. **Tenant-aware observability.** Add a "last-N-errors per tenant" panel in `monitor.php`. Per-tenant p95 latency would be next.

### E.6. Growth-side moves (not engineering)

1. **Self-serve signup landing** with three tiers (Start / Business / Enterprise) and the one-DB-per-tenant model positioned as the Enterprise differentiator.
2. **Compliance badge strip** on `menu.labus.pro/` — 152-ФЗ (Russian PDPA), PCI DSS scope (we proxy, we don't store), CSP A+, ISO 27001 if it ever happens.
3. **Case-study page** — the first three launched tenants with before/after numbers. Currently `test.milyidom.com` exists; use it.
4. **Published uptime and incident history** (statuspage-style). Huge trust signal in the segment.

---

## Section F — Recommended execution order (next 4 weeks)

Week 1 — **Truth-alignment sprint** (no code risk):

- Day 1: Rewrite `README.md`, delete junk (`sql/modx-*`, `AGENTS.md` after confirmation), update `docs/project-reference.md` file inventory.
- Day 2: `docs/payments-integration.md`, `docs/telegram-bot-setup.md`.
- Day 3: `docs/modifiers.md`, `docs/pwa-and-push.md`, `docs/schema-and-migrations.md`.
- Day 4: `docs/deploy/custom-domain-go-live.md`, onboarding note in tenant launch checklist.
- Day 5: Push one doc-only release with the version tag `doc-sync-2026-04-18`; run smoke; no code changes.

Week 2 — **Security Phase 2 execution**:

- Run `scripts/security/apply-network-policy.sh` on production with two SSH sessions open.
- Fill `docs/security-phase-2-inventory.md` with real values.
- Write first `docs/security-change-log-2026-04-XX.md` from the template.
- Promote Phase 2 status in `docs/security-hardening-roadmap.md` to `Implemented`.

Week 3 — **Test infrastructure bootstrap**:

- Add `composer.json` + PHPUnit.
- Write 3 test files (`tests/OrdersLifecycleTest.php`, `tests/IdempotencyTest.php`, `tests/CreateOrderTest.php`).
- Wire PHPUnit into the pre-push hook.
- Start extracting `phpmailer` into a real Composer dependency on the next push.

Week 4 — **First product gap from E.2**:

- Ship **reviews / feedback loop** (simplest of the four because it is append-only data with one admin read surface).
- Add reviews to `docs/feature-audit-matrix.md` and `docs/menu-capabilities-presentation.md`.

After week 4, re-plan based on what the first tenant feedback says — reservations vs. loyalty vs. iiko adapter should be prioritized by actual customer demand, not by this audit.

---

## Sources used for market research

- [Top 10 Restaurant Online Ordering Platforms in US — Inventcolab](https://www.inventcolabssoftware.com/blog/top-restaurant-online-ordering-platforms-in-us/)
- [10 Best Restaurant Online Ordering Systems in 2026 — Menubly](https://www.menubly.com/blog/best-restaurant-online-ordering-systems/)
- [Top 15 Online Ordering Software in 2026 — KitchenHub](https://www.trykitchenhub.com/post/top-10-online-ordering-software)
- [2026 Guide to the Best Restaurant POS Systems — TouchBistro](https://www.touchbistro.com/blog/guide-to-the-best-restaurant-pos-systems/)
- [Best POS Systems for Restaurants in 2026 — posusa.com](https://www.posusa.com/best-pos-systems-for-restaurants/)
- [Top Restaurant Ordering Systems for 2026 — Restolabs](https://www.restolabs.com/blog/restaurant-ordering-systems-guide)
- [ChowNow for Restaurants Reviews — G2](https://www.g2.com/products/chownow-for-restaurants/reviews)
- [iiko — Russian restaurant automation](https://iiko.ru)
- [iiko vs r-keeper — picktech 2026](https://picktech.ru/blog/a-vs-b/iiko-vs-r-keeper-sistema-avtomatizatsii-dlya-restorana-2026/)
- [iiko vs Frontpad comparison — a2is](https://a2is.ru/catalog/programmy-dlya-kafe-i-restoranov/compare/frontpad/iiko)
- [Zenky.io — SaaS delivery-site constructor](https://zenky.io/)
- [Zenky.io store builder with iiko/Frontpad integration](https://zenky.io/store)
- [Frontpad — product overview — picktech](https://picktech.ru/product/frontpad/)
- [Restaurant Technology in 2026: What's Changed — EHL](https://hospitalityinsights.ehl.edu/restaurant-technology)
- [9 Advanced Restaurant Technology Trends for 2026 — MenuTiger](https://www.menutiger.com/blog/restaurant-technology-trends)
- [Restaurant Website Guide 2026 — DoorDash Merchants](https://merchants.doordash.com/en-us/blog/building-restaurant-website)
- [Best Restaurant Website Builders 2026 — UpMenu](https://www.upmenu.com/blog/best-restaurant-website-builders/)

---

## Appendix — files touched vs. untouched during this audit

**Read (docs):** every file under `docs/`, including all subdirectories (`db/`, `dev/`, `deploy/`, `mobile/`).

**Read (code, spot-checks):** `index.php`, `README.md`, `AGENTS.md`, `package.json`, `scripts/api-smoke-runner.php`, `scripts/docs/check-doc-drift.sh`, `.githooks/pre-push`, `nginx-optimized.conf`, `deploy/nginx/custom-domain-template.conf`, `deploy/nginx/server-locations-pool-split.conf`, plus directory listings of `api/v1/`, `lib/`, `scripts/`, `sql/`, `deploy/`, `partials/`, `mobile/`, `js/`, `css/`.

**Not read (out of scope for this pass):** `menu.php`, `admin-menu.php`, `owner.php`, `employee.php`, `cart.php`, full source of the `js/` bundle, full source of any `lib/*.php` class. A deeper audit that opens those files would produce more concrete findings but would not change the high-level picture of this document.

**Not executed:** live HTTP probes, security scans, load tests, real database introspection.

---

*End of audit. This document is a point-in-time snapshot — re-run the cross-reference after the next release cycle, or after any of Sections E.1–E.5 lands, whichever comes first.*
