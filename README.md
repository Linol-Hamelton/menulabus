# Menu Labus

**SaaS-платформа для ресторанов на стадии коммерческого запуска.** Один кодовой базис обслуживает провайдер-витрину `menu.labus.pro` и брендированные сайты ресторанов-тенантов на собственных доменах с полной изоляцией данных. Self-service signup даёт 14-дневный trial с полным функционалом, дальше — подписка через YooKassa.

Долгосрочное видение — «Shopify для ресторанов»: гость-ориентированный онлайн-заказ + бронь + оплата, подсвеченные полноценной операционкой (кухонный дисплей, склад, лояльность, персонал, 54-ФЗ фискальные чеки, маркетинг) и открытой платформой интеграций (webhooks, mobile API). Стратегический «север» — [docs/product-vision-2027.md](./docs/product-vision-2027.md).

## Implementation Status

- Status: `Implemented (Phases 0-8 + 14) · Planned (AI recs + Phase 9 sans billing)`
- Last reviewed: `2026-05-03`
- Live: `https://menu.labus.pro/` (provider) · `https://test.milyidom.com/` (тенант) · `https://<slug>.menu.labus.pro/` (новые тенанты через signup)
- Current state:
  - **Phase 14 — SaaS Billing Engine — shipped (v2.0.0).** Self-service `/signup.php`, 14-day trial, plan picker (Starter / Pro / Enterprise), YooKassa recurring через `save_payment_method`, soft dunning (read-only день 8 → suspended день 30), provider-admin дашборд `/provider/billing.php`. Подробности — [docs/billing.md](./docs/billing.md).
  - **Phases 6–8 — restaurant operations + growth.** KDS, склад, лояльность, multi-location, аналитика v2, маркетинг, отзывы, групповые заказы со split-bill, waitlist, staff-management v2 (включая swap-requests и payroll CSV) — все live и доступны на тарифе Pro+. См. матрицу в [docs/feature-audit-matrix.md](./docs/feature-audit-matrix.md).
  - **Phase 7.2 — 54-ФЗ фискализация** через АТОЛ Онлайн (saved-card recurring уже работает; фискальные чеки — настраиваются в `/owner.php?tab=fiscal`).
  - **Phase 7.3 — i18n.** `t()` helper + `locales/{ru,en,kk}.json` с ~80 ключами, surface-migration в процессе (см. [docs/i18n.md](./docs/i18n.md)).
  - **Architecture refactor.** Корень репо очищен: 92 → 63 PHP-файла, 29 файлов разнесены в `admin/`, `api/save/`, `api/checkout/`, `auth/oauth/`, `kds/`, `api/reservations/` ([docs/architecture-map.md](./docs/architecture-map.md)).
  - **Backlog (Phase 9 sans billing):** AI recommendations, developer platform (public API + SDK + marketplace), compliance pack (GDPR / 152-ФЗ / ЕГАИС / Меркурий / 2FA для admin / audit log), multi-region HA, onboarding 2.0. + iiko adapter (Phase 7.1) — отложен по решению пользователя.

## Feature Highlights

