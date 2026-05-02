# Product Vision 2027 — Top-1 Restaurant SaaS

## Status

- Status: `Draft — strategic`
- Last reviewed: `2026-04-23`
- Horizon: Q2 2026 → Q1 2027 (~12 months)
- Owner: провайдер (platform team)

Этот документ — стратегический «север», а не технический план задач. Сроки и приоритеты — ориентировочные; конкретные спринты разбиваются в [project-improvement-roadmap.md](./project-improvement-roadmap.md).

## 1. North Star

Превратить CleanMenu / Menu Labus из white-label «меню + корзина + заказы» в **цельную платформу управления рестораном**, конкурирующую на одном поле с iiko / R_Keeper / Poster / QuickResto, но:

- в модели **SaaS без коробки** (нулевой ИТ-овский entry для маленького ресторана),
- с приоритетом на **гость-орientированный опыт** (мобильный заказ / бронь / оплата за столом — first-class, POS — дополнительный слой, а не центр вселенной),
- и с **открытой платформой интеграций** (webhooks, public API, marketplace), где сторонние разработчики тянут свои решения вместо того, чтобы платформа пыталась делать всё сама.

Формула позиционирования: **«Shopify для ресторанов»** — то есть всё, что нужно, чтобы запустить и растить ресторан онлайн и оффлайн, без необходимости в IT-команде.

## 2. Where We Are (2026-04-23)

Состояние кода на момент фиксации этого документа:

### 2.1 Shipped core (fully operational)

| Домен | Статус | Notes |
|---|---|---|
| Мульти-тенантность (1 client = 1 DB, custom domain, white-label) | Implemented | Автоматизация go-live в `scripts/tenant/go-live.sh`. |
| Онлайн-заказ (корзина, модификаторы, чаевые, самовывоз/доставка/стол) | Implemented | - |
| Платежи: YooKassa, T-Bank, СБП, наличные | Implemented | Идемпотентность на уровне API. |
| Бронь столов (end-to-end: client UI + staff board + Telegram + API) | Implemented | См. [reservations.md](./reservations.md). |
| Telegram-бот: приём заказов / броней с inline-кнопками | Implemented | См. [telegram-bot-setup.md](./telegram-bot-setup.md). |
| Мобильный API v1 + Capacitor wrapper | Implemented (Capacitor — partial: provider-centric) | См. [openapi.yaml](./openapi.yaml). |
| Outgoing webhooks (HMAC, retry, admin UI) | Implemented | См. [webhook-integration.md](./webhook-integration.md). |
| PWA + Push notifications | Implemented | VAPID rotation, offline-queue. |
| Отзывы (1-5 stars + Google review deep-link) | Implemented | См. [reviews.md](./reviews.md). |
| QR-столы, печать QR-кодов | Implemented | - |
| Онбординг 5-step wizard | Implemented | - |
| Admin UX: drag-n-drop menu order, bulk actions, hotkeys, filters, undo | Implemented | См. [admin-menu-ux.md](./admin-menu-ux.md). |
| CSP nonce-only, CSRF через `lib/Csrf.php`, role gates | Implemented | Strict baseline в `security-headers.php`. |
| ABC-анализ в отчётах владельца | Implemented | - |
| Role-based backoffice (owner/admin/employee/customer) | Implemented | - |

### 2.2 Partially shipped / deferred

- **Security host rollout (Phase 2/3/5)** — скрипты готовы, нужна раскатка на проде.
- **Mobile Capacitor tenant-aware** — пока provider-centric.
- **OAuth (Google/VK/Yandex)** — runtime-config dependent.
- **CSRF на `api/save/project-name.php` / `send_message.php`** — требует правки минифицированного JS.

### 2.3 What we don't yet have (competitive gaps)

