-- OAuth identities table for mobile token-based OAuth (Google/VK/Yandex/etc.)
-- Apply on production:
--   mysql -D <DB_NAME> < sql/mobile-oauth-identities.sql

CREATE TABLE IF NOT EXISTS oauth_identities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  provider VARCHAR(32) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  email VARCHAR(255) DEFAULT NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_provider_subject (provider, subject),
  KEY idx_user_id (user_id),
  KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

