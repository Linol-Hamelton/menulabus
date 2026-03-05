#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-https://menu.labus.pro}"

echo "[1/5] Core availability"
menu_code="$(curl -sS -o /dev/null -w "%{http_code}" "$BASE_URL/menu.php")"
api_code="$(curl -sS -o /dev/null -w "%{http_code}" "$BASE_URL/api/v1/menu.php")"
echo "menu.php: $menu_code"
echo "api/v1/menu.php: $api_code"
[[ "$menu_code" == "200" ]]
[[ "$api_code" == "200" ]]

echo "[2/6] Public diagnostic/admin endpoint exposure"
declare -a locked_paths=(
  "/phpinfo.php"
  "/db-indexes-optimizer.php"
  "/db-indexes-optimizer-v2.php"
  "/order_updates.php"
  "/scripts/api-metrics-report.php"
  "/scripts/api-smoke-runner.php"
)
for p in "${locked_paths[@]}"; do
  code="$(curl -sS -o /dev/null -w "%{http_code}" "$BASE_URL$p")"
  echo "$p: $code"
  [[ "$code" == "404" ]]
done

echo "[3/6] Security headers snapshot (/menu.php)"
curl -sS -I "$BASE_URL/menu.php" | egrep -i "strict-transport-security|content-security-policy|x-frame-options|x-content-type-options|referrer-policy|cross-origin" || true

echo "[4/6] API cache/header snapshot (/api/v1/menu.php)"
for i in {1..5}; do
  curl -sS -D - -o /dev/null "$BASE_URL/api/v1/menu.php" | egrep -i "cache-control|x-cache-status" || true
done

echo "[5/6] clear-cache cookie flags snapshot"
curl -sS -D - -o /dev/null "$BASE_URL/clear-cache.php" | egrep -i "set-cookie|cache-control|pragma" || true

echo "[6/6] Done"
echo "Security smoke passed for $BASE_URL"
