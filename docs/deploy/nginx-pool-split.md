# Nginx + PHP-FPM Pool Split (web/api/sse)

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - This folder contains templates and guidance.
  - It must not be treated as proof that pool split is fully applied in every production environment.

## Purpose

Use separate routing/pool strategy to keep SSE or long-lived requests from starving normal web/API requests.

## Recommended Routing

1. `/api/v1/*` -> API pool
2. `/orders-sse.php` and optionally `/ws-poll.php` -> SSE pool with buffering disabled
3. everything else -> web pool

## Templates

- `deploy/nginx/pool-split-upstreams.conf`
- `deploy/nginx/server-locations-pool-split.conf`

## Microcache

For burst protection, consider microcache for:

- `/api/v1/menu.php` on public unauthenticated traffic

Template:

- `deploy/nginx/api-microcache.conf`

## Current Scope Note

Treat this document as manual-apply operational guidance. If pool split is rolled out on a host, record that rollout separately in deploy/security change logs.
