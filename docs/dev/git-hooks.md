# Git Hooks

This repository stores versioned hooks in `.githooks/`.

## Enable hooks

Run once in each clone:

```bash
git config core.hooksPath .githooks
```

## Hooks included

- `pre-push`: lints staged PHP files (`php -l`) before push.
- `pre-push` (main gate): when a push includes `main`, runs `npm run openapi:validate` and blocks push on failure.
- `post-merge`: runs after `git pull` / merge, lints changed PHP files and clears `data/cache/*` (except `.gitkeep`).

## Notes

- Hooks are local Git configuration. They are not active until `core.hooksPath` is set.
- On the production server, set this once after clone.
- Keep Node.js/npm available on developer machines that push `main`, because OpenAPI validation is mandatory for release pushes.
