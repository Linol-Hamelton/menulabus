# SaaS Billing Engine (Phase 14 + Phase 32)

## Implementation Status

- Status: `Phase 32A live (2026-05-08): pricing v3 (Pro Trial 90d / Pro 6 990 / Pro annual / Enterprise 19 990 / Enterprise annual / Enterprise+ договорная). Phase 32B in backlog: card-required signup, addon purchases, annual billing automation, trial-end conversion cron.`
- Last reviewed: `2026-05-08`
- Provider: YooKassa (recurring via `save_payment_method` + stored `payment_method_id`)
- Plans: see Pricing matrix below

## Pricing matrix (Phase 32 v3)

| Tier | Price | Trial | Card at signup | Notes |
|---|---|---|---|---|
| **Pro Trial** | 0 ₽ × **90 days** | n/a | required (Phase 32B) | Acquisition funnel. Full Pro features for 90 days. Card not charged until day 91 |
| **Pro** | 6 990 ₽/mo | none | yes | Mainstream tier. KDS + inventory + loyalty + marketing + 54-ФЗ + group split + waitlist. Up to 3 locations / 30 staff |
| **Pro annual** | 69 900 ₽/year (2 mo free) | none | yes | 16% discount, lock-in 2026 pricing |
| **Enterprise** | 19 990 ₽/mo | none | yes | + white-label + custom domain + dev API + priority support + SLA. Up to 10 locations. **Bundled: Express onboarding + 4 h training** (~24 900 ₽ value) |
| **Enterprise annual** | 199 900 ₽/year (2 mo free) | none | yes | Annual variant of Enterprise |
| **Enterprise+ / Сеть** | договорная (от 35 000 ₽/mo) | none | sales-led | Chains 10+ locations, SSO, dedicated DB, custom SLA, dedicated success-manager. Bundled: Full onboarding + 4 h training + priority support |

See full strategy + competitor analysis + GTM playbook in [marketing-strategy-2026.md](marketing-strategy-2026.md).

## Phase 14 → 32 deltas

What changed in Phase 32 vs the original Phase 14 model:

- **Killed Starter tier (2 990 ₽)** — was a "starter trap" with severely gutted features (no KDS/inventory/loyalty/marketing). Cannibalized Pro upsells while not being competitive vs Frontpad (449 ₽) at low end.
- **Trial 14 days → 90 days** — 90-day full-Pro trial replaces single-tier short trial. Lower-friction acquisition (no price decision in first 3 months) at cost of longer commit-to-paid window.
- **Annual billing variants added** (`pro_annual`, `enterprise_annual`) — 2 months free as discount lock-in. Standard SaaS pattern; closes the cash-flow gap.
- **Enterprise self-service** — was sales-only with negotiable price; now fixed 19 990 ₽/mo with bundled Express onboarding + training. New tier `enterprise_plus` carries the договорная sales-led contract.
- **Add-on services catalog** — Express onboarding (9 900 ₽), Full onboarding (24 900 ₽), Migration from competitor (49 900 ₽), training packages, Telegram-bot setup, custom domain config. Phase 32B implements purchase API + UI.

## Overview

Phase 14 turned CleanMenu into a SaaS product taking recurring payments. Phase 32 restructured the pricing v3 (above) and added the services-revenue surface. Three flows:

1. **Self-service signup** — anyone visits `https://menu.labus.pro/signup.php`, fills brand + email + password, gets a **90-day Pro trial** tenant on `<slug>.menu.labus.pro`. (Phase 32B: card required at signup, charged on day 91 if not cancelled.)
2. **Conversion** — before trial expiry the owner adds a YooKassa card on `/owner.php?tab=billing`, picks a plan. After first successful charge the YK `payment_method_id` is saved for autocharges. (Phase 32B: trial-end cron sends day 75/85 conversion offers — annual rate 49 900 ₽ instead of 69 900 ₽.)
3. **Recurring billing** — `scripts/billing-cycle-worker.php` (cron `0 */6 * * *`) finds tenants whose `current_period_end` is approaching, creates an invoice, and charges via stored token. Soft dunning if it fails: retry day 1 / 4 / 7, then `past_due` (read-only), then `suspended` (503) at day 30.

## Data Model

