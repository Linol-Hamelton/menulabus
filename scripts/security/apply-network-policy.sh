#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'TXT'
Usage:
  bash scripts/security/apply-network-policy.sh [--apply] [--ssh-port=22] [--allow-tcp=25,587]

Default behavior is dry-run. The script targets UFW and keeps only:
  - SSH port
  - 80/tcp
  - 443/tcp
  - optional extra TCP ports passed via --allow-tcp

Safety rule:
  Keep two active SSH sessions open before using --apply.
TXT
}

APPLY=0
SSH_PORT=22
EXTRA_TCP=""

for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --ssh-port=*) SSH_PORT="${arg#*=}" ;;
    --allow-tcp=*) EXTRA_TCP="${arg#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *)
      echo "Unknown option: $arg" >&2
      usage
      exit 1
      ;;
  esac
done

if ! [[ "$SSH_PORT" =~ ^[0-9]+$ ]]; then
  echo "Invalid --ssh-port value" >&2
  exit 1
fi

IFS=',' read -r -a extra_ports <<< "$EXTRA_TCP"
declare -a allow_ports=("$SSH_PORT" "80" "443")
for port in "${extra_ports[@]}"; do
  [ -n "$port" ] || continue
  if ! [[ "$port" =~ ^[0-9]+$ ]]; then
    echo "Invalid extra TCP port: $port" >&2
    exit 1
  fi
  allow_ports+=("$port")
done

echo "[network-policy] target ports: ${allow_ports[*]}"
echo "[network-policy] mode: $([ "$APPLY" -eq 1 ] && echo apply || echo dry-run)"

if ! command -v ufw >/dev/null 2>&1; then
  echo "[network-policy] ERROR: ufw not found" >&2
  exit 1
fi

declare -a commands=(
  "ufw --force reset"
  "ufw default deny incoming"
  "ufw default allow outgoing"
)

for port in "${allow_ports[@]}"; do
  commands+=("ufw allow ${port}/tcp")
done

commands+=(
  "ufw --force enable"
  "ufw status verbose"
)

if [ "$APPLY" -ne 1 ]; then
  printf '%s\n' "${commands[@]}"
  exit 0
fi

snapshot_dir="$(pwd)/output/security-network/$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$snapshot_dir"
ufw status verbose > "$snapshot_dir/ufw-before.txt" 2>&1 || true
ss -lntup > "$snapshot_dir/ss-before.txt" 2>&1 || true

for cmd in "${commands[@]}"; do
  echo "[network-policy] $cmd"
  eval "$cmd"
done

ufw status verbose > "$snapshot_dir/ufw-after.txt" 2>&1 || true
ss -lntup > "$snapshot_dir/ss-after.txt" 2>&1 || true
echo "[network-policy] snapshots: $snapshot_dir"
