# Phase 2: Port and Service Inventory (Read-Only)

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - This document is an inventory/runbook artifact.
  - It does not prove that firewall restrictions or final network policy are implemented.

## Safety Precondition

Before any later firewall step:

1. keep two active SSH sessions open
2. verify reconnect works in the second session
3. only then proceed to any network policy change

## 1. Server-Local Port Ownership

Run on server:

```bash
ss -lntup
```

Focused check:

```bash
for p in 21 22 25 80 443 3306 8080 8443; do
  echo "===== PORT $p ====="
  ss -lntup "( sport = :$p )" || true
done
```

Optional:

```bash
lsof -nP -iTCP -sTCP:LISTEN
```

## 2. Service Mapping

```bash
systemctl --type=service --state=running --no-pager
```

Targeted units:

```bash
systemctl status nginx mysql mariadb php-fpm ssh fail2ban --no-pager 2>/dev/null || true
```

## 3. Current Network Policy Snapshot

If iptables is used:

```bash
iptables -S
```

If nftables is used:

```bash
nft list ruleset
```

## 4. External Exposure Check

From a trusted external host:

```bash
nmap -Pn -p 21,22,25,80,443,3306,8080,8443 menu.labus.pro
```

## 5. Fill Inventory Table

| Port | Listening process | Service owner/purpose | Publicly reachable | Action recommendation |
|---|---|---|---|---|
| 21 |  |  | yes/no | close or restrict |
| 22 |  |  | yes/no | keep, harden |
| 25 |  |  | yes/no | keep or restrict |
| 80 |  |  | yes/no | keep |
| 443 |  |  | yes/no | keep |
| 3306 |  |  | yes/no | close external access |
| 8080 |  |  | yes/no | close or restrict |
| 8443 |  |  | yes/no | close or restrict |

## Exit Criteria for This Phase

1. Every target port has an identified owner and business purpose.
2. Public exposure status is confirmed from external scan.
3. Draft allowlist policy exists for required ports.
4. No changes are applied in this document's scope.
