# UX/UI Improvement Roadmap

This document is the source of truth for future UX/UI improvements.
It complements `docs/project-improvement-roadmap.md` and does not replace security, performance, or deployment runbooks.

## Goal and Principles

Goal: improve visual quality, usability, information architecture, and conversion without degrading the existing ordering flow, operational speed, or reliability.

Core principles:
- Visual polish must improve comprehension and conversion, not exist for its own sake.
- Familiar order flows must remain recognizable after each iteration.
- Mobile UX, accessibility, staff throughput, and perceived performance must not regress.
- Marketing and transactional goals should not be mixed in the same screen unless the separation is explicit and beneficial.
- Every UX change must be shipped in staged, low-risk increments with clear validation.

## Fixed Product Decisions

These decisions are locked and should not be reopened during later implementation without a separate product decision.

- `index.php` is the marketing and promotions entry point.
- `menu.php` is the primary transactional menu and ordering surface.
- Large promo modules, featured categories, hits, campaigns, and storytelling are allowed on `index.php`.
- Core category browsing, dish cards, modifiers, cart entry, and order initiation must remain on `menu.php`.
- `menu.php` must not become a landing page.
- `index.php` must not become a full catalog replacement.

## Page-by-Page UX Audit

### `index.php`

Current state:
- Brand landing page with hero, service description, reservation/lead form, and footer information.

UX problem:
- The page acts as a brand entry point, but the conversion path toward actual ordering is weak.
- Messaging and CTA structure can compete with the real order path instead of leading into it.
- The page currently risks looking informational rather than transactional.

Safe improvement direction:
- Strengthen the first screen with clearer value proposition and explicit dual CTA: `Ќа акции` and `ќткрыть меню`.
- Add featured categories, top dishes, promos, or seasonal highlights as lightweight modules.
- Use the page to create appetite and direction, not to duplicate the full menu browsing experience.

Why it helps:
- Improves first impression and click-through into the menu.
- Lets marketing, brand, and promotions live in a proper surface without bloating the order screen.

Risk if done poorly:
- A heavy landing page can distract from ordering or slow initial load.
- Turning `index.php` into a pseudo-menu duplicates maintenance and splits user focus.

### `menu.php`

Current state:
- Main category-driven menu surface with the active menu view, category tabs, and cart entry.

UX problem:
- Discoverability is weaker than it should be for first-time customers.
- The first screen is functional, but not yet optimized for fast scanning, quick choice, or impulse selection.
- Cart visibility is not as strong as it could be on mobile.

Safe improvement direction:
- Preserve the current traditional menu structure.
- Improve first-screen clarity, sticky category navigation, search/filter discoverability, and cart prominence.
- Strengthen dish cards with better hierarchy, concise descriptors, stronger pricing/CTA emphasis, and optional quick filters.

Why it helps:
- Increases `menu_view -> add_to_cart` conversion without retraining regular users.
- Makes large menus easier to scan and reduces decision fatigue.

Risk if done poorly:
- Over-designing the menu can slow down choice instead of helping it.
- Excessive promo blocks inside `menu.php` can make ordering feel secondary.

### `cart.php`

Current state:
- Minimal cart screen with summary, empty state, clear button, and checkout button.

UX problem:
- The empty state is weak and does not actively recover the user back into the ordering flow.
- Action hierarchy is not fully context-aware.
- The progression from cart to order creation could feel more guided.

Safe improvement direction:
- Improve empty state with useful CTA back to the menu and context-sensitive guidance.
- Hide or disable meaningless actions in empty state.
- Make totals, order type, and next-step messaging more explicit, especially on mobile.

Why it helps:
- Reduces dead-end moments and checkout abandonment.
- Gives users more confidence about the next action.

Risk if done poorly:
- Sticky or oversized checkout UI can obscure content on mobile.
- Adding too much friction in cart can lower completion rates.

### `customer_orders.php`

Current state:
- Long history list of orders with repeated metadata, delivery type, and tracking CTA.

UX problem:
- Active and historical orders are mixed in one dense feed.
- Repeated metadata and long addresses create noise.
- The most important action is visible, but not visually prioritized enough.

Safe improvement direction:
- Split `јктивные` and `»стори€`.
- Compress repeated metadata on the first level and move secondary details into expandable sections.
- Highlight tracking and repeat-order actions.

Why it helps:
- Reduces time to find the relevant order.
- Improves post-purchase confidence and retention.

Risk if done poorly:
- Excessive collapsing can hide important info.
- Over-segmentation can add taps for frequent users.

### `order-track.php`

Current state:
- Clean order tracking screen with status steps, ETA, order composition, and total.

