-- Audit for modx_redirects hot-path.
-- Safe read-only diagnostics.

SELECT
  COUNT(*) AS total_rows,
  SUM(active = 1) AS active_rows
FROM modx_redirects;

SELECT
  COALESCE(context_key, 'NULL') AS context_key,
  COUNT(*) AS rows_count
FROM modx_redirects
GROUP BY context_key
ORDER BY rows_count DESC;

-- Regex-like heuristic without REGEXP character-class escaping issues.
SELECT
  SUM(
    pattern LIKE '%[%'
    OR pattern LIKE '%]%'
    OR pattern LIKE '%(%'
    OR pattern LIKE '%)%'
    OR pattern LIKE '%|%'
    OR pattern LIKE '%+%'
    OR pattern LIKE '%*%'
    OR pattern LIKE '%?%'
    OR pattern LIKE '%.%'
    OR pattern LIKE '%^%'
    OR pattern LIKE '%$%'
  ) AS regex_like,
  SUM(
    NOT (
      pattern LIKE '%[%'
      OR pattern LIKE '%]%'
      OR pattern LIKE '%(%'
      OR pattern LIKE '%)%'
      OR pattern LIKE '%|%'
      OR pattern LIKE '%+%'
      OR pattern LIKE '%*%'
      OR pattern LIKE '%?%'
      OR pattern LIKE '%.%'
      OR pattern LIKE '%^%'
      OR pattern LIKE '%$%'
    )
  ) AS plain_like
FROM modx_redirects
WHERE active = 1;

-- Top heavy patterns by trigger count: useful for cleanup decisions.
SELECT id, pattern, target, context_key, triggered
FROM modx_redirects
WHERE active = 1
ORDER BY triggered DESC
LIMIT 30;
