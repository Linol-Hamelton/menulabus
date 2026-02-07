-- Remaining active redirects breakdown (for cleanup pacing decisions)

SELECT
  COUNT(*) AS active_total,
  SUM(triggered = 0) AS active_never_used,
  SUM(triggered > 0) AS active_used
FROM modx_redirects
WHERE active = 1;

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
  ) AS active_regex_like,
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
  ) AS active_plain_like
FROM modx_redirects
WHERE active = 1;

SELECT
  SUM(
    triggered = 0 AND (
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
  ) AS active_regex_never_used,
  SUM(
    triggered = 0 AND NOT (
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
  ) AS active_plain_never_used
FROM modx_redirects
WHERE active = 1;