| Пробел | Почему важно |
|---|---|
| Kitchen Display System (KDS) | Кухня сейчас работает «на слух» от Telegram-бота — не масштабируется при ≥50 заказов/час. |
| Складской учёт ингредиентов | Нет auto-списания, закупок, прогноза. Стоп-лист — единственный инструмент. |
| Программа лояльности | Один cashback-канал (Google-review), нет баллов / уровней / персонализации. |
| Multi-location (сеть ресторанов) | Одна лицензия = один ресторан; франшиз пока не обслуживаем. |
| Staff management (смены, зарплаты, чаевые split) | Роли есть, но нет учёта времени и распределения. |
| POS-интеграция (iiko / R_Keeper / Poster) | Тенант, работающий на iiko, сейчас не купит. |
| 54-ФЗ фискальный контур | Эмиссия чеков в РФ не закрыта — блокер для монетизации. |
| Full i18n | Русский хардкод повсюду — не берём англо/казахоязычные рынки. |
| SaaS billing engine | Тарифные планы, триал, скидочные коды, Stripe/Paddle. |
| Marketing automation | Email/SMS кампании, retention-triggers. |
| AI-рекомендации | Апсейл, динамическое ценообразование, прогноз спроса. |
| Compliance-трек (GDPR/152-ФЗ/EGAIS/Меркурий, 2FA, audit log) | Блокер для enterprise-продаж. |
| Public developer platform (SDK, Zapier, marketplace) | Отсутствие экосистемы — слабый moat. |

## 3. Strategic Phases (next 12 months)

Четыре фазы по 3 месяца. Каждая фаза — один релизный «поезд», который можно полностью откатить одним коммитом на release-ветке; между фазами — фиксация контрактов и измерений.

### Phase 6 — Restaurant Operations Core (Q2 2026)

**Цель:** закрыть операционный разрыв с iiko/Poster — сделать кухню, склад и программу лояльности first-class.

| Трек | Выход |
|---|---|
| 6.1 Kitchen Display System | Separate `/kds/index.php` для кухни, per-station routing (hot/cold/bar), drag-to-acknowledge, real-time через SSE или WebSocket. |
| 6.2 Inventory MVP | Таблицы `ingredients`, `recipes`, `stock_movements`; auto-deduction при `order.created`; admin UI для рецептов. |
| 6.3 Loyalty Program | Балльная система, tier levels (Bronze/Silver/Gold), cashback в баллы, промокоды v2. |
| 6.4 Enhanced Analytics | Маржа по блюдам (уже есть `cost`), cohort-анализ клиентов, heatmap час × день, прогноз выручки на неделю. |
| 6.5 Multi-location | Модель `location_id` в orders/menu; cross-location отчёты для владельца сети. |

**Success metric:** первый «полный-цикл» тенант (приём → кухня → склад → лояльность) работает без iiko.

### Phase 7 — Platform & Integration (Q3 2026)

**Цель:** снять интеграционные барьеры для входа — не заставлять тенанта выбирать между старым POS и нами.

| Трек | Выход |
|---|---|
| 7.1 iiko adapter | Two-way sync меню / заказов / стоп-листа. `lib/integrations/Iiko.php`, cron-синхронизация. |
| 7.2 54-ФЗ фискальный | Интеграция с «Атол-Онлайн» или «Эвотор Чек Онлайн» — эмиссия электронных чеков на `order.paid`. |
| 7.3 Full i18n | `/locales/{ru,en,kk}.json`, `lib/I18n.php`, миграция клиентских поверхностей → админка. |
| 7.4 Staff Management | Смены (`shifts`), time-tracking, распределение чаевых (`tip_splits`), KPI по ролям. |
| 7.5 Advanced Payments | Split bill per seat / per item, pay-per-person через QR-ссылки, отложенная оплата. |

**Success metric:** тенант-на-iiko может прийти и включить CleanMenu как витрину, не выключая iiko.

### Phase 8 — Growth & Retention (Q4 2026)

**Цель:** перейти от «ресторан купил» к «ресторан зарабатывает больше благодаря платформе».

| Трек | Выход |
|---|---|
| 8.1 Marketing Automation | Email/SMS/Push кампании; trigger-based сценарии (abandoned cart для online orders, birthday promos, win-back). |
| 8.2 AI Recommendations | Smart upsell в корзине («к этой пицце обычно берут…»), menu optimization suggestions в owner-отчётах, прогноз спроса. |
| 8.3 Group Ordering | Один стол, несколько гостей → каждый сам добавляет позиции → общий чек или split. |
| 8.4 Waitlists | Форма «встать в очередь» при полной загрузке; SMS-пинг когда стол освободился. |
| 8.5 Review Moderation | Ответы на отзывы в owner-панели, публикация лучших отзывов на сайте, модерация через Telegram. |

