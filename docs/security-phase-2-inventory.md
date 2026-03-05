# Phase 2: Port and Service Inventory (Read-Only)

This phase is inventory only.  
Do not apply firewall or service changes in this phase.

## Safety precondition

Before any later firewall step:

1. Keep **two active SSH sessions** open.
2. In session B, verify reconnect works with current credentials.
3. Only then proceed to any network policy change.

## 1) Server-local port ownership

Run on server:

```bash
ss -lntup
```

Focused check for target ports:

```bash
for p in 21 22 25 80 443 3306 8080 8443; do
  echo "===== PORT $p ====="
  ss -lntup "( sport = :$p )" || true
done
```

Optional (if installed):

```bash
lsof -nP -iTCP -sTCP:LISTEN
```

## 2) Service mapping

```bash
systemctl --type=service --state=running --no-pager
```

Targeted units (if present):

```bash
systemctl status nginx mysql mariadb php-fpm ssh fail2ban --no-pager 2>/dev/null || true
```

## 3) Current network policy snapshot

If iptables is used:

```bash
iptables -S
```

If nftables is used:

```bash
nft list ruleset
```

## 4) External exposure check (from trusted external host)

```bash
nmap -Pn -p 21,22,25,80,443,3306,8080,8443 menu.labus.pro
```

## 5) Fill inventory table

| Port | Listening process | Service owner/purpose | Publicly reachable | Action recommendation |
|---|---|---|---|---|
| 21 |  |  | yes/no | close or restrict |
| 22 |  |  | yes/no | keep, harden |
| 25 |  |  | yes/no | keep/restrict |
| 80 |  |  | yes/no | keep (redirect) |
| 443 |  |  | yes/no | keep |
| 3306 |  |  | yes/no | close external access |
| 8080 |  |  | yes/no | close or restrict |
| 8443 |  |  | yes/no | close or restrict |

## Exit criteria for Phase 2

1. Every target port has an identified owner and business purpose.
2. Public exposure status is confirmed from external scan.
3. Draft allowlist policy exists for required ports.
4. No changes applied yet (inventory-only phase).