UX problem:
- ETA and status context can still feel too abstract.
- Stage descriptions are useful, but can become more confidence-building.
- The screen is readable, but not yet emotionally reassuring enough.

Safe improvement direction:
- Make active status more pronounced.
- Add clearer context for ETA and state meaning.
- Adapt messaging to pickup/table/delivery scenarios.

Why it helps:
- Reduces anxiety after order creation.
- Lowers support-style questions like Уwhere is my order?Ф.

Risk if done poorly:
- Too much text can make the screen heavier and less glanceable.

### `account.php`

Current state:
- Customer profile page with role-based shortcuts visible in the same overall context.

UX problem:
- Customer identity and staff/owner shortcuts share the same mental space.
- This can make the account area feel denser and less focused.

Safe improvement direction:
- Separate personal account actions from role-based work panels.
- Keep customer profile tasks visually primary inside `account.php`.

Why it helps:
- Improves orientation and reduces cognitive switching.

Risk if done poorly:
- Hiding role shortcuts too deeply can hurt power users.

### `employee.php`

Current state:
- Operational order stream with expandable cards, status buttons, delivery metadata, and action controls.

UX problem:
- High cognitive load during rush periods.
- Too much repeated information at the same hierarchy level.
- Primary next action competes with surrounding details.

Safe improvement direction:
- Make the page triage-first: better filters, order-type badges, SLA/time emphasis, and clearer primary action.
- Compress non-critical metadata and long addresses.
- Keep the page optimized for speed, not ornament.

Why it helps:
- Improves processing speed and reduces mistakes under load.
- Helps staff focus on action order, not just order content.

Risk if done poorly:
- Over-stylizing staff screens can reduce throughput.
- Hidden details can slow down exceptions handling.

### `admin-menu.php`

Current state:
- Admin area with multiple section layers (`Ѕлюда`, `ƒизайн`, `ќплата`, `—истема`) and mixed operational content.

UX problem:
- Information architecture feels layered and not always obvious.
- Competing navigation levels increase friction.
- Primary content-management tasks are not always visually dominant.

Safe improvement direction:
- Simplify IA, reduce competing navigation patterns, and promote primary actions like dish management.
- Separate content operations from diagnostics and system controls more clearly.

Why it helps:
- Faster admin work, lower confusion, and fewer navigation mistakes.

Risk if done poorly:
- Moving controls without preserving discoverability can frustrate current admins.

### `owner.php`

Current state:
- Owner analytics now has improved KPI, top-items, report tables, and chart cards, but the whole analytics workspace is still not fully unified.

UX problem:
- Analytics blocks are better individually than collectively.
- Report tabs, filters, charts, and summary cards still feel like adjacent modules rather than one workspace.
- Desktop and mobile behavior still need clearer intentional divergence.

Safe improvement direction:
- Turn analytics into a coherent workspace with consistent card language, better tab placement, and lightweight interpretation helpers.
- Keep mobile and desktop patterns intentionally different where reading behavior differs.

Why it helps:
- Owners reach insights faster instead of just seeing data.
- Reduces Уwhat should I conclude from this?Ф friction.

Risk if done poorly:
- Over-condensing analytics can hide important raw numbers.
- Over-expanding analytics can make the screen harder to scan.

## Improvement Backlog by Priority

### Phase 1: Customer UX / Conversion

#### `index.php`
- Strengthen the first screen with stronger value proposition and explicit CTA split.
- Add featured categories, hits, promotions, and directional blocks.
- Create a clear visual path from landing to `menu.php`.

What stays unchanged:
- `index.php` remains a marketing/brand/promotions page.

Potential downside:
- A heavier landing page may distract from ordering or increase load time.

Guardrail:
- Do not duplicate the full menu experience on `index.php`.
- Preserve a fast path into `menu.php` above the fold.

Success signal:
- Higher click-through rate from `index.php` to `menu.php`.

#### `menu.php`
- Improve first-screen menu clarity without changing the familiar structural model.
- Add stronger discoverability patterns: sticky categories, search, quick filters, better cart CTA.
- Improve dish scanning and action hierarchy.

What stays unchanged:
- `menu.php` remains the primary ordering screen and traditional menu surface.

Potential downside:
- Too much UI chrome can slow dish selection.

Guardrail:
- No landing-style storytelling takeover inside the main menu screen.
- Keep ordering actions visible with minimal extra taps.

Success signal:
- Better `menu_view -> add_to_cart` conversion and lower time-to-first-add.

#### `cart.php`
- Improve empty state and make actions context-aware.
- Clarify totals, next steps, and checkout progression.
- Increase mobile checkout clarity without crowding the screen.

What stays unchanged:
- Cart remains a lightweight pre-checkout screen.

Potential downside:
- Sticky checkout elements can take too much viewport space.

