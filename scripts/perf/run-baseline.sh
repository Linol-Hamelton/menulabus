#!/usr/bin/env bash
set -euo pipefail

# Minimal baseline runner for menu.labus.pro
# Usage:
#   HOST=https://menu.labus.pro ./scripts/perf/run-baseline.sh
#
# Requires: curl, wrk

HOST="${HOST:-https://menu.labus.pro}"
OUT_DIR="${OUT_DIR:-./perf-results/$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT_DIR"

echo "HOST=$HOST" | tee "$OUT_DIR/meta.txt"
echo "OUT_DIR=$OUT_DIR" | tee -a "$OUT_DIR/meta.txt"

curl -sk "$HOST/version.json" > "$OUT_DIR/version.json" || true

echo "--- curl quick ---" | tee "$OUT_DIR/curl-quick.txt"
for u in / /menu.php /menu-public.php /api/v1/menu.php; do
  curl -sk -o /dev/null -w "url=%{url_effective} code=%{http_code} ttfb=%{time_starttransfer}s total=%{time_total}s size=%{size_download}\n" \
    "$HOST$u" | tee -a "$OUT_DIR/curl-quick.txt"
done

echo "--- wrk anonymous menu.php ---" | tee "$OUT_DIR/wrk-menu-anon.txt"
wrk -t4 -c50 -d30s --latency "$HOST/menu.php" | tee -a "$OUT_DIR/wrk-menu-anon.txt"

echo "--- wrk api menu ---" | tee "$OUT_DIR/wrk-api-menu.txt"
wrk -t4 -c50 -d30s --latency "$HOST/api/v1/menu.php" | tee -a "$OUT_DIR/wrk-api-menu.txt"

echo "Done. Results in $OUT_DIR"

