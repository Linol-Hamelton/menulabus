# Marketing & Pricing Strategy — CleanMenu 2026

## Implementation Status

- Status: `Phase 32A + 32B shipped 2026-05-08. Live: pricing v3, card-required signup with YK 1 ₽ binding (auto-refunded), addon purchase API (10 SKUs), trial-end reminder cron (D-15/D-5 emails). Deferred: annual billing automation (manual flag works for first 5-10 annual customers; full impl in Phase 32C).`
- Last reviewed: `2026-05-08`
- Owner: provider founder (sole-decision-maker today: feldhausthorsen@gmail.com)

## Context

Phase 14 shipped the SaaS billing engine in 2026-05 with a 4-tier model (Trial 14d / Starter 2 990 / Pro 6 990 / Enterprise 19 990). After 0 paying customers and a competitive audit (Phase 32), the pricing model was restructured to remove dead-weight tiers, extend the trial as the entry funnel, and add the missing services-revenue surface.

**Strategic stance**: don't compete on price. Compete on (a) richer all-in-one bundle than mid-market peers, (b) self-service onboarding without partner-channel friction, (c) white-label as defensible differentiator vs iiko/R-Keeper.

## Final pricing model (Phase 32 v3)

| Tier | Price | Trial | Card at signup | Notes |
|---|---|---|---|---|
| **Pro Trial** | 0 ₽ × **90 days** | n/a | **Required** (charged on day 91 if not cancelled) | Acquisition funnel — full Pro features for 90 days |
| **Pro** | 6 990 ₽/mo | none | yes | Mainstream tier. Features: KDS + inventory + loyalty + marketing + 54-ФЗ + group split + waitlist + i18n + webhooks. Up to 3 locations / 30 staff |
| **Pro annual** | 69 900 ₽/year (2 mo free) | none | yes | 16% discount, lock-in 2026 pricing 12 mo |
| **Enterprise** | 19 990 ₽/mo | none | yes | + white-label + custom domain + dev API + priority support + SLA. Up to 10 locations. **Bundled: Express onboarding (3 h) + 4 h training** (~24 900 ₽ value) |
| **Enterprise annual** | 199 900 ₽/year (2 mo free) | none | yes | Annual variant of Enterprise |
| **Enterprise+ / Сеть** | договорная (от 35 000 ₽/mo) | none | sales-led | Chains 10+ locations, SSO/SAML, dedicated DB, custom SLA, dedicated success-manager. Bundled: Full onboarding + 4 h training + priority support |

### Why these prices

Based on Phase 32 competitor research (top-10 РФ POS/SaaS):

- **Pro 6 990 ₽**: positioned between Quick Resto Pro 5 990 ₽ and iiko Cloud Pro 8 600 ₽. We have richer bundle (group split-bill + waitlist + i18n + open webhooks not standard at Quick Resto Pro).
- **Enterprise 19 990 ₽**: above iiko Enterprise 9 800 ₽ on paper, but iiko Enterprise excludes white-label + custom domain (which is the key Enterprise feature for us). Bundled onboarding + training closes the perceived-value gap.
- **Enterprise+**: чисто sales-led, custom contracts to allow bigger deal sizes for restaurant chains (SSO, dedicated DB are bespoke work).

### Why not lower

Tested in Phase 32 strategy meeting:
- 0 customers right now — no pressure to discount. Discounts signal weakness in absence of brand.
- Competitors don't compete with us on price (iiko 8 600+ for Pro features, Frontpad at 449 ₽ is delivery-only, not full POS).
- 90-day full Pro trial removes price-sensitivity at acquisition. By day 91, customer has product muscle-memory and onboarding investment — switching cost > monthly fee.

## Add-on services (one-time and recurring)

Catalog of paid services positioning as "white-glove premium" — direct revenue before subscription kicks in (during trial), and competitive moat vs iiko/R-Keeper where onboarding goes through opaque dealer pricing.

