-- Roll back plugin id=13 (Redirector) to latest backup from modx_site_plugins_code_backup.
-- Default is SAFE NO-OP until both confirmations are set.
--
-- Usage in one mysql session:
--   SET @confirm_menu_scope := 1;
--   SET @confirm_scope_host := 'menu.labus.pro';
--   -- optional: SET @target_backup_id := 123;
--   SOURCE sql/modx-plugin-13-two-phase-rollback.sql;
--
-- Safety:
-- - Restores only:
--   * DATABASE() = 'labus_pro'
--   * plugin id=13, name='Redirector'
--   * @confirm_menu_scope = 1
--   * @confirm_scope_host = 'menu.labus.pro'

SET @confirm_menu_scope := IFNULL(@confirm_menu_scope, 0);
SET @confirm_scope_host := IFNULL(@confirm_scope_host, '');
SET @require_db := 'labus_pro';
SET @require_plugin_id := 13;
SET @require_plugin_name := 'Redirector';
SET @require_scope_host := 'menu.labus.pro';
SET @target_backup_id := IFNULL(@target_backup_id, 0);

SELECT
  DATABASE() AS current_db,
  @confirm_menu_scope AS confirm_menu_scope,
  @confirm_scope_host AS confirm_scope_host,
  @target_backup_id AS target_backup_id;

SELECT
  p.id,
  p.name,
  CHAR_LENGTH(p.plugincode) AS code_len_current,
  b.backup_id,
  b.backed_up_at,
  CHAR_LENGTH(b.plugincode) AS code_len_backup
FROM modx_site_plugins p
LEFT JOIN (
  SELECT backup_id, plugin_id, backed_up_at, plugincode
  FROM modx_site_plugins_code_backup
  WHERE plugin_id = @require_plugin_id
    AND (@target_backup_id = 0 OR backup_id = @target_backup_id)
  ORDER BY backup_id DESC
  LIMIT 1
) b ON b.plugin_id = p.id
WHERE p.id = @require_plugin_id
  AND p.name = @require_plugin_name;

UPDATE modx_site_plugins p
JOIN (
  SELECT backup_id, plugin_id, plugincode
  FROM modx_site_plugins_code_backup
  WHERE plugin_id = @require_plugin_id
    AND (@target_backup_id = 0 OR backup_id = @target_backup_id)
  ORDER BY backup_id DESC
  LIMIT 1
) b ON b.plugin_id = p.id
SET p.plugincode = b.plugincode
WHERE p.id = @require_plugin_id
  AND p.name = @require_plugin_name
  AND DATABASE() = @require_db
  AND @confirm_menu_scope = 1
  AND @confirm_scope_host = @require_scope_host;

SELECT
  p.id,
  p.name,
  CHAR_LENGTH(p.plugincode) AS code_len_after_rollback,
  LOCATE('Phase A: exact match first', p.plugincode) AS phase_a_marker_pos
FROM modx_site_plugins p
WHERE p.id = @require_plugin_id
  AND p.name = @require_plugin_name;
