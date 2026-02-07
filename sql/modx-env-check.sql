-- Environment check before applying MODX plugin patches.
-- Read-only. Safe to run.
--
-- Usage:
--   mysql -D labus_pro < sql/modx-env-check.sql

SELECT DATABASE() AS current_db, NOW() AS checked_at;

-- Core MODX tables required for plugin patch workflow
SELECT
  SUM(table_name = 'modx_site_plugins') AS has_modx_site_plugins,
  SUM(table_name = 'modx_site_plugin_events') AS has_modx_site_plugin_events,
  SUM(table_name = 'modx_system_settings') AS has_modx_system_settings
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('modx_site_plugins', 'modx_site_plugin_events', 'modx_system_settings');

-- Redirector plugin presence
SELECT id, name, disabled, static, CHAR_LENGTH(plugincode) AS code_len
FROM modx_site_plugins
WHERE id = 13 OR name = 'Redirector'
ORDER BY id;

-- Redirector attached events
SELECT p.id, p.name, e.event, e.priority
FROM modx_site_plugins p
JOIN modx_site_plugin_events e ON e.pluginid = p.id
WHERE p.id = 13 OR p.name = 'Redirector'
ORDER BY p.id, e.priority, e.event;

-- controlImportExport runtime hook status (for diagnostics)
SELECT p.id, p.name, e.event, e.priority
FROM modx_site_plugins p
JOIN modx_site_plugin_events e ON e.pluginid = p.id
WHERE p.id = 28 OR p.name = 'controlImportExport'
ORDER BY p.id, e.priority, e.event;

-- Useful system settings if present
SELECT `key`, `value`
FROM modx_system_settings
WHERE `key` IN ('site_url', 'base_url', 'cultureKey', 'friendly_urls')
ORDER BY `key`;

