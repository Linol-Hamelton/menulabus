#!/usr/bin/env bash
set -euo pipefail

CRON_EXPR="${CRON_EXPR:-17 3 * * *}"
BASE_URL="${BASE_URL:-https://menu.labus.pro}"
PROJECT_DIR="${PROJECT_DIR:-/var/www/labus_pro_usr/data/www/menu.labus.pro}"
LOG_DIR="${LOG_DIR:-/root}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
SCRIPT_PATH="$PROJECT_DIR/scripts/perf/security-smoke-daily.sh"
TAG="# menu-security-smoke-daily"

if [ ! -f "$SCRIPT_PATH" ]; then
  echo "ERROR: script not found: $SCRIPT_PATH"
  exit 1
fi

tmp_file="$(mktemp)"
cleanup() {
  rm -f "$tmp_file"
}
trap cleanup EXIT

crontab -l 2>/dev/null | grep -v "$TAG" >"$tmp_file" || true
echo "$CRON_EXPR BASE_URL=$BASE_URL PROJECT_DIR=$PROJECT_DIR LOG_DIR=$LOG_DIR RETENTION_DAYS=$RETENTION_DAYS /usr/bin/env bash $SCRIPT_PATH $TAG" >>"$tmp_file"
crontab "$tmp_file"

echo "Installed cron job:"
crontab -l | grep "$TAG" || true
