# PHP-FPM Pool Split (web/api/sse)

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - This document describes templates and tuning guidance.
  - It is not a guarantee that all listed pools are already active in production.

## Purpose

Separate pools reduce contention between:

- normal page requests
- API requests
- long-lived SSE/poll requests

## Suggested Pools

- `web`: default dynamic pages
- `api`: `/api/v1/*`
- `sse`: `/orders-sse.php` and optionally `/ws-poll.php`

## Templates

- `deploy/php-fpm/pool-menu.labus.pro-web.conf`
- `deploy/php-fpm/pool-menu.labus.pro-api.conf`
- `deploy/php-fpm/pool-menu.labus.pro-sse.conf`

## Starting Guidance

- start conservatively on `sse`
- size `pm.max_children` from real RAM and queue behavior
- enable per-pool `slowlog`, `request_slowlog_timeout`, and optional status paths if needed

## Current Scope Note

Use this as a manual rollout template. If a server adopts these pools, record the real production state in deployment notes.
