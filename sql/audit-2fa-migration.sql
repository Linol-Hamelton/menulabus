-- Audit log + 2FA migration (Phase 9.3).
-- Run once on each tenant DB. Idempotent.
--
-- audit_log: append-only trail of privileged actions. Any admin / owner
-- surface that mutates tenant state should write a row here so forensic
-- review is possible. actor_id is nullable because we also log system-level
-- actions (cron jobs, webhook workers).
--
-- user_2fa: per-user TOTP secret + one-use recovery codes. Enabled flag
-- toggles enforcement on login. Backup codes stored as hashes (bcrypt
-- or sodium_crypto_pwhash_str); raw codes are shown to the user once.

CREATE TABLE IF NOT EXISTS audit_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id      INT DEFAULT NULL,
    actor_role    VARCHAR(32) DEFAULT NULL,
    action        VARCHAR(64) NOT NULL,
    target_type   VARCHAR(32) DEFAULT NULL,
    target_id     VARCHAR(64) DEFAULT NULL,
    ip            VARCHAR(45) DEFAULT NULL,
    user_agent    VARCHAR(255) DEFAULT NULL,
    meta_json     JSON DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor (actor_id, created_at),
    KEY idx_audit_action (action, created_at),
    KEY idx_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_2fa (
    user_id        INT NOT NULL,
    secret         VARCHAR(64) NOT NULL,
    enabled        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    backup_codes_json JSON DEFAULT NULL,
    last_used_at   DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_2fa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
