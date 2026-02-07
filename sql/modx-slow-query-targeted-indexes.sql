-- Targeted indexes for current slow-query top (MODX tables)
-- Safe to run multiple times (idempotent checks via information_schema).
-- Run in the project DB (example: USE labus_pro;).

SET @db := DATABASE();

-- 1) modx_redirects: helps filter by active/context before regexp checks.
SET @sql := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db
          AND table_name = 'modx_redirects'
          AND index_name = 'idx_modx_redirects_active_context'
    ),
    'SELECT ''skip idx_modx_redirects_active_context'' AS status',
    'ALTER TABLE `modx_redirects` ADD INDEX `idx_modx_redirects_active_context` (`active`, `context_key`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) modx_register_topics: speeds up lookup by topic name.
SET @sql := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db
          AND table_name = 'modx_register_topics'
          AND index_name = 'idx_modx_register_topics_name'
    ),
    'SELECT ''skip idx_modx_register_topics_name'' AS status',
    'ALTER TABLE `modx_register_topics` ADD INDEX `idx_modx_register_topics_name` (`name`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) modx_register_messages: join/order hot-path for topic feed polling.
SET @sql := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db
          AND table_name = 'modx_register_messages'
          AND index_name = 'idx_modx_register_messages_topic_created_valid'
    ),
    'SELECT ''skip idx_modx_register_messages_topic_created_valid'' AS status',
    'ALTER TABLE `modx_register_messages` ADD INDEX `idx_modx_register_messages_topic_created_valid` (`topic`, `created`, `valid`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Secondary filter index for valid-window scans.
SET @sql := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db
          AND table_name = 'modx_register_messages'
          AND index_name = 'idx_modx_register_messages_topic_valid'
    ),
    'SELECT ''skip idx_modx_register_messages_topic_valid'' AS status',
    'ALTER TABLE `modx_register_messages` ADD INDEX `idx_modx_register_messages_topic_valid` (`topic`, `valid`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ANALYZE TABLE `modx_redirects`;
ANALYZE TABLE `modx_register_topics`;
ANALYZE TABLE `modx_register_messages`;

-- 4b) modx_redirects: exact-match fast path for future two-phase redirect lookup.
SET @sql := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db
          AND table_name = 'modx_redirects'
          AND index_name = 'idx_modx_redirects_active_context_pattern'
    ),
    'SELECT ''skip idx_modx_redirects_active_context_pattern'' AS status',
    'ALTER TABLE `modx_redirects` ADD INDEX `idx_modx_redirects_active_context_pattern` (`active`, `context_key`, `pattern`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ANALYZE TABLE `modx_redirects`;

-- 5) modx_msop_modifications: filters by rid/active/type with id/rank ordering.
SET @sql := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db
          AND table_name = 'modx_msop_modifications'
          AND index_name = 'idx_msop_modifications_rid_active_type_id_rank'
    ),
    'SELECT ''skip idx_msop_modifications_rid_active_type_id_rank'' AS status',
    'ALTER TABLE `modx_msop_modifications` ADD INDEX `idx_msop_modifications_rid_active_type_id_rank` (`rid`, `active`, `type`, `id`, `rank`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6) modx_msop_modification_options: key lookup with GROUP BY mid.
SET @sql := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db
          AND table_name = 'modx_msop_modification_options'
          AND index_name = 'idx_msop_mod_options_key_mid'
    ),
    'SELECT ''skip idx_msop_mod_options_key_mid'' AS status',
    'ALTER TABLE `modx_msop_modification_options` ADD INDEX `idx_msop_mod_options_key_mid` (`key`, `mid`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7) modx_msop_modification_options: key+value lookup with GROUP BY mid.
-- value is TEXT/BLOB on many installs, so index uses a safe prefix.
SET @sql := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db
          AND table_name = 'modx_msop_modification_options'
          AND index_name = 'idx_msop_mod_options_key_value_mid'
    ),
    'SELECT ''skip idx_msop_mod_options_key_value_mid'' AS status',
    'ALTER TABLE `modx_msop_modification_options` ADD INDEX `idx_msop_mod_options_key_value_mid` (`key`, `value`(64), `mid`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8) modx_msop_modification_options: rid/key scans for large IN-list queries.
SET @sql := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db
          AND table_name = 'modx_msop_modification_options'
          AND index_name = 'idx_msop_mod_options_rid_key_mid'
    ),
    'SELECT ''skip idx_msop_mod_options_rid_key_mid'' AS status',
    'ALTER TABLE `modx_msop_modification_options` ADD INDEX `idx_msop_mod_options_rid_key_mid` (`rid`, `key`, `mid`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ANALYZE TABLE `modx_msop_modifications`;
ANALYZE TABLE `modx_msop_modification_options`;
