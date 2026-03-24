#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

BASE_URL="${1:-https://menu.labus.pro}"
OUT_DIR="${2:-$ROOT_DIR/output/security-monthly/$(date -u +%Y%m%d-%H%M%S)}"

mkdir -p "$OUT_DIR"

{
  echo "generated_at_utc=$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  echo "base_url=$BASE_URL"
  echo "git_head=$(git rev-parse HEAD)"
  echo "git_branch=$(git rev-parse --abbrev-ref HEAD)"
} > "$OUT_DIR/meta.txt"

(uname -a || true) > "$OUT_DIR/uname.txt" 2>&1
(php -v || true) > "$OUT_DIR/php.txt" 2>&1
(nginx -v || true) > "$OUT_DIR/nginx.txt" 2>&1
(mysql --version || mariadb --version || true) > "$OUT_DIR/mysql.txt" 2>&1

if command -v apt-get >/dev/null 2>&1; then
  (apt-get -s upgrade || true) > "$OUT_DIR/apt-upgrade-simulated.txt" 2>&1
fi

if command -v systemctl >/dev/null 2>&1; then
  systemctl status nginx mysql mariadb php-fpm php8.1-fpm ssh fail2ban --no-pager > "$OUT_DIR/services.txt" 2>&1 || true
fi

if [ -x "$ROOT_DIR/scripts/perf/security-smoke.sh" ]; then
  bash "$ROOT_DIR/scripts/perf/security-smoke.sh" "$BASE_URL" > "$OUT_DIR/security-smoke.txt" 2>&1 || true
fi

if command -v php >/dev/null 2>&1 && [ -f "$ROOT_DIR/scripts/tenant/smoke.php" ]; then
  php "$ROOT_DIR/scripts/tenant/smoke.php" --provider-domain=menu.labus.pro --tenant-domain=test.milyidom.com > "$OUT_DIR/provider-tenant-smoke.json" 2>&1 || true
fi

echo "$OUT_DIR"