**Success metric:** retention по тенантам через 12 месяцев после подключения ≥ 85%.

### Phase 9 — Enterprise & Platform (Q1 2027)

**Цель:** перейти от «маленькое кафе купит» к «сеть из 50 ресторанов подпишет enterprise-контракт».

| Трек | Выход |
|---|---|
| 9.1 SaaS Billing Engine | Тарифные планы (Starter/Pro/Enterprise), usage-based (комиссия с транзакций), Stripe/Paddle. Free trial 14 дней. |
| 9.2 Developer Platform | Public API с rate-limiting и API keys, JS/Python SDK, webhook extensions marketplace, Zapier integration. |
| 9.3 Compliance Pack | GDPR + 152-ФЗ (data export, right to delete); ЕГАИС для алкоголя; Меркурий для мяса/рыбы; 2FA для admin/owner; audit log. |
| 9.4 Multi-region / High-availability | DB replication, read replicas для analytics, CDN для статики, автоматический failover. |
| 9.5 Onboarding 2.0 | Template library (fast-food / fine-dining / cafe / bar / pizzeria), interactive demo, in-app help center, chat support. |

**Success metric:** первая сеть ≥ 10 ресторанов подписана на Enterprise-план.

## 4. What Stays Sacred (non-negotiable)

Эти правила не отменяются ни одной фазой — они обеспечивают устойчивость модели:

1. **1 client = 1 отдельная БД.** Никогда — общая таблица с `tenant_id`.
2. **Strict CSP.** Никаких `unsafe-inline` / `unsafe-eval`. Новые UI — только внешний `.css` / `.js`.
3. **API contract в `openapi.yaml`.** Мобильные клиенты не должны ломаться от релиза сервера.
4. **Идемпотентность на POST с `Idempotency-Key`.** Все новые endpoint-ы создания сущностей — через [lib/Idempotency.php](../lib/Idempotency.php).
5. **Docs-first for major changes.** `docs/feature-audit-matrix.md` обновляется в том же PR, что и фичу вводит; `pre-push` docs-drift guard ловит забытые обновления.
6. **One change = one release step.** Никаких «bundled risky changes» — каждая фаза кончается стабильным релизным поездом.
7. **Backward-compatible schema migrations.** Новая миграция — всегда `ADD COLUMN IF NOT EXISTS` + `NULL default`; drop колонок — только после полного прохождения deprecation-окна.

## 5. Anti-goals (что мы сознательно НЕ делаем)

Чтобы не расползаться — фиксируем то, что отдаём рынку:

- **Hardware POS-терминалы.** Не производим. Работаем с любым Android/iPad/Web.
- **Full ERP для больших сетей.** iiko + 1С — лучше. Мы — слой «гость → заказ → операции», не financial accounting.
- **Собственная доставка.** Интегрируемся с Яндекс.Доставкой / Dostavista / Broniboy, но свой флот курьеров не строим.
- **Social network для ресторанов.** Не TripAdvisor, не Zoon. Отзывы — только для владельца ресторана, не публикация ленты.
- **Коробочная on-premise-установка.** Только SaaS, multi-tenant по домену. Self-hosted — отдельный SKU с enterprise-ценой, не mass-market.

## 6. Risk Register

