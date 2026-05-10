-- sql/recipes-import-migration.sql — Phase 33 recipe CSV-import staging table.
--
-- Idempotent. Tenant-DB only (not control-plane). Apply per-tenant:
--   mysql --defaults-extra-file=/root/.my.cnf <DB_NAME> < sql/recipes-import-migration.sql
--
-- The staging table is intentionally NOT a TEMPORARY table here so DBAs can
-- inspect leftover state if an import crashes mid-flight; bulkSyncRecipesFromCsv
-- uses CREATE TEMPORARY TABLE IF NOT EXISTS at runtime for the actual per-request
-- staging (pattern matches tmp_menu_sync in db.php::bulkSyncMenuFromCsv()).

CREATE TABLE IF NOT EXISTS tmp_recipe_sync (
    dish_external_id  VARCHAR(64) NOT NULL,
    ingredient_name   VARCHAR(255) NOT NULL,
    unit              VARCHAR(16) NOT NULL,
    quantity          DECIMAL(10,3) NOT NULL,
    auto_create       TINYINT(1) NOT NULL DEFAULT 1,
    line_no           INT NOT NULL,
    PRIMARY KEY (dish_external_id, ingredient_name),
    KEY idx_tmp_recipe_dish (dish_external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
