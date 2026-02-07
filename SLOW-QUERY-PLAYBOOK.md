# Slow Query Playbook (BYPASS Focus)

This is the shortest reliable loop to improve BYPASS latency in production.

## 1) Capture a clean slow-query sample

```bash
mysql -e "SET GLOBAL slow_query_log_file='/tmp/menu-slow.log'; \
SET GLOBAL long_query_time=0.05; \
SET GLOBAL log_queries_not_using_indexes=OFF; \
SET GLOBAL slow_query_log='ON';"
```

Run BYPASS load (new random session per request):

```bash
cat > /tmp/wrk-randsess.lua <<'LUA'
math.randomseed(os.time())
request = function()
  return wrk.format("GET", "/menu.php", { ["Cookie"] = "PHPSESSID=" .. math.random(1, 1e9) })
end
LUA

wrk -t4 -c50 -d30s --latency -s /tmp/wrk-randsess.lua https://menu.labus.pro/menu.php
```

Stop capture and print top:

```bash
mysql -e "SET GLOBAL slow_query_log='OFF';"
mysqldumpslow -s t -t 20 /tmp/menu-slow.log
```

## 2) Apply targeted indexes for current MODX hot queries

```bash
mysql labus_pro < sql/modx-slow-query-targeted-indexes.sql
```

If file is not found on server, you are in the wrong directory or the file is not uploaded yet:

```bash
cd /var/www/labus_pro_usr/data/www/menu.labus.pro
ls -la sql/modx-slow-query-targeted-indexes.sql
mysql labus_pro < sql/modx-slow-query-targeted-indexes.sql
```

## 3) Validate with EXPLAIN (critical)

```bash
mysql -D labus_pro -e "
EXPLAIN SELECT msg.* FROM modx_register_messages msg
JOIN modx_register_topics topic ON topic.id = msg.topic
WHERE msg.valid <= NOW()
  AND topic.name = '/modstore/...'
ORDER BY msg.created ASC
LIMIT 5;

EXPLAIN SELECT id, pattern, target
FROM modx_redirects
WHERE active = 1
  AND (context_key = 'web' OR context_key IS NULL);
"
```

Expected: fewer examined rows and stable index usage.
Current priority by your latest slow-log: `modx_msop_modifications` + `modx_msop_modification_options`, then `modx_redirects`.

## 4) Re-run baseline tests

Use both:
- `wrk` for steady-state server measurement.
- `load_test.py` from external client for real-network UX perspective.

Track:
- BYPASS p95 latency.
- RPS under BYPASS.
- `fpm-status?full` (`listen queue`, `max children reached`).

## 5) Query rewrite priorities after indexing

1. Reduce/disable noisy MODX polling (`modx_register_messages`) if not business-critical for menu runtime.
2. Keep redirects table compact; remove stale regex redirects.
3. For expensive custom menu queries, enforce narrow `WHERE`, avoid `SELECT *`, and cap rows per request.

## 6) Redirect bottleneck triage (`modx_redirects`)

If slow-log still shows `modx_redirects` with thousands of examined rows per request, indexes alone are not enough.

Run audit from project dir:

```bash
mysql -D labus_pro < sql/modx-redirects-audit.sql
```

What to look at:
- `active_rows` should be much lower than total; if almost all are active, index selectivity is weak.
- `regex_like` should be small; a large regex share usually causes full scans.
- `top contexts` should be tight (`web` + tiny `NULL` tail).

Practical next step:
1. Keep exact redirects and regex redirects separated logically.
2. Resolve exact match first (`pattern = ?` with indexed lookup).
3. Run regex fallback only when exact match misses.

Expected effect:
- lower `Rows_examined` for redirect lookup;
- lower BYPASS p95/p99 without touching public HIT path.

## 6.1) Immediate safe gain without code rewrite: trim active redirects

Your current snapshot (`active_rows == total_rows`) means every request scans a very large active set.
Before touching app logic, run candidate audit:

```bash
mysql -D labus_pro < sql/modx-redirects-cleanup-candidates.sql
```

Then disable only low-risk candidates first:
- `triggered = 0`
- old `context_key IS NULL` entries duplicating modern `web` routes
- obvious legacy patterns not used by current menu app

