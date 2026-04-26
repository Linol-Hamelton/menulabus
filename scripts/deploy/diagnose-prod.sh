#!/usr/bin/env bash
#
# diagnose-prod.sh — read-only discovery for the upcoming Top-1 SaaS rollout.
#
# Run as the web user (or root) on the production host:
#
#   bash scripts/deploy/diagnose-prod.sh
#
# Prints a structured report to stdout. Touches nothing — safe to run on a
# live server. Goal: confirm project root, web user, PHP-FPM pool, MySQL
# tenant DB list, current git HEAD, and missing migrations relative to the
# release branch tip.

set -u

PROVIDER_DEFAULT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

# ── header ────────────────────────────────────────────────────────────────
hr() { printf '%s\n' "================================================================"; }
hdr() { hr; echo "$1"; hr; }

hdr "1. HOST CONTEXT"
echo "hostname: $(hostname -f 2>/dev/null || hostname)"
echo "kernel:   $(uname -r)"
echo "user:     $(whoami)"
echo "date:     $(date -u +%Y-%m-%dT%H:%M:%SZ) (UTC)"
echo "PHP CLI:  $(command -v php8.4 php8.3 php8.2 php8.1 php 2>/dev/null | head -1)"
[ -x "$(command -v php 2>/dev/null)" ] && php -v 2>&1 | head -1

# ── project roots ────────────────────────────────────────────────────────
hdr "2. PROJECT ROOTS UNDER /var/www/*/data/www/"
if [ -d /var/www ]; then
  find /var/www -maxdepth 4 -type d -name www 2>/dev/null \
    | head -20 \
    | while read -r www; do
        for d in "$www"/*; do
          [ -d "$d/.git" ] && echo "GIT  $d  ($(GIT_DIR=$d/.git git rev-parse --abbrev-ref HEAD 2>/dev/null) @ $(GIT_DIR=$d/.git git rev-parse --short HEAD 2>/dev/null))"
        done
      done
else
  echo "(no /var/www on this host — likely a different layout)"
fi

# ── default provider check ───────────────────────────────────────────────
hdr "3. PROVIDER PATH ($PROVIDER_DEFAULT)"
if [ -d "$PROVIDER_DEFAULT/.git" ]; then
  cd "$PROVIDER_DEFAULT" || exit 1
  echo "branch:    $(git rev-parse --abbrev-ref HEAD 2>&1)"
  echo "HEAD:      $(git rev-parse HEAD 2>&1)"
  echo "HEAD msg:  $(git log -1 --pretty=%s 2>&1)"
  echo "remotes:   $(git remote -v | head -2)"
  echo "owner:     $(stat -c '%U:%G' . 2>/dev/null)"
  echo "perms:     $(stat -c '%a' . 2>/dev/null)"
  echo "size:      $(du -sh . 2>/dev/null | cut -f1)"
  echo
  echo "config_copy.php (DB creds expected here):"
  ls -la ../config_copy.php 2>/dev/null || echo "  (not found at ../config_copy.php)"
  echo
  echo "data/logs writable?"
  [ -d data/logs ] && stat -c '  %a %U:%G %n' data/logs || echo "  (data/logs missing — workers will fail to write)"
else
  echo "(provider repo not at default path — adjust PROVIDER_DEFAULT)"
fi

# ── all sql/ files currently on the server ───────────────────────────────
hdr "4. SQL MIGRATIONS PRESENT IN PROVIDER REPO"
if [ -d "$PROVIDER_DEFAULT/sql" ]; then
  ls -1 "$PROVIDER_DEFAULT/sql/"*.sql 2>/dev/null \
    | sed "s|$PROVIDER_DEFAULT/||"
else
  echo "(no sql/ dir under provider path)"
fi

# ── tenant project roots (heuristic: any *.com / *.ru / etc next to provider) ──
hdr "5. TENANT PROJECT ROOTS (heuristic)"
PROVIDER_USR_DIR="$(dirname "$(dirname "$PROVIDER_DEFAULT")")"   # /var/www/labus_pro_usr/data
TENANT_USR_PARENT="$(dirname "$(dirname "$PROVIDER_USR_DIR")")"  # /var/www
echo "scanning: $TENANT_USR_PARENT/*/data/www/"
find "$TENANT_USR_PARENT" -maxdepth 4 -type d -name www 2>/dev/null \
  | while read -r www; do
      for d in "$www"/*; do
        [ -d "$d/.git" ] || continue
        [ "$d" = "$PROVIDER_DEFAULT" ] && continue
        echo "  $d"
      done
    done

# ── nginx server names (which domains are wired up) ──────────────────────
hdr "6. NGINX SERVER_NAME ENTRIES"
if [ -d /etc/nginx ]; then
  grep -RhE "^\s*server_name\s" /etc/nginx/conf.d /etc/nginx/sites-enabled \
       /etc/nginx/sites-available /etc/nginx/vhosts 2>/dev/null \
    | grep -v "^\s*#" | sort -u | head -30
else
  echo "(no /etc/nginx — different web server?)"
fi

# ── PHP-FPM pools ────────────────────────────────────────────────────────
hdr "7. PHP-FPM POOLS"
for d in /etc/php/8.1/fpm/pool.d /etc/php/8.2/fpm/pool.d /etc/php/8.3/fpm/pool.d /etc/php-fpm.d; do
  [ -d "$d" ] || continue
  echo "[$d]"
  ls -1 "$d"/*.conf 2>/dev/null | sed 's|^|  |'
done

# ── MySQL tenant DBs ─────────────────────────────────────────────────────
hdr "8. MYSQL DATABASES (menu_*) — uses ~/.my.cnf if present"
if command -v mysql >/dev/null 2>&1; then
  mysql -e "SHOW DATABASES" 2>/dev/null \
    | awk 'NR>1 && $1 ~ /^menu_/ { print "  " $1 }' \
    || echo "(mysql client present but query failed — check ~/.my.cnf or pass creds)"
else
  echo "(mysql client missing — install mariadb-client or run from a host with access)"
fi

# ── crontab(s) ───────────────────────────────────────────────────────────
hdr "9. CRONTABS (current user + system)"
echo "[user $(whoami)]"
crontab -l 2>/dev/null | grep -vE "^\s*(#|$)" | head -20 || echo "  (none)"
echo
echo "[/etc/cron.d]"
ls -1 /etc/cron.d 2>/dev/null | head -10

# ── what would be deployed ───────────────────────────────────────────────
hdr "10. PROVIDER vs RELEASE TIP — files to update"
if [ -d "$PROVIDER_DEFAULT/.git" ]; then
  cd "$PROVIDER_DEFAULT" || exit 1
  git fetch --all --quiet 2>&1
  RELEASE_BRANCH="release/bottom-dock-owner-toolbar-2026-03-26"
  if git rev-parse --verify "origin/$RELEASE_BRANCH" >/dev/null 2>&1; then
    AHEAD=$(git rev-list --count "HEAD..origin/$RELEASE_BRANCH" 2>/dev/null)
    BEHIND=$(git rev-list --count "origin/$RELEASE_BRANCH..HEAD" 2>/dev/null)
    echo "ahead-of-prod: $AHEAD commits will arrive"
    echo "behind:        $BEHIND commits would need rebase (should be 0)"
    echo
    echo "SQL migrations new vs prod:"
    git diff --name-only HEAD..origin/$RELEASE_BRANCH -- sql/ 2>&1 | sort
  else
    echo "(release branch not yet fetched — run: git fetch origin)"
  fi
fi

hr
echo "DONE. Send this whole output back."
