# Custom-Domain Go-Live Runbook

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-11`
- Verified against code: [scripts/tenant/go-live.sh](../../scripts/tenant/go-live.sh), [scripts/tenant/launch.php](../../scripts/tenant/launch.php), [deploy/nginx/custom-domain-http-bootstrap.conf](../../deploy/nginx/custom-domain-http-bootstrap.conf), [deploy/nginx/custom-domain-template.conf](../../deploy/nginx/custom-domain-template.conf).

## Purpose

Step-by-step runbook for launching a new tenant on its own custom domain (e.g. `menu.brand.tld`). Covers the one-shot `go-live.sh` automation, what it expects to already be true on the host, and how to handle the manual parts that the script does **not** do (DNS, marketing, OAuth allowlists).

This is the operational complement to [tenant-launch-checklist.md](../tenant-launch-checklist.md) — the checklist is the high-level sign-off list, this document is the actual commands and their order.

## The script: `scripts/tenant/go-live.sh`

One shell script owns the entire provisioning path. It must run as root on the web host.

### What it does, in order

1. **Capture a "pre-golive" security baseline** of the provider domain (`scripts/security/capture-baseline.sh`) so any regression introduced by the new vhost is diffable later.
2. **Run `scripts/tenant/launch.php`** as the webuser (not root), which creates the tenant DB, registers the tenant + domain in the control plane, applies `sql/bootstrap-schema.sql`, seeds demo content, and emits a `launch-*.json` artifact. See [schema-and-migrations.md](../schema-and-migrations.md) for what the bootstrap schema creates.
3. **Render the HTTP-only nginx vhost** from [deploy/nginx/custom-domain-http-bootstrap.conf](../../deploy/nginx/custom-domain-http-bootstrap.conf) (substituting `CUSTOM_DOMAIN` and `PROJECT_ROOT`), symlink into `sites-enabled`, `nginx -t`, `systemctl reload nginx`. This exposes just enough of the tenant to serve ACME challenges — the rest of the site is not reachable yet.
4. **Issue the SSL certificate** via `certbot certonly --webroot -w $PROJECT_ROOT -d $DOMAIN --agree-tos --non-interactive -m $CERTBOT_EMAIL --keep-until-expiring`. Writes to `/etc/letsencrypt/live/<domain>/`.
5. **Render the full HTTPS vhost** from [deploy/nginx/custom-domain-template.conf](../../deploy/nginx/custom-domain-template.conf) (the same file, now with cert paths resolved), `nginx -t`, `systemctl reload nginx`, `systemctl restart $PHP_FPM_SERVICE`. The tenant is now fully reachable.
6. **Run the provider+tenant smoke suite** (`scripts/tenant/smoke.php --provider-domain=... --tenant-domain=...`) and the provider-side security smoke (`scripts/perf/security-smoke.sh`).
7. **Run the post-release browser regression** (`scripts/perf/post-release-regression.sh`, Playwright-driven) against both the provider and the new tenant, logging output.
8. **Capture a "post-golive" security baseline** of the provider domain to diff against the pre-capture.
9. **Emit a `go-live-<slug>-<timestamp>.json` artifact** under `scripts/tenant/data/go-live-artifacts/` that bundles: the launch artifact, both smoke JSONs, the security smoke text, the browser regression log, both baseline directories, and the issued cert path.

**The only manual prerequisite** is a DNS A/AAAA record for `$DOMAIN` pointing at the host **before** step 4, because certbot uses the HTTP-01 challenge via the webroot. Everything else is automated.

### Minimal invocation

```bash
sudo bash scripts/tenant/go-live.sh \
  --brand-name="Милый дом" \
  --brand-slug=milyi-dom \
  --domain=menu.milyidom.com \
  --mode=tenant \
  --owner-email=owner@milyidom.com \
  --tenant-db-user=cleanmenu_milyi \
  --tenant-db-pass='<strong-random>' \
  --certbot-email=ops@labus.pro
