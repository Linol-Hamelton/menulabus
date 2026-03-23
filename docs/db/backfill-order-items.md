# DB Maintenance Scripts

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - This is a maintenance/runbook document for an existing script.
  - It is not an open product roadmap item.

## Backfill `order_items` from `orders.items`

Purpose:

- populate `order_items` so analytics can stop relying on JSON-heavy queries
- backfill legacy data safely in chunks

Safe defaults:

- only process orders that currently have `0` rows in `order_items`
- support `--dry-run`

Run on server:

```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro

php scripts/db/backfill-order-items.php --dry-run --limit=100
php scripts/db/backfill-order-items.php --from-id=1 --to-id=200000 --chunk=200
```

Notes:

- the script expects `config_copy.php` next to the main app config layout
- run off-peak because it inserts rows and can generate IO
