#!/usr/bin/env bash
set -uo pipefail

BASE_URL="${BASE_URL:-https://menu.labus.pro}"
PROJECT_DIR="${PROJECT_DIR:-/var/www/labus_pro_usr/data/www/menu.labus.pro}"
LOG_DIR="${LOG_DIR:-/var/www/labus_pro_usr/data/logs}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"

SMOKE_SCRIPT="$PROJECT_DIR/scripts/perf/security-smoke.sh"
STAMP_UTC="$(date -u +%F-%H%M)"
LOG_FILE="$LOG_DIR/security-smoke-$STAMP_UTC.log"

mkdir -p "$LOG_DIR"

run_status=0

{
  echo "=== security-smoke daily run ==="
  echo "ts_utc=$(date -u +'%F %T')"
  echo "base_url=$BASE_URL"
  echo "project_dir=$PROJECT_DIR"
  echo "retention_days=$RETENTION_DAYS"

  if [ ! -x "$SMOKE_SCRIPT" ] && [ ! -f "$SMOKE_SCRIPT" ]; then
    echo "status=FAIL reason=smoke_script_not_found path=$SMOKE_SCRIPT"
    run_status=2
  else
    if bash "$SMOKE_SCRIPT" "$BASE_URL"; then
      echo "status=PASS"
      run_status=0
    else
      echo "status=FAIL reason=security_smoke_failed"
      run_status=1
    fi
  fi

  # Retention cleanup (best effort).
  find "$LOG_DIR" -maxdepth 1 -type f -name 'security-smoke-*.log' -mtime +"$RETENTION_DAYS" -print -delete || true
  echo "exit_code=$run_status"
} >"$LOG_FILE" 2>&1

cat "$LOG_FILE"
exit "$run_status"
