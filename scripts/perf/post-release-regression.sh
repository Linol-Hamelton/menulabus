#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'TXT'
Usage:
  bash scripts/perf/post-release-regression.sh [--orders] [--require-provider-owner-auth]

Environment:
  CLEANMENU_PROVIDER_DOMAIN=menu.labus.pro
  CLEANMENU_TENANT_DOMAIN=test.milyidom.com
  CLEANMENU_PROVIDER_OWNER_EMAIL=<owner email>                 optional
  CLEANMENU_PROVIDER_OWNER_PASSWORD=<owner password>           optional
  CLEANMENU_REQUIRE_PROVIDER_OWNER_AUTH=1                      fail if provider owner creds are missing
  CLEANMENU_RUN_ORDER_REGRESSION=1                             create and process tenant test orders
  CLEANMENU_REQUIRE_BROWSER_REGRESSION=1                       fail instead of skipping when Playwright runtime is unavailable
  CLEANMENU_REGRESSION_OUT_DIR=/custom/output/path             optional

Notes:
  - safe browser regression is non-destructive by default
  - order lifecycle coverage is mutating and runs only with --orders or CLEANMENU_RUN_ORDER_REGRESSION=1
  - provider owner authenticated checks run only when credentials are supplied
  - every safe run now writes a mandatory desktop/mobile visual sign-off screenshot set and checklist into the report directory
TXT
}

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

for arg in "$@"; do
  case "$arg" in
    --orders) export CLEANMENU_RUN_ORDER_REGRESSION=1 ;;
    --require-provider-owner-auth) export CLEANMENU_REQUIRE_PROVIDER_OWNER_AUTH=1 ;;
    -h|--help) usage; exit 0 ;;
    *)
      echo "Unknown option: $arg" >&2
      usage
      exit 1
      ;;
  esac
done

if ! command -v node >/dev/null 2>&1; then
  if [ "${CLEANMENU_REQUIRE_BROWSER_REGRESSION:-0}" = "1" ]; then
    echo "[post-release-regression] node is required" >&2
    exit 1
  fi
  echo "[post-release-regression] node not found; skipping browser regression"
  exit 0
fi

if ! node -e "require('playwright')" >/dev/null 2>&1; then
  if [ "${CLEANMENU_REQUIRE_BROWSER_REGRESSION:-0}" = "1" ]; then
    echo "[post-release-regression] playwright runtime is required" >&2
    exit 1
  fi
  echo "[post-release-regression] playwright runtime not available; skipping browser regression"
  exit 0
fi

echo "[post-release-regression] Running browser regression suite..."
node scripts/perf/post-release-regression.cjs
echo "[post-release-regression] Browser regression OK"
