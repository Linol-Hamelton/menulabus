-- EXPLAIN pack for common msop slow-query patterns seen in slow logs.
-- Replace example literals with real values from your logs before final analysis.
--
-- Usage:
--   mysql -D labus_pro < sql/modx-msop-explain-pack.sql

-- Pattern A: large IN(rid...) + key filter + join to modifications(active/type)
EXPLAIN
SELECT
    Modification.rid AS product_id,
    ModificationOption.`key`,
    ModificationOption.`value`
FROM modx_msop_modification_options AS ModificationOption
JOIN modx_msop_modifications AS Modification
    ON Modification.id = ModificationOption.mid
   AND Modification.type = 0
   AND Modification.active = 1
WHERE ModificationOption.rid IN (1,2,3,4,5)
  AND ModificationOption.`key` IN ('billboard_side');

-- Pattern B: per-product options fetch with active modifications
EXPLAIN
SELECT
    mo.mid, mo.rid, mo.`key`, mo.`value`
FROM modx_msop_modification_options AS mo
JOIN modx_msop_modifications AS m
    ON m.id = mo.mid
WHERE mo.rid = 1
  AND m.active = 1;

-- Pattern C: main filter/order on modifications table
EXPLAIN
SELECT m.id
FROM modx_msop_modifications AS m
WHERE m.rid = 1
  AND m.active = 1
  AND m.type NOT IN (2)
ORDER BY m.id DESC, m.type ASC, m.rank ASC;

-- Optional cardinality snapshot (helps explain plan quality)
SHOW INDEX FROM modx_msop_modifications;
SHOW INDEX FROM modx_msop_modification_options;

