-- Roll back the latest cleanup batch created by modx-redirects-cleanup-run-01.sql
--
-- Usage:
--   mysql -D labus_pro < sql/modx-redirects-cleanup-rollback-last.sql

SET @last_batch_id := (
  SELECT batch_id
  FROM modx_redirects_cleanup_batches
  ORDER BY created_at DESC
  LIMIT 1
);

SELECT @last_batch_id AS rollback_batch_id;

UPDATE modx_redirects r
JOIN modx_redirects_cleanup_batches b ON b.id = r.id
SET r.active = 1
WHERE b.batch_id = @last_batch_id;

SELECT
  COUNT(*) AS restored_rows
FROM modx_redirects r
JOIN modx_redirects_cleanup_batches b ON b.id = r.id
WHERE b.batch_id = @last_batch_id
  AND r.active = 1;
