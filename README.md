# Menu Labus

Restaurant menu and ordering platform for `menu.labus.pro`.

## RU: Быстрый контекст

Этот репозиторий содержит production-код веб-приложения и API:

- публичное меню и оформление заказов
- кабинет/админ-зоны для персонала и владельца
- mobile API (`/api/v1/*`) с bearer-токенами
- realtime-обновления заказов (`/orders-sse.php`, `/ws-poll.php`)
- набор security/deploy/runbook-документов

### Стек

- PHP + MySQL
- Nginx + PHP-FPM (pool split: web/api/sse)
- Redis/cache abstractions (опционально)
- PWA + push notifications

### Где что смотреть

- Общая актуальная справка: [`docs/project-reference.md`](./docs/project-reference.md)
- Карта документации: [`docs/index.md`](./docs/index.md)
- API контракт (source of truth): [`docs/openapi.yaml`](./docs/openapi.yaml)
- API smoke: [`docs/api-smoke.md`](./docs/api-smoke.md)
- Deployment flow: [`docs/deployment-workflow.md`](./docs/deployment-workflow.md)
- Security runbooks: `docs/security-*`

### Local quick checks

```bash
npm ci
npm run openapi:validate
```

### Current constraints

- Shared-host scope lock: не трогать Docker/порты других сайтов в рамках menu-only изменений.
- API contract source of truth: `docs/openapi.yaml`.
- Legacy docs находятся в `docs/archive/` и не являются source of truth.

## EN: Quickstart for engineers/LLM

1. Start with [`docs/index.md`](./docs/index.md) for the full docs map.
2. Read [`docs/project-reference.md`](./docs/project-reference.md) for architecture and active flows.
3. Treat [`docs/openapi.yaml`](./docs/openapi.yaml) as the API contract source of truth.
4. Validate API spec with `npm run openapi:validate`.
5. Use [`docs/deployment-workflow.md`](./docs/deployment-workflow.md) for server pull/reload flow.
6. Use `docs/security-*` + `scripts/perf/security-smoke.sh` for hardening and smoke verification.
7. Ignore `docs/archive/*` for implementation decisions (legacy only).
