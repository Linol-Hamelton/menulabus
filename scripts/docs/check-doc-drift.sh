#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

collect_changed_files() {
  if [ "$#" -gt 0 ]; then
    local range
    for range in "$@"; do
      git diff --name-only --diff-filter=ACMR "$range" || true
    done
  else
    git diff --cached --name-only --diff-filter=ACMR || true
  fi | awk 'NF' | sort -u
}

is_doc_file() {
  case "$1" in
    docs/*|README.md)
      return 0
      ;;
  esac
  return 1
}

classify_surface() {
  case "$1" in
    account.php|account-header.php|admin-menu.php|owner.php|employee.php|customer_orders.php|cart.php|qr-print.php|help.php|partials/*|css/*|lib/orders/*|stale-order-cleanup.php|scripts/orders/*)
      echo "shell"
      return 0
      ;;
    index.php|session_init.php|tenant_runtime.php|save-brand.php|lib/tenant/*|scripts/tenant/*)
      echo "launch"
      return 0
      ;;
    api/v1/*|payment-*.php|generate-payment-link.php|confirm-cash-payment.php|telegram-*.php|*oauth*.php|orders-sse.php|ws-poll.php)
      echo "integrations"
      return 0
      ;;
    deploy/*|.githooks/*|monitor.php|opcache-status.php|clear-cache.php|file-manager.php|lib/ops/*|lib/admin/*|scripts/perf/*|scripts/security/*)
      echo "ops"
      return 0
      ;;
  esac
  return 1
}

mapfile -t changed_files < <(collect_changed_files "$@")

if [ "${#changed_files[@]}" -eq 0 ]; then
  echo "[docs-drift] No changed files to inspect"
  exit 0
fi

docs_changed=0
declare -A surfaces_seen=()
for file in "${changed_files[@]}"; do
  if is_doc_file "$file"; then
    docs_changed=1
    continue
  fi

  if surface="$(classify_surface "$file")"; then
    surfaces_seen["$surface"]=1
  fi
done

if [ "${#surfaces_seen[@]}" -eq 0 ]; then
  echo "[docs-drift] No contract-bearing surfaces changed"
  exit 0
fi

if [ "$docs_changed" -eq 1 ]; then
  echo "[docs-drift] Docs updated alongside contract-bearing changes"
  exit 0
fi

echo "[docs-drift] ERROR: contract-bearing changes were detected without docs updates."
echo "[docs-drift] Changed surfaces:"
for surface in "${!surfaces_seen[@]}"; do
  echo "  - $surface"
done | sort

echo "[docs-drift] Update at least one relevant doc before pushing release/main."
echo "[docs-drift] Suggested docs:"
echo "  - docs/feature-audit-matrix.md"
echo "  - docs/project-reference.md"
echo "  - docs/project-improvement-roadmap.md"
echo "  - docs/ux-ui-improvement-roadmap.md"
echo "  - docs/tenant-launch-checklist.md"
echo "  - docs/deployment-workflow.md"
echo "  - docs/security-hardening-roadmap.md"
exit 1
