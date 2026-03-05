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

echo "[2/5] phpinfo exposure"
phpinfo_code="$(curl -sS -o /dev/null -w "%{http_code}" "$BASE_URL/phpinfo.php")"
echo "phpinfo.php: $phpinfo_code"
[[ "$phpinfo_code" == "404" ]]

echo "[3/5] Security headers snapshot (/menu.php)"
curl -sS -I "$BASE_URL/menu.php" | egrep -i "strict-transport-security|content-security-policy|x-frame-options|x-content-type-options|referrer-policy|cross-origin" || true

echo "[4/5] API cache/header snapshot (/api/v1/menu.php)"
for i in {1..5}; do
  curl -sS -D - -o /dev/null "$BASE_URL/api/v1/menu.php" | egrep -i "cache-control|x-cache-status" || true
done

echo "[5/5] Done"
echo "Security smoke passed for $BASE_URL"
