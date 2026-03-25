#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'TXT'
Usage:
  sudo bash scripts/tenant/go-live.sh \
    --brand-name="Brand" \
    --brand-slug=brand \
    --domain=menu.brand.tld \
    --mode=tenant \
    --owner-email=owner@example.com \
    --tenant-db-user=db_user \
    --tenant-db-pass=db_pass \
    --certbot-email=ops@example.com \
    [--owner-password=secret] \
    [--seed-profile=restaurant-demo] \
    [--contact-phone=+79000000000] \
    [--contact-address="Москва, Цветной б-р, 24"] \
    [--contact-map-url=https://yandex.ru/maps/... ] \
    [--public-entry-mode=homepage] \
    [--project-root=/var/www/labus_pro_usr/data/www/menu.labus.pro] \
    [--webuser=labus_pro_usr] \
    [--php-fpm-service=php8.1-fpm] \
    [--provider-domain=menu.labus.pro] \
    [--skip-certbot]

The script:
  1. captures a baseline
  2. runs scripts/tenant/launch.php
  3. installs bootstrap + final Nginx vhost for the tenant domain
  4. issues/renews SSL via certbot
  5. restarts PHP-FPM, runs provider/tenant smoke, provider security smoke, and safe browser regression
  6. writes a go-live artifact JSON
TXT
}

require_root() {
  if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    echo "go-live.sh must run as root" >&2
    exit 1
  fi
}

select_php_bin() {
  local bin
  for bin in php8.5 php8.4 php8.3 php8.2 php; do
    if command -v "$bin" >/dev/null 2>&1; then
      echo "$bin"
      return 0
    fi
  done
  return 1
}

escape_sed() {
  printf '%s' "$1" | sed -e 's/[\/&]/\\&/g'
}

render_nginx_template() {
  local template_path="$1"
  local output_path="$2"
  local escaped_domain escaped_root
  escaped_domain="$(escape_sed "$DOMAIN")"
  escaped_root="$(escape_sed "$PROJECT_ROOT")"
  sed \
    -e "s/CUSTOM_DOMAIN/${escaped_domain}/g" \
    -e "s/PROJECT_ROOT/${escaped_root}/g" \
    "$template_path" > "$output_path"
}

require_root

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

BRAND_NAME=""
BRAND_SLUG=""
DOMAIN=""
MODE=""
OWNER_EMAIL=""
OWNER_PASSWORD=""
TENANT_DB_USER=""
TENANT_DB_PASS=""
SEED_PROFILE=""
CONTACT_PHONE=""
CONTACT_ADDRESS=""
CONTACT_MAP_URL=""
PUBLIC_ENTRY_MODE=""
CERTBOT_EMAIL=""
PROJECT_ROOT="${PROJECT_ROOT:-$ROOT_DIR}"
WEBUSER="${WEBUSER:-labus_pro_usr}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.1-fpm}"
PROVIDER_DOMAIN="${PROVIDER_DOMAIN:-menu.labus.pro}"
NGINX_SITES_AVAILABLE="${NGINX_SITES_AVAILABLE:-/etc/nginx/sites-available}"
NGINX_SITES_ENABLED="${NGINX_SITES_ENABLED:-/etc/nginx/sites-enabled}"
LAUNCH_ARTIFACT_DIR="${LAUNCH_ARTIFACT_DIR:-$PROJECT_ROOT/scripts/tenant/data/launch-artifacts}"
GO_LIVE_ARTIFACT_DIR="${GO_LIVE_ARTIFACT_DIR:-$PROJECT_ROOT/scripts/tenant/data/go-live-artifacts}"
SKIP_CERTBOT=0

