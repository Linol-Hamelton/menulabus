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
  - **Phase 35-39 Hardening bundle** (2026-05-12, v3.8.6). (A) **AuditLog wired** across все новые privileged endpoints: `cashier_shift.open/close/encash`, `order.refund`, `vsd.accept/reject`, `egais.invoice.accept/reject`, `egais.bottle_open`, `odata.creds.rotate/enable/disable`, `aggregator.settings.save`, `aggregator.secret.rotate`, `aggregator.webhook.received`, `aggregator.webhook.rate_limited`. Каждый action логируется с target_type + target_id + meta (без секретов). Просматривать через owner audit-log page. (B) `lib/RateLimiter.php` — file-based fixed-window counter (data/cache/ratelimit/<sha>.json), fail-open. Применён в `api/aggregator/webhook.php`: **60 запросов/мин per (provider + client_ip)** → 429 + Retry-After header + audit_log entry. (C) Loosened type hints: `AggregatorAdapter::normalize($db: \\Database)` → `$db: object` для duck-typing в unit tests без реального PDO connection (production code не affected — Database object всё ещё проходит). (D) **Unit tests**: `tests/AggregatorAdapterTest.php` — 14 cases: HMAC verify positive/tampered/empty/truncated (timing-safe via hash_equals), normalize maps fields + falls back unknown name + backfills from local menu_items, mapStatusOutbound (5 internal → external mappings × 2 providers), statusPushUrl содержит externalId. `tests/ODataQueryParserTest.php` — 13 cases: $top/$skip clamping (1..1000 / 0..1M), $select whitelist (unknown stripped), $filter eq/gt/substringof/datetime/bool literals, **reject unknown field** (SQL-injection regression guard), AND-composition, PDO-param keys prefixed `:f`. Локают invariants чтобы будущая refactor не drift'нула auth/SQL boundaries.
  - **Phase 36.4 (Polish bundle)** — финиш Tier-1 (v3.8.5). (A) `Database::listMenuItemsWithAggregatorIds` использовала несуществующую колонку `is_available` (правильная — `available`) → SQL error → mapping-table рисовала «0 блюд» вместо 52. Фикс + добавил `WHERE archived_at IS NULL`. То же исправлено в `api/v1/odata/menu_items.php` (whitelist `IsAvailable` мап на `m.available`). (B) `.vsd-filter-actions a.admin-checkout-btn` получил явные `display: inline-flex` + `text-decoration: none` + `line-height: 1` — anchor-as-button «Сбросить» теперь visually identical с соседней `<button>`. (C) `.aggregator-mapping` (контейнер mapping-table) получил `overflow-x: auto` + table `min-width: 480px` — длинные имена блюд скроллируются горизонтально на mobile (375), не ломая layout.
  - **Phase 36.2** — критичный фикс: `orders.user_id` был `INT NOT NULL` + FK на `users(id)`, но aggregator-orders не имеют локального user_id (user_id=NULL) — INSERT падал с NOT NULL violation → create_failed (v3.8.2). Расширена aggregator-migration: MODIFY COLUMN user_id INT NULL (FK constraint остаётся; NULL — валидное значение для nullable FK). Idempotent через INFORMATION_SCHEMA IS_NULLABLE check.
  - **Phase 36.1** — фиксы после MCP verify (2026-05-12, v3.8.1): (A) `js/owner-integrations.js` csrfToken чтение: `body.data-csrf-token` отсутствует на `/owner.php` (в отличие от `/admin/*`), поэтому save_settings / save_mapping POST'ы возвращали 403 «Invalid CSRF token». Добавлен fallback chain: body→partial[data-csrf-token]→meta[name=csrf-token]. (B) `Database::createOrderFromAggregator` clamps `delivery_details` через `mb_substr(..., 255)`: orders.delivery_details — VARCHAR(255), а я туда совал json_encode со всеми customer_name/phone/external_id → INSERT падал с column-truncation → create_failed. Теперь delivery_details = короткий summary (address + phone), полный normalized payload остаётся в orders.aggregator_payload (JSON column без лимита).
  - **Phase 36** — Yandex.Еда + Delivery Club aggregator integration (2026-05-12, v3.8.0): финальная фаза Tier-1 RU критичных фич. Закрывает revenue-channel blocker — для большинства ресторанов 30-50% выручки идёт через агрегаторов. (A) Новая таблица `aggregator_settings` (provider UNIQUE, api_key, webhook_secret, enabled, last_webhook_at, last_push_at) + 4 nullable колонки на `orders` (aggregator_source/_order_id/_status/_payload JSON) + 2 nullable колонки на `menu_items` (aggregator_yandex_id, aggregator_dc_id) через `INFORMATION_SCHEMA + PREPARE` guards. (B) Adapter pattern: `lib/Aggregator/AggregatorAdapter.php` (interface), `YandexEda.php` + `DeliveryClub.php` (конкретные реализации) — каждый имеет HMAC-SHA256 verifySignature, normalize (приводит partner-specific schema к common shape с `external_id, total, items[{id?, name, qty, price}], customer_name?, customer_phone?, delivery_address?`), mapStatusOutbound (наш статус → partner code), statusPushUrl. (C) Inbound `/api/aggregator/webhook.php?provider=...`: verifies signature header (X-YandexEda-Signature / X-DC-Signature) против webhook_secret, normalizes payload, идемпотентно создаёт заказ через `createOrderFromAggregator` (повторный POST с тем же external_id возвращает existing order_id без insert). Order: user_id=NULL, delivery_type=delivery, payment_status=paid, привязка к открытой смене, aggregator_status='new'. Touches last_webhook_at. (D) Outbound: `scripts/aggregator-status-sync.php` — CLI cron-воркер (рекомендуется каждую минуту в `crontab -u labus_pro_usr`). Picks up orders с aggregator_source!=NULL где наш status drift от aggregator_status, мапит через adapter, PATCH'ит в `statusPushUrl` с Bearer токеном из `api_key`. Best-effort: 2xx → setOrderAggregatorStatus(pushed/delivered_pushed/cancelled_pushed); failure → error_log + skip, retry next tick. (E) `/owner.php?tab=integrations` extended: над 1С-секцией добавлены 2 aggregator-карточки. Каждая: status badge + webhook URL + signature header + last_webhook_at/last_push_at + форма (API key + webhook secret + enabled toggle) + collapsible `<details>` с mapping-table для всех menu_items. (F) Новый endpoint `api/save-aggregator.php` (3 actions: save_settings, rotate_secret, save_mapping; owner-only). At save_settings auto-generate webhook_secret если пуст. (G) `getAllOrders` patch: SELECT добавил aggregator_source/_order_id/_status; `JOIN users u` → `LEFT JOIN users u` чтобы aggregator-orders (user_id=NULL) попадали в список. (H) Бэйджи `.aggregator-badge--yandex_eda` (жёлтый, Я.Еда) / `.aggregator-badge--delivery_club` (розовый, DC) на карточках заказов в employee.php сразу после #ID. (I) Никаких inline-стилей / inline-скриптов: `css/owner-integrations.css` extended (aggregator-form + mapping-table + badge colors + mobile responsive) + `js/owner-integrations.js` extended (save_settings + save_mapping handlers с delegation по `[data-aggregator-card]`). (J) Документация: `docs/aggregator-integration.md` с HMAC-curl example, cron setup guide, 4-step verification flow. (K) **Real partner credentials** (Yandex.Еда + DC sandbox) — требуют partner agreement через partner-channel; MVP протестирован через self-signed mock webhook. Auto-discover товаров через partner-API + realtime websocket вместо polling — Phase 36.1/.2.
  - **Phase 37.1** — Polish: фикс показа amber key-banner на /owner.php?tab=integrations + пользовательские CSS правки на menu cart-item layout (2026-05-12). (A) `.integrations-key-banner` имел `display: grid` — это бьёт UA `[hidden] { display: none }` по specificity (class 0,1,0 vs attr 0,1,0 но class объявлен позже), поэтому жёлтый banner показывался всегда вместо только-после-rotate. Фикс: `.integrations-key-banner[hidden] { display: none !important }`. (B) Пользовательские правки: убрал `margin-right: 10%` с `.cart-item-image` в fa-styles.min.css, поменял `gap: 14px → 10%` в `body.menu-catalog-page .cart-item-info` в ui-ux-polish.css — корректируют layout cart-item на странице меню.
  - **Phase 37** — 1С OData integration (2026-05-12, v3.7.0): read-only OData v3 endpoint для подключения 1С Конфигуратора как «Внешний источник данных». (A) Новая single-row таблица `odata_credentials` (username UNIQUE, api_key_hash, enabled, last_used_at) — hash хранится через `password_hash()`, plaintext key показывается ровно один раз при rotate. (B) `lib/OData/OData.php` — query-parser ($top/$skip/$count/$select/$filter с min grammar: eq/ne/gt/ge/lt/le + substringof + AND-композиция; literals: 'str', datetime'iso', числа, null, true/false). Envelope-builder в OData v3 формате `{"d":{"results":[...],"__count":"N"}}`. Field-to-column whitelist предотвращает SQL-injection (любое unknown поле в $filter/$select → 400). PDO-параметры для всех литералов. (C) `lib/OData/BasicAuth.php` — HTTP Basic decoder + `Database::verifyOdataAuth()` (password_verify + enabled=1; touches last_used_at best-effort). На fail 401 + `WWW-Authenticate: Basic realm="CleanMenu OData"`. (D) Три read-only endpoint'а: `api/v1/odata/orders.php` (12 полей), `menu_items.php` (9 полей), `customers.php` (7 полей; auto-filter role='customer'). Все поддерживают $top/$skip/$count/$select/$filter. `Cache-Control: no-store` на все ответы. (E) В owner.php новый tab «Интеграции» → `partials/owner_integrations_section.php`: статус-бейдж (включено=green/выключено=red/не настроено=gray), endpoint URL + логин + last_used_at, кнопки «Сгенерировать новый ключ» (confirm + amber-banner с plaintext key и copy-button) / «Включить/Выключить интеграцию», встроенный `<details>` блок «Как подключить 1С Конфигуратор» с curl-примерами и step-by-step. (F) Новый endpoint `api/save-odata-creds.php` (actions: rotate/enable/disable, owner-only). При первом rotate auto-enable=true. (G) Никаких inline-стилей / inline-скриптов: `css/owner-integrations.css` (status badges + amber key-banner + endpoint code-style + responsive) + `js/owner-integrations.js` с nonce. Документация: `docs/1c-integration.md` с curl examples и 1С setup guide.
  - **Phase 39** — ЕГАИС / алкоголь manual MVP (2026-05-12, v3.6.0): закрывает legal blocker по 171-ФЗ для ресторанов с алкоголем. (A) 3 новые таблицы: `alc_invoices` (ttn_number, ttn_date, supplier_inn/name, total_amount, status pending/accepted/rejected, accepted_at/by, notes) + `alc_invoice_items` (FK→alc_invoices CASCADE, ingredient_id nullable, alc_code АСНА, quantity, unit, price) + `alc_openings` (ingredient_id, bottle_volume_ml, opened_at/by, shift_id FK→cashier_shifts, notes) + 2 nullable колонки `ingredients.is_alcohol` + `ingredients.alc_code` через `INFORMATION_SCHEMA + PREPARE` guards (idempotent migration `sql/egais-migration.sql`). (B) Новая страница `/admin/egais.php` (role admin/owner, gated за inventory feature) с 3 табами на основе `.egais-tab` strip: «Накладные» (summary 3 cards + filter-bar + таблица с действиями), «Акты вскрытия» (timeline alc_openings), «Отчёт об остатках» (за диапазон дат: принято/вскрыто/текущий остаток по каждому алкогольному ингредиенту). (C) Модал «Принять ТТН» с dynamic items-list: «+ Добавить позицию» добавляет row с product mapping + АСНА код + кол-во + цена. Auto-fill АСНА код из выбранного ingredient. (D) При acceptAlcInvoice → по каждой mapped позиции stock_movements row reason=receipt с note `'ТТН №X (АСНА Y)'` и stock_qty += quantity. (E) В employee shift dock новая кнопка «Вскрыть бутылку» (только при открытой смене) → modal с выбором алко-продукта + объём + notes. Автоматическая привязка к открытой смене (alc_openings.shift_id). API endpoint `open_bottle` доступен employee+. (F) В edit-modal `/admin/inventory.php` toggle «Алкоголь (171-ФЗ)» + поле «АСНА код» (показывается только при включённом toggle через JS) — сохраняется через POST /api/save-egais.php action=`toggle_alcohol` после основного save_ingredient. (G) Новый endpoint `api/save-egais.php` (6 actions: create_invoice/accept_invoice/reject_invoice/delete_invoice/open_bottle/toggle_alcohol; CSRF; open_bottle = employee+, остальное = manager_only). (H) Nav link «ЕГАИС» в account-header.php для owner/admin (рядом со «ВСД»). (I) Никаких inline-стилей / inline-скриптов: `css/admin-egais.css` (egais-tabs strip с underline-active, items-list grid с responsive flex-basis 100%↔45%) + `js/admin-egais.js` с nonce. `js/employee-shift.js` patch добавляет bottle-open handler. `js/admin-inventory.js` patch wiring is_alcohol/alc_code. Embedded `<script type="application/json" id="egaisAlcIngredients" nonce>` для передачи ingredients в JS — CSP-compliant. (J) Auto-mode через УТМ-прокси / Контур.ЕГАИС API — Phase 39.1. Подробности: `docs/egais-integration.md`.
  - **Phase 38** — Меркурий / ВСД manual MVP (2026-05-12, v3.5.0): закрывает legal blocker по 243-ФЗ для ресторанов с мясной/рыбной/молочной продукцией. (A) Новая таблица `vsd_records` (ingredient_id FK→ingredients.id, vsd_number, vsd_date, supplier_inn/name, quantity, unit, status ENUM pending/accepted/rejected, accepted_at/by, notes) + nullable колонка `ingredients.requires_vsd` через `INFORMATION_SCHEMA + PREPARE` guard (idempotent migration `sql/mercury-migration.sql`). (B) Новая страница `/admin/vsd.php` (role: admin/owner, gated за inventory plan feature): summary-cards (ожидает гашения с warn-tone / гашено / отклонено), filter-bar (статус, ингредиент со звёздочкой ★ для required-vsd, диапазон дат), таблица записей с действиями «Гашение» / «Отклонить» на pending. (C) Два `.design-modal`: создание ВСД (ingredient, ВСД №, дата, ИНН/название поставщика, кол-во+ед., заметки) + отклонение (только причина). (D) При acceptVsd: `status → accepted` + автоматический `stock_movements` row reason=receipt с прибавлением qty к `ingredients.stock_qty`. (E) В edit-modal `/admin/inventory.php` toggle «Меркурий: требует ВСД» — сохраняется через дополнительный POST `/api/save-vsd.php` action=`toggle_requires` после основного save_ingredient. (F) Новый endpoint `api/save-vsd.php` (actions: create/update/accept/reject/delete/toggle_requires, CSRF + admin/owner gate). (G) Nav link «ВСД» в account-header.php для owner/admin (рядом со «Склад»). (H) Никаких inline-стилей / inline-скриптов: `css/admin-vsd.css` (filter-bar + status-badge цвета: pending=#fef3c7/#92400e, accepted=#d1fae5/#065f46, rejected=#fee2e2/#991b1b) + `js/admin-vsd.js` с nonce. Использует существующие классы `.admin-section-card`/`.admin-pane-header`, `.design-modal`, `.checkout-btn`, `.admin-checkout-btn`+`.cancel`, `.inv-edit-form`/`.inv-edit-field`, `.recipe-save-msg-success/error`. (I) Auto-mode через Vetis REST API (требует ЭЦП клиента + Windows-service для подписи запросов) — Phase 38.1, не входит в MVP. Подробности: `docs/mercury-integration.md`.
  - **Phase 35.1** — Polish форм в shift/refund модалках (2026-05-12): labels были inline с inputs потому что employee.php не подключал admin-inventory.css (где `.inv-edit-field` определён как column flex). Скопировал минимальный form-layout block в `css/employee-shift.css`, scope'нутый на `#shiftOpenModal / #shiftCloseModal / #shiftEncashModal / #orderRefundModal` — изолированно от admin-страниц. Также сделал .modal-body grid с 14px gap для shiftCloseModal (там 2 ребёнка: Z-report + form).
  - **Phase 35** — Кассовая смена + Refund / чек коррекции (2026-05-12, v3.4.0): первая из 5-фазовой Tier-1 программы критичных RU-фич (1.7 + 1.5). (A) Новые таблицы: `cashier_shifts` (cashier_id, opened_at, closed_at, opening_cash, closing_cash, encashment_total, notes), `shift_encashments` (FK на cashier_shifts.id ON DELETE CASCADE), `order_refunds` (order_id, refunded_by, amount, is_partial, reason, fiscal_receipt_uuid/status/url, shift_id) + nullable `orders.shift_id` через `INFORMATION_SCHEMA + PREPARE` guard. (B) Sticky `partials/employee_shift_dock.php` над tabs `/employee.php`: статус смены + размен + выручка + кол-во заказов + возвраты, кнопки «Открыть смену» / «Инкассация» / «Закрыть смену». Role-gated (employee/admin/owner). Owner/admin видят чужие открытые смены как supervisor. (C) Три новых `.design-modal`: открытие смены (поле размена + notes), Z-отчёт при закрытии (orders_count / gross / tips / refund / encashment + ожидаемые наличные = размен + cash-выручка − инкассация − возвраты), инкассация (сумма + причина bank_deposit/safe/other). (D) Refund: на оплаченных карточках заказа кнопка «Возврат» (`.js-refund-trigger`) открывает modal full/partial + причина. `api/save-refund.php`: проверяет available = total − Σ предыдущих, привязывает к открытой смене, при full → `orders.payment_status='refunded'`, best-effort вызывает АТОЛ /sell_refund (новый `AtolOnline::sendCorrectionReceipt` с external_id `refund-{order}-{key}`, формирует items[] либо synthetic single-line, type 0/1 по payment_method) и пишет uuid/status в `order_refunds`. (E) `Database::createOrder` теперь автоматически заполняет `shift_id` из `getAnyOpenShift()`. (F) Новые `api/save-cashier-shift.php` (actions: open/close/encash/status, CSRF + role-gating: свой кассир ИЛИ admin/owner может закрыть) + `api/save-refund.php`. (G) Никаких inline-стилей и inline-скриптов: `css/employee-shift.css` (sticky dock + Z-report grid), `js/employee-shift.js` (dialog open/close + 3 form submits), `js/employee-refund.js` (delegated `.js-refund-trigger` listener + radio mode toggle); оба JS с `nonce` атрибутом. Использует существующие классы `.admin-section-card`-стиль, `.design-modal`, `.checkout-btn`, `.admin-checkout-btn`+`.cancel`, `.recipe-save-msg-success/error`, `.modal-card/head/body/foot`, `.inv-edit-form`/`.inv-edit-field`. Подробности: `docs/cashier-shift.md`.
  - **Phase 34.5** — поставщики через мобильную версию /admin/inventory.php (2026-05-12, v3.3.6): кнопка «Редактировать» (.js-edit-sup) на мобильной supplier-карточке открывает новую `<dialog id="invSupEditModal">` с полями name/contact/notes (сохранение через `save_supplier`); добавлена мобильная кнопка «+ Создать поставщика» + slide-down форма для создания. Обе ноды скрыты на desktop (там уже есть inline new-row внизу таблицы). Также подхвачены пользовательские правки admin-inventory.css и ui-ux-polish.css.
  - **Phase 34.4.1** — User manual CSS tweaks (2026-05-11): `margin-top: 80px` на `body.cart-page .account-section--cart-shell` и на `body.account-page:not(.help-page):not(.admin-menu-page) .account-section:last-child` для отступа от верхнего хедера; `.clear-cart-container` поменял `margin-top: 8px` + `padding-top: 20px` → `padding-top: 4%` (выровнен с базовым padding 4%); base `.clear-cart-container` в fa-styles.min.css: `padding: 2% 4%` → `padding: 4%` (равные отступы).
  - **Phase 34.4** — cart polish: nutrition reorder + clear-cart gap (2026-05-11, v3.3.4): nutrition-items в `cart.min.js` (2 template strings) реордерены — Калории перенесён в конец, порядок теперь Б → Ж → У → К. На desktop 3 макроса в одной строке, Калории отдельно ниже; на мобиле макросы wrap'ятся в 2×2 grid, Калории внизу. `.clear-cart-container` получил `gap: 12px` для надёжного spacing'a между кнопками Очистить/Заказать.
  - **Phase 34.3** — cart.php structural fix + responsive layout + nutrition wrap (2026-05-11, v3.3.3): MCP Playwright диагностика показала что все 5 cart-секций (header, items, buttons, total, summary) были завёрнуты внутрь `.account-header-bar.account-section-head` вместо того чтобы быть siblings в `.account-section--cart-shell`. `.section-header-menu` имеет `padding: 50px 8% 0` (8% от containing-block) → max-content авторазмер раздувал head до 382px при 375 viewport → grid-track расширялся → КБЖУ контейнер и другие дети вылезали на 53px за viewport. Фикс HTML: закрываем `</div>` для head сразу после `.section-header-menu`, остальное становится siblings шеллa. Фикс CSS: добавлена явная `grid-template-areas` раскладка для shell. На desktop ≥768px последний ряд `[summary] [total] [actions]` — КБЖУ слева, итого по центру, кнопки справа (primary CTA у правого края — стандартный cart UX). На мобиле — single column без overflow.
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
