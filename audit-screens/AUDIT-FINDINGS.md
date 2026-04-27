# Visual Audit Findings — 2026-04-27

Live audit driven by Playwright MCP against `https://menu.labus.pro` after
the Phase 8.1-8.4 deploy (commits `aa45b4e`, `2e1b164`, `11b5fcc`,
`a3320f0`, plus hot-fix `5697e19`). Owner login as
`fruslanj@gmail.com`.

Artifacts: 20 PNG screenshots in this folder, named
`NN-<surface>-<viewport>.png`.

## Summary

| | Count |
|---|---|
| Pages walked (desktop 1920) | 14 |
| Pages walked (mobile 375) | 5 |
| Pages walked (tablet 768) | 1 |
| **Critical regressions introduced by Phase 8** | **4 (all fixed)** — see A1-A4 |
| Pre-existing UX issues observed | 9 — see B1-B9 |
| Pre-existing functional issues observed | 1 (menu.php 500 cookieless) — see C1 |
| Phase 8 features end-to-end verified | 5 (header layout, KDS dark theme, admin token migration, inline-style cleanup, mobile burger) + dropdown toggle/ESC/click-outside |

### Final commit chain
| Commit | What |
|---|---|
| `aa45b4e` | Phase 8.1 — header dropdown + nav margin reduction |
| `2e1b164` | Phase 8.2 — inline style → data-w attributes (CSP) |
| `11b5fcc` | Phase 8.3 — 13 CSS files migrated to design tokens |
| `a3320f0` | Phase 8.4 — Playwright visual regression suite (55 tests) |
| `5697e19` | Hot-fix A1 — `.nav` specificity boost |
| `867104a` | Version bump 1.4.0 → 1.4.1 (cache-bust) |
| `e165076` | Hot-fix A2+A3+A4 — toggle binding + nonce + owner.php; bump 1.4.2 |

---

## Critical regressions introduced by Phase 8 — ALL FIXED

### A1. `.nav-more` dropdown rendered OPEN by default on desktop ⚠️ FIXED
**Hot-fix:** `5697e19` — `.nav` prefix on every `.nav-more*` rule
(specificity 0,2,0 beats `.nav ul` at 0,1,1).
**Verification:** audit-screens/21 — single-row header, dropdown closed.

### A2. Toggle button click did nothing — handler never fired ⚠️ FIXED
**Hot-fix:** `e165076` — bind handler directly to `.nav-more-toggle`
instead of `document`. Some upstream global click listener (mobile
burger / app.min.js) calls `stopPropagation()`, so the document-level
listener never received the event. Outside-click + Escape stay on
`document` but use the **capture** phase to win against the same
upstream stoppers.
**Verification (live, via Playwright MCP):**
- audit-screens/22 — clicking "Ещё ▾" opens a 220×162px dropdown card
  (Бронь / Общий стол / RU EN KK) with rotated caret, soft shadow,
  brand-aligned border. `is-open` class set, `aria-expanded="true"`.
- ESC: dropdown closes, focus returns to toggle button.
- Click anywhere outside (h1, body): dropdown closes.

### A3. `<script>` nonce attribute emitted as empty string ⚠️ FIXED
**Hot-fix:** `e165076` — `isset($GLOBALS['scriptNonce'])` →
`!empty(...)` so the attribute is omitted (rather than `nonce=""`)
when scriptNonce is unset. External scripts still load via
`script-src 'self'`.

### A4. Stragger inline `style="width:80px"` on owner.php analytics ⚠️ FIXED
**Hot-fix:** `e165076` — analytics heatmap days input migrated to
`data-w="sm"`. The Phase 8.2 sweep missed it because it lives
outside `admin-*.php`.

---

## Phase 8 features verified working

- ✅ **Header single row on desktop** — `.header-inner` height = 60px
  on 1920px (was ~140px before fix). One row at all desktop widths.
