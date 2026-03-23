# Backoffice Role Helpers

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - The current in-app helper surface is [`/help.php`](../help.php).
  - The page is available to `employee`, `admin`, and `owner` roles through the shared account shell.
  - The helper is intentionally operational and compact: it is meant to reduce onboarding time and common backoffice mistakes.

## Purpose

This document defines the current helper scope for privileged roles:

- `employee`
- `admin`
- `owner`

The goal is not to duplicate every screen field. The goal is to give each role:

- the correct entry point
- the correct daily flow
- the most common failure-avoidance rules
- a short demo path for training or handoff

## Employee Helper

Primary screen:

- `employee.php`

Operator focus:

- accept incoming orders
- keep the queue moving by status
- use search and delivery-type filters to reduce noise
- open details only when delivery context or line items matter
- generate payment links and confirm cash only when payment actually happened
- use `qr-print.php` for table service

Daily flow:

1. Open `Приём`.
2. Check `Готовим`.
3. Resolve urgent delivery or table orders.
4. Print or reprint QR codes if floor service needs them.
5. Only move the order to the next state after a real action.

## Admin Helper

Primary screen:

- `admin-menu.php`

Admin focus:

- maintain the catalog
- upload or sync menu items
- archive and restore items
- manage brand, assets, fonts, colors, and white-label settings
- configure payment providers and system integrations

Practical rule set:

- use CSV for bulk updates
- use manual edit for precise corrections
- keep modifiers only where guest choice matters
- treat `contact_address` as display text
- treat `contact_map_url` as the actual map CTA source
- after payment or branding changes, re-check public menu and cart behavior

## Owner Helper

Primary screen:

- `owner.php`

Owner focus:

- read KPI summary first
- review top items and trend slices
- inspect bottlenecks and employee efficiency
- validate launch quality across provider and tenant domains

Daily review sequence:

1. KPI snapshot.
2. Top items today/week.
3. Revenue/profit view.
4. Bottlenecks.
5. User and role hygiene if staffing changed.

## Shared Launch Acceptance

For tenant launch or release acceptance, privileged roles should be able to confirm:

- tenant homepage opens correctly
- tenant menu contains restaurant content, not provider services
- cart and checkout open
- employee queue opens
- admin brand/contact fields render correctly
- owner analytics open without broken tabs

## Related Docs

- [Menu Capabilities Presentation](./menu-capabilities-presentation.md)
- [Project Reference](./project-reference.md)
- [Tenant Launch Checklist](./tenant-launch-checklist.md)
