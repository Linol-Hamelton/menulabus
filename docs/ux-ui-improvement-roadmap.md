# UX/UI Improvement Roadmap

## Implementation Status

- Status: `Partial · Phase 20.2 nav-actions space-evenly on mobile 2026-05-06`
- Last reviewed: `2026-05-06`

## Phase 20.2 — `.section-header-nav-actions` justify-content space-evenly on mobile (2026-05-06)

После Phase 20.1 (мобильная типографика выровнена) на mobile ≤768px ряд из 6 nav-кнопок (Заказы / Кухня / Очередь / Смены / Помощь / История) внутри `.account-header-bar` визуально клumpился к левому краю из-за `justify-content: flex-start`. Изменено на `justify-content: space-evenly` в `css/ui-ux-polish.css` L1109 — кнопки теперь равномерно распределены по ширине строки, balance с quick-actions иконками сверху. Markup и backend без изменений.



## Phase 20.1 — mobile-polish coverage для 10 missing pages (v2.5.1, 2026-05-06)

После Phase 20 desktop `.section-header-menu` стал pixel-identical между `/admin/menu.php` и `/owner.php`. Однако MCP-verify на mobile 375 показал, что admin button height всё ещё **47 px** vs owner **43 px** — Phase 20 не закрыл разницу на mobile.

Root cause — Phase 18 mobile pass добавил `css/mobile-polish.css` только в 4 страницы (`/account.php`, `/owner.php`, `/help.php`, `/admin/staff.php`), а **10 других** страниц включающих `account-header.php` остались без него:

- `/admin/menu.php`
- `/admin/inventory.php`
- `/admin/kitchen.php`
- `/admin/locations.php`
- `/admin/loyalty.php`
- `/admin/marketing.php`
- `/admin/waitlist.php`
- `/admin/webhooks.php`
- `/employee.php`
- `/customer_orders.php`

Universal mobile-правило `body.account-page .section-header-nav-actions .back-to-menu-btn { padding: 9px 12px; font-size: 0.92rem }` (mobile-polish.css L187-198) жило ТОЛЬКО в mobile-polish.css. Без подключения файла страница на mobile получала desktop-стили (padding 10px 16px / font-size 16px) → button height 47 px вместо целевых 43 px.

Phase 20.1 — простое добавление `<link rel="stylesheet" href="/css/mobile-polish.css">` в `<head>` всех 10 missing страниц после `admin-menu-polish.css` (чтобы mobile rules перебивали desktop overrides). Изменения чисто аддитивные, нет правок CSS / markup / JS. Backend / DB / API без изменений.

Теперь `.section-header-menu` и дочерние элементы pixel-identical на **14 account-page страницах** на всех viewports (desktop 1280, tablet 768, mobile 375). Кросс-страничная стандартизация навигационного header'а из `account-header.php` достигнута.



## Phase 20 — `.section-header-menu` cross-page identity (v2.5.0, 2026-05-06)

Один и тот же DOM-блок из `account-header.php` (`<div class="section-header-menu">` с 2 quick-action иконками и 6 nav-кнопками) визуально отличался на `/admin/menu.php` vs `/owner.php`. MCP-замер на mobile 375 показал расхождение: admin button height 47px vs owner 43px, padding 10px 14px vs 9px 14px, font-size 16px vs 14.72px (0.92rem).

Root cause — `css/admin-menu-polish.css` содержал **два** конфликтующих override-блока на `body.employee-page.admin-menu-page` для shared селекторов `.section-header-menu` / `.section-header-quick-actions` / `.section-header-nav-actions` / `.back-to-menu-btn`:

- **Block A** (L779-806): `min-height: 38px`, `gap: 8px`, muted background, hover/focus переопределения. Полностью dead-code — beaten by Block B в том же файле.
- **Block B** (L2861-2869): `min-height: 40px`, `gap: 8px`, `min-height: 72px на .section-header-menu`, RED background (`var(--primary-color)`) на `.back-to-menu-btn`. Побеждал universal `body.account-page` rules по specificity (`0,0,4,1` vs `0,0,2,1`).

RED-цвет на проде НЕ применялся (его перебивал `ui-ux-polish.css` L1222 по source order — он грузится позже), но `gap: 8px` и `min-height: 40px` побеждали и создавали реальное расхождение.

Phase 20 удалил **оба** блока (~30 строк CSS). Canonical owner теперь — universal `body.account-page` rules в `ui-ux-polish.css` + `account-styles.min.css` + `mobile-polish.css`. Admin-only overrides на truly admin-specific селекторы (`.admin-tabs-container`, `.admin-tabs`, `.admin-tab-btn`, `.admin-form-group`, `.admin-section-card`, `.admin-pane-*`, `.account-form-container`) сохранены — это не shared blocks.

