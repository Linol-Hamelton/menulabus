# Project Improvement Roadmap

## Implementation Status

- Status: `Implemented (Phases 0-8 + 13 + 14 + 15 + 32A + 32B + 33 + 34) · Planned (Phase 34.1+: forecasting, supplier dashboard expansion, marketing pages, referral, SEO content; AI recs; iiko adapter; Phase 9)`
- Last reviewed: `2026-05-11`
- Current implementation notes:
  - **Phases 0-5** — foundation + launch automation + release discipline: `Implemented`.
  - **Phase 6** — KDS, inventory MVP, loyalty, multi-location, analytics v2: `Implemented`.
  - **Phase 7** — 54-ФЗ fiscal (АТОЛ), full i18n (helper + 80 keys; surface migration ongoing), staff-management v2 (shifts + swap requests + payroll CSV), split-bill payments: `Implemented`. iiko adapter (Phase 7.1): `Planned (deferred — explicit user decision Phase 13)`.
  - **Phase 8** — marketing automation, group ordering, waitlists, review moderation: `Implemented`. AI recommendations: `Planned`.
  - **Phase 14** — SaaS Billing Engine (signup, plan registry, recurring YK, soft dunning, provider admin): `Implemented` (v2.0.0, 2026-05-03).
  - **Phase 15** — Audit hotfix M1-M6 (Playwright MCP audit от 2026-05-03 закрыл file-manager 404 на /admin/menu.php, ?tab= URL routing, ghost update modal, `\r\n` literal в changelog, hero clipping на reservation/group/signup, archive 7 broken-image test items): `Implemented` (v2.0.1, 2026-05-04). Детали в `docs/archive/2026/audit-2026-05-03.md`.
  - **Phase 9 (sans billing)** — developer platform, compliance pack (GDPR/152-ФЗ/ЕГАИС/Меркурий), multi-region HA, onboarding 2.0: `Planned`.
  - **Phase 13** — Architecture refactor: `Implemented`. Root PHP files reduced from 92 → 63; logic разнесена в `admin/`, `api/save/`, `api/checkout/`, `auth/oauth/`, `kds/`, `api/reservations/`. All routes verified post-refactor; no broken paths.
  - **Phase 32A** — Pricing & marketing strategy v3 (2026-05-08, v3.0.0): Killed Starter tier; Pro trial extended 14d → **90 days**; Enterprise self-service at 19 990 ₽/mo with bundled Express onboarding + 4h training; new `enterprise_plus` (договорная, sales-led); annual billing variants (Pro 69 900 ₽, Enterprise 199 900 ₽ — 2 months free). Add-on services catalog (Express / Full / Migration onboarding, training packages, Telegram-bot setup, custom domain) — display in 32A, purchase API in 32B. New `index.php` provider-mode pricing section; rewritten `signup.php` messaging; `partials/owner_billing_section.php` plan grid + addons. New canonical doc `docs/marketing-strategy-2026.md`. Strategy: don't compete on price (vs iiko 8 600 / Quick Resto 5 990 / R-Keeper 6 170); compete on bundle + cloud-only + white-label. Direct-sales GTM M1-M3 (founder-led, no paid ads). Targets: 8 paying customers + 56k MRR by M3, 30 + 210k by M6, 100-120 + 600-800k by M12.
  - **Phase 34.3** — cart.php structural fix + responsive layout (2026-05-11, v3.3.3): MCP Playwright диагностика показала что все 5 cart-секций (header, items, buttons, total, summary) были завёрнуты внутрь `.account-header-bar.account-section-head` вместо того чтобы быть siblings в `.account-section--cart-shell`. `.section-header-menu` имеет `padding: 50px 8% 0` (8% от containing-block) → max-content авторазмер раздувал head до 382px при 375 viewport → grid-track расширялся → КБЖУ контейнер и другие дети вылезали на 53px за viewport. Фикс HTML: закрываем `</div>` для head сразу после `.section-header-menu`, остальное становится siblings шеллa. Фикс CSS: добавлена явная `grid-template-areas` раскладка для shell. На desktop ≥768px последний ряд `[summary] [total] [actions]` — КБЖУ слева, итого по центру, кнопки справа (primary CTA у правого края — стандартный cart UX). На мобиле — single column без overflow.
  - **Phase 34.2** — Inventory CSS-only fix: horizontal-scroll on mobile + hidden-attribute regression (2026-05-11, v3.3.2): диагностика через MCP Playwright показала что `body.admin-page .inv-table-wrapper { display: block }` из admin-menu-polish.css бил `.inv-table-wrapper.desktop-table { display: none }` из admin-inventory.css по specificity (0,2,1 vs 0,2,0). Mobile-cards и desktop-table рендерились одновременно, таблица вылезала за viewport на 423px (ingredients) и 51px (suppliers). Фикс: `body.admin-page .inv-table-wrapper.desktop-table { display: none }` (0,3,1) побеждает. Второй фикс: `.inv-adjust-controls`, `.inv-bulk-bar`, `.inv-create-panel`, `.inv-mobile-list` имели `display: flex/block` который бил UA `[hidden] { display: none }` — добавлен явный `[hidden] { display: none !important }` для этих 4 классов. Минорный mobile-polish: Active/Archive теперь 50/50 horizontal toggle, bulk-bar кнопки full-width.
  - **Phase 34.1** — Inventory UI/UX polish v2 (2026-05-11, v3.3.1): доработка после Phase 34 по feedback пользователя. (1) Toolbar в стиле admin/menu.php: переключатель Активные/Архив + кнопка «+ Создать ингредиент» → slide-down create-panel (вместо постоянной new-row внизу). (2) Десктоп-таблица: убрана ID-колонка (ID показывается hint-ом под названием), «Порог» merged в ячейку остатка под значением, рядом с числом — colour-coded бейдж OK/Низкий/Нет + kebab-кнопка «⋯» раскрывает inline ± дельта + Применить + История. (3) Mobile dual-render: при ≤768px десктоп-таблица скрыта, рендерится `.inv-mobile-list` с компакт-карточками (без горизонтального скролла), кнопка «Редактировать» открывает `<dialog id=\"invEditModal\">` (на базе .design-modal паттерна) с полной формой; отдельная adjust-модалка для изменения остатка. (4) Фильтры и bulk-archive работают одинаково над таблицей и над карточками.
  - **Phase 34** — Inventory UX overhaul + ingredient COGS analytics + lifecycle webhooks (2026-05-11, v3.3.0): (A) `/admin/inventory.php` переписан под admin/menu.php-парный паттерн (admin-pane-header / kicker / caption / admin-section-card), добавлены 3 summary-card (активных позиций / низкий остаток / общая стоимость склада), view-toggle Активные/Архив через `?view=`, filter-bar (поиск + поставщик + low/out/ok), checkbox-column + bulk-archive bar. Mobile-стек на 768px. (B) Поле `cost_per_unit` теперь живёт: `db.php::getRecipeCost()` и `getInventoryValueSummary()` + модалка «Рецепт» блюда показывает «Себестоимость рецепта: X ₽» с live-пересчётом при изменении количеств. (C) `getDishMargins()` расширен additive-колонкой `ingredient_cogs_per_unit` + `ingredient_margin/_pct` (LEFT JOIN на recipes); UI в `owner.php?tab=analytics-v2` «Маржа по блюдам» получил новую колонку «Ингр. cost» с бейджем «Точная»/«Грубая» (точная = рассчитано по техкарте, грубая = legacy fallback на menu_items.cost). (D) Три новых lifecycle webhook: `ingredient.archived`, `ingredient.restored`, `ingredient.cost_changed` (последний с no-op-сравнением по float-tolerance). Все события + ранее не отображавшийся `inventory.stock_low` теперь exposed в `admin/webhooks.php` event-picker. (E) Удалены diagnostic-логи vk-oauth (Phase 33.2 cleanup) — `data/logs/vk-debug.log` удаляется при deploy.
  - **Phase 33.2** — Hide lang-picker + migrate VK OAuth to VK ID (2026-05-11, v3.2.2): (1) Языкопереключатель RU/EN/KK в `header.php` (`<li class="lang-picker-item">`, L75-86) скрыт за `<?php if (false): ?>` до завершения i18n EN/KK переводов — в M1 продажи только в русскоговорящих рынках. CSS-файл `/css/lang-picker.css` оставлен (нулевой cost, классов в DOM нет). (2) VK OAuth полностью переписан с legacy `oauth.vk.com/authorize` (который теперь возвращает `{"error":"invalid_request","error_description":"Security Error"}` 401) на современный **VK ID** протокол (`id.vk.com`). Новый flow: PKCE-protected (S256), `scope=email vkid.personal_info`, token exchange на `https://id.vk.com/oauth2/auth` с `grant_type=authorization_code` + `code_verifier`, OIDC-style `id_token` (JWT) с claims `sub`/`email`/`given_name`/`family_name`. Файлы: `auth/oauth/vk-start.php` + `auth/oauth/vk-callback.php` переписаны; `lib/OAuthVK.php` теперь имеет `parseIdToken()` (JWT decode без signature verify — TLS канал доверенный) + `fetchUserInfo()` fallback на `/oauth2/user_info`. Документация `docs/vk-oauth-setup.md` обновлена с troubleshooting-таблицей. **Важно**: для VK app в Developer Console может потребоваться миграция на «VK ID» тип приложения — если останется Security Error после deploy, проблема не в коде.
  - **Phase 33.1** — Nav entry for /admin/inventory.php (2026-05-11, v3.2.1): добавлен пункт «Склад» в shared owner/admin nav-row в `account-header.php` (между «Смены» и «Помощь»). Role-gated на owner+admin (employee к /admin/inventory.php не имеет доступа). До этого попасть на страницу можно было только через deep-link в recipe-tab редактора блюда или через help.php — обе точки требовали предварительной навигации.
  - **Phase 33** — Recipe UX: structured units + CSV-import техкарт (2026-05-11, v3.2.0): (1) `lib/Inventory/UnitCatalog.php` — master-list 7 канонических единиц («г», «кг», «мл», «л», «шт», «порц», «упак») как PHP-константа. `admin/inventory.php` L100 заменён `<input class="inv-unit">` → `<select class="inv-unit-select">` + inline «Другое…» free-text fallback для backwards-compat с legacy values («гр», «грамм»). `db.php::saveIngredient()` validation теперь через `UnitCatalog::isValid()`. (2) Bulk CSV-import рецептов: новый `db.php::bulkSyncRecipesFromCsv()` (паттерн `bulkSyncMenuFromCsv` L1799) → temp-table staging → upsert `recipes` rows. UI: новая кнопка «Загрузить из CSV» в recipe-tab модалки блюда → `<dialog id="recipeImportModal">` с file-upload, radio Merge (additive) vs Replace (per-dish full sync), чекбокс auto-create новых ингредиентов, ссылкой на `download-recipe-sample.php`. Endpoint `api/import-recipes.php` (CSRF + owner-only, ≤2 МБ, UTF-8). CSV format: `dish_external_id;ingredient_name;unit;quantity;auto_create_ingredient`. Replace mode имеет confirm-dialog перед submit. `sql/recipes-import-migration.sql` — idempotent CREATE TABLE для staging.
  - **Phase 32B** — Card-required signup + addon purchase API + trial-end reminder cron (2026-05-08, v3.1.0): (1) `api/signup.php` теперь после создания tenant'а инициирует 1 ₽ binding payment через YK с `save_payment_method=true`; возвращает `redirect_url` на YK; `js/signup.js` редиректит на YK для ввода карты; на успехе webhook handler сохраняет payment_method и **auto-refunds 1 ₽** (новый `YookassaRecurring::refundPayment()`). (2) Новый `lib/Billing/AddonCatalog.php` (10 SKUs: 9 покупаемых + 1 bundled-only) + `sql/addons-migration.sql` (`addon_purchases` таблица) + `api/billing-purchase-addon.php` endpoint (POST sku → creates YK one-time payment → возвращает redirect_url). UI: «Купить» кнопки в `partials/owner_billing_section.php` заменили `mailto:hello@labus.pro` placeholder; bundled-в-тариф addon'ы автоматически показывают «Включено в ваш тариф» вместо кнопки. `SubscriptionStore::onWebhook` short-circuit'ит на addon-payment'ах (не extends subscription period). (3) `scripts/billing-trial-reminder.php` daily cron — 15 days и 5 days до trial-end шлёт email reminders с CTA на /owner.php?tab=billing. Idempotent через `subscription_events` dedup. (4) Annual billing automation deferred — manual `billing_period` flag достаточно для первых 5-10 annual customer'ов; полный automation в Phase 32C когда signal'ит.
  - Release discipline: docs-drift check + visual regression + provider/tenant smoke + provider security smoke + post-release browser regression.
  - Tenant go-live: scriptable via `scripts/tenant/go-live.sh` (manual) AND через `/signup.php` (self-service trial).

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
5. ~~Close remaining CSRF gaps on `api/save/project-name.php` and `send_message.php`~~ — **Done**. Both endpoints route through `Csrf::requireValid()` (verified 2026-05-06).
6. Finish Mobile Capacitor tenant-aware rework (preferences-driven `server.url`).

### Medium-term — Phase 6 (Restaurant Operations Core)

| Track | Key deliverables |
|---|---|
| 6.1 Kitchen Display System | New `/kds/index.php` surface, per-station routing (hot/cold/bar/pizza), drag-to-acknowledge, SSE or WS live updates. |
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
