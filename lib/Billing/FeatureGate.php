<?php
/**
 * FeatureGate — runtime per-tenant feature + limit checks (Phase 14.2).
 *
 * Reads `tenants.plan_id` and `subscription_status` from the control-plane
 * DB once per request (cached in $_SESSION['_billing_state'] and a static
 * variable to avoid touching control DB on every check).
 *
 * Public API:
 *   FeatureGate::isAllowed('kds')        // bool — can use the feature
 *   FeatureGate::limit('max_menu_items') // int|null — usage cap, null = unlimited
 *   FeatureGate::status()                // 'trial' | 'active' | 'past_due' | 'suspended' | 'cancelled'
 *   FeatureGate::planId()                // current plan id
 *   FeatureGate::renderPaywall($feature) // HTML stub for blocked surfaces
 *   FeatureGate::isReadOnly()            // true when past_due — block writes
 *   FeatureGate::isSuspended()           // true when suspended — block reads too
 *   FeatureGate::clearCache()            // invalidate after plan change
 *
 * Suspended state is enforced at the session_init.php gate (returns 503 for
 * customer flows). past_due is soft — admin pages render a banner, write
 * actions return 402 Payment Required.
 */

namespace Cleanmenu\Billing;

require_once __DIR__ . '/PlanRegistry.php';

final class FeatureGate
{
    /** @var array{plan_id:string,status:string,trial_ends_at:?string,current_period_end:?string}|null */
    private static ?array $cache = null;

    /**
     * Returns the billing state for the current tenant. Reads from
     * control-plane DB on first call, caches for the rest of the request.
     * Returns null in legacy/single-DB mode (no control plane configured).
     */
    public static function state(): ?array
    {
        if (self::$cache !== null) return self::$cache;

        $tenantId = $GLOBALS['tenantId'] ?? null;
        if (!$tenantId) {
            self::$cache = ['plan_id' => 'pro', 'status' => 'active', 'trial_ends_at' => null, 'current_period_end' => null];
            return self::$cache;
        }

        $pdo = self::controlPlanePdo();
        if (!$pdo) {
            self::$cache = ['plan_id' => 'pro', 'status' => 'active', 'trial_ends_at' => null, 'current_period_end' => null];
            return self::$cache;
        }

        try {
            $stmt = $pdo->prepare('SELECT plan_id, subscription_status, trial_ends_at, current_period_end
                                   FROM tenants WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                self::$cache = ['plan_id' => 'pro', 'status' => 'active', 'trial_ends_at' => null, 'current_period_end' => null];
                return self::$cache;
            }
            self::$cache = [
                'plan_id'            => (string)$row['plan_id'],
                'status'             => (string)$row['subscription_status'],
                'trial_ends_at'      => $row['trial_ends_at'] ?? null,
                'current_period_end' => $row['current_period_end'] ?? null,
            ];
        } catch (\Throwable $e) {
            error_log('FeatureGate::state read failed: ' . $e->getMessage());
            self::$cache = ['plan_id' => 'pro', 'status' => 'active', 'trial_ends_at' => null, 'current_period_end' => null];
        }
        return self::$cache;
    }

    public static function planId(): string
    {
        return self::state()['plan_id'] ?? 'pro';
    }

    public static function status(): string
    {
        return self::state()['status'] ?? 'active';
    }

    public static function trialEndsAt(): ?string
    {
        return self::state()['trial_ends_at'] ?? null;
    }

    public static function currentPeriodEnd(): ?string
    {
        return self::state()['current_period_end'] ?? null;
    }

    public static function isAllowed(string $feature): bool
    {
        // Suspended tenants can't access anything.
        if (self::isSuspended()) return false;
        $plan = PlanRegistry::byId(self::planId());
        if (!$plan) return false;
        return !empty($plan['features'][$feature]);
    }

    public static function limit(string $key): ?int
    {
        $plan = PlanRegistry::byId(self::planId());
        if (!$plan) return null;
        return $plan['limits'][$key] ?? null;
    }

    public static function isReadOnly(): bool
    {
        return in_array(self::status(), ['past_due', 'suspended', 'cancelled'], true);
    }

    public static function isSuspended(): bool
    {
        return self::status() === 'suspended';
    }

    public static function clearCache(): void
    {
        self::$cache = null;
        unset($_SESSION['_billing_state']);
    }

    /**
     * Throws / exits with 402 Payment Required if a write operation is
     * attempted in read-only state. Call at the top of API endpoints.
     */
    public static function requireWriteable(): void
    {
        if (!self::isReadOnly()) return;
        http_response_code(402);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'subscription_payment_required',
            'status'  => self::status(),
            'message' => 'Подписка не активна. Откройте /owner.php?tab=billing чтобы возобновить.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function renderPaywall(string $feature, ?string $featureLabel = null): string
    {
        $label = $featureLabel ?? $feature;
        $plan  = PlanRegistry::byId(self::planId());
        $name  = $plan['name'] ?? 'Текущий тариф';
        return '<div class="billing-paywall" role="status">'
             . '<h3>Функция «' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '» недоступна на тарифе ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</h3>'
             . '<p>Для доступа перейдите на тариф Pro или Enterprise.</p>'
             . '<a href="/owner.php?tab=billing" class="checkout-btn">Сменить тариф</a>'
             . '</div>';
    }

    /**
     * Open a PDO to the control-plane DB. Returns null when there is no
     * control plane configured (legacy single-DB mode).
     */
    private static function controlPlanePdo(): ?\PDO
    {
        if (!empty($GLOBALS['controlPlanePdo']) && $GLOBALS['controlPlanePdo'] instanceof \PDO) {
            return $GLOBALS['controlPlanePdo'];
        }
        $cfg = $GLOBALS['cleanmenuTenantRuntime']['control'] ?? null;
        if (!$cfg) return null;
        try {
            $dsn = 'mysql:host=' . ($cfg['host'] ?? '127.0.0.1')
                 . ';dbname=' . ($cfg['db'] ?? '')
                 . ';charset=utf8mb4';
            $pdo = new \PDO(
                $dsn,
                (string)($cfg['user'] ?? ''),
                (string)($cfg['pass'] ?? ''),
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $GLOBALS['controlPlanePdo'] = $pdo;
            return $pdo;
        } catch (\Throwable $e) {
            error_log('FeatureGate: cannot open control-plane PDO: ' . $e->getMessage());
            return null;
        }
    }
}
