# Deployment Workflow (Git Pull on Server)

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - Git-based deploy, versioned hooks, anti-mojibake pre-push validation, and OpenAPI validation are implemented.
  - Provider/tenant regression smoke now runs automatically from `.githooks/post-merge` on the production checkout path.
  - Current production rollout commonly restarts the active PHP-FPM unit after branch checkout/pull.
  - OPcache reset remains a manual post-pull step.
  - This document describes the current production workflow, not a fully automated release pipeline.

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

- `pre-push`: PHP lint, anti-mojibake scan for pushed text files, and OpenAPI validation when pushing `main`
- `post-merge`: PHP lint after pull, cache cleanup, and provider/tenant smoke on production

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
2. Verify that the automatic provider/tenant regression smoke passed in `post-merge`.
3. Verify admin/owner pages if new PHP methods or shared includes changed.

Automatic regression smoke command:

```bash
php scripts/tenant/smoke.php --provider-domain=menu.labus.pro --tenant-domain=test.milyidom.com
```

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