- ✅ **Token migration on KDS** — `kds.php` renders dark slate
  theme via scoped `--kds-*` tokens; the empty-station picker reads
  cleanly (audit-screens/12).
- ✅ **Token migration on admin pages** — admin-loyalty,
  admin-inventory, admin-locations, admin-marketing, admin-staff,
  admin-webhooks, admin-waitlist all render in tenant brand red,
  no Tailwind-default blue/green leaks. Buttons, table headers,
  inputs cohesive (audit-screens 04-11).
- ✅ **Inline-style cleanup** — `data-w="…"` attributes on table
  inputs work; widths preserved (admin-loyalty promo-code col is
  still 140px-wide, etc.).
- ✅ **Mobile burger preserves Phase 7 items** — burger nav on
  375px lists Позвонить, Меню, Заказ, Аккаунт, Ещё, **Бронь**,
  **Общий стол**, **RU**, **EN**, **KK** as flat items
  (`display:contents` dissolves the .nav-more wrapper as designed).

---

## Pre-existing UX issues (not regressions, but visible)

These were observed during the audit but predate the Phase 8 commits.
Listed for prioritization in a follow-up sprint.

### B1. Account page — 2FA card has ~200px of white space below it
audit-screens/02 — between the "Включить 2FA" CTA and the next "Профиль"
section there is a large unfilled area. Looks like a `min-height` set
on `.account-2fa-block` that never tightens after the wizard collapses.

### B2. Account page — section tabs (Профиль / Безопасность / Меню /
Обновления) float horizontally midway through the page, OVERLAPPING the
profile form below. This is the bottom-dock owner toolbar appearing in
the wrong scroll position. audit-screens/02.

### B3. Three concurrent navigation rails on owner.php
audit-screens/03 shows: top header (5 items + Ещё), two rows of
quick-action chips (Заказы / Кухня / …), top of report tabs
(Статистика / Пользователи / …), and a bottom-dock that overlaps
the analytics card content (Продажи / Прибыль / Оперативность /
Клиенты / Топ блюд / Загруженность / Официанты / Узкие места). Too
much chrome competing for attention; the bottom dock specifically
slices the analytics card visually.

### B4. Top-left "settings" + "chart" icon pair has no labels and no
top spacing — they sit ~12px from the header (audit-screens/02-11). On
admin-loyalty / inventory / locations they look orphaned.

### B5. owner.php — "Закрыть просроченные (6)" warning button sits
inline next to "Открыть заказы" CTA without visual hierarchy.
Looks like two equal-weight CTAs (audit-screens/03). The warning
should read as "alert", not as a competing primary.

### B6. admin-kitchen — routing matrix renders a 1-column table
("Блюдо" only) when no stations exist. ~700px of empty space to the
right of the dish column. Empty state copy would help (audit-screens/05).

### B7. admin-loyalty — promo-codes row reads odd: "Действия" column
is the last column with the "Создать" button, but the table is so
wide it implies a table of existing promos (when actually it's a
single empty new-row). Empty state missing (audit-screens/04).

### B8. tablet 768 — homepage cards stack 1-column same as mobile.
Could be 2-column to use the wider viewport better
(audit-screens/20).

### B9. tablet 768 — "О сервисе" image area renders as an empty pink
rectangle (image source missing). Pre-existing, not Phase 8
(audit-screens/20).

---

## Pre-existing functional issue

### C1. `/menu.php` returns HTTP 500 on **cookieless first hit**
With a PHPSESSID cookie set, `/menu.php` returns 200 + 90KB body
+ strict CSP with nonce (verified via in-browser fetch). Without
cookies (curl, monitor, smoke), it returns 500 + 1KB partial HTML
+ fallback CSP. The browser still renders the page because PHP
emits the HTML stream BEFORE the eventual 500 status — this is a
session-init race in `session_init.php`, NOT a Phase 8 regression.
Real users never see this (they always have cookies after the first
landing). External monitors / curl smoke see 500 and panic.