```

### Useful optional flags

| Flag | Why you might use it |
|---|---|
| `--owner-password=<pw>` | Seed a known owner password instead of letting `launch.php` generate one (printed in the launch artifact). |
| `--seed-profile=restaurant-demo` | Seed profile name (default is restaurant demo). Other profiles live in [scripts/tenant/data/](../../scripts/tenant/data/). |
| `--contact-phone=`, `--contact-address=`, `--contact-map-url=` | Pre-fill public contact info so the onboarding wizard is optional for this launch. |
| `--public-entry-mode=homepage` | Tenant `/` renders the restaurant homepage. Use `menu` to redirect `/` straight to `/menu.php`. See [product-model.md](../product-model.md). |
| `--skip-certbot` | Skip SSL issuance entirely. Useful for staging / internal hosts that are fronted by a different TLS terminator, or when re-running go-live on a domain whose cert already exists. Requires that a valid cert is already under `/etc/letsencrypt/live/<domain>/` — nginx will fail the template render otherwise. |
| `--project-root=`, `--webuser=`, `--php-fpm-service=`, `--provider-domain=` | Override the conventional paths and service names. Defaults match the production host (`/var/www/labus_pro_usr/data/www/menu.labus.pro`, `labus_pro_usr`, `php8.1-fpm`, `menu.labus.pro`). |
| `--nginx-sites-available=`, `--nginx-sites-enabled=` | Override nginx config layout if the host doesn't use Debian-style `sites-*`. |

### Required host preconditions

- Running as root.
- `nginx`, `certbot`, and at least one `php{X.Y}` binary present in `PATH`. The script picks the first available from `php8.5 → php8.4 → php8.3 → php8.2 → php`.
- The control-plane DB credentials are already in the codebase (via `config.php` / env), so `launch.php` can register the tenant.
- A working webroot at `$PROJECT_ROOT` that contains this repo, owned by `$WEBUSER`.
- DNS A/AAAA for `$DOMAIN` pointing at the host's public IP (required before step 4).
- The provider domain is reachable over HTTPS (required by the baseline capture in step 1).

## Two nginx templates

Both templates use `sed` substitution of two tokens — `CUSTOM_DOMAIN` and `PROJECT_ROOT` — and are rendered into `/etc/nginx/sites-available/<domain>.conf`, then symlinked from `sites-enabled/`.

### `deploy/nginx/custom-domain-http-bootstrap.conf` — the bootstrap vhost

- Listens on HTTP only.
- Serves `/.well-known/acme-challenge/` from the project root.
- Does **not** route PHP. Used only long enough to satisfy certbot.

### `deploy/nginx/custom-domain-template.conf` — the production vhost

- HTTPS only on :443 with HTTP→HTTPS redirect.
- Certificate paths: `/etc/letsencrypt/live/<domain>/fullchain.pem` + `privkey.pem`.
- Three FastCGI pool upstreams per the PHP-FPM pool split:
  - `php_fpm_sse` for `orders-sse.php` and `ws-poll.php` (long-poll / SSE endpoints; isolated so a thundering herd here doesn't starve the web pool).
  - `php_fpm_api` for everything under `/api/v1/` (mobile API).
  - `php_fpm_web` for all other `.php` (menu, admin, employee, owner).
  See [php-fpm-pool-split.md](./php-fpm-pool-split.md) for the FPM pool contract.
- Scope lock: `location ^~ /scripts/ { return 404; }` — scripts under `/scripts/` are CLI-only and must never be reachable via HTTP. Verified by security smoke.
- Static asset caching: `Cache-Control: public, max-age=31536000, immutable` for CSS/JS/images, since they are versioned via `?v=<filemtime>` query strings.
- Optional brotli/gzip on text assets.
- `location = /manifest.webmanifest { rewrite ^ /manifest.php last; }` — the PWA manifest is dynamic (see [pwa-and-push.md](../pwa-and-push.md)).

## After `go-live.sh` finishes

The script has made the tenant reachable and validated, but several surfaces require a human to touch:

### 1. Credentials and external services

- **Payment credentials** — YooKassa and/or T-Bank SBP settings must be entered by the owner/admin via the panel. Neither provider allows credentials to be pre-seeded here. See [payments-integration.md](../payments-integration.md).
- **Payment webhooks** — register `https://<domain>/payment-webhook.php` in both provider consoles. YooKassa: enable `payment.succeeded` + `payment.canceled`. T-Bank: set notification URL.
- **Telegram bot** — add `telegram_chat_id` setting for this tenant and register the webhook to `https://<domain>/telegram-webhook.php`. See [telegram-bot-setup.md](../telegram-bot-setup.md).
- **OAuth redirect URIs** — VK and Yandex consoles both require the exact tenant origin to be added to the allowlist. See [vk-oauth-setup.md](../vk-oauth-setup.md), [yandex-oauth-setup.md](../yandex-oauth-setup.md).
- **VAPID keys** — if this deployment supports web push, confirm `data/vapid-keys.json` exists on the host and the public key is baked into `js/push-notifications.min.js`. See [pwa-and-push.md](../pwa-and-push.md).

