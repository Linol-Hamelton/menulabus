# Deployment Workflow (Git Pull on Server)

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-25`
- Current implementation notes:
  - Git-based deploy, versioned hooks, anti-mojibake pre-push validation, docs-drift validation, and OpenAPI validation are implemented.
  - Provider/tenant regression smoke, release baseline capture, provider security smoke, and a safe browser regression suite now run automatically from `.githooks/post-merge` on the production checkout path when the Playwright runtime is available.
  - The browser regression suite now writes a mandatory visual sign-off set with desktop/mobile screenshots for key public and internal pages, plus a short visual checklist in the generated report.
  - A separate exhaustive audit runner, `scripts/perf/full-ui-audit.cjs`, now exists for full-scope production audits; it reuses the release baseline checks, covers desktop/mobile route inventories, and produces a findings matrix plus fix plan under `data/logs/full-ui-audit/run-<timestamp>/`.
  - Visual sign-off waits for the final shared polish stylesheet to load and for tab-rail computed styles to stabilize before screenshots and overlap checks are recorded, so release gating no longer depends on cold CSS timing races.
  - Full mutating order-lifecycle coverage is available through the same browser regression suite, but remains opt-in to avoid creating test orders on every pull.
  - Current production rollout commonly restarts the active PHP-FPM unit after branch checkout/pull.
  - OPcache reset remains a manual post-pull step.
  - Tenant custom-domain go-live is now scriptable through `scripts/tenant/go-live.sh` once DNS is ready.

## Quick Copy (Production)

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"
BRANCH="main" # or release/<name>

runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout -B "$BRANCH" "origin/$BRANCH"
runuser -u "$WEBUSER" -- git -C "$PROJECT" pull --ff-only origin "$BRANCH"
systemctl restart php8.1-fpm
```

## Goal

- No manual file upload.
- Source of truth: Git.
- Deployment: `git pull` on server.
- Validation before push and basic post-merge checks via repo hooks.

## Related Documentation

- Main docs map: `docs/index.md`
- Project reference: `docs/project-reference.md`
- API contract: `docs/openapi.yaml`
- Security runbook set: `docs/security-*`

## PHP Compatibility Note

- The server may expose several PHP binaries.
- Repo hooks auto-select the newest suitable binary in this order:
  - `php8.5`
  - `php8.4`
  - `php8.3`
  - `php8.2`
  - `php`
- Hooks lint project PHP files only and skip `vendor/`, `node_modules/`, `.git/`, and `data/cache/`.

## Current Production Specifics

- Production path: `/var/www/labus_pro_usr/data/www/menu.labus.pro`
- Git operations run as `labus_pro_usr`
- `core.hooksPath` is set to `.githooks`
- Local server-only files stay excluded via `.git/info/exclude`

## Branch Policy

- `main`: long-lived production-ready branch
- `release/*`: pinned rollout branch when isolating a release or preserving rollback clarity
- working branches: local/in-review only

Recommended release flow:

1. Prepare changes in a working branch.
2. Run required checks locally.
3. Promote to a reviewed `release/*` branch or fast-forward `main`, depending on rollout risk.
4. Push to remote.
5. On server: deploy the explicit target branch with `checkout -B ... origin/<branch>`.

