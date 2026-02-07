-- Apply two-phase redirect lookup patch to plugin id=13 (Redirector).
-- Default is SAFE NO-OP until both confirmations are set.
--
-- Usage:
--   mysql -D labus_pro < sql/modx-plugin-13-two-phase-apply.sql
--
-- Safety:
-- - Backs up current plugincode into modx_site_plugins_code_backup (only when patch is not present).
-- - Patches only when:
--   * plugin id=13, name='Redirector'
--   * not already patched
--   * start/end markers are found
--   * @confirm_menu_scope = 1
--   * @confirm_scope_host = 'menu.labus.pro'

SET @confirm_menu_scope := IFNULL(@confirm_menu_scope, 0);
SET @confirm_scope_host := IFNULL(@confirm_scope_host, '');
SET @require_db := 'labus_pro';
SET @require_plugin_id := 13;
SET @require_plugin_name := 'Redirector';
SET @require_scope_host := 'menu.labus.pro';

SET @start_marker := '/** @var modRedirect $redirect */';
SET @end_marker := '            if (!empty($redirect) && is_object($redirect)) {';
SET @patched_marker := 'Phase A: exact match first';

-- two-phase block stored in escaped MODX DB format (\n as literal chars)
SET @insert_block := '$ctx = $modx->context->get(''key'');\n            $ctxEscape = $modx->quote($ctx);\n\n            /** @var modRedirect $redirect */\n            $redirect = null;\n\n            /**\n             * Phase A: exact match first (cheap, indexed)\n             */\n            $cExact = $modx->newQuery(''modRedirect'');\n            $cExact->where(array(\n                "(`modRedirect`.`pattern` = " . $searchEscape . ")",\n                "(`modRedirect`.`context_key` = " . $ctxEscape . " OR `modRedirect`.`context_key` IS NULL OR `modRedirect`.`context_key` = '''')",\n                ''`modRedirect`.`active` = 1'',\n            ));\n            $cExact->sortby("(`modRedirect`.`context_key` = " . $ctxEscape . ")", ''DESC'');\n            $cExact->sortby(''`modRedirect`.`id`'', ''DESC'');\n            $cExact->limit(1);\n\n            $redirect = $modx->getObject(''modRedirect'', $cExact);\n\n            /**\n             * Phase B: regex fallback only if exact not found\n             */\n            if (empty($redirect) || !is_object($redirect)) {\n                $c = $modx->newQuery(''modRedirect'');\n                $c->where(array(\n                    "(`modRedirect`.`context_key` = " . $ctxEscape . " OR `modRedirect`.`context_key` IS NULL OR `modRedirect`.`context_key` = '''')",\n                    ''`modRedirect`.`active` = 1'',\n                    "(" .\n                        "`modRedirect`.`pattern` = " . $searchEscape .\n                        " OR " . $searchEscape . " REGEXP `modRedirect`.`pattern`" .\n                        " OR " . $searchEscape . " REGEXP CONCAT(''^'', `modRedirect`.`pattern`, ''$'')" .\n                    ")",\n                ));\n                $c->sortby(''`modRedirect`.`id`'', ''DESC'');\n                $c->limit(1);\n\n                $redirect = $modx->getObject(''modRedirect'', $c);\n            }\n\n';

CREATE TABLE IF NOT EXISTS modx_site_plugins_code_backup (
  backup_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plugin_id INT UNSIGNED NOT NULL,
  plugin_name VARCHAR(191) NOT NULL,
  backup_note VARCHAR(191) NOT NULL,
  backed_up_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  plugincode LONGTEXT NOT NULL,
  PRIMARY KEY (backup_id),
  KEY idx_plugin_id_backed_up_at (plugin_id, backed_up_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

INSERT INTO modx_site_plugins_code_backup (plugin_id, plugin_name, backup_note, plugincode)
SELECT id, name, 'before_two_phase_redirect_patch', plugincode
FROM modx_site_plugins
WHERE id = @require_plugin_id
  AND name = @require_plugin_name
  AND DATABASE() = @require_db
  AND @confirm_menu_scope = 1
  AND @confirm_scope_host = @require_scope_host
  AND LOCATE(@patched_marker, plugincode) = 0;

-- diagnostics before patch
SELECT
  DATABASE() AS current_db,
  @confirm_menu_scope AS confirm_menu_scope,
  @confirm_scope_host AS confirm_scope_host,
  id,
  name,
  CHAR_LENGTH(plugincode) AS code_len,
  LOCATE(@start_marker, plugincode) AS start_pos,
  LOCATE(@end_marker, plugincode) AS end_pos,
  LOCATE(@patched_marker, plugincode) AS patched_pos
FROM modx_site_plugins
WHERE id = @require_plugin_id AND name = @require_plugin_name;

UPDATE modx_site_plugins
SET plugincode = CASE
  WHEN DATABASE() = @require_db
    AND @confirm_menu_scope = 1
    AND @confirm_scope_host = @require_scope_host
    AND id = @require_plugin_id
    AND name = @require_plugin_name
    AND LOCATE(@patched_marker, plugincode) = 0
    AND LOCATE(@start_marker, plugincode) > 0
    AND LOCATE(@end_marker, plugincode) > LOCATE(@start_marker, plugincode)
  THEN CONCAT(
    SUBSTRING(plugincode, 1, LOCATE(@start_marker, plugincode) - 1),
    @insert_block,
    SUBSTRING(plugincode, LOCATE(@end_marker, plugincode))
  )
  ELSE plugincode
END
WHERE id = @require_plugin_id
  AND name = @require_plugin_name;

-- diagnostics after patch
SELECT
  DATABASE() AS current_db,
  id,
  name,
  CHAR_LENGTH(plugincode) AS code_len,
  LOCATE(@patched_marker, plugincode) AS patched_pos,
  LOCATE('cExact->where', plugincode) AS cexact_pos
FROM modx_site_plugins
WHERE id = @require_plugin_id
  AND name = @require_plugin_name;

SELECT
  backup_id, plugin_id, plugin_name, backup_note, backed_up_at
FROM modx_site_plugins_code_backup
WHERE plugin_id = @require_plugin_id
ORDER BY backup_id DESC
LIMIT 3;