Визуальный эффект — admin-page header-bar высота вырастает с 40 до 44 px (desktop) / 43 до ~44 px (mobile) для byte-identity с `/owner.php`. Visual baselines обновлены через `npm run visual:update`. Backend / DB / API / markup без изменений.



## Phase 19.2 — /help.php desktop dock specificity bump (v2.4.2, 2026-05-06)

Phase 19.1 добавил `!important` ко всем декларациям `position: sticky` на desktop, но MCP-verify показал что dock всё равно `position: static`. Причина — cascade с одинаковой specificity и обоими `!important` тёрся source order'ом, а `ui-ux-polish.css` грузится ПОСЛЕ `help-page.css` (вытягивается через header.php-цепочку), поэтому его `body.account-page .menu-tabs-container { position: static !important }` (specificity 0,0,2,0, !important) побеждал наш `body.help-page .help-tabs-dock { position: sticky !important }` (та же 0,0,2,0).

Фикс — bump selector specificity: `.help-tabs-dock` → `.menu-tabs-container.help-tabs-dock` (две класса на одном элементе → 0,0,3,0). 0,0,3,0 беспроигрышно побеждает 0,0,2,0 независимо от source order. Селекторы переписаны в `@media (min-width: 1025px)` блоке для outer wrapper и inner `.menu-tabs`.

## Phase 19.1 — /help.php desktop dock не sticky'ил (v2.4.1, 2026-05-06)

