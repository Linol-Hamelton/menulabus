# Nginx + PHP-FPM Pool Split (web/api/sse)

This folder contains configuration templates. Apply on the server manually.

Goals:
- Keep SSE (long-lived requests) from starving normal web/API requests.
- Allow API to use a lightweight PHP-FPM pool with different limits and logs.

## Recommended routing
1) /api/v1/* -> api pool
2) /orders-sse.php and optionally /ws-poll.php -> sse pool (buffering off)
3) everything else -> web pool

Templates:
- `deploy/nginx/pool-split-upstreams.conf` (upstream blocks)
- `deploy/nginx/server-locations-pool-split.conf` (location routing)

## Microcache
For burst protection, consider microcache for:
- /api/v1/menu.php (public only, bypass on Authorization header)

Template:
- `deploy/nginx/api-microcache.conf`
