#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'TXT'
Usage:
  bash scripts/security/harden-ssh-fail2ban.sh [--apply] [--ssh-port=22] [--allow-users=user1,user2] [--disable-password-auth]

Default behavior is dry-run. On apply, the script writes:
  - /etc/ssh/sshd_config.d/99-cleanmenu-hardening.conf
  - /etc/fail2ban/jail.d/cleanmenu-sshd.conf

Safety rule:
  Keep two active SSH sessions open and verify key-based login before disabling password auth.
TXT
}

APPLY=0
SSH_PORT=22
ALLOW_USERS=""
DISABLE_PASSWORD_AUTH=0

for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --ssh-port=*) SSH_PORT="${arg#*=}" ;;
    --allow-users=*) ALLOW_USERS="${arg#*=}" ;;
    --disable-password-auth) DISABLE_PASSWORD_AUTH=1 ;;
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

ssh_conf="Port ${SSH_PORT}
PermitRootLogin no
MaxAuthTries 4
LoginGraceTime 30
ClientAliveInterval 300
ClientAliveCountMax 2
UsePAM yes"

if [ -n "$ALLOW_USERS" ]; then
  ssh_conf="${ssh_conf}
AllowUsers ${ALLOW_USERS//,/ }"
fi

if [ "$DISABLE_PASSWORD_AUTH" -eq 1 ]; then
  ssh_conf="${ssh_conf}
PasswordAuthentication no
KbdInteractiveAuthentication no"
fi

fail2ban_conf="[sshd]
enabled = true
port = ${SSH_PORT}
backend = systemd
maxretry = 5
findtime = 10m
bantime = 1h"

echo "[ssh-hardening] mode: $([ "$APPLY" -eq 1 ] && echo apply || echo dry-run)"
echo "[ssh-hardening] sshd drop-in:"
printf '%s\n' "$ssh_conf"
echo
echo "[ssh-hardening] fail2ban jail:"
printf '%s\n' "$fail2ban_conf"

if [ "$APPLY" -ne 1 ]; then
  exit 0
fi

if ! command -v sshd >/dev/null 2>&1; then
  echo "[ssh-hardening] ERROR: sshd not found" >&2
  exit 1
fi

mkdir -p /etc/ssh/sshd_config.d /etc/fail2ban/jail.d
printf '%s\n' "$ssh_conf" > /etc/ssh/sshd_config.d/99-cleanmenu-hardening.conf
printf '%s\n' "$fail2ban_conf" > /etc/fail2ban/jail.d/cleanmenu-sshd.conf

sshd -t

if command -v apt-get >/dev/null 2>&1; then
  DEBIAN_FRONTEND=noninteractive apt-get update -y >/dev/null
  DEBIAN_FRONTEND=noninteractive apt-get install -y fail2ban >/dev/null
fi

systemctl restart ssh
systemctl enable fail2ban >/dev/null 2>&1 || true
systemctl restart fail2ban
systemctl status ssh --no-pager
fail2ban-client status sshd || fail2ban-client status
