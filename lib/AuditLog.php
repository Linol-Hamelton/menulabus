<?php

/**
 * AuditLog — append-only trail of privileged actions (Phase 9.3).
 *
 * Usage from any admin path:
 *
 *   AuditLog::record('menu_item.archive', 'menu_item', (int)$id, ['from' => $oldState]);
 *
 * Implementation notes:
 *   - actor_id / actor_role are pulled from the session automatically so
 *     the caller doesn't have to thread them through.
 *   - ip / user_agent are sampled once — same values a web-layer request log
 *     would see. SSE/cron callers explicitly pass null via record() kwargs
 *     to avoid bogus "server IP" entries.
 *   - meta_json is truncated to 8 KB server-side to keep rows small.
 *   - Never throws: a silent error_log() on DB failure beats blocking
 *     the triggering business action.
 */
final class AuditLog
{
    private const MAX_META_BYTES = 8192;

    public static function record(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $meta = [],
        ?int $actorIdOverride = null
    ): void {
        if ($action === '' || mb_strlen($action) > 64) return;

        $actorId = $actorIdOverride ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
        $actorRole = isset($_SESSION['user_role']) ? (string)$_SESSION['user_role'] : null;
        $ip = self::requestIp();
        $userAgent = self::requestUserAgent();

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($metaJson)) $metaJson = '{}';
        if (strlen($metaJson) > self::MAX_META_BYTES) {
            $metaJson = json_encode([
                '_truncated' => true,
                'original_size' => strlen($metaJson),
            ], JSON_UNESCAPED_UNICODE);
        }

        try {
            if (!class_exists('Database', false)) {
                return;
            }
            $db = Database::getInstance();
            $stmt = $db->getConnection()->prepare("
                INSERT INTO audit_log (actor_id, actor_role, action, target_type, target_id, ip, user_agent, meta_json)
                VALUES (:actor, :role, :action, :ttype, :tid, :ip, :ua, :meta)
            ");
            $stmt->execute([
                ':actor'  => $actorId,
                ':role'   => $actorRole,
                ':action' => $action,
                ':ttype'  => $targetType,
                ':tid'    => $targetId,
                ':ip'     => $ip,
                ':ua'     => $userAgent,
                ':meta'   => $metaJson,
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog::record error: ' . $e->getMessage());
        }
    }

    /**
     * Read the last N entries, optionally filtered by actor_id / target / action.
     * Used by owner audit-log page.
     */
    public static function recent(int $limit = 100, array $filters = []): array
    {
        $limit = max(1, min(1000, $limit));
        $clauses = ['1=1'];
        $params = [];
        if (!empty($filters['actor_id'])) {
            $clauses[] = 'actor_id = :actor';
            $params[':actor'] = (int)$filters['actor_id'];
        }
        if (!empty($filters['action'])) {
            $clauses[] = 'action LIKE :action';
            $params[':action'] = $filters['action'] . '%';
        }
        if (!empty($filters['target_type'])) {
            $clauses[] = 'target_type = :ttype';
            $params[':ttype'] = $filters['target_type'];
        }
        $where = implode(' AND ', $clauses);

        try {
            $db = Database::getInstance();
            $stmt = $db->getConnection()->prepare("
                SELECT id, actor_id, actor_role, action, target_type, target_id,
                       ip, user_agent, meta_json, created_at
                FROM audit_log
                WHERE {$where}
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            error_log('AuditLog::recent error: ' . $e->getMessage());
            return [];
        }
    }

    private static function requestIp(): ?string
    {
        $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $val = (string)$_SERVER[$key];
                // X-Forwarded-For can contain a chain — take the first.
                if (strpos($val, ',') !== false) $val = trim(explode(',', $val)[0]);
                if (filter_var($val, FILTER_VALIDATE_IP)) return $val;
            }
        }
        return null;
    }

    private static function requestUserAgent(): ?string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') return null;
        return mb_substr($ua, 0, 255);
    }
}
