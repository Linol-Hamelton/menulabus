# Deployment Workflow (Git Pull on Server)

## Quick Copy (Production)

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout main
runuser -u "$WEBUSER" -- git -C "$PROJECT" pull --ff-only origin main
```

## PHP Compatibility Note (Important)

- On this server, default `php` can point to 8.1, while project dependencies in `vendor/` may require `>=8.2`.
- Repository hooks (`pre-push`, `post-merge`) auto-select the best available binary for lint in this order:
  - `php8.5` -> `php8.4` -> `php8.3` -> `php8.2` -> `php`
- Hooks lint project PHP files only and skip `vendor/`, `node_modules/`, `.git/`, and `data/cache/`.
- Temporary compatibility mode before runtime upgrade:
  - WebPush in `update_order_status.php` can run in degrade mode on PHP `<8.2` (status update succeeds, push is skipped with a log entry).

## Goal

- No WinSCP/manual file upload.
- Source of truth: Git.
- Deployment: `git pull` on server (in `main` only).
- Everything else (validation and post-pull checks) is automated.

## Related Documentation

- Main docs map: `docs/index.md`
- Project reference: `docs/project-reference.md`
- API contract: `docs/openapi.yaml`
- Security runbook set: `docs/security-*`

## Current production specifics (as configured)

- Production path: `/var/www/labus_pro_usr/data/www/menu.labus.pro`
- Git operations are executed as `labus_pro_usr` via `runuser -u ...`.
- We do not run repo commands as `root` to avoid `dubious ownership`.
- `core.hooksPath` is set to `.githooks`.
- Local server-only files are ignored through `.git/info/exclude`.

## Branch policy

- `main`: production-ready branch.
- `actual`: working integration branch (can be kept equal to `main` if needed).

Recommended release flow:

1. Work in `actual`.
2. Commit changes.
3. Fast-forward `main` from `actual`.
4. Push to remote.
5. On server: `git pull --ff-only`.

## One-time setup on server (already applied)

If repository is already initialized in project directory:

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" config core.hooksPath .githooks
runuser -u "$WEBUSER" -- git -C "$PROJECT" config --get core.hooksPath
```

This enables:

- `pre-push` hook: PHP syntax check for staged project PHP files with auto-selected PHP binary.
- `post-merge` hook: PHP syntax check after pull for project PHP files with auto-selected PHP binary, plus cache cleanup.

If project directory has no `.git`, run one-time bootstrap:

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"
REPO_URL="https://github.com/Linol-Hamelton/menulabus"

runuser -u "$WEBUSER" -- git -C "$PROJECT" init -b main
runuser -u "$WEBUSER" -- git -C "$PROJECT" remote add origin "$REPO_URL"
runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout -f -B main origin/main
runuser -u "$WEBUSER" -- git -C "$PROJECT" config core.hooksPath .githooks
```

## Local release commands

```bash
# 1) Ensure working branch is up to date
git checkout actual
git pull --ff-only

# 2) Commit
git add -A
git commit -m "release: <short description>"

# 3) Move production branch forward
git checkout main
git merge --ff-only actual

# 4) Push
git push origin main
git push gitlab actual
```

If both remotes use `main`, push `main` to both.

## Server deploy commands

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout main
runuser -u "$WEBUSER" -- git -C "$PROJECT" pull --ff-only origin main
```

After pull:

1. Run OPcache reset (your existing `monitor.php` flow).
2. Smoke-check key pages and payment scenario.

## Safety rules

- Use `--ff-only` on pull to avoid accidental merge commits on server.
- Do not edit files directly on server.
- If hotfix is needed, do it in Git and redeploy by pull.
- Keep deploy logs and commit hash for each release.

## Rollback

Fast rollback by commit hash:

```bash
WEBUSER="labus_pro_usr"
PROJECT="/var/www/labus_pro_usr/data/www/menu.labus.pro"

runuser -u "$WEBUSER" -- git -C "$PROJECT" log --oneline -n 20
# choose previous stable hash
runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout <hash>
```

Then apply the same OPcache reset and smoke-check.

For long-term rollback policy, keep stable releases tagged.

## Security rollout (preventive-first)

Use these documents together:

- `docs/security-hardening-roadmap.md`
- `docs/security-smoke-checklist.md`
- `docs/security-change-log-template.md`

Rules for security changes:

1. Apply one production change per release step.
2. Run config syntax checks (`nginx -t` and relevant service checks) before reload.
3. Run full smoke from `docs/security-smoke-checklist.md` after each step.
4. Observe production for 30 minutes before next step.
5. If stop criteria triggers, rollback immediately and document in change log.