MCP-verify Phase 19 на desktop 1280 показал: `.help-tabs-dock` рендерился как `position: static`, не как `sticky`. Причина — в `ui-ux-polish.css` есть unconditional baseline `body.account-page .menu-tabs-container { position: static !important }` (применяется на всех viewport'ах кроме mobile, где другая `@media (max-width: 1024px)` перебивает на `fixed !important`). Мой Phase 19 `position: sticky` без `!important` проигрывал.

Фикс: каждая декларация в desktop-блоке `@media (min-width: 1025px) body.help-page .help-tabs-dock { … }` теперь несёт `!important` (position, bottom, margin, max-width, background, border, box-shadow, padding, z-index, overflow). Внутренний `.menu-tabs` получил `!important`-ресеты для transparent-фон/0-border, чтобы не унаследовать mobile-style белой полосы. Mobile-путь не тронут и продолжает работать через существующие `body.account-page` правила.

## Phase 19 — /help.php UX overhaul (v2.4.0, 2026-05-05)

`/help.php` was a single long scroll of 6 sections × ~6 cards = ~36 instructional cards, with a top `.menu-tabs` strip used as a TOC. Pain points: navigating from the bottom back to a section meant scrolling 4-5 screens up on mobile; the active tab was hardcoded on `#staff-helper` and never updated; finding a specific tip required visual scanning; and all 6 sections were visible to every role (employee saw owner-billing and admin-helper for no reason).

Five-part overhaul, single atomic commit:

1. **Bottom-docked TOC**: `<div class="menu-tabs">` was moved from the hero into a new `<nav class="menu-tabs-container help-tabs-dock">` placed after the last `<section>`. On `≤1024px` it picks up the existing fixed-bottom rules from `body.account-page .menu-tabs-container` in `ui-ux-polish.css` (Phase 9.2). On desktop it becomes a `position: sticky; bottom: 12px` floating bar with backdrop blur.
2. **Scroll-spy via `IntersectionObserver`**: new `js/help-page.js` watches every `<section id>` (rootMargin `-30% 0 -60% 0`) and moves `.tab-btn.active` to whichever section is currently in the viewer's reading band. Emits `CustomEvent('helpactivetabchange')` so `js/mobile-tabs-scroll.js` re-centres the active tab in the horizontal-scroll lane on mobile.
3. **Live text-filter** in the hero: `<input id="helpFilter">` filters `<li>` instructions by substring (Cyrillic-normalized: `ё→е`, debounced 120ms). Cards/sections with no visible items hide via the `[hidden]` attribute. Heading matches (`<h3>` / `<h2>`) re-show all children. `Escape` / × button clear; empty-state status uses `role="status"` + `aria-live="polite"`.
4. **Role-based section visibility**: `$sectionsByRole` map at the top of `help.php`:
   - `employee` → `staff-helper`, `menu-presentation` (2 tabs)
   - `admin` → `staff-helper`, `admin-helper`, `operations-helper`, `menu-presentation` (4 tabs)
   - `owner` → all 6 sections.
   Each `<section id>` is wrapped in `<?php if (isset($visibleSections[id])): ?>`. Tabs render from `foreach ($visibleSections)` so the dock auto-syncs.
5. **Smooth-scroll + offset**: `body.help-page { scroll-behavior: smooth }` (suppressed under `prefers-reduced-motion: reduce`); `section[id] { scroll-margin-top: 24px }` so anchor jumps don't bury the heading at the viewport edge.

New files: `js/help-page.js`, `css/help-page.css`. No backend / DB / API changes.

## Phase 18.2 — help.php inline URL break-all (v2.3.2, 2026-05-05)

MCP verify после Phase 18.1 показал: `body.scrollWidth = 454` при `viewport = 360` (clipped через `body { overflow-x: hidden }`, поэтому визуально не торчит — но логически блок всё ещё «выходит за границы экрана»).

Root cause: внутри `<li>` есть inline-ссылки вроде `<a href="/owner.php?tab=billing">/owner.php?tab=billing</a>`. Текст ссылки — один атомарный токен без пробелов; ни `word-break: normal`, ни default `overflow-wrap` его не разрывают. Min-content такого `<li>` ≈ 337px. Поскольку `.account-container` — `display: grid` с auto-track, эта 337px-ширина каскадирует через `<ol>` (337+22=359) → `.admin-form-container` (359+28=388) → `.account-section` (388+36=425) → grid-track контейнера тоже становится 425px. **Все 7 sections на странице** наследуют эту ширину, не только тот, где живёт URL.

Фикс — universal на body.help-page mobile (max-width: 768px):

- `<li>` → `overflow-wrap: anywhere; word-break: break-word;`
- `<a>` / `<code>` внутри `<li>`, `<p>` → `word-break: break-all; overflow-wrap: anywhere;`
- `<p>` → тот же `overflow-wrap: anywhere`.

Теперь URL рендерятся с переносом по любому символу — min-content sections падает в нормальный диапазон, grid-track сжимается, `body.scrollWidth ≤ viewport`.

## Phase 18.1 — help.php nested overflow + контент-актуализация (v2.3.1, 2026-05-05)

Follow-up по Phase 18: на `/help.php` mobile 360px блок `<section id="staff-helper">` всё ещё уходил за правый край viewport. Корень — Phase 18 покрыл `.account-section` и `.account-container`, но **не** трогал `.admin-form-container` (sub-card внутри section). У этого контейнера остался desktop-padding/max-width, плюс `<ol>/<ul>` рендерились с дефолтным `padding-left: 40px` — комбинация выжимала текст за viewport на 360px.

Фикс в `css/mobile-polish.css` (universal на 6 страниц body-классов):

- `.account-section .admin-form-container` → `box-sizing: border-box; max-width: 100%; padding: 14px; margin: 12px 0 0` (first-of-type → margin-top 0).
- `body.help-page .account-section ol/ul` → `padding-left: 22px` (вместо 40px), `<li>` → `margin-bottom: 8px; line-height: 1.4`.
- `<h2>/<h3>` → `overflow-wrap: anywhere` для длинных слов.

Параллельно — content actualization `/help.php`:

- Устаревшая ссылка `/owner.php?tab=brand` (Phase 17 удалил эту вкладку) → `/admin/menu.php?tab=design`.
- Добавлено описание Phase 17 plate-UX: 5 модальных редакторов (🏷️Бренд / Aa Шрифты / ■Цвета / 📁Файлы / 🚀Launch), 3 sub-tabs Brand modal (Identity / Контакты / Домен), 4 color presets (Классический / Тёмный / Свежий / Свой) + collapsed advanced на 9 переменных.

## Phase 18 — mobile pass для аккаунт-страниц (v2.3.0, 2026-05-05)

MCP audit на 360px viewport обнаружил серьёзный overflow на `/account.php`, `/owner.php`, `/help.php` и `/admin/staff.php` — содержимое выходило за viewport, скрыто из-за `body { overflow-x: hidden }`. Решение — единый `css/mobile-polish.css` с `@media (max-width: 768px)`:

- Принудительный `box-sizing: border-box; max-width: 100vw` на `.account-section` / `.account-container` (контент не вылезает).
- `.menu-tabs` → horizontal-scroll lane с `mask-image` fade-edge gradient вместо переноса; scroll-snap.
- `staff-shifts-table` и `staff-swap-table` (7 колонок) → стек карточек: `display: block` на table/tbody/tr/td, лейблы через `:nth-child::before`. Inputs full-width.
- `section-header-nav-actions` → 2-col grid.
- `staff-filter`, `staff-tips-form`, `owner-reports/period` grids → 1-col flex.

Новый `js/mobile-tabs-scroll.js` auto-centres active tab on load для длинных tab-strips (Owner 6 / Help 6 tabs).

Подключено в 4 страницах через `<link>`+`<script>`. Без правок в backend / БД.
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current implementation notes:
  - Provider and tenant public UX are now clearly split.
  - Critical visible icon-font leakage was removed from key public and account-facing surfaces.
  - Order-card metadata compression is implemented in the main customer and employee views.
  - Shared help and operational shell improvements are live and now extend through the common shell contract.
  - Shared stale-order lifecycle badges, thresholds, and cleanup actions now exist in customer, employee, and owner-facing operational views.
  - The final shared polish layer now covers provider and tenant shell density, public menu rails, help/QR/cart rhythm, and a deterministic visual release gate.
  - The current shell contract uses a persistent edge-to-edge bottom-docked tab rail for menu and internal navigation surfaces: full-width, no rail rounding, centered on desktop, and horizontally scrollable on tablet/mobile. Owner analytics keep a distinct report-toolbar treatment with clearer hierarchy between report tabs and period controls.
  - Narrow-screen account headers no longer force quick actions into a horizontal scroller when that harms readability; the shell now allows them to settle into the normal vertical rhythm.
  - Menu catalog pages now use a tighter closing rhythm so the last cards transition into footer copy without an oversized dead zone above the bottom dock.
  - In `admin-menu.php`, the desktop catalog actions column now uses a dedicated intermediate-width layout from `769px` to `978px`, turning action links into a centered vertical stack instead of a cramped inline pair.
  - The modifiers editor on `admin-menu.php?edit=*` now uses the same CSRF token fallback chain as the other admin JS modules, so edit-mode API calls no longer depend on a page-level meta tag being present.
  - The `admin-modifiers.js` asset is now filemtime-versioned from `admin-menu.php`, so deploys invalidate stale immutable browser cache for the edit-mode modifiers UI.
  - The `index.php` first screen (provider and tenant hero) is rebuilt around a calmer, breathier layout: the translucent card, eyebrow label, and static provider quick-points are removed, H1/subtitle/CTAs fade up with a short stagger, and the background uses a slow ken-burns loop. Motion is fully suppressed under `prefers-reduced-motion: reduce`. All new rules live in a scoped `css/index-hero.css` so existing hero consumers and later landing sections are untouched. Broken `HDR_1024`/`HDR_1440` picture sources were also dropped to eliminate desktop 404s after the earlier asset cleanup.
  - All eight Phase 6-10 admin pages (`admin-{kitchen,inventory,loyalty,locations,marketing,staff,webhooks,waitlist}.php`) now load `js/app.min.js` in their tail script block. Previously only `admin-menu.php` (Phase 1-5) loaded it; the new pages shipped without it, so the mobile burger button (`.mobile-menu-btn` toggle in `app.min.js`) was inert on every Phase 6-10 admin surface — clicking burger did nothing on mobile, no nav menu opened. Fixed by adding `<script src="/js/app.min.js?v=…" defer nonce>` next to the existing `security.min.js` include on all eight pages.
  - Global admin-table overflow guard added in `css/admin-menu-polish.css`. On `body.admin-page .account-section > table` (and the inv/routing wrappers) at `≤768px`, the table becomes `display: block; overflow-x: auto` so its row scrolls horizontally inside the card instead of forcing the card to the table's full width. Symptom before fix: `.staff-shifts-table` measured 744px wide in a 318px viewport → `.account-section` widened → `.account-container` widened → the page rendered as a 40%-of-viewport column with a 60% white margin on the right (visible on `/admin-staff.php` mobile, audit-screens/60). Same pattern existed in inventory / loyalty / kitchen / marketing tables; the global rule covers all of them at once. `.staff-filter` / `.staff-tips-form` / `.waitlist-filter` / `.webhook-form .form-row` get `flex-wrap: wrap` for the same reason.
  - `auto-colors.php` now also emits `--{key}-rgb` triplets (e.g. `--primary-color-rgb: 205, 23, 25`) so stylesheets can write `rgba(var(--primary-color-rgb), 0.06)` instead of hardcoded `rgba(205, 23, 25, 0.06)` literals. Migrated all 30+ such literals across `account-styles.min.css`, `admin-menu-polish.css`, `employee-triage.css`, `fa-styles.min.css`, `index-hero.css`, `index-landing.css`, `menu-discovery.css`, `monitor.css`, `order-track.css`, `owner-styles.min.css`, `ui-ux-polish.css`. Tenant brand-color override now also recolors translucent washes / focus-rings / gradient overlays — previously locked to the `#cd1719` default regardless of tenant config.
  - `/owner.php?tab=analytics-v2` no longer logs 189 console CSP violations on every render. The cohort retention table and the hour×weekday heatmap previously generated cells with inline `style="background:hsl(...)"` from JS — strict `style-src 'self' 'nonce-…'` blocks every JS-set inline style (`element.style.X = …`, including via `setProperty`), so the heat encoding was silently dropped and every cell looked identical. Now `js/owner-analytics-v2.js` discretizes the 0..1 fill ratio into 6 buckets and writes `data-heat="N"`; the matching `[data-heat="N"]` selectors live in `css/owner-analytics-v2.css` and reproduce the original HSL ramp at fixed stops. CSP-clean, visually identical to the intended gradient (within bucket resolution), and now actually reads as a heatmap instead of a uniform table.
  - `/account.php` 2FA wizard moved from the Profile tab to the Security tab. Was a logical mismatch — Profile carried the loyalty card *and* the 2FA setup, while Security only had the password change form, so users looking to enable 2FA didn't find it there. Loyalty card stays on Profile (it's user-state, not security setup).
  - `/account.php?tab=updates` "Что нового" box no longer shows residual `\r\n` literals at the end of the changelog. The renderer normalizes both real and escape-sequence newlines (`\r\n`, `\n`, `\r`, and the literal-text variants `\\r\\n` / `\\n`) to single `\n`, then runs `nl2br(htmlspecialchars(...))`. Source `version.json` `changelog` field is treated as plain text from now on; if multi-line copy is needed it can use real newlines or literal escapes interchangeably.
  - `/kds/index.php` empty state (no kitchen stations created yet) now shows a clear "Создать первую станцию" primary CTA linking directly to `/admin-kitchen.php` for admin/owner roles, and a hint for employees telling them to ask the admin. Previously the only path was the inline link `/admin-menu.php?tab=stations` which 404'd because that route never existed — kitchen stations are managed at `/admin-kitchen.php` (separate page since Phase 6.1).
  - All hardcoded `font-family: proxima-nova` and `font-family: Inter` literals are migrated to `var(--font-text)` / `var(--font-heading)` across `fa-styles.min.css`, `account-styles.min.css`, `menu-content-info.min.css`, `menu-alt.min.css`. Tenant brand-font override via `auto-fonts.php` now propagates to every shell surface; previously these literals locked in the default fonts even when the tenant configured custom faces.
  - `/owner.php` workspace header CTAs now reflect proper visual hierarchy: "Открыть заказы" is the filled-primary CTA, "Закрыть просроченные" becomes an outline-secondary in brand color (filled on hover; muted-grey when disabled = no stale orders). Was both filled, with the destructive cleanup action visually competing with the primary navigate action.
  - `/admin-kitchen.php` routing matrix now shows a dashed empty-state card ("Сначала создайте станции выше — затем здесь появится матрица маршрутизации блюд") instead of a 1-column table with 700px of dead space to the right when no stations exist yet. Once the first station is created the table appears with proper columns.
  - `/admin-loyalty.php` "Промо-коды" section shows an empty-state copy block ("Промо-кодов ещё нет. Заполните строку ниже, чтобы создать первый.") above the table when there are no codes — and the table has a `.loyalty-promos-table--new-only` modifier that softens the header opacity, so the lone editable row reads as the intended new-row form rather than as an existing promo waiting to be edited.
  - Tablet (769–991px) homepage cards now stay in a 2-column grid instead of collapsing to 1-column at the 992px breakpoint. The fall to 1-column moved into a separate `(max-width: 768px)` media block so real mobile is unaffected.
  - The provider/tenant homepage no longer 404s on `/images/HDR1_1440.webp` — that file was never delivered with the asset pack, only `HDR1_320/640/1024.webp` exist. All references now point to `HDR1_1024.webp` (largest available); the broken-image pink rectangle in the "О сервисе" / hero region disappears.
  - The cog/chart "quick actions" icons at the top of `/account.php`, `/owner.php` etc. now have 24px top padding (`.account-header-bar`) instead of crashing into the site header. Both icon `<a>` elements also carry a `title` attribute mirroring the `aria-label` so desktop hover surfaces the action name.
  - The 2FA card on `/account.php` no longer leaves ~200px of dead space below it. Root cause was `body.customer_orders-page .account-section { margin-bottom: 100px }` in `admin-menu-polish.css` — historical bottom-dock compensation that compounded with the next section's `margin-top: 25px` to ~125px between every consecutive `.account-section` (and ~200px on the 2FA card because `padding-bottom: 28px` stacked too). After Phase 9.2 the dock flows inline on desktop and `--bottom-tab-rail-clearance` handles mobile safe-area, so the 100px is reduced to a normal 24px inter-section gap.
  - The bottom-dock tab rail (Профиль/Безопасность/Меню/Обновления on `/account.php`, similar on `/owner.php` / `/customer_orders.php` / `/employee.php` / `/menu-catalog`) is now `position: fixed; bottom: 0` only at `(max-width: 1024px)`. On desktop (≥1025px) it falls through to the static-position rule already present (~line 170 of `ui-ux-polish.css`) and flows inline with the page content. Before, the rail floated over the profile form on `/account.php` and over the analytics card on `/owner.php`. The matching `--bottom-tab-rail-clearance` paddings on `.account-container` / `.account-section:last-child` / `body.menu-catalog-page #menu` / `.footer` and the `scroll-margin-bottom` on form CTAs are also wrapped in the same media query, so desktop doesn't carry 88px of dead space. Mobile/tablet behavior unchanged.
  - `/menu.php` no longer emits `Cache-Control: public, max-age=600, s-maxage=600`. The page depends on session state (cart count, user role, auto-fonts cookie), so a public cache stored 10-minute responses keyed without `PHPSESSID` — cookieless first-hits (cron-smoke / external monitors) raced `session_init.php` and got a 500, then nginx happily served that cached 500 to everyone, including authenticated browsers, until the cache TTL expired. `session_init.php` already emits `no-store, no-cache, must-revalidate` which is the correct posture for any session-bearing surface; the override on menu.php is removed. Real users are unaffected (their browsers were holding a cookied 200 already); cron-smoke `provider_tenant_regression` now stays green.
  - The "Ещё ▾" toggle initially looked dead even after the dropdown stayed closed — clicks did nothing. Root cause: `header-more.js` listened at `document` level, but some upstream global click handler (mobile burger / app.min.js) calls `stopPropagation()` so the document listener never saw the toggle event. Fixed by binding the toggle handler directly to the `.nav-more-toggle` button; outside-click + Escape stay on `document` but use the **capture** phase to win against the same upstream stoppers. Same commit also drops `nonce=""` from the script tag when `$GLOBALS['scriptNonce']` is empty (`isset()` → `!empty()`) so browsers don't downgrade-block it.
  - The shared `header.php` no longer wraps to a second row on desktop. After Phase 7 added reservation, group ordering, and a language picker, eight nav items × `margin: 0 50px` exceeded the desktop padding box and `flex-wrap: wrap` pushed half the items to a second line. The secondary items (reservation, group, language picker) now collapse into a single `<li class="nav-more">` whose `.nav-more-menu` is an absolute-positioned dropdown above 1251px and `display: contents` below it (so the existing burger menu still renders all items in its flat column with no DOM duplication). Toggle logic lives in `js/header-more.js` (external, defer, CSP-nonced); click-outside and Escape close the menu, `aria-expanded` reflects state. Dropdown chrome runs entirely on existing tokens (`--ui-surface`, `--ui-border`, `--shell-radius-soft`, `--shell-shadow-soft`); `lang-picker.css` is migrated off hardcoded `rgba(15,23,42,…)` to `--ui-surface-muted` / `--ui-border` / `--ui-text` for the same reason. New `nav.more` translation key added to `locales/{ru,en,kk}.json`. **Specificity gotcha caught in production audit:** the initial drop landed `.nav-more-menu { display: none }` at specificity 0,1,0 — but `.nav ul { display: flex }` higher up in the same file is 0,1,1 and won the cascade, so the dropdown rendered open by default. Every `.nav-more*` rule is now `.nav`-prefixed (0,2,0) and `flex-direction: column` is pinned on the dropdown explicitly.
  - All inline `style="width:XXpx"` on table inputs across the seven Phase 6/8 admin surfaces (`admin-inventory.php`, `admin-loyalty.php`, `admin-locations.php`, `admin-kitchen.php`, `admin-staff.php`, `admin-menu.php` recipe modal, `admin-marketing.php` segment threshold) — plus a one straggler in `owner.php` analytics heatmap days input found during the post-deploy audit — are replaced with `data-w="3xs|2xs|xs|sm|md|lg|xl"` attributes. The matching attribute selectors (`[data-w="…"] { width: … }`) live in `admin-menu-polish.css`, which is already loaded on every admin page. Rationale: even though strict CSP only blocks `<style>` blocks (not `style=""` attributes), every inline style is drift from the Phase 1.2 baseline and an obstacle to a future `style-src-attr 'none'` tightening. Visual width unchanged; the attribute approach was chosen over class merging because the latter required per-input regex that bled width into parent `<td>` elements on the first attempt.
  - Thirteen Phase 6–8 page-specific stylesheets (`kds.css`, `admin-kitchen/inventory/loyalty/locations/marketing/staff/webhooks/waitlist.css`, `loyalty-card.css`, `group-order.css`, `reviews.css`, `owner-analytics-v2.css`) are migrated off hardcoded hex/rgb to the existing design tokens (`--ui-surface`, `--ui-surface-muted`, `--ui-border`, `--ui-text`, `--ui-text-muted`, `--ui-accent`, `--ui-success`, `--ui-danger`, `--primary-color`, `--accent-color`). Net hardcoded color count across the thirteen files dropped from 248+ to ~83 — and most of the remainder is the KDS dark-theme token defaults consolidated into a single `:root`-equivalent block on `body.kds-page` (`--kds-bg`, `--kds-surface`, `--kds-accent`, etc.), so a future tenant-driven KDS recolor is one block of overrides instead of 41 scattered values. Other intentional literals: status pills in `admin-marketing.css` (queued/sending/sent/failed conventions), star color and Google CTA in `reviews.css`, soft amber low-stock banner in `admin-inventory.css`, success-tinted submit panel in `group-order.css`, save-state row tints in `admin-kitchen.css` — each annotated in-file. White-label brand changes now propagate to KDS, loyalty card, webhook delivery state, analytics cards, and review submit button without touching markup.
  - A Playwright visual-regression suite (`tests/visual/`) is wired in as the deterministic gate for all of the above. Three viewport projects (`desktop-1920`, `tablet-768`, `mobile-375`) exercise the same set of routes against a live tenant — owner login is performed once via `global.setup.ts` and the authenticated `storageState` is reused across every spec. Coverage: header layout (single-row desktop, "Ещё" dropdown open/close, mobile burger fallback), full-page snapshots of all 13 Phase 6-8 surfaces (`admin-kitchen/inventory/loyalty/locations/marketing/staff/webhooks/waitlist.php`, `kds.php`, `owner.php?tab=analytics-v2`, `account.php?tab=loyalty|security`, `group.php`), plus a CSP-violation guard that fails if any route logs a `Content Security Policy` console error. 55 tests total, run via `npm run visual` (headless), `npm run visual:headed` (browser visible), `npm run visual:update` (regenerate baselines after intentional change). Credentials live in `.env.local` (gitignored); see `tests/visual/README.md` for setup.
  - The hardcoded `#121212` "secondary" hex literal (and matching `rgba(18,18,18,…)`) is migrated to `var(--secondary-color)` / `rgba(var(--secondary-color-rgb), …)` across `employee-triage.css`, `owner-styles.min.css`, `index-landing.css`, `order-track.css` (~55 occurrences total). These were the largest remaining brand-bypass hot-spots after the Phase 8 token sweep — employee shell, owner page, homepage marketing, and the customer order-track timeline now respect the tenant's secondary-color override (formerly locked to `#121212`). Status-greens/reds and decorative neutral greys are intentionally left as-is; they are not brand-recolorable. `monitor.css` and `opcache-status.css` were checked and had no `#121212` to migrate.
  - `.githooks/pre-push` now runs the visual-regression suite (`npm run visual`) automatically on any push to `main` or `release/*`. Failures dump the last 20 lines of the run log and block the push with hints (`npm run visual:report`, `npm run visual:update`, `CLEANMENU_NO_VISUAL=1` escape hatch). Skips gracefully when Playwright is not installed locally, when `.env.local` is missing, when `npx` is not on PATH, or when chromium is not yet installed (`npx playwright install chromium`). Contributors without a local Playwright setup are not blocked; the gate engages exactly when the local environment is set up to run it.
  - The header burger toggle (`.mobile-menu-btn` in `header.php`) is now a real `<button type="button">` with `aria-label`, `aria-expanded`, and `aria-controls="primary-nav"`; the `<nav>` got a matching `id`. Previously it was a non-focusable `<div>`, so keyboard users could not open the mobile menu at all. The handler in `js/app.min.js` now syncs `aria-expanded` on toggle, closes the menu on `Escape`, and returns focus to the toggle. The button gets a `:focus-visible` ring (`outline 2px var(--ui-accent)`). New translation key `nav.toggle_menu` added to `locales/{ru,en,kk}.json`.
  - The header "Ещё ▾" dropdown gets full keyboard navigation in `js/header-more.js`. `ArrowDown` on the toggle opens the menu and focuses the first item; `ArrowUp` opens and focuses the last item. While open: `ArrowDown`/`ArrowUp` step through items, `Home`/`End` jump to first/last, `Escape` closes and returns focus to the toggle. Disabled items (any `[disabled]`) are skipped automatically. Existing click + outside-click + aria-expanded behavior is unchanged.
  - New `js/focus-trap.js` helper (~80 LoC) exposes `window.FocusTrap.activate(modal, {onEscape})` / `.deactivate(modal)` / `.isActive(modal)`. On activation it stores `document.activeElement`, focuses the first visible focusable inside the modal, listens for `Tab`/`Shift+Tab` and wraps focus around the first/last items so keyboard users cannot escape the dialog. Optional `onEscape` callback is fired on `Escape` (consumer typically hides the modal then calls deactivate). On deactivate it removes its listener and restores focus to the original element. Per-modal state lives in a `WeakMap` so multiple modals can coexist and repeated activate/deactivate cycles never leak. Strict CSP-friendly (no inline JS, no eval). The helper is now loaded once via `header.php` so any page that includes the shared header gets `window.FocusTrap` automatically — no per-page script include needed.
  - `payLinkModal` (employee.php), the dynamically-built modifier modal (`js/menu-modifiers.js` `buildModal`/`openModal`/`closeModal`), and the menu composition modal (`js/app.min.js` `showCompositionModal`/`closeCompositionModal`) are all wired through `window.FocusTrap`. Each modal markup gets `role="dialog" aria-modal="true" aria-labelledby` pointing at the title heading.
  - The cart's `deliveryModal` and `guestOrderModal` show/hide handlers in `js/cart.min.js` (`showGuestModal`/`hideGuestModal` plus the inline `g()` close + `e.style.display = "flex"` open inside the DOMContentLoaded init) now activate/deactivate the same `window.FocusTrap`. `cart.php` markup gets `role="dialog" aria-modal="true" aria-labelledby` and the headings get `id` attributes so screen readers announce the dialog purpose. All five modals across the app (payLink, modifier, composition, delivery, guest order) are now keyboard-accessible: Tab is wrapped inside the dialog, Escape closes, focus returns to the trigger on close.
  - New `css/a11y.css` (loaded once via `header.php`) adds the missing accessibility baseline that wasn't centrally defined before: a token-based `:focus-visible` ring on every interactive element (anchors, buttons, inputs, selects, textareas, `[role="button"]`, `[tabindex]`) using `--ui-focus-ring` and `--ui-focus-shadow`; `.sr-only` utility for screen-reader-only labels; `.skip-link` helper that becomes visible on focus so keyboard users can jump past the header; opt-in `prefers-reduced-motion` suppression via `[data-motion-respect]`; and a focus boundary on `[role="dialog"]` for the focus-trap fallback path. Default token contrast verified against WCAG AA: `--ui-text` (`#1f2328`) on `--ui-surface` (`#fff`) = ~14.6:1 (AAA); `--ui-text-muted` (`#5f6368`) on `--ui-surface` = ~4.86:1 (AA body); `--ui-text-muted` on `--ui-surface-muted` (`#f5f5f5`) = ~4.55:1 (AA body just-passes). Tenant-customised brand colours are not auto-audited and remain the operator's responsibility.

