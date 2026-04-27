-- Reservation reminder migration (Polish 12.2.3, 2026-04-27).
-- Adds a single nullable column to track which reservations have already
-- received a Telegram reminder. The worker (scripts/reservation-reminder-worker.php)
-- claims pending rows where the column IS NULL and the reservation starts
-- within the next ~2 hours, sends a Telegram message, and stamps the
-- column with NOW() on success.
--
-- Idempotent: ALTER TABLE … ADD COLUMN IF NOT EXISTS is supported by
-- MySQL 8.0+ via the standard ADD COLUMN syntax with a column-name guard
-- check. We check via INFORMATION_SCHEMA before issuing the ALTER so the
-- migration is safe to re-run.

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'reservations'
      AND COLUMN_NAME = 'reminder_sent_at'
);

SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE reservations ADD COLUMN reminder_sent_at DATETIME NULL DEFAULT NULL AFTER confirmed_at',
    'SELECT 0'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index supports the worker's claim query (status IN (...) AND
-- reminder_sent_at IS NULL AND starts_at BETWEEN ...). The existing
-- idx_reservations_status_time already covers (status, starts_at), so
-- we lean on it and filter reminder_sent_at IS NULL in the WHERE clause.