| Риск | Митигация |
|---|---|
| Конкуренты (iiko, Poster) снижают цены / добавляют web-первичность. | Наш moat — white-label + мобильный UX. Поддерживаем цикл «неделя от фичи до прод» через docs-drift guard + Playwright regression. |
| 54-ФЗ / ЕГАИС / Меркурий нормативная нестабильность. | Фаза 9.3 — изолированный компонент; смена API провайдеров фискальных услуг не требует переписывания бизнес-логики. |
| Недостаток инженерных ресурсов для всех 4 фаз за 12 мес. | Фазы независимы — можно отложить 8 или 9, не блокируя 6-7. Phase 6 — MVP, каждая под-фича отгружаема отдельно. |
| Миграция на фазах ломает существующих тенантов. | `menu-sort-order-migration.sql` / `modifiers-soft-delete-migration.sql` — эталон: `IF NOT EXISTS`, обратная совместимость без миграции, rollback документирован. |
| Внешние API (Telegram, YooKassa, Google Reviews) меняют контракты. | Обёртки в `lib/*` изолируют; сломанный API = одна замена интеграционного слоя. |
| Security-инциденты в open webhook-системе. | HMAC на POST; rate-limit на admin-webhooks endpoint; Phase 9.3 audit log. |

## 7. Cross-cutting Investment Areas

Эти вложения не относятся к одной фазе — они идут фоном и ускоряют все остальные:

- **Observability.** Перевод с `error_log` на Monolog + Sentry / Glitchtip; метрики в Prometheus; дашборды в Grafana. Starting point: [scripts/api-metrics-report.php](../scripts/api-metrics-report.php) + `webhook_lag_seconds`.
- **Tests.** Покрытие `Database::*` MySQL-gated тестами; Playwright smoke расширяем на KDS, inventory, loyalty. Baseline: [docs/testing-strategy.md](./testing-strategy.md).
- **Developer experience.** Docker Compose для локальной разработки; composer автолoad (PSR-4) для `lib/*`; CHANGELOG.md + semantic versioning.
- **Performance.** AssetPipeline в production (Phase 3 из исходного roadmap); lazy-loading + WebP в клиентском меню; Redis warm-up после меню-правки.
- **Accessibility.** ARIA на все icon-only кнопки (уже есть частично в admin-webhooks); `role="dialog"` + focus-trap для модалок; контрасты WCAG AA.

## 8. Success Metrics (12 months)

| Метрика | Baseline (2026-04) | Target (2027-04) |
|---|---|---|
| Активных тенантов | 1 (test.milyidom.com) | 50+ коммерческих |
| MRR | 0 | ≥ 500k ₽ |
| Среднее время от sign-up до первого заказа | ~1 сутки (ручная настройка) | ≤ 2 часа (self-serve onboarding) |
| Retention 12 месяцев | n/a | ≥ 85% |
| p95 latency `/menu.php` | not measured | ≤ 300 ms |
| Webhook delivery success rate | n/a (не в проде) | ≥ 99.5% |
| Покрытие кода тестами (lib/*) | ~20% (lifecycle + idempotency + reservations) | ≥ 60% |
| Known security advisories | 3 отложенных (save-project-name, send_message, host rollout) | 0 |

## 9. How to Use This Document

- **Product decisions.** Если предлагаемая фича не проходит в одну из фаз 6-9 — она либо anti-goal, либо должна быть перенесена отдельной Phase 10 с обоснованием.
- **Technical decisions.** Sacred rules (§4) — железные. Anti-goals (§5) — можно оспорить, но через отдельный ADR.
- **Hiring / resourcing.** Phase 6 = 1 full-stack + 1 PM; Phase 7 = +1 integration engineer; Phase 8 = +1 ML engineer; Phase 9 = +1 SRE + 1 compliance lead. Если штат не масштабируется — откладываем поздние фазы, а не сжимаем качество ранних.
- **Revisions.** Пересматривать документ каждые 3 месяца (конец фазы) — добавлять shipped в §2.1, переоткрывать §2.3, сверять с §8.

## 10. Related Docs

- [project-improvement-roadmap.md](./project-improvement-roadmap.md) — тактический роадмап (фазы 0-5 уже закрыты; 6-9 синхронизированы с этим документом).
- [feature-audit-matrix.md](./feature-audit-matrix.md) — текущая матрица фич; секция `planned` расширена фазами этого документа.
- [admin-menu-ux.md](./admin-menu-ux.md) / [reservations.md](./reservations.md) / [webhook-integration.md](./webhook-integration.md) — reference-документы по уже отгруженным модулям.
- [testing-strategy.md](./testing-strategy.md) / [security-hardening-roadmap.md](./security-hardening-roadmap.md) — cross-cutting треки.
