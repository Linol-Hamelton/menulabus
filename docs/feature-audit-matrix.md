# Feature Audit Matrix

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - This document captures the current `repo + live` audit baseline.
  - The audit is docs-first: runtime mismatches are recorded here and in roadmaps, not fixed in this cycle.
  - No whole active doc files met deletion criteria after the audit; stale content was removed from retained docs instead.

## Audit Baseline

- Provider live target: `https://menu.labus.pro`
- Tenant live target: `https://test.milyidom.com`
- Auth-gated live audit: `account`, `help`, `admin-menu`, `owner`, `employee`, `customer_orders`, `qr-print`, `monitor`, `opcache-status`
- Repo-first audit: `api/v1/*`, OAuth routes, payment/webhook routes, SSE/long-poll, `file-manager.php`, `clear-cache.php`, `scripts/tenant/*`

## 1. Provider Public

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/` | live provider landing, title `labus \| Меню ресторана`, B2B CTA `Оставить заявку` present | `index.php`, `header.php`, `session_init.php` | `README`, `product-model`, `public-layer-guidelines`, `project-reference` | implemented |
| `/index.php` | same provider landing behavior as `/` | `index.php` | partially explicit | implemented |
| `/menu.php` | live provider demo / transactional surface with provider catalog semantics (`SEO`, `Контент`, `Пакеты`) | `menu.php`, `menu-content*.php` | `project-reference`, `public-layer-guidelines` | implemented |
| `/cart.php`, `/auth.php` | shared public adjunct pages remain reachable on provider domain | `cart.php`, `auth.php` | partial | implemented |

## 2. Tenant Public

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/` | live restaurant-facing homepage, title `DOM \| Пицца, гриль и завтраки весь день` | `index.php`, `tenant_runtime.php`, `session_init.php` | `README`, `product-model`, `public-layer-guidelines`, `tenant-demo-seed` | implemented |
| `/menu.php` | live restaurant catalog and ordering surface (`Пицца`, `Гриль`, `Завтраки`, `Десерты`) | `menu.php`, tenant seed/runtime | same as above | implemented |
| `/cart.php`, `/auth.php` | reachable and tenant-branded adjunct public pages | `cart.php`, `auth.php` | partial | implemented |
| public entry config | tenant `/` works, but per-deployment public-entry selection is still not configurable | runtime + launch docs | documented gap | partial |

## 3. Auth / Account / Backoffice

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/account.php` | live account shell, auth-gated | `account.php`, `account-header.php` | `project-reference` | implemented |
| `/help.php` | live in-app helper for `employee/admin/owner` | `help.php` | `backoffice-role-helpers`, `menu-capabilities-presentation`, `project-reference` | implemented |
| `/admin-menu.php` | live admin catalog/branding/files/payments surface | `admin-menu.php`, `js/admin-menu-page.js` | `project-reference`, `tenant-launch-checklist`, helper docs | implemented |
| `/owner.php` | live owner analytics surface | `owner.php`, `js/owner.min.js` | `project-reference`, helper docs | implemented |
| `/employee.php` | live employee queue / payment / QR flow | `employee.php`, `js/employee-*.js` | `project-reference`, helper docs | implemented |
| `/customer_orders.php` | live order-history surface | `customer_orders.php` | partial | implemented |
| `/qr-print.php` | live QR print surface | `qr-print.php` | partial | implemented |
| internal shell normalization | major regressions reduced, but shared shell contract is still not fully centralized | account/admin/owner/employee/cart/qr-print stack | `ux-ui-improvement-roadmap` | partial |

## 4. Ops / Diagnostics

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/monitor.php` | unauthenticated `302 -> auth.php`, auth-gated monitoring page | `monitor.php`, `lib/ops/monitor-page.php` | partial before audit | implemented |
| `/opcache-status.php` | unauthenticated `302 -> auth.php`, auth-gated OPcache page | `opcache-status.php`, `lib/ops/opcache-status-page.php` | documented in security docs | implemented |
| `/clear-cache.php?scope=server` | returns `405`, no public server-reset behavior | `clear-cache.php`, `lib/ops/clear-cache-endpoint.php` | partial before audit | implemented |
| `/file-manager.php?action=get_fonts` | unauthenticated `302 -> auth.php`, auth-gated JSON surface | `file-manager.php`, `lib/admin/file-manager-endpoint.php` | partial before audit | implemented |
| modularized ops/admin endpoints | root URLs remain stable while logic moved to `lib/ops/*` and `lib/admin/*` | wrappers + delegated modules | partial before audit | implemented |

## 5. API / Mobile Surface

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/api/v1/menu.php`, auth, orders, push, geocode | implemented; OpenAPI validation passes | `api/v1/*`, `docs/openapi.yaml` | `project-reference`, `api-smoke`, OpenAPI | implemented |
| `api/v1/bootstrap.php` | internal API bootstrap helper, not public contract endpoint | `api/v1/bootstrap.php` | missing before audit | implemented internal helper |
| mobile wrapper | buildable but still provider-centric (`menu.labus.pro/menu.php`) | `mobile/*`, `capacitor-wrapper.md` | current docs already mark gap | partial |

## 6. Integrations / Callbacks / Webhooks

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| Google / VK / Yandex OAuth | implemented in code, runtime-config dependent | `*oauth*.php`, `lib/OAuth*.php` | OAuth setup docs | partial |
| payment return / webhook / link generation | present in repo, repo-first audited only | `payment-*.php`, `generate-payment-link.php`, `confirm-cash-payment.php` | scattered / partial | partial |
| Telegram webhook / notifications | present in repo, repo-first audited only | `telegram-*.php` | weak explicit coverage | partial |
| SSE / long-poll | live runtime path remains `orders-sse.php` + `ws-poll.php` | `orders-sse.php`, `ws-poll.php` | `project-reference`, security/docs | implemented |

## Docs-First Findings

1. `deployment-workflow` was stale about production deploy branch strategy and needed release-branch aware commands.
2. `project-reference` under-described current ops/admin utility endpoints and integration entrypoints.
3. `ux-ui-improvement-roadmap` still treated the address/map-link track as if the model itself were open, while the real remaining gap is QA/validation.
4. `security` docs did not fully capture the current auth-gated state of `monitor.php` and `file-manager.php`.
5. `api/v1/bootstrap.php` existed in code but was undocumented as an internal helper.

## Current Backlog Produced By This Audit

- finish internal shell normalization across remaining operational pages
- define and implement stale-order cleanup as an explicit product/ops mechanic
- tighten launch automation around DNS / vhost / SSL / launch artifact generation
- document payment and Telegram integration surfaces more explicitly
