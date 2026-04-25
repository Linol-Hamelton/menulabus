# Testing Strategy

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-04-12`
- Current state:
  - **Browser regression:** Playwright smoke via [scripts/perf/post-release-regression.cjs](../scripts/perf/post-release-regression.cjs) and [scripts/perf/post-release-regression.sh](../scripts/perf/post-release-regression.sh) — wired into the `post-merge` git hook and into the tenant go-live flow.
  - **API smoke:** PHP CLI runner [scripts/api-smoke-runner.php](../scripts/api-smoke-runner.php) exercises the mobile `/api/v1/*` contract end-to-end. See [api-smoke.md](./api-smoke.md).
  - **Security smoke:** [scripts/perf/security-smoke.sh](../scripts/perf/security-smoke.sh), baselines captured by [scripts/security/capture-baseline.sh](../scripts/security/capture-baseline.sh) before/after each go-live.
  - **Static docs guards:** `pre-push` hook runs doc-drift / OpenAPI validation / mojibake checks. See [dev/git-hooks.md](./dev/git-hooks.md).
  - **PHP unit tests:** bootstrapped in Week 3 of the audit. [composer.json](../composer.json) + [phpunit.xml](../phpunit.xml) + [tests/bootstrap.php](../tests/bootstrap.php) with three test files: [tests/OrdersLifecycleTest.php](../tests/OrdersLifecycleTest.php) (always runs, pure PHP), [tests/IdempotencyTest.php](../tests/IdempotencyTest.php) and [tests/CreateOrderTest.php](../tests/CreateOrderTest.php) (both MySQL-gated via `CLEANMENU_TEST_MYSQL_DSN`, self-skip when absent). Wired into the `pre-push` git hook — see Section 2 below.

## Purpose

This document describes how we verify that the product still works — what exists today, what doesn't, and the **target state** we're moving toward. It is the source of truth for "did you test X?" conversations in code review.

## The pyramid we actually have

```
    [ manual owner sign-off ]                  rare, human
    [ Playwright browser regression ]          every merge + every go-live
    [ API smoke + security smoke ]             every merge + every go-live
    [ PHP unit tests ]                         every push (pre-push, lifecycle suite always)
    [ doc drift / OpenAPI / mojibake ]         every push (pre-push)
```

The unit layer is thin on purpose — three test files covering the highest-value pure-PHP surfaces (order lifecycle, idempotency, `Database::createOrder`). Growing it is a per-bug activity, not a big-bang rewrite: when a PHP-only bug slips through, write the repro as a PHPUnit case first, then fix. See "How to add a test when fixing a bug" below.

## Layer by layer

### 1. Static pre-push guards

Run locally by the `pre-push` git hook, installed via `docs/dev/git-hooks.md`. Fast, deterministic, no network.

- **Documentation drift** (`scripts/docs/check-doc-drift.sh`) — fails the push if a file referenced from an `## Implementation Status` block no longer exists, or if the referenced file was modified more recently than the doc that claims to verify it. Prevents docs from silently rotting.
- **OpenAPI validation** (`npm run openapi:validate`) — validates `docs/openapi.yaml` as a schema and cross-checks against `api/v1/*.php` route registration.
- **Mojibake scan** (`scripts/check-mojibake.php`) — scans for CP1251/Latin-1-looking UTF-8 in PHP files. We had a recurring class of encoding bugs with Russian text; the scanner stops them at the push boundary.

Treat these as the "does this diff even make sense" layer — cheap to run, cheap to fix, never skip with `--no-verify`.

### 2. PHP unit tests

Bootstrapped in Week 3 of the audit. Layout:

- [composer.json](../composer.json) — `phpunit/phpunit ^10.5` in `require-dev`; `phpmailer/phpmailer ^6.10` in `require` (phase 1 of the vendored-copy → Composer-dep migration described in [project-reference.md](./project-reference.md) §3). `composer install` already runs on production hosts for the Minishlink WebPush library, so neither addition introduces a new dependency manager — they just extend the existing one.
- [phpunit.xml](../phpunit.xml) at the repo root with a single `unit` suite under `tests/`, `failOnRisky` + `failOnWarning` enabled.
- [tests/bootstrap.php](../tests/bootstrap.php) loads `lib/orders/lifecycle.php` + `lib/Idempotency.php` + the [TestDatabase](../tests/fixtures/TestDatabase.php) fixture — deliberately **does not** bootstrap `session_init.php` / `tenant_runtime.php` so the suite stays fast and hermetic.
- Three test files:
  - [tests/OrdersLifecycleTest.php](../tests/OrdersLifecycleTest.php) — 20+ assertions against the pure-PHP state machine in [lib/orders/lifecycle.php](../lib/orders/lifecycle.php). Pure PHP, zero DB, **always runs**. This is the canonical regression gate for the [order-lifecycle-contract.md](./order-lifecycle-contract.md): any new status or threshold change must come with a matching update here.
  - [tests/IdempotencyTest.php](../tests/IdempotencyTest.php) — exercises [lib/Idempotency.php](../lib/Idempotency.php) against a real MySQL schema. Covers `hashPayload` determinism, store+find replay, same-key/different-payload 409 conflict, scope isolation, upsert on repeated store, expired-row cleanup, `getHeaderKey` trimming/truncation.
  - [tests/CreateOrderTest.php](../tests/CreateOrderTest.php) — exercises `Database::createOrder()` against a real MySQL schema. Bypasses the singleton's private constructor via `ReflectionClass::newInstanceWithoutConstructor()` and injects a PDO directly, so production `db.php` stays untouched. Covers initial status, items JSON, tips column isolation + negative-value clamping, `order_items` rows, `order_status_history` entry, and transaction rollback on `DECIMAL(10,2)` overflow.

The two MySQL-coupled tests self-skip when `CLEANMENU_TEST_MYSQL_DSN` is not exported — see [tests/fixtures/TestDatabase.php](../tests/fixtures/TestDatabase.php). This keeps the lifecycle suite green on vanilla dev hosts while letting CI (or any developer with a scratch DB) opt into the full suite via:

```bash
export CLEANMENU_TEST_MYSQL_DSN="mysql:host=127.0.0.1;dbname=cleanmenu_test;charset=utf8mb4"
export CLEANMENU_TEST_MYSQL_USER="cleanmenu_test"
export CLEANMENU_TEST_MYSQL_PASS="…"
composer test
```

**Composer scripts:**

- `composer test` — full `unit` suite (lifecycle always, MySQL-gated skip if unset).
- `composer test:lifecycle` — `--filter OrdersLifecycle`, the fastest path, ~2 seconds.

**Pre-push wiring.** [.githooks/pre-push](../.githooks/pre-push) runs `vendor/phpunit/phpunit/phpunit --testsuite unit` after the mojibake check and before docs-drift / OpenAPI validation, so a failing unit test fails fast without touching the slower downstream steps. If `vendor/phpunit` is missing the hook prints a hint and continues — push is not blocked on a fresh clone, only once `composer install` has run.

**Why these three files and not more.** The other high-value areas — payments, push, Telegram — are all external-facing and are better covered by the smoke layer above them. Unit tests should own the pure-PHP correctness that smokes can't diagnose without network access.

**Conventions:**

- Each test file targets one file from `db.php` / `lib/*.php`. Do not write "integration" tests in the unit suite — that's what smoke is for.
- Real MySQL only (no SQLite). We use `JSON`, `ENUM`, `INFORMATION_SCHEMA`, `ON DUPLICATE KEY UPDATE` — SQLite would lie about too many of them.
- Fixtures live under `tests/fixtures/`. The schema applied by [TestDatabase::applySchema()](../tests/fixtures/TestDatabase.php) is a minimal slice (only the tables these tests touch); full `bootstrap-schema.sql` remains the production source of truth.
- No mocking of `Database`. If a test needs to stub the DB, it's in the wrong layer. The `CreateOrderTest` reflection trick is for *construction*, not behavior — the actual SQL runs.
- Test names describe the behavior, not the method: `test_same_key_different_payload_is_a_conflict`, not `test_Idempotency_find`.

### 3. API smoke — mobile API v1

Runner: [scripts/api-smoke-runner.php](../scripts/api-smoke-runner.php). Documented in [api-smoke.md](./api-smoke.md). Exercises the full mobile contract against a running site:

```bash
php scripts/api-smoke-runner.php --base=https://menu.labus.pro --run-order=1
```

Covers `login`, `menu`, `POST /api/v1/orders/create.php` (with idempotency replay), status polling, and history read. Exits non-zero on any contract violation. Run manually after every API-surface edit, and automatically by the tenant go-live flow (see [deploy/custom-domain-go-live.md](./deploy/custom-domain-go-live.md)).

The smoke runner does **not** cover the web endpoints (`save-*.php`, `toggle-*.php`, `update_*.php`) because they are not part of the OpenAPI contract. They are covered by the browser regression layer instead.

### 4. Security smoke + baseline diff

Runner: [scripts/perf/security-smoke.sh](../scripts/perf/security-smoke.sh). Checks the public attack surface:

- Required security headers (`Content-Security-Policy`, `Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`).
- `location ^~ /scripts/ { return 404; }` is actually returning 404.
- No provider marketing copy bleeding into tenant public URLs.
- CSP has no `'unsafe-inline'` in `style-src` (the non-negotiable rule — see [public-layer-guidelines.md](./public-layer-guidelines.md)).

The baseline capture script ([scripts/security/capture-baseline.sh](../scripts/security/capture-baseline.sh)) records headers and response sizes to a directory, so two captures can be diffed after a deploy. Go-live captures one before + one after and embeds both paths in the go-live artifact — that's your rollback evidence if something regressed.

### 5. Browser regression (Playwright)

Runner: [scripts/perf/post-release-regression.cjs](../scripts/perf/post-release-regression.cjs), invoked by [scripts/perf/post-release-regression.sh](../scripts/perf/post-release-regression.sh). Wired into:

- The **`post-merge`** git hook, so every `git pull` or merge-to-release triggers a run against the local or staging target.
- The **go-live script**, so every new tenant boots with a green regression.

The regression today is a shallow smoke across the main customer flows (menu → add to cart → checkout → mock payment → success), plus visual stability checks on the owner/admin/employee shells. It is **not** a comprehensive end-to-end suite — it's a "did something obvious break" gate. See the cjs file for the exact steps.

Environment variables: `CLEANMENU_PROVIDER_DOMAIN`, `CLEANMENU_TENANT_DOMAIN`. Both must be reachable over HTTPS.

### 6. Manual owner sign-off

Some things still need a human: the exact shade of a brand color, the "feel" of a motion transition, whether a copy edit reads well in Russian. These are tracked in the [ux-ui-improvement-roadmap.md](./ux-ui-improvement-roadmap.md) "Current implementation notes" section after each sign-off pass, and in the PR description when a visual change is in scope.

## How to add a test when fixing a bug

The rule is: **reproduce the bug at the lowest possible layer, then fix.** Pick from this ladder, stopping at the first layer that can see the bug:

1. A PHPUnit case under `tests/` — preferred for anything in `lib/**`, `db.php` query behavior, or pure helpers. Copy the shape of the existing file closest to the target (lifecycle / idempotency / createOrder) and keep DB-coupled tests MySQL-gated.
2. An API smoke case added to `scripts/api-smoke-runner.php`.
3. A new assertion in the security smoke shell script.
4. A new Playwright step in `post-release-regression.cjs`.
5. A manual test case added to the relevant doc's "Test flow" section.

Do not write a Playwright test for something a unit test could catch — it's 100x slower and harder to debug. Do not write a unit test for something only a real browser can reproduce — it will lie to you about the fix working.

## What to run before merging to `main`

Minimum (enforced by hooks):

1. `pre-push` hook passes (PHP lint, mojibake, PHPUnit `unit` suite, doc drift, OpenAPI).
2. `post-merge` hook passes after you pull (Playwright regression).

Additional, depending on the area touched:

- Any `api/v1/*.php` change → run `scripts/api-smoke-runner.php --run-order=1` manually against a local stack.
- Any `lib/TBank.php`, `generate-payment-link.php`, or `payment-webhook.php` change → manual test flow from [payments-integration.md](./payments-integration.md) at least for the affected provider.
- Any change to nginx templates under `deploy/nginx/` → `nginx -t` on a staging host before pushing.
- Any SQL migration under `sql/` → apply to a scratch tenant DB and re-run `scripts/tenant/smoke.php` on it.
- Any CSP-affecting change → load the page, verify zero `Content-Security-Policy` violation logs in DevTools.

## Known coverage gaps

The PHPUnit layer exists but is intentionally thin — three files, one per highest-value surface. The rest of `db.php` / `lib/*.php` still has no unit coverage and relies on smoke + manual testing. This is fine as long as everyone knows the shape of the gap:

- Changes to any query in `db.php` outside `createOrder()` are not covered by unit tests. You **must** exercise the calling flow (API smoke runner or a manual test documented in the PR). "It compiles" is not validation.
- Refactoring a pure helper in `lib/` with no test is still flying blind. The fix for this is to add a test file — the bootstrap is there, copying the shape of [tests/OrdersLifecycleTest.php](../tests/OrdersLifecycleTest.php) for pure-PHP helpers is a 10-minute task.
- The encoding-bug class we had is a reminder that small changes can misbehave in ways that type checking and linting don't catch — the mojibake scanner catches the class of bug that used to recur, but new classes will always appear.

## Related docs

- [api-smoke.md](./api-smoke.md) — the API smoke runner and what it exercises.
- [security-smoke-checklist.md](./security-smoke-checklist.md) — the security-specific smoke checks.
- [dev/git-hooks.md](./dev/git-hooks.md) — how to install the `pre-push` / `post-merge` hooks that gate this whole strategy.
- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — the state machine that [tests/OrdersLifecycleTest.php](../tests/OrdersLifecycleTest.php) gates.
- [project-improvement-roadmap.md](./project-improvement-roadmap.md) — the broader roadmap that tracked the PHPUnit layer from "target" to "implemented."
