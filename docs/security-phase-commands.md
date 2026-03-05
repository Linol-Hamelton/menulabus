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
