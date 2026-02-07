-- Find where msop-heavy SQL is stored (plugins/snippets/chunks/modules in DB).
-- Useful when app code lives in MODX DB elements rather than filesystem.
--
-- Usage:
--   mysql -D labus_pro < sql/modx-msop-code-hunt.sql

SELECT 'plugins' AS source, id, name, disabled, static, CHAR_LENGTH(plugincode) AS code_len
FROM modx_site_plugins
WHERE
    plugincode LIKE '%modx_msop_modifications%'
    OR plugincode LIKE '%modx_msop_modification_options%'
    OR plugincode LIKE '%msopModification%'
    OR plugincode LIKE '%billboard_side%'
    OR plugincode LIKE '%billboard_booking%'
ORDER BY id;

SELECT 'snippets' AS source, id, name, CHAR_LENGTH(snippet) AS code_len
FROM modx_site_snippets
WHERE
    snippet LIKE '%modx_msop_modifications%'
    OR snippet LIKE '%modx_msop_modification_options%'
    OR snippet LIKE '%msopModification%'
    OR snippet LIKE '%billboard_side%'
    OR snippet LIKE '%billboard_booking%'
ORDER BY id;

SELECT 'chunks' AS source, id, name, CHAR_LENGTH(snippet) AS code_len
FROM modx_site_htmlsnippets
WHERE
    snippet LIKE '%modx_msop_modifications%'
    OR snippet LIKE '%modx_msop_modification_options%'
    OR snippet LIKE '%msopModification%'
ORDER BY id;

SELECT 'modules' AS source, id, name, CHAR_LENGTH(script) AS code_len
FROM modx_site_modules
WHERE
    script LIKE '%modx_msop_modifications%'
    OR script LIKE '%modx_msop_modification_options%'
    OR script LIKE '%msopModification%'
ORDER BY id;
