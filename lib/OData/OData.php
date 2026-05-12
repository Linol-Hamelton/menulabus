<?php
/**
 * lib/OData/OData.php — Phase 37 (1С OData integration).
 *
 * Minimal OData v3 envelope + query-parser for the three read-only endpoints
 * (/api/v1/odata/orders, menu_items, customers).
 *
 * Supports a deliberate subset of OData query options sufficient for
 * 1С Конфигуратор external-data-source flow:
 *   $top=N             — page size (1..1000, default 100)
 *   $skip=N            — offset (default 0)
 *   $count=true        — include __count in envelope
 *   $select=a,b,c      — column whitelist
 *   $filter=...        — small expression grammar:
 *       <field> eq <value>
 *       <field> ne <value>
 *       <field> gt <value>      (numeric / iso-datetime)
 *       <field> ge <value>
 *       <field> lt <value>
 *       <field> le <value>
 *       substringof('s',<field>) — translates to LIKE '%s%'
 *       <expr> and <expr>        — multiple terms (AND only)
 *
 * Anything outside of this subset is rejected with 400.
 *
 * Output JSON shape:
 *   {
 *     "d": {
 *       "__count": "42",          // only when $count=true
 *       "results": [ {row}, ... ]
 *     }
 *   }
 */

namespace Cleanmenu\OData;

use InvalidArgumentException;

final class OData
{
    /**
     * Parse query string into { top, skip, count, select, filter_sql, filter_params }.
     * $allowedFields whitelists field names usable in $select/$filter.
     *
     * @param array $query  Usually $_GET.
     * @param array<string,string> $allowedFields  field => column (column may include qualified name)
     *
     * @return array{top:int,skip:int,count:bool,select:array<string>,
     *               filter_sql:string,filter_params:array<string,mixed>}
     */
    public static function parseQuery(array $query, array $allowedFields): array
    {
        $top = isset($query['$top']) ? (int)$query['$top'] : 100;
        if ($top < 1) $top = 1;
        if ($top > 1000) $top = 1000;

        $skip = isset($query['$skip']) ? max(0, (int)$query['$skip']) : 0;
        if ($skip > 1_000_000) $skip = 1_000_000;

        $count = isset($query['$count'])
            && in_array(strtolower((string)$query['$count']), ['true', '1', 'yes'], true);

        $select = [];
        if (!empty($query['$select'])) {
            foreach (preg_split('/\s*,\s*/', (string)$query['$select']) as $f) {
                if (isset($allowedFields[$f])) {
                    $select[] = $f;
                }
            }
        }
        if (empty($select)) {
            $select = array_keys($allowedFields);
        }

        $filterSql = '';
        $filterParams = [];
        if (!empty($query['$filter'])) {
            [$filterSql, $filterParams] = self::compileFilter((string)$query['$filter'], $allowedFields);
        }

        return [
            'top'           => $top,
            'skip'          => $skip,
            'count'         => $count,
            'select'        => $select,
            'filter_sql'    => $filterSql,
            'filter_params' => $filterParams,
        ];
    }

    /**
     * Compile a minimal $filter expression into (sql, params) tuple.
     * Throws InvalidArgumentException for syntax errors.
     */
    private static function compileFilter(string $expr, array $allowedFields): array
    {
        $parts = preg_split('/\s+and\s+/i', $expr);
        if (!is_array($parts) || empty($parts)) {
            throw new InvalidArgumentException('Empty $filter');
        }
        $sqlParts = [];
        $params = [];
        $i = 0;
        foreach ($parts as $term) {
            $term = trim($term);
            if ($term === '') continue;

            // substringof('text', field)
            if (preg_match("/^substringof\\(\\s*'([^']*)'\\s*,\\s*([a-zA-Z_][a-zA-Z_0-9]*)\\s*\\)$/i", $term, $m)) {
                $field = $m[2];
                if (!isset($allowedFields[$field])) {
                    throw new InvalidArgumentException("Unknown field in filter: {$field}");
                }
                $key = ':f' . (++$i);
                $sqlParts[] = $allowedFields[$field] . ' LIKE ' . $key;
                $params[$key] = '%' . $m[1] . '%';
                continue;
            }

            // <field> <op> <value>
            if (preg_match("/^([a-zA-Z_][a-zA-Z_0-9]*)\\s+(eq|ne|gt|ge|lt|le)\\s+(.+)$/i", $term, $m)) {
                $field = $m[1];
                $op    = strtolower($m[2]);
                $rhs   = trim($m[3]);
                if (!isset($allowedFields[$field])) {
                    throw new InvalidArgumentException("Unknown field in filter: {$field}");
                }
                $sqlOp = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'ge' => '>=', 'lt' => '<', 'le' => '<='][$op];
                $value = self::parseLiteral($rhs);
                $key = ':f' . (++$i);
                $sqlParts[] = $allowedFields[$field] . ' ' . $sqlOp . ' ' . $key;
                $params[$key] = $value;
                continue;
            }

            throw new InvalidArgumentException("Unrecognized term: {$term}");
        }
        return [implode(' AND ', $sqlParts), $params];
    }

    /**
     * Parse a literal: 'str', datetime'iso-8601', number, true/false.
     */
    private static function parseLiteral(string $s)
    {
        $s = trim($s);
        if ($s === '') return null;
        // String literal
        if (preg_match("/^'((?:''|[^'])*)'$/", $s, $m)) {
            return str_replace("''", "'", $m[1]);
        }
        // datetime'YYYY-MM-DDTHH:MM:SS' — strip the wrapper, keep the inner string for MySQL.
        if (preg_match("/^datetime'([^']+)'$/i", $s, $m)) {
            return str_replace('T', ' ', $m[1]);
        }
        // null
        if (strcasecmp($s, 'null') === 0) return null;
        // bool
        if (strcasecmp($s, 'true') === 0) return 1;
        if (strcasecmp($s, 'false') === 0) return 0;
        // numeric
        if (is_numeric($s)) {
            return strpos($s, '.') !== false ? (float)$s : (int)$s;
        }
        throw new InvalidArgumentException("Unparseable literal: {$s}");
    }

    /**
     * Emit standardized OData envelope and `Content-Type: application/json; odata=verbose`.
     */
    public static function emit(array $results, ?int $totalCount = null): void
    {
        header('Content-Type: application/json; odata=verbose; charset=utf-8');
        header('Cache-Control: no-store');
        $payload = ['d' => ['results' => array_values($results)]];
        if ($totalCount !== null) {
            $payload['d']['__count'] = (string)$totalCount;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Emit OData error envelope per spec.
     */
    public static function emitError(int $httpCode, string $code, string $message): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; odata=verbose; charset=utf-8');
        echo json_encode([
            'error' => [
                'code' => $code,
                'message' => ['lang' => 'ru', 'value' => $message],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