for arg in "$@"; do
  case "$arg" in
    --brand-name=*) BRAND_NAME="${arg#*=}" ;;
    --brand-slug=*) BRAND_SLUG="${arg#*=}" ;;
    --domain=*) DOMAIN="${arg#*=}" ;;
    --mode=*) MODE="${arg#*=}" ;;
    --owner-email=*) OWNER_EMAIL="${arg#*=}" ;;
    --owner-password=*) OWNER_PASSWORD="${arg#*=}" ;;
    --tenant-db-user=*) TENANT_DB_USER="${arg#*=}" ;;
    --tenant-db-pass=*) TENANT_DB_PASS="${arg#*=}" ;;
    --seed-profile=*) SEED_PROFILE="${arg#*=}" ;;
    --contact-phone=*) CONTACT_PHONE="${arg#*=}" ;;
    --contact-address=*) CONTACT_ADDRESS="${arg#*=}" ;;
    --contact-map-url=*) CONTACT_MAP_URL="${arg#*=}" ;;
    --public-entry-mode=*) PUBLIC_ENTRY_MODE="${arg#*=}" ;;
    --certbot-email=*) CERTBOT_EMAIL="${arg#*=}" ;;
    --project-root=*) PROJECT_ROOT="${arg#*=}" ;;
    --webuser=*) WEBUSER="${arg#*=}" ;;
    --php-fpm-service=*) PHP_FPM_SERVICE="${arg#*=}" ;;
    --provider-domain=*) PROVIDER_DOMAIN="${arg#*=}" ;;
    --nginx-sites-available=*) NGINX_SITES_AVAILABLE="${arg#*=}" ;;
    --nginx-sites-enabled=*) NGINX_SITES_ENABLED="${arg#*=}" ;;
    --launch-artifact-dir=*) LAUNCH_ARTIFACT_DIR="${arg#*=}" ;;
    --go-live-artifact-dir=*) GO_LIVE_ARTIFACT_DIR="${arg#*=}" ;;
    --skip-certbot) SKIP_CERTBOT=1 ;;
    -h|--help) usage; exit 0 ;;
    *)
      echo "Unknown option: $arg" >&2
      usage
      exit 1
      ;;
  esac
done

for required in BRAND_NAME BRAND_SLUG DOMAIN MODE OWNER_EMAIL TENANT_DB_USER TENANT_DB_PASS; do
  if [ -z "${!required}" ]; then
    echo "Missing required option for $required" >&2
    usage
    exit 1
  fi
done

if [ "$SKIP_CERTBOT" -ne 1 ] && [ -z "$CERTBOT_EMAIL" ]; then
  echo "--certbot-email is required unless --skip-certbot is used" >&2
  exit 1
fi

PHP_BIN="$(select_php_bin || true)"
if [ -z "$PHP_BIN" ]; then
  echo "No PHP CLI binary found" >&2
  exit 1
fi

if ! command -v nginx >/dev/null 2>&1; then
  echo "nginx is required" >&2
  exit 1
fi

if [ "$SKIP_CERTBOT" -ne 1 ] && ! command -v certbot >/dev/null 2>&1; then
  echo "certbot is required unless --skip-certbot is used" >&2
  exit 1
fi

tmp_dir="$(mktemp -d)"
cleanup() {
  rm -rf "$tmp_dir"
}
trap cleanup EXIT

mkdir -p "$GO_LIVE_ARTIFACT_DIR"

timestamp="$(date -u +%Y%m%d-%H%M%S)"
baseline_pre_dir="$PROJECT_ROOT/output/security-baselines/pre-golive-${BRAND_SLUG}-${timestamp}"
baseline_post_dir="$PROJECT_ROOT/output/security-baselines/post-golive-${BRAND_SLUG}-${timestamp}"
launch_json="$tmp_dir/launch.json"
smoke_json="$tmp_dir/provider-tenant-smoke.json"
provider_security_smoke="$tmp_dir/provider-security-smoke.txt"
browser_regression_log="$tmp_dir/browser-regression.txt"
go_live_artifact="$GO_LIVE_ARTIFACT_DIR/go-live-${BRAND_SLUG}-${timestamp}.json"
nginx_conf_path="$NGINX_SITES_AVAILABLE/${DOMAIN}.conf"
nginx_enabled_path="$NGINX_SITES_ENABLED/${DOMAIN}.conf"

bash "$PROJECT_ROOT/scripts/security/capture-baseline.sh" "https://${PROVIDER_DOMAIN}" "$baseline_pre_dir" >/dev/null

launch_cmd=(
  "$PHP_BIN" "$PROJECT_ROOT/scripts/tenant/launch.php"
  "--brand-name=${BRAND_NAME}"
  "--brand-slug=${BRAND_SLUG}"
  "--domain=${DOMAIN}"
  "--mode=${MODE}"
  "--owner-email=${OWNER_EMAIL}"
  "--tenant-db-user=${TENANT_DB_USER}"
  "--tenant-db-pass=${TENANT_DB_PASS}"
  "--artifact-dir=${LAUNCH_ARTIFACT_DIR}"
)

