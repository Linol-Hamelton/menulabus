-- modx_redirects cleanup candidates (read-only diagnostics + safe workflow snippets)

-- 1) Distribution by usage.
SELECT
  SUM(triggered = 0) AS never_used,
  SUM(triggered BETWEEN 1 AND 5) AS used_1_5,
  SUM(triggered BETWEEN 6 AND 50) AS used_6_50,
  SUM(triggered > 50) AS used_gt_50
FROM modx_redirects
WHERE active = 1;

-- 2) Potentially weak context rows.
SELECT
  SUM(context_key IS NULL) AS null_context_active,
  SUM(context_key = 'web') AS web_context_active
FROM modx_redirects
WHERE active = 1;

-- 3) Top candidates: active but never triggered.
SELECT id, pattern, target, context_key, triggered, triggered_first, triggered_last
FROM modx_redirects
WHERE active = 1
  AND triggered = 0
ORDER BY id ASC
LIMIT 500;

-- 4) Optional safety table for rollback (run once before first cleanup batch).
-- CREATE TABLE IF NOT EXISTS modx_redirects_backup LIKE modx_redirects;

-- 5) Example batch workflow (manual, intentionally commented).
-- Backup candidate batch:
-- INSERT INTO modx_redirects_backup
-- SELECT *
-- FROM modx_redirects
-- WHERE active = 1 AND triggered = 0
-- ORDER BY id ASC
-- LIMIT 300;

-- Disable same batch by ids copied to backup in previous step:
-- UPDATE modx_redirects r
-- JOIN (
--   SELECT id
--   FROM modx_redirects_backup
--   ORDER BY id DESC
--   LIMIT 300
-- ) b ON b.id = r.id
-- SET r.active = 0;

-- Rollback last batch if needed:
-- UPDATE modx_redirects r
-- JOIN (
--   SELECT id
--   FROM modx_redirects_backup
--   ORDER BY id DESC
--   LIMIT 300
-- ) b ON b.id = r.id
-- SET r.active = 1;
