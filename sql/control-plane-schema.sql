CREATE TABLE IF NOT EXISTS tenants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mode ENUM('provider', 'tenant') NOT NULL DEFAULT 'tenant',
  brand_slug VARCHAR(120) NOT NULL,
  db_name VARCHAR(190) NOT NULL,
  db_user VARCHAR(190) NOT NULL,
  db_pass_enc MEDIUMTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tenants_brand_slug (brand_slug),
  UNIQUE KEY uniq_tenants_db_name (db_name),
  UNIQUE KEY uniq_tenants_db_user (db_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_domains (
  host VARCHAR(253) NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (host),
  KEY idx_tenant_domains_tenant_id (tenant_id),
  KEY idx_tenant_domains_primary (tenant_id, is_primary),
  CONSTRAINT fk_tenant_domains_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
