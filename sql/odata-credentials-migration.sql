-- sql/odata-credentials-migration.sql — Phase 37 (1С OData integration).
--
-- Idempotent. Tenant-DB only.
-- Apply per tenant:
--   mysql --protocol=SOCKET -u root <DB_NAME> < sql/odata-credentials-migration.sql
--
-- Single-row table: tenant exposes one OData service to 1С Конфигуратор.
-- Username is a stable identifier; api_key is rotatable secret stored as
-- bcrypt-style hash. Plaintext key is shown once on rotation and never again.

CREATE TABLE IF NOT EXISTS odata_credentials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    api_key_hash VARCHAR(255) NOT NULL,        -- password_hash() result
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_odata_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
