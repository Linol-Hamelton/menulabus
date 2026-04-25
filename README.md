# Menu Labus

**White-label SaaS-платформа для управления рестораном.** Цель — первый из коробки сервис, который позволяет маленькому кафе выйти в онлайн и расти в сеть без собственной IT-команды. Один кодовой базис работает одновременно в двух режимах — B2B-витрина провайдера (`menu.labus.pro`) и брендированные сайты ресторанов-тенантов на собственных доменах с полной изоляцией данных.

Долгосрочное видение — «Shopify для ресторанов»: гость-ориентированный онлайн-заказ + бронь + оплата, подсвеченные полноценной операционкой (кухонный дисплей, склад, лояльность, персонал) и открытой платформой интеграций (webhooks, public API, marketplace). Стратегический «север» — [docs/product-vision-2027.md](./docs/product-vision-2027.md).

## Implementation Status

- Status: `Implemented (foundation, Phases 0-5) + Planned (Phases 6-9)`
- Last reviewed: `2026-04-23`
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current notes:
  - Фундамент платформы завершён — см. [Feature Highlights](#feature-highlights) ниже.
  - Следующие 12 месяцев: Phase 6 (KDS / инвентаризация / лояльность / multi-location), Phase 7 (iiko / 54-ФЗ / i18n / staff / split bill), Phase 8 (marketing automation / AI / group ordering / waitlists), Phase 9 (SaaS billing / public API / compliance / multi-region). Детали — в [project-improvement-roadmap.md](./docs/project-improvement-roadmap.md).
  - Провайдер-тенант split реализован на уровне рантайма (`session_init.php`, `lib/tenant/*`).
  - `public_entry_mode` — публичная точка входа тенанта настраивается per-deployment (`homepage` оставляет лендинг ресторана, `menu` редиректит на `/menu.php`).
  - Автоматизация запуска тенанта: `scripts/tenant/launch.php`, `scripts/tenant/go-live.sh`, `scripts/tenant/smoke.php`; DNS остаётся внешней предпосылкой, всё остальное (схема, сид, vhost bootstrap, certbot, финальный vhost swap) — один проход.
  - Полный `repo + live` аудит документации + кода проведён `2026-04-11` — см. [PROJECT_AUDIT_2026-04-11.md](PROJECT_AUDIT_2026-04-11.md) / [PROJECT_AUDIT_2026-04-11_RU.md](PROJECT_AUDIT_2026-04-11_RU.md).

## Feature Highlights

### Shipped foundation

- **Онлайн-заказ:** корзина, самовывоз / доставка / стол, модификаторы (группы + опции), чаевые, ABC-анализ в отчётах владельца.
- **Платежи:** YooKassa, T-Bank (Тинькофф), СБП, наличные — с идемпотентностью и отдельным webhook-слоем.
- **Бронь столов:** клиентская форма на `/reservation.php`, админский борд в `employee.php`, Telegram accept/reject, mobile API и session endpoints ([docs/reservations.md](./docs/reservations.md)).
- **Outgoing webhooks:** HMAC-SHA256, exponential-backoff retries, админ-панель на `/admin-webhooks.php`, CLI worker, event catalogue ([docs/webhook-integration.md](./docs/webhook-integration.md)).
- **Admin UX:** drag-n-drop сортировка меню, bulk actions (hide / show / archive / move-to-category), горячие клавиши, client-side фильтры/сортировка, undo-toast для soft-delete модификаторов ([docs/admin-menu-ux.md](./docs/admin-menu-ux.md)).
- **Мульти-тенантность:** `1 клиент = 1 отдельная БД`, имена БД содержат slug клиента, one-command go-live.
- **Ролевой бэкофис:** владелец, администратор, сотрудник, клиент — каждому своя поверхность.
- **PWA + push:** service worker, offline-очередь, VAPID push, динамический manifest с брендингом тенанта.
- **Мобильный API v1:** REST для Capacitor-обёртки, OpenAPI-контракт в [docs/openapi.yaml](./docs/openapi.yaml).
- **Telegram-бот:** уведомления о заказах и бронях с inline-кнопками accept/reject, алерты по стоп-листу.
- **Онбординг-мастер** для новых владельцев тенантов (5 шагов).
- **QR-столы:** печать QR-кодов, привязка заказов к столу, pre-fill стола на формах.
- **Брендинг:** кастомный домен, свои цвета / шрифты / лого, опциональное скрытие «Powered by».
- **Отзывы:** 1-5 звёзд после `завершён`, 5-звёздочные подсвечивают Google review deep-link.
- **OAuth:** VK, Yandex (Google — в процессе).
- **Security hardening:** жёсткий CSP (`default-src 'none'`, nonce-only script/style), унифицированный CSRF через `lib/Csrf.php`, role gates на всех админских endpoint-ах, HSTS, COOP/CORP/COEP.
- **Git-based деплой:** pre-push страж docs-drift / OpenAPI / mojibake / PHPUnit, post-merge browser regression на Playwright.

### Roadmap (next 12 months)

Детали — в [docs/product-vision-2027.md](./docs/product-vision-2027.md) и [docs/project-improvement-roadmap.md](./docs/project-improvement-roadmap.md).

- **Phase 6 (Q2 2026) — Restaurant Operations Core:** Kitchen Display System, складской учёт ингредиентов, программа лояльности, расширенная аналитика, multi-location.
- **Phase 7 (Q3 2026) — Platform & Integration:** iiko-адаптер, 54-ФЗ фискальный контур, полный i18n (RU/EN/KK), управление сменами и чаевыми, split bill.
- **Phase 8 (Q4 2026) — Growth & Retention:** marketing automation (email/SMS/push), AI-рекомендации, group ordering, waitlists, модерация отзывов.
- **Phase 9 (Q1 2027) — Enterprise & Platform:** SaaS billing engine, developer platform (public API + SDK + marketplace), compliance pack (GDPR/152-ФЗ/ЕГАИС/Меркурий), multi-region HA, onboarding 2.0.

## Core Rules

- `1 клиент = 1 отдельная БД`.
- Имена БД должны содержать бренд-slug клиента.
- Провайдерский маркетинг не должен появляться на публичных тенант-доменах.
- Все стили — во внешних `.css`, все скрипты — во внешних `.js`; встраивание `<style>`/`<script>` без `nonce` запрещено политикой CSP.
- Активная документация живёт в [`docs/`](./docs/index.md).

## Quick Start (разработчик)

```bash
npm ci
npm run openapi:validate            # проверка контракта мобильного API
bash scripts/docs/check-doc-drift.sh # локальный страж документации
```

Настройку pre-push / post-merge хуков см. в [docs/dev/git-hooks.md](./docs/dev/git-hooks.md).

## Documentation Map

Документация сгруппирована по назначению. Начать стоит с **Core Docs** — они описывают «правду» о продукте и обязательны к поддержанию в актуальном состоянии.

### Core Docs — база, поддерживать актуальной

| Документ | О чём |
|---|---|
| [docs/index.md](./docs/index.md) | Индекс всей документации, стартовая точка. |
| [docs/product-model.md](./docs/product-model.md) | Продуктовая модель: провайдер vs. тенант, зоны ответственности, роли, понятия. |
| [docs/feature-audit-matrix.md](./docs/feature-audit-matrix.md) | Матрица фич: что реализовано, в каком состоянии, ссылка на код. |
| [docs/project-reference.md](./docs/project-reference.md) | Инвентарь файлов и эндпоинтов проекта: что где лежит и для чего. |
| [docs/project-improvement-roadmap.md](./docs/project-improvement-roadmap.md) | Сквозной роадмап улучшений с фазами и статусами. |
| [docs/order-lifecycle-contract.md](./docs/order-lifecycle-contract.md) | Контракт жизненного цикла заказа: статусы, переходы, stale-пороги. |
| [docs/openapi.yaml](./docs/openapi.yaml) | Контракт мобильного API v1 (source of truth для `api/v1/*`). |
| [docs/api-smoke.md](./docs/api-smoke.md) | Smoke-раннер для API: как запускать и какие кейсы покрывает. |

### Развёртывание, запуск тенанта и операции

| Документ | О чём |
|---|---|
| [docs/deployment-workflow.md](./docs/deployment-workflow.md) | Правила деплоя, релизные ветки, процесс пуша, валидация. |
| [docs/tenant-launch-checklist.md](./docs/tenant-launch-checklist.md) | Чеклист запуска нового тенанта от подготовки схемы до go-live. |
| [docs/tenant-demo-seed.md](./docs/tenant-demo-seed.md) | Как сидировать демо-тенант для показа/тестов. |
| [docs/deploy/nginx-pool-split.md](./docs/deploy/nginx-pool-split.md) | Разделение nginx pool между тенантами/провайдером. |
| [docs/deploy/php-fpm-pool-split.md](./docs/deploy/php-fpm-pool-split.md) | Разделение PHP-FPM pool. |
| [docs/db/backfill-order-items.md](./docs/db/backfill-order-items.md) | Runbook одноразового backfill `order_items`. |

### Безопасность

| Документ | О чём |
|---|---|
| [docs/security-hardening-roadmap.md](./docs/security-hardening-roadmap.md) | Фазовый роадмап упрочнения: статусы, план, что сделано. |
| [docs/security-smoke-checklist.md](./docs/security-smoke-checklist.md) | Smoke-чеклист для быстрой проверки безопасной поверхности. |
| [docs/security-phase-commands.md](./docs/security-phase-commands.md) | Конкретные команды для каждой фазы hardening. |
| [docs/security-phase-2-inventory.md](./docs/security-phase-2-inventory.md) | Инвентарь сетевой защиты (Phase 2). |
| [docs/security-change-log-template.md](./docs/security-change-log-template.md) | Шаблон журнала изменений по безопасности (копировать в `security-change-log-<date>.md`). |

### UX / клиентский слой

| Документ | О чём |
|---|---|
| [docs/public-layer-guidelines.md](./docs/public-layer-guidelines.md) | Правила публичного слоя: что можно/нельзя показывать тенанту и провайдеру. |
| [docs/ux-ui-improvement-roadmap.md](./docs/ux-ui-improvement-roadmap.md) | UX/UI-роадмап: что улучшено, что открыто, как проверено. |
| [docs/menu-capabilities-presentation.md](./docs/menu-capabilities-presentation.md) | Презентация возможностей меню — для sales/демо. |
| [docs/backoffice-role-helpers.md](./docs/backoffice-role-helpers.md) | Подсказки по ролям для бэкофиса (владелец/админ/сотрудник). |

### Интеграции

| Документ | О чём |
|---|---|
| [docs/vk-oauth-setup.md](./docs/vk-oauth-setup.md) | Настройка VK OAuth. |
| [docs/yandex-oauth-setup.md](./docs/yandex-oauth-setup.md) | Настройка Yandex OAuth. |
| [docs/mobile/capacitor-wrapper.md](./docs/mobile/capacitor-wrapper.md) | Мобильный Capacitor-враппер: текущее состояние, ограничения. |

### Для разработчика

| Документ | О чём |
|---|---|
| [docs/dev/git-hooks.md](./docs/dev/git-hooks.md) | pre-push / post-merge / post-commit хуки и как их включить. |

### Аудит и стратегия

| Документ | О чём |
|---|---|
| [PROJECT_AUDIT_2026-04-11.md](./PROJECT_AUDIT_2026-04-11.md) | Полный аудит `docs/` ↔ реализация + конкурентный бенчмарк + стратегический роадмап (EN). |
| [PROJECT_AUDIT_2026-04-11_RU.md](./PROJECT_AUDIT_2026-04-11_RU.md) | Русскоязычная версия того же аудита. |

> **Документы, которые стоит создать** (выявлено аудитом, раздел C.4):
> `payments-integration.md`, `telegram-bot-setup.md`, `modifiers.md`, `tips.md`, `pwa-and-push.md`, `schema-and-migrations.md`, `deploy/custom-domain-go-live.md`, `testing-strategy.md`.

## Current Constraints

- **Shared-host scope lock:** изменения меню не должны трогать Docker/порты других сайтов на том же хосте.
- **CSP:** никаких инлайн-стилей и скриптов без nonce — всё во внешних файлах с `?v=<?= $appVer ?>`.
- **API contract source of truth:** [docs/openapi.yaml](./docs/openapi.yaml) — это контракт **мобильного** API. Веб-эндпоинты (`save-*.php`, `toggle-*.php`, `update_*.php`) пока вне OpenAPI.
- **Тесты:** браузерная регрессия на Playwright (`scripts/perf/post-release-regression.{sh,cjs}`), PHP-юнитов пока нет — см. PROJECT_AUDIT, раздел B.10.
