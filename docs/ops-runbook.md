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

## 6. TLS / Let's Encrypt cert lifecycle

### Background — what went wrong on 2026-05-06

The `menu.labus.pro` Let's Encrypt cert expired (notAfter `May 6 19:51:12 2026 GMT`). FastPanel had been failing auto-renewal for ~14 days because the SAN included `www.menu.labus.pro`, and ACME HTTP-01 validation for `www.` failed:

- Default nginx `/.well-known/acme-challenge/` location lives in `/etc/nginx/fastpanel2-includes/letsencrypt.conf` (`alias /usr/local/fastpanel2/web/letsencrypt/`).
- The site's per-host server-blocks (in `/etc/nginx/fastpanel2-sites/labus_pro_usr/menu.labus.pro.conf`) **did not include** that file.
- Result: ACME validator hit the `:80` server-block for `www.menu.labus.pro` → `301 https://menu.labus.pro/...` → `:443` server-block → `location /` → PHP-FPM → 404.
- LE marked validation as failed → entire renewal aborted (one failed SAN fails the whole cert).
- HSTS preload meant Chrome/Firefox blocked the site without the user being able to bypass.

**Fix applied (Phase 29-30)**:

1. Both `:80` server-blocks (apex + `www.`) now `include /etc/nginx/fastpanel2-includes/letsencrypt.conf;` **before** the `return 301` (FastPanel's location handler matches first because of `^~` prefix → ACME challenge files are served directly without redirect).
2. Cert reissued through FastPanel UI → success for both SANs.
3. Daily monitoring added (see §6.3).

### 6.1 Cert health check (one-shot)

```bash
echo "=== Cert ===" && \
echo | openssl s_client -servername menu.labus.pro -connect menu.labus.pro:443 2>/dev/null \
  | openssl x509 -noout -dates -ext subjectAltName ; \
echo && echo "=== Security headers ===" && \
curl -sI https://menu.labus.pro/menu.php \
  | grep -iE "^(strict-|x-frame|x-content|cross-origin|referrer|permissions|content-security|x-xss)" \
  | sort
```

`notAfter` should be ≥ 30 days in the future. SAN should include `DNS:menu.labus.pro` and `DNS:www.menu.labus.pro`. All 9 security headers should appear.

### 6.2 Force-renew procedure (when monitor alerts)

1. **FastPanel UI** — Sites → `menu.labus.pro` → SSL → "Issue / Renew Let's Encrypt".
2. If renewal fails for `www.` SAN — verify both `:80` server-blocks include `letsencrypt.conf`:
   ```bash
   sudo nginx -T 2>/dev/null | grep -A3 "listen.*:80" | grep -E "server_name|letsencrypt.conf"
   ```
3. If include is missing — re-add it in FastPanel "Custom config" (same pattern as `nginx-optimized.conf`):
   ```nginx
   server {
       listen 62.217.178.117:80;
       server_name menu.labus.pro;
       include /etc/nginx/fastpanel2-includes/letsencrypt.conf;
       location / { return 301 https://$host$request_uri; }
   }
   ```
4. After cert renewed, reload nginx: `sudo nginx -t && sudo systemctl reload nginx`.

### 6.3 Daily monitoring cron (proactive alerting)

```cron
# Daily 06:00 UTC — alert if any monitored cert expires within 30 days
0 6 * * * /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/security/cert-expiry-check.sh \
    >> /var/log/cleanmenu/cert-expiry.log 2>&1 \
    || /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/security/notify-telegram.sh \
       "🔥 cert-expiry alert — see /var/log/cleanmenu/cert-expiry.log"
```

The script `scripts/security/cert-expiry-check.sh` returns:
- `0` — every monitored domain has > 30 days until expiry
- `1` — at least one domain expires within 30 days (or already expired)
- `2` — at least one domain unreachable / cert unparseable

`WARN_DAYS=14` env var tightens the threshold; positional args replace the default domain list (`menu.labus.pro`, `www.menu.labus.pro`, `test.milyidom.com`).

The same script is also invoked by `scripts/security/monthly-review.sh` and its output appears in `output/security-monthly/<timestamp>/cert-expiry.txt`.

### 6.4 HSTS preload submission

`menu.labus.pro` already emits `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`. To get into Chrome/Firefox built-in preload list:

1. Pre-flight — verify **all** `*.labus.pro` subdomains are HTTPS-only:
   ```bash
   dig labus.pro NS
   # Manually verify each subdomain on FastPanel: app.labus.pro, www.labus.pro, etc.
   curl -sI http://app.labus.pro    # should be 301 → https://
   curl -sI http://www.labus.pro    # same
   ```
2. Submit at https://hstspreload.org/ → enter `menu.labus.pro` → wait 6-12 weeks for inclusion.
3. **Once submitted, removal takes months**. Do not submit if any subdomain might need plain HTTP in the next year.

### 6.5 Conflicting server name warnings

After deploys, `nginx -t` may emit:

```
nginx: [warn] conflicting server name "app.labus.pro" on 62.217.178.117:80, ignored
nginx: [warn] conflicting server name "www.labus.pro" on 62.217.178.117:80, ignored
```

These are **informational, not errors**. They mean two server-blocks declare the same `server_name`+`listen` pair (e.g. one in a default vhost and one site-specific) — nginx picks the first defined and ignores the duplicate. Investigation:

```bash
sudo grep -rn "server_name.*\(app\|www\)\.labus\.pro" /etc/nginx/ 2>/dev/null
```

Resolution: deduplicate by removing the duplicate `server_name` from the orphan vhost (typically the FastPanel default). Not blocking, but worth cleaning up.
