-- Batch cleanup for modx_redirects: disable never-used redirects safely.
-- Safe default: only active rows with triggered = 0.
--
-- Usage:
--   mysql -D labus_pro < sql/modx-redirects-cleanup-run-01.sql
-- Re-run the same file for next batches (there is no run-02/run-03 by design).
--
-- Notes:
-- - Stores batch ids in helper table.
-- - Copies full rows to backup table before disable.
-- - Prints batch id and affected rows for audit.

SET @batch_size := 500;
SET @batch_id := CONCAT(
  DATE_FORMAT(NOW(), 'cleanup_%Y%m%d_%H%i%s'),
  '_',
  LPAD(FLOOR(RAND() * 1000000), 6, '0')
);

CREATE TABLE IF NOT EXISTS modx_redirects_backup LIKE modx_redirects;

CREATE TABLE IF NOT EXISTS modx_redirects_cleanup_batches (
  batch_id VARCHAR(64) NOT NULL,
  id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (batch_id, id),
  KEY idx_cleanup_batches_id (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

SET @sql := CONCAT(
  'INSERT INTO modx_redirects_cleanup_batches (batch_id, id) ',
  'SELECT ''', @batch_id, ''', r.id ',
  'FROM modx_redirects r ',
  'WHERE r.active = 1 AND r.triggered = 0 ',
  'ORDER BY r.id ASC ',
  'LIMIT ', @batch_size
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO modx_redirects_backup
SELECT r.*
FROM modx_redirects r
JOIN modx_redirects_cleanup_batches b ON b.id = r.id
WHERE b.batch_id = @batch_id;

UPDATE modx_redirects r
JOIN modx_redirects_cleanup_batches b ON b.id = r.id
SET r.active = 0
WHERE b.batch_id = @batch_id
  AND r.active = 1;

SELECT @batch_id AS batch_id;

SELECT
  COUNT(*) AS ids_in_batch
FROM modx_redirects_cleanup_batches
WHERE batch_id = @batch_id;

SELECT
  COUNT(*) AS disabled_now
FROM modx_redirects r
JOIN modx_redirects_cleanup_batches b ON b.id = r.id
WHERE b.batch_id = @batch_id
  AND r.active = 0;

SELECT
  COUNT(*) AS still_active_total
FROM modx_redirects
WHERE active = 1;

SELECT r.id, r.pattern, r.target, r.context_key, r.triggered
FROM modx_redirects r
JOIN modx_redirects_cleanup_batches b ON b.id = r.id
WHERE b.batch_id = @batch_id
ORDER BY r.id ASC
LIMIT 20;