[ -n "$OWNER_PASSWORD" ] && launch_cmd+=("--owner-password=${OWNER_PASSWORD}")
[ -n "$SEED_PROFILE" ] && launch_cmd+=("--seed-profile=${SEED_PROFILE}")
[ -n "$CONTACT_PHONE" ] && launch_cmd+=("--contact-phone=${CONTACT_PHONE}")
[ -n "$CONTACT_ADDRESS" ] && launch_cmd+=("--contact-address=${CONTACT_ADDRESS}")
[ -n "$CONTACT_MAP_URL" ] && launch_cmd+=("--contact-map-url=${CONTACT_MAP_URL}")
[ -n "$PUBLIC_ENTRY_MODE" ] && launch_cmd+=("--public-entry-mode=${PUBLIC_ENTRY_MODE}")

runuser -u "$WEBUSER" -- "${launch_cmd[@]}" > "$launch_json"

render_nginx_template "$PROJECT_ROOT/deploy/nginx/custom-domain-http-bootstrap.conf" "$nginx_conf_path"
ln -sfn "$nginx_conf_path" "$nginx_enabled_path"
nginx -t
systemctl reload nginx

if [ "$SKIP_CERTBOT" -ne 1 ]; then
  certbot certonly --webroot \
    -w "$PROJECT_ROOT" \
    -d "$DOMAIN" \
    --agree-tos \
    --non-interactive \
    -m "$CERTBOT_EMAIL" \
    --keep-until-expiring
fi

render_nginx_template "$PROJECT_ROOT/deploy/nginx/custom-domain-template.conf" "$nginx_conf_path"
nginx -t
systemctl reload nginx
systemctl restart "$PHP_FPM_SERVICE"

runuser -u "$WEBUSER" -- "$PHP_BIN" "$PROJECT_ROOT/scripts/tenant/smoke.php" \
  "--provider-domain=${PROVIDER_DOMAIN}" \
  "--tenant-domain=${DOMAIN}" > "$smoke_json"

bash "$PROJECT_ROOT/scripts/perf/security-smoke.sh" "https://${PROVIDER_DOMAIN}" > "$provider_security_smoke"
if [ -f "$PROJECT_ROOT/scripts/perf/post-release-regression.sh" ]; then
  CLEANMENU_PROVIDER_DOMAIN="$PROVIDER_DOMAIN" \
  CLEANMENU_TENANT_DOMAIN="$DOMAIN" \
  bash "$PROJECT_ROOT/scripts/perf/post-release-regression.sh" > "$browser_regression_log" 2>&1
fi
bash "$PROJECT_ROOT/scripts/security/capture-baseline.sh" "https://${PROVIDER_DOMAIN}" "$baseline_post_dir" >/dev/null

"$PHP_BIN" -r '
$launch = json_decode((string)file_get_contents($argv[1]), true);
$smoke = json_decode((string)file_get_contents($argv[2]), true);
$providerSecuritySmoke = (string)file_get_contents($argv[3]);
$browserRegression = is_file($argv[10]) ? (string)file_get_contents($argv[10]) : "";
$payload = [
  "kind" => "tenant_go_live_artifact",
  "generated_at" => gmdate("c"),
  "domain" => $argv[4],
  "php_fpm_service" => $argv[5],
  "nginx_conf" => $argv[6],
  "launch" => $launch,
  "provider_tenant_smoke" => $smoke,
  "provider_security_smoke" => $providerSecuritySmoke,
  "browser_regression" => $browserRegression,
  "baseline_pre" => $argv[7],
  "baseline_post" => $argv[8],
  "ssl_cert_path" => "/etc/letsencrypt/live/" . $argv[4] . "/fullchain.pem",
];
file_put_contents(
  $argv[9],
  json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
);
 ' "$launch_json" "$smoke_json" "$provider_security_smoke" "$DOMAIN" "$PHP_FPM_SERVICE" "$nginx_conf_path" "$baseline_pre_dir" "$baseline_post_dir" "$go_live_artifact" "$browser_regression_log"

echo "[go-live] launch artifact: $go_live_artifact"