Do it in batches and measure after each batch:
1. disable 200-500 rows
2. run BYPASS `wrk` 60s
3. compare slow-log `Rows_examined` and p95/p99

Rollback rule:
- every batch must be restorable by ids from backup table.

Ready-to-run scripts:

```bash
# Disable next batch of never-used redirects (default 500)
mysql -D labus_pro < sql/modx-redirects-cleanup-run-01.sql

# Roll back latest batch if needed
mysql -D labus_pro < sql/modx-redirects-cleanup-rollback-last.sql
```

Higher-impact variant first (regex-like + never-used):

```bash
mysql -D labus_pro < sql/modx-redirects-cleanup-regex-never-used.sql
mysql -D labus_pro < sql/modx-redirects-remaining-breakdown.sql
```

For multiple batches, run the same script repeatedly, or use helper:

```bash
chmod +x sql/modx-redirects-cleanup-run-many.sh
./sql/modx-redirects-cleanup-run-many.sh 3 labus_pro
```

After each cleanup batch:

```bash
wrk -t4 -c50 -d60s --latency -s /tmp/wrk-randsess.lua https://menu.labus.pro/menu.php
```

Then capture quick slow-log sample and compare `modx_redirects` `Rows_examined`.

## 7) When `mysqldumpslow` crashes on long lines

Use raw slow-log fallback:

```bash
grep -n "Query_time:" /tmp/menu-slow.log | tail -n 40
```

This is enough to track regressions while you iterate.

## 8) Next phase: `msop_*` source hunt + query rewrite target

Now that redirect lookup is optimized, move to `modx_msop_*` only.

Find where these heavy queries live (DB elements, not only filesystem):

```bash
mysql -D labus_pro < sql/modx-msop-code-hunt.sql
```

If results are non-empty, dump matching plugin/snippet code and patch there.

Run focused EXPLAIN pack:

```bash
mysql -D labus_pro < sql/modx-msop-explain-pack.sql
```

Practical goal for this phase:
- remove nested correlated `IF(count(...), EXISTS(...), TRUE)` patterns where possible;
- split logic into 2 steps in app code:
  - Step A: fetch candidate `mid` set by indexed filters;
  - Step B: apply optional value filters on a reduced set.

Measure exactly like before:

```bash
mysql -e "SET GLOBAL slow_query_log_file='/tmp/menu-slow-msop.log'; SET GLOBAL long_query_time=0.05; SET GLOBAL log_queries_not_using_indexes=OFF; SET GLOBAL slow_query_log='ON';"
wrk -t4 -c50 -d60s --latency -s /tmp/wrk-randsess.lua https://menu.labus.pro/menu.php
mysql -e "SET GLOBAL slow_query_log='OFF';"
grep -n "Query_time:" /tmp/menu-slow-msop.log | tail -n 40
```

## 9) Safe apply/rollback for Redirector plugin patch (menu scope only)

Run environment check first:

```bash
mysql -D labus_pro < sql/modx-env-check.sql
```

Apply two-phase patch safely (single mysql session with confirmations):

```bash
mysql -D labus_pro <<'SQL'
SET @confirm_menu_scope := 1;
SET @confirm_scope_host := 'menu.labus.pro';
SOURCE sql/modx-plugin-13-two-phase-apply.sql;
SQL
```

Rollback to latest plugin backup (same safety gates):

```bash
mysql -D labus_pro <<'SQL'
SET @confirm_menu_scope := 1;
SET @confirm_scope_host := 'menu.labus.pro';
SOURCE sql/modx-plugin-13-two-phase-rollback.sql;
SQL
```

Rollback to a specific backup id:

```bash
mysql -D labus_pro <<'SQL'
SET @confirm_menu_scope := 1;
SET @confirm_scope_host := 'menu.labus.pro';
SET @target_backup_id := 123; -- replace with real id
SOURCE sql/modx-plugin-13-two-phase-rollback.sql;
SQL
```

Quick verify:

```bash
mysql -D labus_pro <<'SQL'
SELECT
  LOCATE('Phase A: exact match first', plugincode) AS has_phase_a,
  LOCATE('cExact->where', plugincode) AS has_cexact
FROM modx_site_plugins
WHERE id=13;
SQL
```
