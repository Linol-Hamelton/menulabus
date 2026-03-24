# Security Phase Commands (Server Runbook)

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - Commands for Phase 1 and Phase 4A are relevant to implemented menu-only hardening.
  - Commands for later phases remain planning/runbook material, not proof of completed rollout.

## Phase 0 - Baseline Capture

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" rev-parse --short HEAD
runuser -u "$WEBUSER" -- git -C "$PROJECT" status --short
nginx -t
php -v || true
```

Preferred artifact-producing baseline command:

```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
bash scripts/security/capture-baseline.sh https://menu.labus.pro
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

Expected: `404`.

## Phase 1A - Daily smoke and retention

Install/update daily cron entry:

```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
bash scripts/perf/install-security-smoke-cron.sh
```

Manual test run:

```bash
BASE_URL="https://menu.labus.pro" \
PROJECT_DIR="/var/www/labus_pro_usr/data/www/menu.labus.pro" \
LOG_DIR="/var/www/labus_pro_usr/data/logs" \
RETENTION_DAYS="14" \
bash /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/perf/security-smoke-daily.sh
```

## Phase 2 - Port/service inventory (read-only)

```bash
ss -lntp
```

Optional external check from trusted host:

```bash
nmap -Pn -p 21,22,25,80,443,3306,8080,8443 menu.labus.pro
```

Detailed runbook:

- `docs/security-phase-2-inventory.md`

Important: before any firewall edits, keep two active SSH sessions open.

Repo-owned apply script:

```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
bash scripts/security/apply-network-policy.sh --ssh-port=22 --allow-tcp=25
```

## Phase 3 - SSH/fail2ban validation

```bash
sshd -t
systemctl status ssh --no-pager
fail2ban-client status
```

Use only if those controls are actually being rolled out by an admin.

Repo-owned hardening script:

```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
bash scripts/security/harden-ssh-fail2ban.sh --ssh-port=22 --allow-users=labus_pro_usr --disable-password-auth
```

## Phase 4 - Nginx safe reload flow

```bash
nginx -t && systemctl reload nginx
```

Then run:

```bash
bash scripts/perf/security-smoke.sh https://menu.labus.pro
```

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

Expected: all listed paths return `404`.

```bash
curl -sS -I https://menu.labus.pro/monitor.php
curl -sS -I https://menu.labus.pro/opcache-status.php
curl -sS -I "https://menu.labus.pro/file-manager.php?action=get_fonts"
curl -sS -I "https://menu.labus.pro/clear-cache.php?scope=server"
```

Expected:

- `monitor.php` => `302`
- `opcache-status.php` => `302`
- `file-manager.php?action=get_fonts` => `302`
- `clear-cache.php?scope=server` => `405`

## Rollback by Commit

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" log --oneline -n 20
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout <previous_stable_hash>
nginx -t && systemctl reload nginx
```

After rollback:

1. reset OPcache
2. rerun `docs/security-smoke-checklist.md`

## Phase 5 - Monthly review cadence

```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
bash scripts/security/monthly-review.sh https://menu.labus.pro
```