**Fix path (separate task):** investigate why `header()` calls in
session_init.php emit a late 500 when no cookie is present.
Probably a `set_error_handler` invocation mid-render. PHP-FPM error
log is silent because it's a controlled `http_response_code(500)`,
not a fatal.

---

## Phase 11 sweep — admin shell completeness + DB write verification

Added 2026-04-27 after the user reported `/admin-staff.php` gaps and an
inactive burger, plus a general request to validate every interactive
element with real DB-mutating click-throughs.

### Found regressions (FIXED in commit 3a28b54, v1.4.9)

**P1. `js/app.min.js` missing on 8 Phase 6-10 admin pages.**
Only `admin-menu.php` (Phase 1-5) included it. Every Phase 6-10
admin page (admin-kitchen, admin-inventory, admin-loyalty,
admin-locations, admin-marketing, admin-staff, admin-webhooks,
admin-waitlist) shipped without — so the `.mobile-menu-btn` toggle
(burger handler lives in app.min.js) was inert on every Phase 6-10
admin surface. Mobile users could not open the navigation drawer.

**Fix:** added `<script src="/js/app.min.js?v=…" defer nonce="…">`
next to the existing `security.min.js` include on all 8 pages.

**P2. `.account-section` table overflow on mobile.**
`/admin-staff.php` on 375px viewport rendered as a 40%-of-viewport
column with 60% white margin to the right. Root cause: the
`.staff-shifts-table` cumulative columns were 744px wide, forcing
`.account-section` → `.account-container` to widen. Same shape
existed in inventory / loyalty / kitchen / marketing tables.

**Fix (global guard in `css/admin-menu-polish.css`):**
At `(max-width: 768px)`, every direct-child `<table>` of
`body.admin-page .account-section` (and the `inv-table-wrapper` /
`routing-table-wrapper`) becomes `display: block; overflow-x: auto;
max-width: 100%;` so its row scrolls horizontally INSIDE the card
instead of forcing the card to the table width. `.staff-filter`,
`.staff-tips-form`, `.waitlist-filter`, `.webhook-form .form-row`
get `flex-wrap: wrap` so multi-input rows stack vertically.

### Verified live (Playwright MCP)

| Surface | Action | Result |
|---|---|---|
| /admin-staff mobile-375 | bodyWidth check | **361 ≤ 375** (was 791 → overflow gone) |
| /admin-staff mobile-375 | burger click | `.mobile-menu-btn.active` + `.nav` display:flex |
| /admin-kitchen | "Создать" station "Горячий цех/hot/sort=1" | row `data-station-id="1"` persisted in DB after page reload |
| /admin-loyalty | "Создать" tier "Bronze, 1000₽, 3%" | row `data-tier-id="1"` persisted, name "Bronze", min_spent 1000.00, cashback_pct 3.00 |
| /admin-loyalty | "Создать" promo "TEST10, 10%, 500₽ min, lim=100" | row `data-promo-id="1"` persisted with full payload |
| /admin-locations | "Создать" location "Центр / Тверская, 12 / +7964… / Europe/Moscow" | row `data-location-id="1"` persisted |
| /admin-inventory | "Создать" ingredient "Мука пшеничная, г, 5000, threshold 500, cost 0.05" | row `data-ingredient-id="1"` persisted |
| /admin-marketing | "Сохранить как черновик" "Phase 11 Test Campaign / email" | row `data-campaign-id="1"` in `mk-table` after reload |
| /admin-webhooks | "Создать" subscription `order.created → webhook.site/test-phase-11` | row in `.webhooks-table` after reload, action buttons (История / Сменить ключ / Удалить) wired |
| /admin-staff | "Начать смену" | status `На смене с 2026-04-27 20:41:59`, button flipped to "Закончить смену". "Закончить смену" → status reverted. |
| /kds.php | After station #1 creation | empty-state replaced with two selectable cards: "Горячий цех" + "Без маршрута" |

**All 7 admin DB-write surfaces produce server-side persisted rows
that survive a page reload.** Click-through audit closed.

