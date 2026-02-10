# PHP-FPM Pool Split (web/api/sse)

This folder contains templates for separate PHP-FPM pools.

Why:
- SSE keeps workers busy for up to ~25s.
- Without isolation, a spike in SSE clients can block API and page loads.

Suggested pools:
- web: default, dynamic pages
- api: /api/v1/*
- sse: /orders-sse.php (+ ws-poll.php if used)

Templates:
- `deploy/php-fpm/pool-menu.labus.pro-web.conf`
- `deploy/php-fpm/pool-menu.labus.pro-api.conf`
- `deploy/php-fpm/pool-menu.labus.pro-sse.conf`

Start conservative on sse:
- pm.max_children: 10-30 (tune by RAM and queue metrics)

Enable per-pool:
- slowlog
- request_slowlog_timeout
- status path (/fpm-status-web, /fpm-status-api, /fpm-status-sse) if desired
