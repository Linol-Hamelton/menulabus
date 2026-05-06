# Operations Runbook

Outstanding **operational** tasks tracked outside the code repo (host configuration, per-tenant migration runs, cron schedules). This file consolidates items previously scattered across multiple roadmaps under the "Pending host rollout" or "operational adoption" labels.

## Implementation Status

- Status: `Active operations checklist`
- Last reviewed: `2026-05-06`
- Owner: provider devops (sole-owner today: feldhausthorsen@gmail.com)

## 1. Pending tenant migrations

Four migrations exist in the repo but **may not yet be applied on every live tenant DB**. New tenants get them via `scripts/tenant/launch.php` bootstrap, but existing tenants require manual `mysql … < migration.sql` runs.

| Migration | File | What it does | Idempotent? |
|---|---|---|---|
| `menu-sort-order` | `sql/menu-sort-order-migration.sql` | adds `sort_order INT` to `menu_items`, backfills sequential per-category | ✅ uses `INFORMATION_SCHEMA` guard |
| `modifiers-soft-delete` | `sql/modifiers-soft-delete-migration.sql` | adds `deleted_at TIMESTAMP NULL` to `modifier_groups` + `modifier_options` | ✅ guarded |
| `webhooks` | `sql/webhooks-migration.sql` | creates `webhook_subscriptions`, `webhook_deliveries` tables | ✅ uses `CREATE TABLE IF NOT EXISTS` |
| `reservations` | `sql/reservations-migration.sql` | creates `reservations` table + indexes | ✅ guarded |

### Per-tenant rollout procedure

For each live tenant DB (currently: `menu_labus` is the canonical tenant; `menu_concept` is a Laravel app — **do not** migrate against it):

```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
DB_NAME=menu_labus  # adjust per tenant
for m in sql/menu-sort-order-migration.sql sql/modifiers-soft-delete-migration.sql sql/webhooks-migration.sql sql/reservations-migration.sql; do
  echo "=== Applying $m to $DB_NAME ==="
  mysql --defaults-extra-file=/root/.my.cnf "$DB_NAME" < "$m"
done
```

After running, verify via `SHOW COLUMNS FROM menu_items LIKE 'sort_order'` (and equivalents).

### Tracking applied migrations

Until we have a `migrations_log` table, track applied per-tenant migrations in this file:

| Tenant DB | menu-sort-order | modifiers-soft-delete | webhooks | reservations |
|---|---|---|---|---|
| `menu_labus` | _pending_ | _pending_ | _pending_ | _pending_ |

Update the table after each run.

## 2. Cron / systemd timers

Three workers exist in repo and **must be wired up on the prod host** to actually run.

### 2.1 Webhook delivery worker

- File: `scripts/webhook-worker.php`
- Purpose: drains pending `webhook_deliveries` rows, retries failures with exponential backoff
- Recommended cadence: every 1 minute

Add to crontab (`crontab -e -u webuser`):

```cron
* * * * * /usr/bin/php8.1 /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/webhook-worker.php >> /var/log/cleanmenu/webhook-worker.log 2>&1
```

### 2.2 Soft-deleted orders purge

- File: `scripts/orders/purge-soft-deleted.php`
- Purpose: hard-delete orders that have been `deleted_at` for >90 days
- Recommended cadence: daily at 04:00

```cron
0 4 * * * /usr/bin/php8.1 /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/orders/purge-soft-deleted.php >> /var/log/cleanmenu/purge.log 2>&1
```

### 2.3 Monthly security review

- File: `scripts/security/monthly-review.sh`
- Purpose: emit a monthly checklist of patches, fail2ban stats, suspicious-IP report
- Recommended cadence: 1st of each month at 09:00

```cron
0 9 1 * * /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/security/monthly-review.sh >> /var/log/cleanmenu/security-review.log 2>&1
```

After installing, verify with `crontab -l -u webuser` and tail the logs after first scheduled run.

## 3. Network / SSH hardening (one-shot host scripts)

These are repo-owned scripts that need to be **executed once** on the prod host, not scheduled.

| Script | Purpose | Run when |
|---|---|---|
| `scripts/security/apply-network-policy.sh` | UFW/iptables rules for menu.labus.pro | After provisioning new host or rotating firewall config |
| `scripts/security/harden-ssh-fail2ban.sh` | sshd_config + fail2ban jail.local for SSH brute-force | After provisioning new host |

Both are idempotent. Run via:

```bash
sudo bash scripts/security/apply-network-policy.sh
sudo bash scripts/security/harden-ssh-fail2ban.sh
```

After running, verify:

```bash
ufw status verbose
fail2ban-client status sshd
```

## 4. Verification checklist after running everything

Once all migrations + cron + hardening are done, verify the system is healthy:

- [ ] `ufw status verbose` shows expected allow rules (80/443/22 from limited IPs)
- [ ] `fail2ban-client status sshd` shows `Currently failed:` is small
- [ ] `crontab -l -u webuser` shows the 3 cron entries from §2
- [ ] Tail `/var/log/cleanmenu/webhook-worker.log` after 2 minutes — should have at least one "no work" / "drained N" line
- [ ] Tenant menu_labus has all 4 schema changes from §1
- [ ] `SHOW TABLES LIKE 'webhook_%'` and `LIKE 'reservations'` succeed
- [ ] `/api/v1/menu.php` still 200 (provider/tenant smoke green)

## 5. Rollback notes

- Migrations from §1 are **forward-only**. If reverting, restore from `mysqldump` taken before the migration run. There is no automatic rollback script.
- Cron entries can be removed via `crontab -e` — they don't modify state, just stop running workers. Active jobs in flight finish gracefully.
- Hardening scripts modify `/etc/ufw/*` and `/etc/fail2ban/jail.local`. Each script has its own backup-before-write pattern; check the script source for the backup file path.