## Goal

Improve quality, clarity, and conversion without breaking the ordering engine or mixing provider marketing with tenant public UX.

## Fixed Product Decisions

- `menu.php` is the primary transactional menu and ordering surface in both deployment modes.
- On provider domains, `index.php` is the B2B marketing and demo entry point.
- On tenant domains, `index.php` is optional and must be restaurant-facing if used.
- Provider marketing content must never appear on tenant domains.
- `menu.php` must not become a provider landing page.
- Tenant public UX must feel like a real restaurant product, not a provider demo.

## What Is Already Implemented

### Public split

- provider `/` is a B2B landing
- tenant `/` can render a restaurant-facing homepage
- tenant `/menu.php` stays the transactional menu

### Public and account cleanup

- critical cart/header icon leakage is fixed in the main public flow
- customer and employee order cards now keep long details in expanded sections instead of the first visible row
- tenant public pages no longer mirror provider B2B catalog content

### Internal page improvements

- major layout regressions on `admin-menu.php`, `owner.php`, `employee.php`, `cart.php`, and `qr-print.php` have been reduced
- scroll retention on `admin-menu.php` interactions is implemented
- `help.php` now provides a shared role helper and product walkthrough surface
- shared shell polish and desktop/mobile visual sign-off now protect provider and tenant account/admin flows from overlap and fixed-rail regressions
- the owner report toolbar now separates report switching from period filtering with a stronger card hierarchy and cleaner spacing
- the bottom tab rail now uses one shared geometry contract across provider/tenant menu, account, owner, employee, and admin-menu surfaces instead of page-specific fixed-bottom overrides
- `admin-menu.php` now also keeps one shared inline width for the section header actions, top admin tabs, and main editor/catalog cards, so the working column stays aligned on desktop, tablet, and mobile
- in the `dishes` tab, the operator flow now shows the catalog surface before the update/editor surface, matching the primary browse-then-edit workflow

## What Is Still Open

### 1. Legacy non-critical icon debt

- critical visible cases are fixed
- legacy Font Awesome usage still exists in some non-critical parts of the codebase and should not silently grow again

## Validation Metrics

Customer-facing:

- public entry -> `menu.php` click-through
- `menu_view -> add_to_cart`
- `add_to_cart -> order_create_success`
- repeat-order usage

Staff/owner-facing:

- time to first action on new order
- clicks to common admin tasks
- time to find key owner insight

## Non-Regression Rules

- do not worsen p95 or perceived performance
- do not hide critical actions behind extra taps
- do not add heavy visual complexity to staff flows
- do not break the familiar checkout path
- do not ship provider marketing into tenant public pages
- do not turn staged improvements into a big-bang redesign
