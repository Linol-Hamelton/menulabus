# Security Phase Commands (Server Runbook)

These commands are for controlled production execution, one phase at a time.

## Phase 0 - Baseline capture

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" rev-parse --short HEAD
runuser -u "$WEBUSER" -- git -C "$PROJECT" status --short
nginx -t
php -v || true
```

```bash
curl -sS -o /dev/null -w "menu.php status=%{http_code} time_total=%{time_total}\n" https://menu.labus.pro/menu.php
curl -sS -o /dev/null -w "api/menu status=%{http_code} time_total=%{time_total}\n" https://menu.labus.pro/api/v1/menu.php
curl -sS -I https://menu.labus.pro/menu.php | egrep -i "strict-transport-security|content-security-policy|x-frame-options|x-content-type-options|referrer-policy|cross-origin"
```

## Phase 1 - `phpinfo` exposure validation

```bash
curl -sS -I https://menu.labus.pro/phpinfo.php
```

Expected: `HTTP/2 404`.

## Phase 1A - Daily smoke + retention (cron)

Install/update daily cron entry (runs at `03:17` UTC by default):

```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
bash scripts/perf/install-security-smoke-cron.sh
```

Manual test run:

```bash
BASE_URL="https://menu.labus.pro" \
PROJECT_DIR="/var/www/labus_pro_usr/data/www/menu.labus.pro" \
LOG_DIR="/root" \
RETENTION_DAYS="14" \
bash /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/perf/security-smoke-daily.sh
```

Expected:

- log file `/root/security-smoke-<UTC>.log` is created
- output contains `status=PASS` or `status=FAIL`
- logs older than 14 days are deleted automatically

Daily top-3 checkout error reasons (last 24h):

```bash
php /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/perf/checkout-error-top.php \
  --log=/var/www/labus_pro_usr/data/logs/menu.labus.pro-php.error.log \
  --hours=24 \
  --top=3
```

## Phase 2 - Port/service inventory (read-only)

```bash
ss -lntp
```

Optional external check from trusted host:

```bash
nmap -Pn -p 21,22,25,80,443,3306,8080,8443 menu.labus.pro
```

Detailed runbook and table for this phase:

- `docs/security-phase-2-inventory.md`
- `scripts/perf/phase2-port-inventory.sh`

Example:

```bash
bash scripts/perf/phase2-port-inventory.sh menu.labus.pro
```

Important: before any future firewall edits, keep two active SSH sessions open.

## Phase 3 - SSH/fail2ban validation (after config by admin)

```bash
sshd -t
systemctl status ssh --no-pager
fail2ban-client status
```

## Phase 4 - Nginx safe reload flow

```bash
nginx -t && systemctl reload nginx
```

Then run:

```bash
bash scripts/perf/security-smoke.sh https://menu.labus.pro
```

If script path is unavailable on server, run commands from `docs/security-smoke-checklist.md` manually.

## Phase 4A - Menu-only exposure lock verification

```bash
for p in \
  /phpinfo.php \
  /db-indexes-optimizer.php \
  /db-indexes-optimizer-v2.php \
  /order_updates.php \
  /scripts/api-metrics-report.php \
  /scripts/api-smoke-runner.php
do
  echo "$p => $(curl -sS -o /dev/null -w "%{http_code}" "https://menu.labus.pro$p")"
done
```

Expected: all paths return `404`.

## Rollback by commit

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" log --oneline -n 20
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout <previous_stable_hash>
nginx -t && systemctl reload nginx
```

After rollback:

1. Reset OPcache via your standard monitor flow.
2. Re-run `docs/security-smoke-checklist.md`.
