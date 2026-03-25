# Git Hooks

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-25`
- Current implementation notes:
  - Versioned hooks live in `.githooks/`.
  - `pre-push` enforces PHP lint, an anti-mojibake text scan for pushed files, a docs-drift guard for `release/*` and `main`, and the OpenAPI gate for `main`.
  - `post-merge` performs PHP lint, cache cleanup, baseline capture, mandatory provider/tenant + provider-security smoke, and a safe Playwright-based browser regression on the production checkout path when the runtime is available.
  - The browser regression step now includes a mandatory visual sign-off screenshot set and checklist in its report output.

## Enable Hooks

Run once in each clone:

```bash
git config core.hooksPath .githooks
```

## Hooks Included

- `pre-push`: lints staged PHP files with `php -l`
- `pre-push`: runs `scripts/check-mojibake.php` on changed text files in the pushed range
- `pre-push` on `release/*` and `main`: runs `scripts/docs/check-doc-drift.sh` and blocks pushes that change contract-bearing code without docs updates
- `pre-push` on `main`: runs `npm run openapi:validate` and blocks push on failure
- `post-merge`: lints changed PHP files and clears `data/cache/*` except `.gitkeep`
- `post-merge` on the production checkout path: captures a release baseline, runs `php scripts/tenant/smoke.php --provider-domain=menu.labus.pro --tenant-domain=test.milyidom.com`, runs `bash scripts/perf/security-smoke.sh https://menu.labus.pro`, and then runs `bash scripts/perf/post-release-regression.sh`

## Notes

- Hooks are local Git configuration and are inactive until `core.hooksPath` is set.
- On the production server, set this once after clone.
- Developers pushing `main` need Node.js/npm available because OpenAPI validation is mandatory.
- Browser regression skips cleanly when `node`/`playwright` are unavailable unless `CLEANMENU_REQUIRE_BROWSER_REGRESSION=1` is set.
- Mutating order-lifecycle coverage stays opt-in through `CLEANMENU_RUN_ORDER_REGRESSION=1` or `--orders`.
- The safe browser regression is now also the default visual QA artifact generator: review its screenshot set before final release sign-off.
