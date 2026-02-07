# ACTION PLAN 2026 — menu.labus.pro (актуально на 2026-02-08)

## Что уже реализовано в проекте
- FastCGI-кэш для `menu.php` и общего PHP-трафика уже настроен в `nginx-optimized.conf`.
- Публичное меню вынесено в `menu-public.php` (без сессии), приватное — в `menu.php`.
- Кэш уровня приложения есть: `QueryCache` + `RedisCache` (подключен в `db.php`).
- Инвалидация кэша реализована через `clear-cache.php` (client/server scope).
- SQL-пакет для медленных MODX-запросов подготовлен в `sql/`.
- Мониторинг состояния приложения есть в `monitor.php`.

## Что сейчас не доведено до конца
- API microcache в Nginx не включен (endpoint'ы идут напрямую в PHP-FPM).
- `RedisCache` без batch-методов (`mget/mset`) и без `getOrSet`.
- В `php-fpm-optimization.conf` режим `pm=dynamic`, JIT выключен (закомментирован).
- Документация была раздроблена и частично ссылалась на несуществующие файлы.

## План «здесь и сейчас» (приоритет сверху вниз)

### Шаг 1. Убрать текущий SQL bottleneck (день 1)
1. Применить индексы:
   `mysql labus_pro < sql/modx-slow-query-targeted-indexes.sql`
2. Прогнать EXPLAIN-пакет:
   `mysql -D labus_pro < sql/modx-msop-explain-pack.sql`
3. Снять короткий slow-log до/после и сравнить `Rows_examined` по:
   - `modx_redirects`
   - `modx_msop_modifications`
   - `modx_msop_modification_options`

Критерий готовности:
- падение `Rows_examined` по горячим запросам минимум в 2-3 раза;
- снижение BYPASS p95/p99 по `wrk`.

### Шаг 2. Дожать Redirector (день 1-2)
1. Выполнить аудит:
   `mysql -D labus_pro < sql/modx-redirects-audit.sql`
2. Запускать cleanup пакетами (безопасно, с rollback):
   - `sql/modx-redirects-cleanup-run-01.sql`
   - при необходимости `sql/modx-redirects-cleanup-rollback-last.sql`
3. После каждого пакета: 60с BYPASS-нагрузка + контроль slow-log.

Критерий готовности:
- `modx_redirects` уходит из топа медленных запросов;
- стабилизация p95 BYPASS.

### Шаг 3. Включить microcache для API (день 2)
1. Добавить отдельную cache-zone и microcache для API endpoint'ов в `nginx-optimized.conf`.
2. Сразу исключить персонализированные запросы и авторизованные сессии из кэширования.
3. Проверить `X-Cache-Status` и отсутствие функциональных регрессий.

Критерий готовности:
- на повторяющихся API-запросах доля HIT растет;
- меньше загрузка PHP-FPM под пиковыми burst.

### Шаг 4. Точечный рефактор RedisCache (день 2-3)
1. Добавить методы:
   - `mget(array $keys)`
   - `mset(array $pairs, int $ttl)`
   - `getOrSet($key, callable $resolver, int $ttl)`
2. Применить в `db.php` на чтениях меню/категорий.
3. Проверить корректность invalidate-паттернов после изменений.

Критерий готовности:
- меньше round-trip к Redis;
- меньше повторных SQL-чтений при пиковом трафике.

### Шаг 5. Контролируемый тюнинг PHP-FPM/OPcache (день 3)
1. Сначала зафиксировать baseline по `monitor.php` + `wrk`.
2. Поднять только безопасные параметры OPcache (без резкого изменения архитектуры).
3. JIT включать только после сравнения до/после на ваших реальных запросах.
4. Переход в `pm=static` делать только после расчета по RAM (иначе риск OOM).

Критерий готовности:
- нет роста 5xx;
- латентность лучше baseline;
- нет деградации по памяти.

## Как мерить после каждого шага
- `wrk` (серверная метрика): HIT и BYPASS отдельно.
- `python load_test.py <url> -n <N> -c <C>` (клиентская метрика).
- `monitor.php`: OPcache hit rate, память, общая health-сводка.
- slow query log: сравнение top-N до/после.

## Целевые метрики на ближайший спринт
- BYPASS p95: снизить минимум на 30% от текущего baseline.
- HIT p95: удерживать стабильно < 120 ms.
- 5xx: < 0.1% на тестовом профиле нагрузки.
- Slow queries: убрать постоянное доминирование `modx_redirects`/`msop_*`.

## Актуальные документы
- `ACTION-PLAN-2026.md` — основной рабочий план.
- `SLOW-QUERY-PLAYBOOK.md` — цикл диагностики/фикса SQL.
- `load_test_guide.md` — запуск и интерпретация нагрузочного теста.
