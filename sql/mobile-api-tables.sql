-- Mobile API support tables: refresh tokens + idempotency + order_items

CREATE TABLE IF NOT EXISTS mobile_refresh_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device_name VARCHAR(120) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  ip_address VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_mobile_refresh_hash (token_hash),
  KEY idx_mobile_refresh_user (user_id),
  KEY idx_mobile_refresh_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_idempotency_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  idempotency_key VARCHAR(128) NOT NULL,
  scope VARCHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  response_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_idempotency_scope_key (scope, idempotency_key),
  KEY idx_idempotency_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  item_name VARCHAR(255) DEFAULT NULL,
  quantity INT NOT NULL DEFAULT 1,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_items_order_id (order_id),
  KEY idx_order_items_item_id (item_id),
  KEY idx_order_items_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional backfill (run in maintenance window).
-- INSERT INTO order_items (order_id, item_id, item_name, quantity, price, created_at)
-- SELECT o.id, jt.item_id, jt.item_name, jt.quantity, jt.price, o.created_at
-- FROM orders o
-- JOIN JSON_TABLE(o.items, '$[*]' COLUMNS (
--   item_id INT PATH '$.id',
--   item_name VARCHAR(255) PATH '$.name',
--   quantity INT PATH '$.quantity',
--   price DECIMAL(10,2) PATH '$.price'
-- )) jt;

