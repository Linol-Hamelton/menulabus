#!/usr/bin/env bash
# cert-expiry-check.sh — alert when any monitored domain's TLS cert is
# within ${WARN_DAYS:-30} days of expiry, or already expired.
#
# Why this exists:
#   On 2026-05-06 the menu.labus.pro Let's Encrypt cert expired (FastPanel
#   auto-renewal had been failing for ~14 days because the www. SAN
#   couldn't be validated — nginx was missing the
#   /etc/nginx/fastpanel2-includes/letsencrypt.conf include in the :80
#   server-blocks). HSTS preload meant browsers hard-blocked the site.
#   Recovery took 3 days. This script ensures the next renewal failure is
#   caught with 30+ days lead time.
#
# Usage:
#   bash scripts/security/cert-expiry-check.sh                      # default domains
#   bash scripts/security/cert-expiry-check.sh menu.labus.pro foo   # custom list
#   WARN_DAYS=14 bash scripts/security/cert-expiry-check.sh         # tighter threshold
#
# Exit codes:
#   0 — every checked domain has > WARN_DAYS until expiry
#   1 — at least one domain expires within WARN_DAYS or already expired
#   2 — a domain could not be reached or returned no cert
#
# Recommended cron (daily 06:00, alert via Telegram if non-zero):
#   0 6 * * * /var/www/.../scripts/security/cert-expiry-check.sh \
#       || /var/www/.../scripts/security/notify-telegram.sh \
#         "🔥 cert-expiry alert — see /var/log/cleanmenu/cert-expiry.log"
#
set -uo pipefail

WARN_DAYS="${WARN_DAYS:-30}"
WARN_SECS=$((WARN_DAYS * 24 * 3600))

# Default monitored domains — adjust if more sites get added.
DEFAULT_DOMAINS=(
  "menu.labus.pro"
  "www.menu.labus.pro"
  "test.milyidom.com"
)

DOMAINS=("${@:-${DEFAULT_DOMAINS[@]}}")
if [ "$#" -gt 0 ]; then DOMAINS=("$@"); fi

OVERALL_RC=0
NOW_TS=$(date -u +%s)

printf "%-30s %-25s %-9s %s\n" "DOMAIN" "NOT_AFTER_UTC" "DAYS_LEFT" "STATUS"
printf "%-30s %-25s %-9s %s\n" "------" "-------------" "---------" "------"

for d in "${DOMAINS[@]}"; do
  raw=$(echo | timeout 8 openssl s_client -servername "$d" -connect "$d:443" 2>/dev/null \
        | openssl x509 -noout -enddate 2>/dev/null || true)
  if [ -z "$raw" ]; then
    printf "%-30s %-25s %-9s %s\n" "$d" "-" "-" "UNREACHABLE"
    OVERALL_RC=2
    continue
  fi

  not_after=${raw#notAfter=}
  exp_ts=$(date -d "$not_after" -u +%s 2>/dev/null || true)
  if [ -z "$exp_ts" ]; then
    printf "%-30s %-25s %-9s %s\n" "$d" "$not_after" "-" "PARSE_FAIL"
    OVERALL_RC=2
    continue
  fi

  diff=$(( exp_ts - NOW_TS ))
  days=$(( diff / 86400 ))

  if [ "$diff" -lt 0 ]; then
    status="EXPIRED"
    [ "$OVERALL_RC" -lt 1 ] && OVERALL_RC=1
  elif [ "$diff" -lt "$WARN_SECS" ]; then
    status="EXPIRING_SOON"
    [ "$OVERALL_RC" -lt 1 ] && OVERALL_RC=1
  else
    status="OK"
  fi

  printf "%-30s %-25s %-9s %s\n" "$d" "$(date -u -d "$not_after" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || echo "$not_after")" "$days" "$status"
done

exit "$OVERALL_RC"