### 2. Brand polish via the owner panel

- Brand colors, logo, fonts, custom CSS.
- Contact block (if not passed via flags).
- `public_entry_mode` override if you want to flip `homepage ↔ menu` later.
- Onboarding wizard is available to the owner at `/onboarding.php`; it no-ops after the first complete run.

### 3. Open items to verify manually

- Load `https://<domain>/` and `https://<domain>/menu.php` — they should render the tenant brand with **no** provider marketing copy bleeding through.
- Place a test order, confirm it lands in `employee.php`, and (if Telegram is wired) check the bot card.
- Open DevTools → Application → Manifest — `name`, `theme_color`, icons should reflect the tenant brand.
- Hit `/manifest.webmanifest` directly and confirm it returns `application/manifest+json` with the right content.
- Diff the pre/post security baselines: any new HTTP header or cookie should be expected and justified.

### 4. Rollback

If something is wrong and you want the tenant off the internet quickly:

1. `rm /etc/nginx/sites-enabled/<domain>.conf`
2. `systemctl reload nginx`

The tenant DB and control-plane entries remain intact, so a rerun of `go-live.sh` re-links the same config. Only drop the tenant DB if you genuinely want to start over — `launch.php` does not destroy data on re-run, but the control-plane row is unique per `brand_slug`, so you'll need to delete it first if you reuse the slug.

## Common failure modes

| Symptom | Likely cause | Where to look |
|---|---|---|
| `certbot: the following errors were reported by the server: Type: unauthorized` | DNS not yet pointing at the host, or pointing at a different host | `dig +short <domain>`; wait for propagation |
| `nginx: [emerg] cannot load certificate "/etc/letsencrypt/live/<domain>/fullchain.pem"` after step 5 | Certbot step was skipped or failed silently; the final template references cert paths that don't exist | `/etc/letsencrypt/live/<domain>/`; rerun without `--skip-certbot` |
| `launch.php` exits with duplicate `brand_slug` | Control-plane `tenants` row already exists from a previous attempt | Delete the stale row by slug in the control-plane DB, then rerun |
| `smoke.php` reports tenant HTTP 500 | `php-fpm` was not restarted, or the FPM pool config is out of date | `journalctl -u php8.1-fpm -n 100`; confirm the three pools exist |
| `/scripts/*` is reachable over HTTP (security smoke fails) | The `location ^~ /scripts/ { return 404; }` rule is missing from the rendered vhost | Diff the rendered `sites-available/<domain>.conf` against the template |
| Manifest shows old provider name/colors | Tenant `app_name`/`color_*` settings not populated yet, or 1h edge cache still serving old manifest | `settings` table; wait 1h or bump a setting to force a fresh render |
| Browser regression (step 7) fails on a page that has nothing to do with the new tenant | Provider-side regression — unrelated to this go-live, but the new tenant's vhost was reloaded first, so surface area changed. Investigate normally | `browser_regression.txt` in the go-live artifact |

## Related docs

- [tenant-launch-checklist.md](../tenant-launch-checklist.md) — the non-technical sign-off checklist for launches.
- [nginx-pool-split.md](./nginx-pool-split.md), [php-fpm-pool-split.md](./php-fpm-pool-split.md) — the pool split that the final vhost template depends on.
- [schema-and-migrations.md](../schema-and-migrations.md) — what `launch.php` applies to the new tenant DB.
- [product-model.md](../product-model.md) — provider vs. tenant, `public_entry_mode`, what must never bleed across.
- [security-smoke-checklist.md](../security-smoke-checklist.md) — what the security smoke step actually checks.