## One-Time Setup on Server

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" config core.hooksPath .githooks
runuser -u "$WEBUSER" -- git -C "$PROJECT" config --get core.hooksPath
```

This enables:

- `pre-push`: PHP lint, anti-mojibake scan for pushed text files, docs-drift guard on `release/*` and `main`, and OpenAPI validation when pushing `main`
- `post-merge`: PHP lint after pull, cache cleanup, release baseline capture, provider/tenant smoke, provider security smoke, and safe browser regression on production

## Local Release Commands

```bash
git checkout -b release/<short-name>
npm run openapi:validate
git add -A
git commit -m "release: <short description>"
git push origin HEAD
```

Gate details:

- `.githooks/pre-push` blocks pushes to `main` when `npm run openapi:validate` fails
- `.githooks/pre-push` blocks any push when `scripts/check-mojibake.php` finds suspicious text patterns in pushed files
- `.githooks/pre-push` blocks `release/*` and `main` pushes when contract-bearing changes land without updated docs
- if pushing a release branch, still run `npm run openapi:validate` locally before push because the hard gate is only automatic on `main`
- if validation fails, fix `docs/openapi.yaml` or the implementation before retrying

## Server Deploy Commands

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"
BRANCH="main" # or release/<name>

runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout -B "$BRANCH" "origin/$BRANCH"
runuser -u "$WEBUSER" -- git -C "$PROJECT" pull --ff-only origin "$BRANCH"
systemctl restart php8.1-fpm
```

Manual post-pull steps:

1. Reset OPcache via the established monitor/admin flow.
2. Verify that the automatic provider/tenant regression smoke, baseline capture, provider security smoke, and browser regression passed in `post-merge`.
3. Review the generated visual sign-off screenshots and checklist in the browser regression report before calling the release complete.
4. If the release touched ordering, run the full order-lifecycle browser regression explicitly.

Automatic regression smoke command:

```bash
php scripts/tenant/smoke.php --provider-domain=menu.labus.pro --tenant-domain=test.milyidom.com
```

Automatic security smoke command:

```bash
bash scripts/perf/security-smoke.sh https://menu.labus.pro
```

Automatic safe browser regression command:

```bash
bash scripts/perf/post-release-regression.sh
```

The safe run now also produces:

- desktop/mobile screenshots for key provider and tenant pages
- a `Visual Sign-Off` section in `report.md`
- a short checklist for overlap, sticky behavior, density, and hierarchy review
- deterministic waiting for `ui-ux-polish.css` and stable tab-rail layout before screenshots and visual checks
- a failing release result if either the functional regression steps or the visual checks stay red

Full release sign-off with order lifecycle:

```bash
CLEANMENU_PROVIDER_OWNER_EMAIL=owner@example.com \
CLEANMENU_PROVIDER_OWNER_PASSWORD=secret \
CLEANMENU_RUN_ORDER_REGRESSION=1 \
bash scripts/perf/post-release-regression.sh --orders --require-provider-owner-auth
```

One-shot exhaustive production audit:

```bash
CLEANMENU_PROVIDER_OWNER_EMAIL=owner@example.com \
CLEANMENU_PROVIDER_OWNER_PASSWORD=secret \
node scripts/perf/full-ui-audit.cjs
```

The exhaustive run is not part of automatic `post-merge`; use it for final release acceptance, broad route/component audits, or after large UI/security changes.

## Safety Rules

- Use `--ff-only` on pull to avoid accidental merge commits on server.
- Do not edit tracked files directly on server.
- If a hotfix is needed, do it in Git and redeploy by pull.
- Keep deploy logs and commit hash for each release.

## Rollback

Fast rollback by commit hash:

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" log --oneline -n 20
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout <previous_stable_hash>
```

Then:

1. reset OPcache
2. rerun provider/tenant smoke
3. record the rollback in release notes or change log

Fast rollback by branch:

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"
ROLLBACK_BRANCH="release/<previous-stable>"

runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout -B "$ROLLBACK_BRANCH" "origin/$ROLLBACK_BRANCH"
runuser -u "$WEBUSER" -- git -C "$PROJECT" pull --ff-only origin "$ROLLBACK_BRANCH"
```

## Security Rollout Rule

Use these documents together:

- `docs/security-hardening-roadmap.md`
- `docs/security-smoke-checklist.md`
- `docs/security-change-log-template.md`

Rules for security changes:

1. Apply one production change per release step.
2. Run config syntax checks before reload.
3. Run smoke after each step.
4. Observe production before the next step.
5. If stop criteria triggers, rollback immediately.

## Deploy Pitfalls (learned 2026-04-27)

### Never use `echo … | crontab -u $user -` to add a single line

`crontab -u $user -` reads stdin as the **entire new crontab** and replaces
whatever was there. Piping a single `echo` line wipes the rest of the user's
cron — webhook-worker, marketing-worker, purge-soft-deleted, and the
FastPanel scheduler line all disappear silently. The `# >>> cleanmenu cron >>>` /
`# <<< cleanmenu cron <<<` markers only help if the surrounding code is
preserved. To insert one line:

```bash
crontab -u "$WEBUSER" -l \
  | awk '/^# <<< cleanmenu cron <<</ && !d { print "<NEW LINE>"; d=1 } { print }' \
  | crontab -u "$WEBUSER" -
```

Or write the whole block in a here-doc when restoring after a wipe.

### Avoid `if` inside an nginx `location` that also has `fastcgi_cache`

The site config used to swap the FastCGI script via:

```nginx
location = /menu.php {
    set $menu_script /menu.php;
    if ($http_cookie !~* "PHPSESSID") { set $menu_script /menu-public.php; }
    fastcgi_cache CAFECACHE;
    fastcgi_cache_min_uses 2;
    fastcgi_cache_revalidate on;
    ...
}
```

`if` inside `location` creates an "implicit nested location" (see
[https://nginx.org/en/docs/http/ngx_http_rewrite_module.html#if](https://nginx.org/en/docs/http/ngx_http_rewrite_module.html#if))
that does not inherit FastCGI parameters and caching state correctly.
With `fastcgi_cache + min_uses + revalidate` on the same location, the
response body silently goes to 0 bytes — headers still go out, so the
client sees `200 OK` with an empty body, and HTTP/2 closes the stream
with `INTERNAL_ERROR`.

The clean fix is an http-level `map` and a single `set` inside the
location:

```nginx
# in http {} (top of the per-site conf is fine)
map $http_cookie $menu_target_script {
    default       /menu-public.php;
    "~*PHPSESSID" /menu.php;
}

# inside the location
location = /menu.php {
    set $menu_script $menu_target_script;
    ...
}
```

### Mirror live conf back to the FastPanel template

FastPanel keeps a parallel copy at
`/etc/nginx/fastpanel2-available/<user>/<host>.conf` and may regenerate
the live `fastpanel2-sites/...` copy from it via the UI. After any
manual edit to the live conf, mirror it back:

```bash
cp /etc/nginx/fastpanel2-sites/<user>/<host>.conf \
   /etc/nginx/fastpanel2-available/<user>/<host>.conf
```

### After moving files into subdirectories — grep for bare-relative requires

The Phase 13B.3 mass `git mv` of 29 PHP files into purpose-named subdirs
(`admin/`, `api/save/`, `api/checkout/`, `auth/oauth/`, `kds/`,
`api/reservations/`) caught most path issues via the `__DIR__ . '/X'` →
`__DIR__ . '/../../X'` mass-sed. But it **missed** one anti-pattern:
bare relative requires without `__DIR__`:

```php
require_once 'session_init.php';   // BAD — resolves relative to PHP's cwd
require_once 'require_auth.php';
```

These work while the file lives at repo root (cwd = root, so the bare
filename resolves correctly), but break silently after the move with
500 / fatal "require_once(): Failed opening required ...". Production
caught this on `api/save/brand.php` — that endpoint returned `500` on
every POST until both requires got the proper `__DIR__ . '/../../'`
prefix.

Always grep for the anti-pattern after a subdir refactor:

```bash
grep -rnE "^\s*require(_once)?\s*['\"][a-z][a-z_-]*\.php['\"]" --include="*.php" .
```

If the grep returns anything, those files will fatal as soon as their
cwd no longer matches root.

### After moving files into subdirectories — also grep for relative `<a href>`

Twin sibling of the bare-`require` trap. After Phase 13B.3 the partial
`account-header.php` (included from 9 admin/*.php pages plus 6 root
pages) still contained relative URLs like `href="admin/menu.php"`. From
root-level callers these resolved correctly. From `/admin/<page>` they
resolved to `/admin/admin/menu.php` — nginx returned `File not found`.
The break manifested as **intermittent** 404s — only when navigating
between admin pages, not on the first hop from `/account.php`.

Same anti-pattern in `admin/menu.php` itself — internal `href="admin/
menu.php?view=…"` and root-target links like `href="download-sample
.php"` and `href="monitor.php"` resolved to `/admin/admin/...` and
`/admin/download-sample.php`.

Grep after any move that places a previously-root file under a subdir,
or after editing a shared partial:

```bash
grep -rnE "(href|action|src)=['\"][a-z][a-z_/-]*\.php" --include="*.php" .
```

Any match should be **absolute** (`/-prefixed`) when the file may be
included from multiple directory depths. Anchor every navigational link
to site root — relative is a bug-magnet across subdir refactors.

### After moving files into subdirectories — also grep for relative `Location:` redirects

Same trap as `<a href>`, but server-side. Browsers resolve a relative
`Location:` against the **request URL**, so:

```php
header('Location: auth.php');   // From /admin/staff.php → /admin/auth.php (404)
                                 // From /kds/index.php  → /kds/auth.php  (404)
                                 // From /account.php    → /auth.php       ✓
```

`require_auth.php` is included from 9 `admin/*.php`, 2 `kds/*.php`, and
multiple `api/*.php` endpoints. Relative `Location:` there fired
intermittent "File not found" pages every time a session expired or
rate-limit kicked in.

Grep:

```bash
grep -rnE "header\(.{1,3}Location:\s*[a-z]" --include="*.php" .
```

All matches must be `/-prefixed`. The post-13B.3 sweep fixed
`require_auth.php` (4 redirects), `admin/menu.php` (5 self-redirects
after CRUD), and 8 root files defensively.

### Create cache zone directories before nginx reload

The site declares two `fastcgi_cache_path` zones at `/var/cache/nginx/`.
That parent dir does not exist on a fresh FastPanel host, so the master
process logs `[emerg] mkdir() ".../fastcgi_cafe" failed (2: No such file
or directory)` on every reload and the cache zones never create. Cache
is silently disabled. Create the dirs once per host:

```bash
mkdir -p /var/cache/nginx/fastcgi_cafe /var/cache/nginx/fastcgi_api_micro
chown -R www-data:www-data /var/cache/nginx/fastcgi_cafe /var/cache/nginx/fastcgi_api_micro
```
