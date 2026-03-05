#!/usr/bin/env bash
set -euo pipefail

TARGET_HOST="${1:-menu.labus.pro}"
PORTS=(21 22 25 80 443 3306 8080 8443)

echo "=== Phase 2 Inventory (read-only) ==="
echo "Host: $TARGET_HOST"
echo "Date UTC: $(date -u '+%Y-%m-%d %H:%M:%S')"
echo

echo "=== 1) Listening sockets (ss -lntup) ==="
if command -v ss >/dev/null 2>&1; then
  ss -lntup || true
else
  echo "ss not found"
fi
echo

echo "=== 2) Target ports ownership ==="
if command -v ss >/dev/null 2>&1; then
  for p in "${PORTS[@]}"; do
    echo "----- PORT $p -----"
    ss -lntup "( sport = :$p )" || true
  done
else
  echo "ss not found; skipping per-port ownership check"
fi
echo

echo "=== 3) lsof listen snapshot (optional) ==="
if command -v lsof >/dev/null 2>&1; then
  lsof -nP -iTCP -sTCP:LISTEN || true
else
  echo "lsof not found"
fi
echo

echo "=== 4) Running services snapshot ==="
if command -v systemctl >/dev/null 2>&1; then
  systemctl --type=service --state=running --no-pager || true
  echo
  systemctl status nginx mysql mariadb php-fpm ssh fail2ban --no-pager 2>/dev/null || true
else
  echo "systemctl not found"
fi
echo

echo "=== 5) Firewall policy snapshot ==="
if command -v iptables >/dev/null 2>&1; then
  echo "--- iptables -S ---"
  iptables -S || true
fi
if command -v nft >/dev/null 2>&1; then
  echo "--- nft list ruleset ---"
  nft list ruleset || true
fi
if ! command -v iptables >/dev/null 2>&1 && ! command -v nft >/dev/null 2>&1; then
  echo "No iptables/nft command found"
fi
echo

echo "=== 6) External scan (optional, from this host) ==="
if command -v nmap >/dev/null 2>&1; then
  nmap -Pn -p 21,22,25,80,443,3306,8080,8443 "$TARGET_HOST" || true
else
  echo "nmap not found"
fi
echo

echo "Inventory collection finished."
