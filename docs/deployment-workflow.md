# Deployment Workflow (Git Pull on Server)

## Goal

- No WinSCP/manual file upload.
- Source of truth: Git.
- Deployment: `git pull` on server.
- Everything else (validation and post-pull checks) is automated.

## Branch policy

- `main`: production-ready branch.
- `actual`: working integration branch (can be kept equal to `main` if needed).

Recommended release flow:

1. Work in `actual`.
2. Commit changes.
3. Fast-forward `main` from `actual`.
4. Push to remote.
5. On server: `git pull --ff-only`.

## One-time setup (local + server clone)

```bash
git config core.hooksPath .githooks
```

This enables:

- `pre-push` hook: PHP syntax check for staged PHP files.
- `post-merge` hook: PHP syntax check after pull and cache cleanup.

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
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
git fetch --all --prune
git checkout main
git pull --ff-only
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
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
git log --oneline -n 20
# choose previous stable hash
git checkout <hash>
```

Then apply the same OPcache reset and smoke-check.

For long-term rollback policy, keep stable releases tagged.