| Service | Price | Type | What's included |
|---|---|---|---|
| **Express onboarding** | 9 900 ₽ | one-time | 3 h: account setup, brand (logo+colors), CSV menu import, QR codes for 1 location |
| **Full onboarding** | 24 900 ₽ | one-time | 8 h: Express + categories + modifiers + recipes/ingredients + KDS stations + 54-ФЗ + Telegram-bot |
| **Migration from iiko/R-Keeper/Quick Resto/Poster** | 49 900 ₽ | one-time | 12+ h: data export, mapping, validation, parallel-run support |
| **Group training (online)** | 6 900 ₽ | one-time | 2 h, up to 5 staff: orders, KDS, reporting |
| **Individual training** | 4 900 ₽ | one-time | 1 h 1-on-1: settings, analytics, marketing |
| **Telegram-bot setup** | 4 900 ₽ | one-time | bot creation, webhook config, inline-buttons |
| **Custom domain config** | 2 900 ₽ | one-time | DNS + Let's Encrypt + nginx config (Pro doplata; in Enterprise included) |
| **Recorded video course (lifetime)** | 2 900 ₽ | one-time | self-paced learning library |
| **Priority support 24/7** | 990 ₽/mo | recurring | SLA-2h response on Pro (in Enterprise included) |
| **On-site visit** (regional Mosсow/SPb) | 19 900 ₽ + travel | one-time | half-day setup + training |

Implementation: catalog defined in code in Phase 32B (`lib/Billing/AddonCatalog.php` + `addon_purchases` table + `/api/billing-purchase-addon.php` endpoint).

## Go-to-market — direct sales first

Decision (founder): no paid marketing budget M1-M3. **Direct sales playbook** until product-market fit on first 30-50 customers.

### M1-M3 — founder-led acquisition