Guardrail:
- Keep summary readable and avoid blocking product visibility on mobile.

Success signal:
- Lower cart abandonment and stronger `add_to_cart -> order_create_success` conversion.

#### `customer_orders.php` and `order-track.php`
- Make active orders more visible and history less noisy.
- Strengthen repeat-order and track-order scenarios.
- Reduce metadata clutter and long-address overload.

What stays unchanged:
- Existing order visibility, tracking, and history access remain available.

Potential downside:
- Too much collapsing can hide details users expect immediately.

Guardrail:
- Keep primary status, amount, date, and main CTA visible by default.

Success signal:
- Faster navigation to active orders and increased repeat-order usage.

### Phase 2: Staff / Throughput

#### `employee.php`
- Add triage-first UX: filters, search, status focus, compact cards, clear next action.
- Make operational urgency more visible than decorative detail.

What stays unchanged:
- Staff order processing flow and state transitions remain functionally the same.

Potential downside:
- Over-compression can hide exception details needed in real operations.

Guardrail:
- Keep critical order context reachable within one interaction.
- Avoid heavy animation and non-essential visual effects.

Success signal:
- Lower time to first action on a new order and fewer staff-side UI mistakes.

#### `admin-menu.php`
- Simplify information architecture and reduce competing nav layers.
- Elevate primary content-management actions.
- Separate diagnostics/system tools from core menu operations.

What stays unchanged:
- Existing admin capabilities, sections, and permissions remain intact.

Potential downside:
- Reorganizing controls can temporarily hurt operator muscle memory.

Guardrail:
- Preserve predictable access to all admin functions during staged rollout.

Success signal:
- Fewer clicks and less time to common admin tasks.

### Phase 3: Owner / Analytics

#### `owner.php`
- Unify KPI, top items, report tables, charts, and tabs into one analytics workspace.
- Intentionally differentiate desktop and mobile reading patterns.
- Add interpretation helpers without obscuring raw data.

What stays unchanged:
- Existing metrics and owner-only access rules remain intact.

Potential downside:
- Over-abstracting analytics can reduce operator trust in the numbers.

Guardrail:
- Keep raw values visible and avoid replacing data with only summaries.

Success signal:
- Owners find key daily/weekly insights faster and with fewer clicks.

#### Internal tool UX defects
Fix and track the following cross-cutting defects because they reduce UX quality even when layout is good:
- icon-font text leakage into visible/accessible text streams
- CSP-related broken chart styling in internal tooling
- long raw metadata strings inside order cards

What stays unchanged:
- Security restrictions and internal tool access controls remain intact.

Potential downside:
- Accessibility or CSP fixes can expose coupling between old frontend assumptions.

Guardrail:
- Any semantic cleanup must preserve current workflows and security posture.

Success signal:
- Cleaner UI text, fewer broken visual states, and less semantic noise.

## Non-Regression Rules

Every UX improvement should be reviewed against the following rules before implementation.

- Do not worsen p95 or perceived performance.
- Do not hide critical actions behind unnecessary clicks.
- Do not add heavy motion, layers, or decorative complexity to staff flows.
- Do not break the familiar checkout path.
- Do not change API contracts, order creation behavior, auth flow, or role permissions for UX-only work.
- Do not mix promo/marketing content into the main ordering surface in a way that competes with ordering.
- Do not turn a staged improvement into a big-bang redesign.

For every concrete UX task, implementation notes should record:
- `What stays unchanged`
- `Potential downside`
- `Guardrail`
- `Success signal`

## Validation and Success Metrics

UX changes must be validated by outcomes, not taste.

### Customer metrics
- `menu_view -> add_to_cart`
- `add_to_cart -> order_create_success`
- checkout abandonment
- repeat order usage
- click-through from `index.php` to `menu.php`

### Staff metrics
- time to first action on a new order
- average number of interactions before status change
- frequency of errors or refusals linked to UI confusion

### Owner/Admin metrics
- time to find a target report or setting
- click count to key actions
- subjective readability of analytics blocks after release checks

### Validation scenarios
- New customer on mobile: landing -> menu -> cart -> order
- Returning user: history -> repeat/track
- Employee during peak hour: triage -> accept -> next status
- Owner: open analytics and understand key day/week signals from the first screen
- Post-release smoke for key surfaces: `menu.php`, `cart.php`, `owner.php`, `employee.php`

## Relationship to Other Roadmaps

Before implementing any UX item, verify that it does not conflict with:
- `docs/project-improvement-roadmap.md`
- security restrictions and rollout rules already documented under `docs/security-*`
- the fixed product split between `index.php` and `menu.php`

This roadmap is intentionally specific about UX direction and intentionally conservative about implementation risk.