---

## Phase 9 sweep — all 9 pre-existing issues + C1 FIXED

Added 2026-04-27 after the user requested a sequential pass through B1-B9 + C1
with re-verification at each step. Final commit chain:

| # | Commit | Track |
|---|---|---|
| 9.1 | `e0005ec` | C1.1 — drop public Cache-Control on menu.php |
| C1f | `e0a08b6` | C1.2 — defensive `require_once lib/I18n.php` in header.php (real root cause: `t()` undefined under PUBLIC_MENU mode) |
| 9.2 | `e3bc2d2` | B2 + B3 — bottom-dock tabs flow inline on desktop (≥1025px), fixed only on mobile/tablet |
| 9.3 + 9.4 | `a75045c` | B4 — `.account-header-bar` 24px top padding + admin icon `title` attr; B1 — `body.customer_orders-page .account-section { margin-bottom: 100px → 24px }` |
| 9.5 + 9.6 + 9.7 + 9.8 + 9.9 | `79ab2da` | B5 owner CTA hierarchy (filled primary + outline secondary); B6 admin-kitchen empty state; B7 admin-loyalty promo empty state; B8 tablet 768-991 keeps 2-col cards; B9 broken `HDR1_1440.webp` references → `HDR1_1024.webp` |

Live verification via Playwright MCP at 1920px (audit-screens 30-34) and 900px tablet (35):

- **30-homepage-1920-FINAL** — header single row, dropdown closed, hero clean.
- **31-account-1920-FINAL** — tabs `Профиль/Безопасность/Меню/Обновления` are inline above 2FA card (was: floating mid-page over the Profile form). 2FA → Профиль gap is now ~120px (was ~200px). Cog/chart icons have 24px top padding (was: ~12px crashing into header).
- **32-owner-1920-FINAL** — "Открыть заказы" filled-red primary, "Закрыть просроченные (6)" outline-red secondary (proper hierarchy). Bottom-dock tabs `Продажи/Прибыль/…` are inside the analytics card (was: floating across viewport bottom).
- **33-admin-kitchen-1920-FINAL** — routing matrix shows dashed empty-state copy ("Сначала создайте станции выше — затем здесь появится матрица…") instead of a 1-column table with 700px of dead space.
- **34-admin-loyalty-1920-FINAL** — promo-codes section shows empty-state copy ("Промо-кодов ещё нет. Заполните строку ниже…") above a single new-row form (was: lone editable row in a borderless table that read as existing data).
- **35-homepage-tablet-900-FINAL** — entry cards stay in 2 columns (was: collapsed to 1-column at the 992px breakpoint, wasting half the row).

Functional verification:
- `curl -sk https://menu.labus.pro/menu.php` cookieless returns **HTTP 200** after PHP-FPM restart (was: STALE 500 on every smoke). The post-merge cron-smoke that runs immediately after `git pull` may still see 500 because PHP-FPM hasn't been restarted yet — that's a deploy-ordering quirk, not a real issue. After `systemctl restart php8.1-fpm` the smoke goes green.
- All 13 Phase 8 admin surfaces still verified: HTTP 200/302 (auth-gate), no console CSP violations, no regressions.

## What I did not test

- Real ordering flow (would create test orders in prod)
- 2FA enrollment (would alter the owner account)
- Promo code creation (would alter tenant data)
- KDS with real stations (none configured on the tenant)
- Admin-marketing campaign send (would email actual users)

These are all interaction tests; the visual/structural audit is
complete. Adding them to the Playwright suite (`tests/visual/`) is
a follow-up.

---

## Next steps

1. Deploy `5697e19` to prod (`pull --ff-only` + reload php-fpm)
2. Re-run a quick screenshot pass on desktop 1920 to confirm
   dropdown is closed by default
3. Triage B1-B9 UX issues into either polish-this-sprint or
   defer-to-roadmap
4. Investigate C1 (menu.php cookieless 500) as a separate task
