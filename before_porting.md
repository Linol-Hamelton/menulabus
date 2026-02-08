API-контракт как отдельный слой (/api/v1/*)
Сейчас логика размазана по страницам и session-эндпойнтам. Для mobile это самый дорогой техдолг.
Сделайте стабильные JSON DTO + OpenAPI.

Auth для mobile (token-based)
Сейчас все завязано на cookie/session (session_init.php, check-auth.php). Для iOS/Android лучше access+refresh token, а веб оставить на cookie.

Убрать дорогой polling в ws-poll.php
Сейчас каждый poll дергает несколько SQL (в т.ч. по orders). На мобильном это быстро станет bottleneck.
Минимум: Redis-ключ “last_update”; лучше: SSE/WebSocket.

Идемпотентность создания заказа
Для create_new_order.php/create_guest_order.php добавьте Idempotency-Key, чтобы при плохой сети не плодить дубли.

Нормализовать orders.items JSON
Для аналитики/статусов и мобильных экранов лучше таблица order_items, иначе вы упретесь в SQL/CPU на росте.

Починить конфликт манифестов
AssetPipeline.php пишет в manifest.json, а это же PWA manifest (manifest.json).
Разделите: asset-manifest.json и manifest.webmanifest.

Подготовить CORS/headers под mobile runtime
В session_init.php жестко задан menu.labus.pro.
Для нативного клиента (Capacitor/React Native) это часто ломает запросы.

Наблюдаемость API
Добавьте request_id, p95/p99 по эндпойнтам, отдельный лог ошибок API. Это резко снизит стоимость отладки мобильных релизов.

Контрактные и smoke-тесты API
Перед портом зафиксируйте 10-15 ключевых сценариев (auth, меню, корзина, заказ, статус, push-subscribe).

Offline-стратегия (минимум)
Очередь несинхронизированных действий (черновик корзины/заказа) + retry policy.