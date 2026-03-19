# Git Hooks

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-19`
- Current implementation notes:
  - Versioned hooks live in `.githooks/`.
  - `pre-push` enforces PHP lint, an anti-mojibake text scan for pushed files, and the OpenAPI gate for `main`.
  - `post-merge` performs PHP lint, cache cleanup, and mandatory provider/tenant smoke on the production checkout path.

## Enable Hooks

Run once in each clone:

```bash
git config core.hooksPath .githooks
```

## Hooks Included

- `pre-push`: lints staged PHP files with `php -l`
- `pre-push`: runs `scripts/check-mojibake.php` on changed text files in the pushed range
- `pre-push` on `main`: runs `npm run openapi:validate` and blocks push on failure
- `post-merge`: lints changed PHP files and clears `data/cache/*` except `.gitkeep`
- `post-merge` on the production checkout path: runs `php scripts/tenant/smoke.php --provider-domain=menu.labus.pro --tenant-domain=test.milyidom.com`

## Notes

- Hooks are local Git configuration and are inactive until `core.hooksPath` is set.
- On the production server, set this once after clone.
- Developers pushing `main` need Node.js/npm available because OpenAPI validation is mandatory.