**Channels (P0/P1):**
- **Telegram outbound**: founder personally messages cafe/restaurant Telegram channel admins + scrape 2GIS/Я.Карты for new openings → personal pitch
- **Telegram-сообщества рестораторов**: РестораторРФ, ReNomeChat, city-specific chats — content posts (case studies, tactical tips), не реклама
- **30-min personal demos**: live walkthrough on owner's actual menu data → "запустим за час бесплатно на 90 дней"
- **Concierge onboarding**: 1-on-1 founder support for first 50 customers (deliberately doesn't scale — gathers PMF feedback)

**Targets M3:**
- 30 active trial-users
- 8 paying customers (Pro+Enterprise)
- 56 000 ₽ MRR
- 80 000 ₽ one-time onboarding revenue
- 5+ NPS-friendly testimonials

### M4-M6 — content + partnerships

- **SEO content launch** (after testimonials accumulate): 10 cornerstone articles on common search intents — "iiko альтернатива", "POS для кофейни", "QR-меню с заказами и оплатой", "как заменить R-Keeper", etc.
- **Partner program**: revenue-share with fiscal-monitoring services, POS hardware resellers, accounting firms (10-20% rev-share for first-year revenue)
- **Telegram paid рассылки**: 5-15 ₽/contact для commercial HoReCa-боты после первых 5 paying customers (signal validation)

**Targets M6:**
- 80 trials active
- 30 paying customers
- 210 000 ₽ MRR + 300 000 ₽ cumulative onboarding revenue
- ~3.5 М ₽ ARR projection

### M7-M12 — paid acquisition + conferences

- **Я.Директ + VK Ads**: keywords "iiko альтернатива", "QR меню кафе", "POS для ресторана"
- **HoReCa-конференции**: PIR, GASTREET, ResTeam — selective attendance with founder-led booth
- **Customer-facing case studies + video testimonials** from M1-M6 customers

**Targets M12:**
- 200 trials active
- 80-120 paying customers
- 600-900 000 ₽ MRR + 800 000-1 200 000 ₽ cumulative onboarding revenue
- ~10 М ₽ ARR projection

## Differentiation messaging

Single-line pitch:
> «Полная автоматизация ресторана за 5 минут — без iiko-сложности и хардвер-привязки»

Three battle-cards for direct comparison:

**vs iiko** (target: SMB cafe owner)
- Cloud-only (no hardware покупка) vs iiko on-prem POS terminals
- Self-service signup vs iiko dealer-sales cycle (~weeks)
- Full bundle pricing (KDS / inventory / loyalty / marketing all in 6 990) vs iiko modular (each is +add-on)

**vs Quick Resto** (target: cloud-native SMB)
- White-label на своём домене (Quick Resto не предлагает)
- Group orders + split-bill (нативно у нас, не у QR)
- 54-ФЗ в базе (у QR — отдельный модуль)

**vs LiteMenu / qrlip / Restomenu** (target: outgrown free QR-menu)
- "Вы переросли простое QR-меню? У нас вы уже на полноценной POS-платформе с тем же UX"
- One-page upsell from "free QR-only" to "Pro POS"

**Anti-positioning** (что мы НЕ говорим):
- НЕ конкурируем по цене ("не дешевле, но без скрытых затрат")
- НЕ обещаем enterprise для chains 50+ — слишком тяжёлый sales cycle для founder-only operation
- НЕ ввязываемся в feature-by-feature comparison с iiko на их территории (склад в iiko мощнее)

## KPI targets (12 months)

| Metric | Current (M0) | M3 | M6 | M12 |
|---|---|---|---|---|
| Active trials | 0 | 30 | 80 | 200 |
| Paying customers | 0 | 8 | 30 | 100-120 |
| MRR | 0 ₽ | 56 000 ₽ | 210 000 ₽ | 600-800 000 ₽ |
| Cumulative onboarding revenue | 0 ₽ | 80 000 ₽ | 300 000 ₽ | 800-1 200 000 ₽ |
| Annual upfront cash | 0 ₽ | 0 ₽ | 100 000 ₽ | 1 500 000 ₽ |
| Trial→Paid conversion | n/a | n/a | 15-20% | 20-25% |
| ARPU (subscription) | n/a | 7 000 ₽ | 7 000 ₽ | 8 000 ₽ (incl. annual upgrades) |
| Monthly churn | n/a | n/a | <5% | <3% |
| **Total ARR projection** | 0 ₽ | ~700 000 ₽ | ~3.5 М ₽ | ~10 М ₽ |

## Known risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Trial-end conversion <10% (тире-kicker problem) | Medium | High | Card-required at signup (Phase 32B) raises serious-only. Annual lock-in offer at day 75-85. Manual outreach by founder for high-quality trials. |
| iiko/R-Keeper launch cheap-tier for SMB | Low | High | Differentiate by white-label + cloud-only + UX, not by price. Defensible niche. |
| YooKassa/АТОЛ commissions eat margin | Low | Medium | Pricing already includes 3-5% buffer. Pass-through tax on Enterprise+ tier. |
| Free 90 days = abuse / infra burden | Low (with card-required) | Low | Card-required at signup filters real customers. Soft caps on storage/bandwidth in trial. |
| Competitor дempinг ниже наших цен | Low | Medium | We compete on bundle + UX, not price. Frontpad cheaper (449 ₽) — different use-case (delivery-only). |

## Implementation roadmap

| Phase | Status | Scope | Effort |
|---|---|---|---|
| **32A** | ✅ Shipped 2026-05-08 (this doc + code) | PlanRegistry v3 (kill Starter, Pro trial 90d, Enterprise+ tier, annual variants), signup.php messaging, owner billing tab grid + addons display, index.php pricing section, marketing strategy doc | 1 day |
| **32B** | ⏳ Backlog | Card-required signup flow (YooKassa SaveMethod widget), AddonCatalog + addon_purchases table + /api/billing-purchase-addon.php, annual billing automation in SubscriptionStore (12-mo cycle), trial-end conversion cron (`scripts/billing-trial-reminder.php` day 75/85 emails) | 5-7 days |
| **33** | Backlog | Marketing pages: pricing comparison vs iiko/Quick Resto/R-Keeper, free-QR-menu lead-magnet landing, schema.org markup, sitemap, OG images | 4-5 days |
| **34** | Backlog | Referral program: code generation, tracking table, rewards application, referral landing | 2-3 days |
| **35** | Backlog | Founder ops kit: TG outbound script templates, demo recording template, customer feedback form, onboarding checklists | 2-3 days |
| **36** | Backlog | SEO content: 10 cornerstone articles (external copywriter or founder), schema.org for articles, internal linking | 30+ days incl. content |

## Out of scope for Phase 32

- Bulk/volume discounts (not at our scale yet)
- Multi-currency (RUB only)
- Crypto/non-standard payment methods (YooKassa only)
- Self-serve enterprise+ contracts (sales-led only)
- Sub-account billing for chains (single billing entity per chain in Enterprise+)
- Detailed proration on plan upgrades/downgrades (deferred until 5+ customers ask)

## Rollback plan

Phase 32A is a single atomic commit. `git revert <sha>` restores the 4-tier (Trial 14d / Starter / Pro / Enterprise 19 990 negotiable) model with all features intact. Database schema is unchanged (PlanRegistry is code-only); existing tenant `plan_id` values remain valid (no Starter customers exist, so nothing breaks).

Phase 32B introduces schema changes (addon_purchases table, billing_period column) — those will need explicit migration rollback steps when written.
