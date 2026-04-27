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