### Public + Customer
- **Онлайн-заказ:** корзина, самовывоз / доставка / стол / бар, модификаторы (группы + опции), чаевые.
- **Платежи:** YooKassa, T-Bank/СБП, наличные — с идемпотентностью и отдельным webhook-слоем. Подписки на платформу — через `payment_method_id` recurring.
- **Бронь столов:** клиентская форма на `/reservation.php` с availability picker, админский борд в `employee.php`, Telegram accept/reject. Reminder за 2 часа до брони — автомат через cron.
- **Группы (split-bill):** общий чек на группу через `/group.php` с QR-кодом стола; на оплату — три режима (один за всех / каждый за свои позиции / поровну) с YK multi-payment reconciliation. См. [docs/group-split-bill.md](./docs/group-split-bill.md).
- **Программа лояльности:** тиры, баллы, промокоды, история транзакций. Карта в `/account.php?tab=loyalty`.
- **Отзывы:** звёзды после `завершён`, owner-моderation в `/owner.php?tab=reviews`.
- **PWA + push:** service worker, offline-очередь, VAPID push, динамический manifest с брендингом тенанта.
- **OAuth:** Google, Yandex, VK (callback'и под `/auth/oauth/<provider>-callback.php`).

### Operations
- **KDS** (Kitchen Display System): станции, маршрутизация позиций по станциям, real-time через SSE, статусы готовки → автоматический webhook `order.ready` + Telegram официанту. [docs/kds.md](./docs/kds.md).
- **Inventory:** ингредиенты, поставщики, рецепты, автоматическое списание при оплате, low-stock алерты в Telegram + webhook `inventory.stock_low`. [docs/inventory.md](./docs/inventory.md).
- **Multi-location:** до 3 локаций на Pro, без лимита на Enterprise. Per-location меню, заказы, отчёты.
- **Staff v2:** смены, time clock, tip splits, **shift-swap requests** (employee→volunteer→manager approve), payroll-CSV экспорт. [docs/staff-v2.md](./docs/staff-v2.md).
- **Marketing automation:** email/SMS/push кампании, сегменты, queued worker.
- **54-ФЗ:** интеграция с АТОЛ Онлайн, чек выписывается на `order.paid`, URL сохраняется в `orders.fiscal_receipt_url`. [docs/fiscal-54fz.md](./docs/fiscal-54fz.md).
- **Outgoing webhooks:** HMAC-SHA256, exponential-backoff retries, админ-панель `/admin/webhooks.php`. [docs/webhook-integration.md](./docs/webhook-integration.md).
- **Мобильный API v1:** REST для Capacitor-обёртки, OpenAPI-контракт в [docs/openapi.yaml](./docs/openapi.yaml).
- **Telegram-бот:** уведомления о заказах, бронях, swap-requests, low-stock с inline-кнопками accept/reject.

### Owner / Admin / Analytics
- **Аналитика v2** (`/owner.php?tab=analytics-v2`): heatmap час×день недели, маржа по позициям, cohort retention, EWMA-прогноз выручки.
- **Admin UX:** drag-n-drop сортировка меню, bulk actions, hotkeys, client-side фильтры/сортировка, undo-toast для soft-delete. [docs/admin-menu-ux.md](./docs/admin-menu-ux.md).
- **Брендинг:** кастомный домен, свои цвета / шрифты / лого, опциональное скрытие «Powered by», белая марка.
- **QR-столы:** печать QR-кодов, привязка заказов к столу, pre-fill стола на формах.

### Platform & SaaS
- **Multi-tenant:** `1 клиент = 1 отдельная БД`, имена БД содержат slug клиента, control-plane DB со ссылками `tenants → tenant_domains`.
- **Self-service signup:** `/signup.php` — за 60 секунд новый тенант на `<slug>.menu.labus.pro` с 14-дневным trial. См. [docs/billing.md](./docs/billing.md).
- **Provider admin:** `/provider/billing.php` — список тенантов, MRR, статусы, действия comp/extend_trial/force_*. Доступ — по email-allowlist (`BILLING_PROVIDER_ADMINS`).
- **Recurring billing:** YooKassa `save_payment_method` + `chargeStored`. Cron `0 */6 * * *`. Soft dunning policy.
- **Feature gating:** `lib/Billing/FeatureGate.php` — admin pages рендерят paywall если фича не входит в план; `session_init.php` → 503 на customer surfaces для suspended тенантов.

### Security & Quality
- **Security hardening:** жёсткий CSP (`default-src 'none'`, nonce-only script/style), унифицированный CSRF через `lib/Csrf.php`, role gates, HSTS, COOP/CORP/COEP. См. [docs/security-hardening-roadmap.md](./docs/security-hardening-roadmap.md).
- **Visual regression:** Playwright suite (49 baseline-снимков на 3 viewports) с авто-проверкой на push в release/main; CSP-violation guard.
- **Pre-push gate:** PHP lint + mojibake check + PHPUnit + docs-drift + visual-regression + OpenAPI validation.
- **Post-merge гейт:** automatic provider/tenant smoke + security smoke + browser-regression suite.

## Plans (SaaS pricing)

| Plan | Price/mo | Locations | Menu items | Orders/mo | Что включено |
|---|---|---|---|---|---|
| **Trial** | бесплатно (14 дней) | 3 | unlimited | unlimited | всё (Pro-уровень) |
| **Starter** | 2 990 ₽ | 1 | 200 | 1 500 | analytics v2, отзывы, webhooks, i18n |
| **Pro** | 6 990 ₽ | 3 | unlimited | unlimited | + KDS, склад, лояльность, multi-location, маркетинг, group-orders, split-bill, waitlist, staff v2, 54-ФЗ |
| **Enterprise** | от 19 990 ₽ (custom) | unlimited | unlimited | unlimited | + dev API, white-label, приоритетная поддержка, SLA |

См. [docs/billing.md](./docs/billing.md) для архитектуры и status lifecycle (trial → active → past_due → suspended → cancelled).

## Core Rules

- `1 клиент = 1 отдельная БД`. Имена БД содержат бренд-slug клиента.
- Провайдерский маркетинг не должен появляться на публичных тенант-доменах.
- Все стили — во внешних `.css`, все скрипты — во внешних `.js`; встраивание `<style>`/`<script>` без `nonce` запрещено политикой CSP.
- Mobile API v1 — backwards-compatible only; ломающие изменения через v2.
- Активная документация живёт в [`docs/`](./docs/index.md).

## Quick Start (разработчик)

```bash
npm ci
npm run openapi:validate            # проверка контракта мобильного API
npm run visual                      # Playwright visual-regression suite
bash scripts/docs/check-doc-drift.sh # локальный страж документации
```

Настройку pre-push / post-merge хуков см. в [docs/dev/git-hooks.md](./docs/dev/git-hooks.md).

## Documentation Map

Документация сгруппирована по назначению. Полный индекс — [docs/index.md](./docs/index.md).

### Ядро — обязательно поддерживать актуальной

| Документ | О чём |
|---|---|
| [docs/architecture-map.md](./docs/architecture-map.md) | Карта фич ⇆ файлы / API / DB / cron. Стартовая точка для онбординга. |
| [docs/product-model.md](./docs/product-model.md) | Продуктовая модель: провайдер vs. тенант, зоны ответственности, роли. |
| [docs/feature-audit-matrix.md](./docs/feature-audit-matrix.md) | Матрица фич: что реализовано, в каком состоянии. |
| [docs/project-reference.md](./docs/project-reference.md) | Инвентарь файлов и эндпоинтов. |
| [docs/order-lifecycle-contract.md](./docs/order-lifecycle-contract.md) | Контракт жизненного цикла заказа. |
| [docs/openapi.yaml](./docs/openapi.yaml) | Контракт мобильного API v1. |

### Модули (фичи)

| Модуль | Документ |
|---|---|
| SaaS Billing (Phase 14) | [docs/billing.md](./docs/billing.md) |
| Reservations | [docs/reservations.md](./docs/reservations.md) |
| KDS (Kitchen Display) | [docs/kds.md](./docs/kds.md) |
| Inventory | [docs/inventory.md](./docs/inventory.md) |
| Loyalty | [docs/loyalty.md](./docs/loyalty.md) |
| Multi-location | [docs/multi-location.md](./docs/multi-location.md) |
| Analytics v2 | [docs/analytics.md](./docs/analytics.md) |
| Group orders + split-bill | [docs/group-split-bill.md](./docs/group-split-bill.md) |
| Staff v2 (shifts + swap + payroll) | [docs/staff-v2.md](./docs/staff-v2.md) |
| 54-ФЗ Fiscal | [docs/fiscal-54fz.md](./docs/fiscal-54fz.md) |
| Reviews | [docs/reviews.md](./docs/reviews.md) |
| Modifiers | [docs/modifiers.md](./docs/modifiers.md) |
| Outgoing webhooks | [docs/webhook-integration.md](./docs/webhook-integration.md) |
| i18n | [docs/i18n.md](./docs/i18n.md) |
| Payments (YooKassa / T-Bank) | [docs/payments-integration.md](./docs/payments-integration.md) |
| PWA + Push | [docs/pwa-and-push.md](./docs/pwa-and-push.md) |

### Развёртывание и операции

| Документ | О чём |
|---|---|
| [docs/deployment-workflow.md](./docs/deployment-workflow.md) | Правила деплоя, релизные ветки, post-merge гейты. |
| [docs/tenant-launch-checklist.md](./docs/tenant-launch-checklist.md) | Чеклист запуска нового тенанта. |
| [docs/deploy/nginx-pool-split.md](./docs/deploy/nginx-pool-split.md) | nginx pool split. |
| [docs/deploy/php-fpm-pool-split.md](./docs/deploy/php-fpm-pool-split.md) | PHP-FPM pool split. |

### Безопасность

| Документ | О чём |
|---|---|
| [docs/security-hardening-roadmap.md](./docs/security-hardening-roadmap.md) | Фазовый роадмап упрочнения. |
| [docs/security-smoke-checklist.md](./docs/security-smoke-checklist.md) | Smoke-чеклист. |
| [docs/security-change-log-template.md](./docs/security-change-log-template.md) | Шаблон журнала изменений. |

### UX / Frontend

| Документ | О чём |
|---|---|
| [docs/ui-patterns.md](./docs/ui-patterns.md) | Канонические block compositions для нового UI. |
| [docs/public-layer-guidelines.md](./docs/public-layer-guidelines.md) | Правила публичного слоя (provider vs tenant). |
| [docs/ux-ui-improvement-roadmap.md](./docs/ux-ui-improvement-roadmap.md) | UX/UI-роадмап. |

### Стратегия

| Документ | О чём |
|---|---|
| [docs/product-vision-2027.md](./docs/product-vision-2027.md) | North-star видение на 12 месяцев. |
| [docs/project-improvement-roadmap.md](./docs/project-improvement-roadmap.md) | Сквозной phased-роадмап. |

## Current Constraints

- **Shared-host scope lock:** изменения меню не должны трогать Docker/порты других сайтов на том же хосте.
- **CSP:** никаких инлайн-стилей и скриптов без nonce — всё во внешних файлах с `?v=$appVersion`.
- **API contract source of truth:** [docs/openapi.yaml](./docs/openapi.yaml) — это контракт **мобильного** API v1.
- **Тесты:** Playwright visual + browser regression (`tests/visual/`); PHPUnit unit suite (lifecycle + idempotency + createOrder); MySQL-gated тесты на `composer test`.
