# Tenant Demo Seed

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-17`
- Verified against published pages: `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current implementation notes:
  - `test.milyidom.com` behaves like a restaurant tenant after seeding.
  - The seed writes restaurant-oriented data into the tenant database and does not modify provider data in `menu_labus`.

## Purpose

Use the reusable tenant demo seed to turn a tenant into a restaurant-facing demo environment instead of a provider-style catalog.

Current supported profile:

- `restaurant-demo`

The profile seeds:

- restaurant-facing brand settings
- separate address text plus map-link setting
- synthetic restaurant catalog
- modifier groups and options
- demo admin / employee / customer accounts
- demo orders for owner / employee / customer smoke

## Seed Existing Tenant

Resolve the tenant through the control-plane and replace tenant demo content:

```bash
php scripts/tenant/seed.php --profile=restaurant-demo --domain=test.milyidom.com --force
```

Or resolve by slug:

```bash
php scripts/tenant/seed.php --profile=restaurant-demo --brand-slug=test_milyidom --force
```

CLI output now includes:

- target domain
- target DB name
- owner identity
- smoke result
- rollback hint

## Seed During Provisioning

For a brand-new tenant database:

```bash
php scripts/tenant/provision.php \
  --brand-name="Demo Bistro" \
  --brand-slug=demo_bistro \
  --domain=demo.example.com \
  --mode=tenant \
  --owner-email=owner@example.com \
  --tenant-db-user=demo_bistro \
  --tenant-db-pass=secret \
  --seed-profile=restaurant-demo
```

## Smoke Expectations

After seeding:

- tenant `/` opens the tenant public entry and can render a restaurant-facing homepage
- tenant `/menu.php` shows restaurant categories and dishes
- provider domain does not mirror tenant catalog
- tenant public contacts use restaurant address text and optional map CTA, not provider fallback
- admin can archive, unarchive, and edit restaurant items
- employee board shows seeded operational data
- owner reports are populated by seeded completed orders

## Safety Notes

- Use `--force` only on demo tenants. It replaces tenant catalog, modifiers, and seeded demo orders.
- Demo staff/customer passwords are emitted by the CLI JSON output.
- The owner account is kept intact; the seed does not overwrite owner credentials.
