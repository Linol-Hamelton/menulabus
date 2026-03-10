# Tenant Demo Seed

`test.milyidom.com` should behave like a restaurant tenant, not like the provider catalog.

This runbook defines the reusable demo seed process for tenant databases.

## Profile

Current supported profile:

- `restaurant-demo`

The profile seeds:

- restaurant-facing brand settings
- synthetic restaurant catalog
- modifier groups and options
- demo admin / employee / customer accounts
- demo orders for owner / employee / customer smoke

It does not modify provider data in `menu_labus`.

## Seed Existing Tenant

Use control-plane lookup and replace tenant demo content:

```bash
php scripts/tenant/seed.php --profile=restaurant-demo --domain=test.milyidom.com --force
```

Or resolve by slug:

```bash
php scripts/tenant/seed.php --profile=restaurant-demo --brand-slug=test_milyidom --force
```

## Seed During Provisioning

For a brand-new tenant database, seed the restaurant demo during provisioning:

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

- tenant `/` redirects to `/menu.php`
- public menu shows restaurant categories and dishes
- provider domain does not mirror tenant catalog
- admin can archive / unarchive and edit restaurant items
- employee board shows active and historical orders
- owner reports are populated by seeded completed orders

## Notes

- Use `--force` only on demo tenants. It replaces tenant catalog, modifiers, and orders.
- Demo staff/customer passwords are emitted by the CLI JSON output.
- Owner account stays unchanged; the seed does not overwrite owner credentials.
