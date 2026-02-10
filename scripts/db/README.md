# DB Maintenance Scripts

## Backfill `order_items` from `orders.items`

Purpose:
- Populate `order_items` so reports/analytics can stop using JSON-heavy queries.
- This is a one-time migration step for legacy data.

Safe defaults:
- Only processes orders that currently have **0 rows** in `order_items`.
- Supports `--dry-run`.

Run (server):
```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro

# dry-run first
php scripts/db/backfill-order-items.php --dry-run --limit=100

# real run in chunks
php scripts/db/backfill-order-items.php --from-id=1 --to-id=200000 --chunk=200
```

Notes:
- The script expects `config_copy.php` to exist in the parent directory (same as `db.php`).
- Run off-peak; it inserts rows and may generate IO.