All billing tables live in the **control-plane DB**, not in any tenant DB.

| Table | Role |
|---|---|
| `tenants` (extended) | new fields: `plan_id`, `subscription_status`, `trial_ends_at`, `current_period_end`, `owner_email`, `owner_user_id` |
| `subscription_invoices` | one row per billing-cycle attempt: `period_start..period_end`, `amount_kop`, `status` (`pending`/`paid`/`failed`/`refunded`/`cancelled`), `yk_payment_id`, `retry_count`, `next_retry_at` |
| `payment_methods` | saved YK token: `yk_payment_method_id`, `last4`, `brand`, expiry, `is_default` |
| `subscription_events` | audit log keyed by `tenant_id` + `event_type` (`charge_attempt`, `charge_success`, `charge_failed`, `status_changed`, `plan_changed`, `signup`, `comp`, `trial_extended`, ...) |

Migration: [`sql/billing-migration.sql`](../sql/billing-migration.sql) — applies to control-plane DB. Idempotent via INFORMATION_SCHEMA-guarded ALTERs.

## Status lifecycle

```
trial → active → past_due → suspended → cancelled
            ↓        ↑
            └────────┘
```

| Status | Meaning | Customer view | Admin view |
|---|---|---|---|
| `trial` | New signup, 14 days. Card not required yet. | full menu / orders | full admin |
| `active` | Card on file, charges succeeding. | full menu / orders | full admin |
| `past_due` | Last charge failed, retry pending. | **read-only** banner | red banner "обновите карту" |
| `suspended` | Day 30: too many failed retries. | **503** | only `/auth.php` + `/owner.php?tab=billing` work |
| `cancelled` | User clicked Cancel; runs out at `current_period_end` | full while period valid | reactivate button visible |

## Plans

Static catalog in [`lib/Billing/PlanRegistry.php`](../lib/Billing/PlanRegistry.php). Edit + ship to update prices.

| Plan | Price | Locations | Menu items | Orders/mo | Features |
|---|---|---|---|---|---|
| Trial | Free 14d | 3 | unlimited | unlimited | all (Pro-equiv) |
| Starter | 2 990 ₽/mo | 1 | 200 | 1 500 | analytics_v2, reviews, webhooks, i18n |
| Pro | 6 990 ₽/mo | 3 | unlimited | unlimited | + kds, inventory, loyalty, multi_location, marketing, group_orders, waitlist, staff_v2, fiscal_54fz, split_bill |
| Enterprise | 19 990 ₽/mo (custom) | unlimited | unlimited | unlimited | + dev_api, white_label, priority_support, sla |

## Files

