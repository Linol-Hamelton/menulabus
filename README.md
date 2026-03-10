# Menu Labus

White-label restaurant menu and ordering platform.

`menu.labus.pro` is the provider-owned deployment used for B2B promotion and demo flows.
Client restaurant deployments should run the same codebase on their own domains with separate databases.

## RU: Быстрый контекст

Этот репозиторий содержит:

- provider-mode публичный слой для продвижения решения от лица `Labus`
- tenant-mode публичный слой для реальных ресторанов на клиентских доменах
- кабинет/админ-зоны для персонала и владельца
- mobile API (`/api/v1/*`) с bearer-токенами
- realtime-обновления заказов (`/orders-sse.php`, `/ws-poll.php`)
- набор security/deploy/runbook-документов

## Основные правила

- `1 клиент = 1 отдельная БД`
- имя БД должно содержать slug бренда клиента
- `1 клиент = 1 домен + 1 набор бренд-настроек`
- провайдерский B2B-контент не должен попадать на клиентские домены
- source of truth по документации хранится в `docs/`

## Стек

- PHP + MySQL
- Nginx + PHP-FPM (pool split: web/api/sse)
- Redis/cache abstractions (опционально)
- PWA + push notifications

## Где что смотреть

- Карта документации: [`docs/index.md`](./docs/index.md)
- Продуктовая модель: [`docs/product-model.md`](./docs/product-model.md)
- Архитектура и active flows: [`docs/project-reference.md`](./docs/project-reference.md)
- Приоритетный roadmap: [`docs/project-improvement-roadmap.md`](./docs/project-improvement-roadmap.md)
- Runbook запуска нового клиента: [`docs/tenant-launch-checklist.md`](./docs/tenant-launch-checklist.md)
- API контракт: [`docs/openapi.yaml`](./docs/openapi.yaml)
- Deployment flow: [`docs/deployment-workflow.md`](./docs/deployment-workflow.md)
- Security roadmap и smoke: `docs/security-*`

## Local quick checks

```bash
npm ci
npm run openapi:validate
```

## Current constraints

- Shared-host scope lock: не трогать Docker/порты других сайтов в рамках menu-only изменений.
- API contract source of truth: `docs/openapi.yaml`.
