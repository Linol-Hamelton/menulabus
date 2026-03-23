# Menu Capabilities Presentation

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - The current presentation summary is reflected in the in-app helper page [`/help.php`](../help.php).
  - This document is the source version for sales demos, onboarding, and internal walkthroughs.
  - The presentation reflects the current provider/tenant model rather than the old single-domain model.

## One-Sentence Pitch

`MenuLabus` is a white-label digital menu and restaurant operations workspace that combines public ordering, staff order handling, admin catalog control, and owner analytics.

## What Guests Get

- restaurant-facing homepage for tenant domains
- public menu with categories, descriptions, composition, and nutrition data
- cart and checkout for delivery, takeaway, table, or bar scenarios
- QR ordering for table service
- account access, order history, and repeat-order flow

## What Staff Get

- real-time order queue by stage
- search and delivery-type filtering
- compact order summaries with expandable details
- payment link generation
- cash confirmation flow
- QR print workflow for tables

## What Admins Get

- catalog management
- CSV import and manual editing
- archive and restore flow
- modifiers management
- brand, files, colors, fonts, logo, favicon, and custom-domain settings
- payment and system settings in dedicated sections

## What Owners Get

- KPI snapshot
- sales, profit, efficiency, customer, item, load, employee, and bottleneck reports
- chart and table views
- user oversight and role visibility
- release/launch validation from the business side

## White-Label Value

- provider and tenant domains are separated by runtime host resolution
- tenant content is seeded independently from provider demo content
- brand settings drive tenant-facing presentation
- public tenant experience can look like a real restaurant, not a provider demo

## Recommended 5-Minute Demo Flow

1. Open the tenant homepage.
2. Show the tenant public menu and add an item to cart.
3. Explain delivery modes and QR table ordering.
4. Open `employee.php` and show the order queue.
5. Open `admin-menu.php` and show catalog + brand controls.
6. Open `owner.php` and close with analytics and bottlenecks.

## Demo Outcome

At the end of the demo, the audience should understand that the product is not only a digital menu, but a connected operational layer for:

- guest ordering
- staff execution
- admin control
- owner reporting
- white-label tenant launches

## Related Docs

- [Backoffice Role Helpers](./backoffice-role-helpers.md)
- [Public Layer Guidelines](./public-layer-guidelines.md)
- [Project Improvement Roadmap](./project-improvement-roadmap.md)