| File | Role |
|---|---|
| [`sql/billing-migration.sql`](../sql/billing-migration.sql) | Schema migration. |
| [`lib/Billing/PlanRegistry.php`](../lib/Billing/PlanRegistry.php) | Plan catalog. |
| [`lib/Billing/FeatureGate.php`](../lib/Billing/FeatureGate.php) | Runtime feature checks per tenant. |
| [`lib/Billing/SubscriptionStore.php`](../lib/Billing/SubscriptionStore.php) | DAO for invoices / payment_methods / events / status. |
| [`lib/Billing/YookassaRecurring.php`](../lib/Billing/YookassaRecurring.php) | YK adapter: `createInitialPayment` (save card) + `chargeStored` (autocharge). |
| [`scripts/billing-cycle-worker.php`](../scripts/billing-cycle-worker.php) | Cron worker — creates invoices and charges due tenants. |
| [`signup.php`](../signup.php) + [`api/signup.php`](../api/signup.php) | Self-service tenant provisioning. Wraps `scripts/tenant/provision.php`. |
| [`owner.php?tab=billing`](../owner.php) → [`partials/owner_billing_section.php`](../partials/owner_billing_section.php) | Tenant-side billing page. |
| [`api/billing-action.php`](../api/billing-action.php) | Tab actions: change_plan, update_payment_method, cancel, reactivate. |
| [`provider/billing.php`](../provider/billing.php) + [`provider/tenant.php`](../provider/tenant.php) | Provider-only admin dashboards. |
| [`api/provider/tenant-action.php`](../api/provider/tenant-action.php) | Provider actions: extend_trial, force_*, comp. |
| [`require_provider_admin.php`](../require_provider_admin.php) | Middleware for /provider/* — owner role + email allowlist. |
| [`partials/billing_feature_gate.php`](../partials/billing_feature_gate.php) | Reusable paywall for admin pages. |
| [`payment-webhook.php`](../payment-webhook.php) | Extended with `metadata.kind=subscription_invoice` branch. |
| [`session_init.php`](../session_init.php) | Suspended-tenant gate (503 on customer surfaces). |

## Configuration (constants in `../config_copy.php`)

```php
define('BILLING_YK_SHOP_ID',  '...');     // YooKassa shop_id of labus.pro itself
define('BILLING_YK_SECRET_KEY', '...');   // YooKassa secret
define('BILLING_PROVIDER_ADMINS', [       // emails allowed into /provider/*
    'fruslanj@gmail.com',
]);
```

If `BILLING_YK_*` constants are not defined the adapter falls back to provider-tenant `settings.yookassa_shop_id`/`yookassa_secret_key` — that lets you reuse the provider's existing YK shop until you decide to split.

## Cron

Add to the `# >>> cleanmenu cron >>>` block in `crontab -u labus_pro_usr`:

```cron
0 */6 * * * cd /var/www/labus_pro_usr/data/www/menu.labus.pro && php scripts/billing-cycle-worker.php >> data/logs/billing-cycle-worker.log 2>&1
```

Six-hour cadence is fine — billing has no sub-day SLA. The webhook handler is the canonical state mutator; the worker only initiates charges.

## Verification (sandbox)

1. **Schema:** `mysql control_db < sql/billing-migration.sql` — re-runs cleanly.
2. **Signup:** open `https://menu.labus.pro/signup.php` (provider host) → fill form with test slug → 14-day trial tenant created, redirect to `<slug>.menu.labus.pro/auth.php`.
3. **Card:** on the new tenant, log in → `/owner.php?tab=billing` → click "Добавить карту" → YK Sandbox flow (test card 5555 5555 5555 4444) → success → return → saved card visible.
4. **Cycle:** `UPDATE tenants SET current_period_end = NOW() WHERE id = X` → `php scripts/billing-cycle-worker.php` → invoice created, charged via stored token, status `paid`, `current_period_end += 1 month`.
5. **Dunning:** force fail (use YK Sandbox decline card 5555 5555 5555 4477) → `next_retry_at` populated; after 4 retries → `suspended` → `/menu.php` returns 503.
6. **Provider admin:** log in with email in `BILLING_PROVIDER_ADMINS` → `/provider/billing.php` shows MRR + tenant table → `/provider/tenant.php?id=X` shows audit log + extend_trial / comp buttons.
7. **Feature gate:** on Starter plan → `/admin/kitchen.php` renders paywall, on Pro → renders normally.

## Known gaps / future work

- **No proration** on plan upgrade — switching to Pro mid-period charges full Pro price at next cycle. Low priority for first-launch.
- **No annual billing** — monthly only. Add `billing_period: 'yearly'` to PlanRegistry + a discount column to do this.
- **No dunning email templates** — failure events log to `subscription_events` but no Mailer wiring yet. Reuse `lib/Mailer.php` (if present) or extend Telegram notifications.
- **No webhook → /v3/payments verification idempotency** — the webhook handler may be called multiple times for the same payment_id; SubscriptionStore::onWebhook is idempotent per design (re-applying paid state is a no-op), but explicit dedupe by `(yk_payment_id, status)` would be safer.
- **No tax / VAT** — flat amount, no fiscal receipt for the provider's own income (54-ФЗ for the SaaS provider's revenue is a separate, larger track).
- **No referral / affiliate** — Phase 9 follow-up.
- **No usage-based pricing add-ons** ("loyalty +N ₽ per 1k balances", etc).

## Related docs

- [`docs/architecture-map.md`](architecture-map.md) — feature ⇆ file map (will gain a "SaaS Billing" section in 14.9).
- [`docs/feature-audit-matrix.md`](feature-audit-matrix.md) — promote `SaaS billing engine` row to `Implemented`.
- [`docs/openapi.yaml`](openapi.yaml) — should grow `/api/signup.php`, `/api/billing-action.php`, `/api/provider/tenant-action.php` entries.
