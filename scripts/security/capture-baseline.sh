#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

BASE_URL="${1:-https://menu.labus.pro}"
OUT_DIR="${2:-$ROOT_DIR/output/security-baselines/$(date -u +%Y%m%d-%H%M%S)}"

mkdir -p "$OUT_DIR"

{
  echo "generated_at_utc=$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  echo "base_url=$BASE_URL"
  echo "git_head=$(git rev-parse HEAD)"
  echo "git_branch=$(git rev-parse --abbrev-ref HEAD)"
} > "$OUT_DIR/meta.txt"

git status --short > "$OUT_DIR/git-status.txt" || true
git log --oneline -n 10 > "$OUT_DIR/git-log.txt" || true

nginx -t > "$OUT_DIR/nginx-test.txt" 2>&1 || true
(php -v || true) > "$OUT_DIR/php-version.txt" 2>&1
(openssl version || true) > "$OUT_DIR/openssl-version.txt" 2>&1

{
  curl -sS -o /dev/null -w "menu.php status=%{http_code} total=%{time_total}\n" "$BASE_URL/menu.php"
  curl -sS -o /dev/null -w "api/menu status=%{http_code} total=%{time_total}\n" "$BASE_URL/api/v1/menu.php"
  curl -sS -o /dev/null -w "auth status=%{http_code} total=%{time_total}\n" "$BASE_URL/auth.php"
} > "$OUT_DIR/http-quick.txt" 2>&1 || true

curl -sS -I "$BASE_URL/menu.php" > "$OUT_DIR/menu-headers.txt" 2>&1 || true
curl -sS -I "$BASE_URL/api/v1/menu.php" > "$OUT_DIR/api-headers.txt" 2>&1 || true

if [ -x "$ROOT_DIR/scripts/perf/security-smoke.sh" ]; then
  bash "$ROOT_DIR/scripts/perf/security-smoke.sh" "$BASE_URL" > "$OUT_DIR/security-smoke.txt" 2>&1 || true
fi

echo "$OUT_DIR"
