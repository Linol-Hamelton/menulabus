<?php
/**
 * SubscriptionStore — control-plane DAO for billing tables (Phase 14.3+).
 *
 * Wraps reads/writes against:
 *   - tenants               — billing fields (plan_id, status, period_end, etc.)
 *   - subscription_invoices
 *   - payment_methods
 *   - subscription_events   — audit log
 *
 * Lives in the control-plane DB only. PDO is reused from the global cache
 * populated by FeatureGate::controlPlanePdo() / tenant_runtime.php.
 *
 * Public methods:
 *   getTenantBilling(int $tenantId): ?array
 *   updateTenantStatus(int $tenantId, string $status, ?string $periodEnd = null, ?string $planId = null): bool
 *   getDefaultPaymentMethod(int $tenantId): ?array
 *   savePaymentMethod(int $tenantId, array $info): int
 *   createInvoice(int $tenantId, string $planId, string $periodStart, string $periodEnd, int $amountKop, ?string $ykPaymentId = null): int
 *   updateInvoiceByYk(string $ykPaymentId, string $status, ?string $failureReason = null): bool
 *   getInvoicesByTenant(int $tenantId, int $limit = 12): array
 *   getDueForCharge(int $batchSize): array
 *   logEvent(int $tenantId, string $eventType, array $payload = []): void
 *   onWebhook(string $paymentId, string $apiStatus, array $ykPayment): void
 */

namespace Cleanmenu\Billing;

require_once __DIR__ . '/FeatureGate.php';

final class SubscriptionStore
{
    public static function pdo(): \PDO
    {
        $r = new \ReflectionClass(FeatureGate::class);
        $m = $r->getMethod('controlPlanePdo');
        $m->setAccessible(true);
        $pdo = $m->invoke(null);
        if (!$pdo) {
            throw new \RuntimeException('SubscriptionStore: control-plane PDO unavailable');
        }
        return $pdo;
    }

    public static function getTenantBilling(int $tenantId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT id, brand_slug, plan_id, subscription_status,
                    trial_ends_at, current_period_end, owner_email, owner_user_id, is_active
             FROM tenants WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function updateTenantStatus(
        int $tenantId,
        string $status,
        ?string $periodEnd = null,
        ?string $planId = null
    ): bool {
        $allowed = ['trial', 'active', 'past_due', 'suspended', 'cancelled'];
        if (!in_array($status, $allowed, true)) return false;

        $sets   = ['subscription_status = :status'];
        $params = [':status' => $status, ':id' => $tenantId];
        if ($periodEnd !== null) { $sets[] = 'current_period_end = :pe'; $params[':pe'] = $periodEnd; }
        if ($planId !== null)    { $sets[] = 'plan_id = :pl'; $params[':pl'] = $planId; }
        $sql = 'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $ok = self::pdo()->prepare($sql)->execute($params);
        if ($ok) {
            self::logEvent($tenantId, 'status_changed', [
                'status'      => $status,
                'period_end'  => $periodEnd,
                'plan_id'     => $planId,
            ]);
        }
        return (bool)$ok;
    }

    public static function getDefaultPaymentMethod(int $tenantId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT id, yk_payment_method_id, last4, brand, expires_month, expires_year
             FROM payment_methods
             WHERE tenant_id = :id AND is_default = 1
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function savePaymentMethod(int $tenantId, array $info): int
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            // De-default existing methods.
            $pdo->prepare('UPDATE payment_methods SET is_default = 0 WHERE tenant_id = :id')
                ->execute([':id' => $tenantId]);

            $stmt = $pdo->prepare(
                'INSERT INTO payment_methods
                   (tenant_id, provider, yk_payment_method_id, last4, brand,
                    expires_month, expires_year, is_default)
                 VALUES (:tid, :prov, :yk, :l4, :br, :em, :ey, 1)
                 ON DUPLICATE KEY UPDATE
                    last4 = VALUES(last4), brand = VALUES(brand),
                    expires_month = VALUES(expires_month),
                    expires_year = VALUES(expires_year),
                    is_default = 1'
            );
            $stmt->execute([
                ':tid'  => $tenantId,
                ':prov' => (string)($info['provider'] ?? 'yookassa'),
                ':yk'   => (string)$info['yk_payment_method_id'],
                ':l4'   => $info['last4'] ?? null,
                ':br'   => $info['brand'] ?? null,
                ':em'   => $info['expires_month'] ?? null,
                ':ey'   => $info['expires_year'] ?? null,
            ]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
            self::logEvent($tenantId, 'payment_method_saved', $info);
            return $id;
        } catch (\Throwable $e) {
            try { $pdo->rollBack(); } catch (\Throwable $_) {}
            throw $e;
        }
    }

    public static function createInvoice(
        int $tenantId,
        string $planId,
        string $periodStart,
        string $periodEnd,
        int $amountKop,
        ?string $ykPaymentId = null
    ): int {
        $stmt = self::pdo()->prepare(
            'INSERT INTO subscription_invoices
               (tenant_id, plan_id, period_start, period_end, amount_kop, yk_payment_id)
             VALUES (:tid, :pl, :ps, :pe, :amt, :yk)'
        );
        $stmt->execute([
            ':tid' => $tenantId,
            ':pl'  => $planId,
            ':ps'  => $periodStart,
            ':pe'  => $periodEnd,
            ':amt' => $amountKop,
            ':yk'  => $ykPaymentId,
        ]);
        return (int)self::pdo()->lastInsertId();
    }

    public static function updateInvoiceByYk(string $ykPaymentId, string $status, ?string $failureReason = null): bool
    {
        $allowed = ['pending', 'paid', 'failed', 'refunded', 'cancelled'];
        if (!in_array($status, $allowed, true)) return false;
        $sql  = 'UPDATE subscription_invoices SET status = :status';
        if ($status === 'paid')   $sql .= ', paid_at = NOW()';
        if ($status === 'failed') $sql .= ', failure_reason = :reason, retry_count = retry_count + 1';
        $sql .= ' WHERE yk_payment_id = :yk';

        $params = [':status' => $status, ':yk' => $ykPaymentId];
        if ($status === 'failed') $params[':reason'] = (string)($failureReason ?? '');
        return self::pdo()->prepare($sql)->execute($params);
    }

    public static function attachYkPaymentToInvoice(int $invoiceId, string $ykPaymentId): bool
    {
        return self::pdo()->prepare(
            'UPDATE subscription_invoices SET yk_payment_id = :yk WHERE id = :id'
        )->execute([':yk' => $ykPaymentId, ':id' => $invoiceId]);
    }

    public static function setNextRetry(int $invoiceId, string $nextRetryAt): bool
    {
        return self::pdo()->prepare(
            'UPDATE subscription_invoices SET next_retry_at = :nr WHERE id = :id'
        )->execute([':nr' => $nextRetryAt, ':id' => $invoiceId]);
    }

    public static function getInvoicesByTenant(int $tenantId, int $limit = 12): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = self::pdo()->prepare(
            "SELECT id, plan_id, period_start, period_end, amount_kop, currency,
                    status, yk_payment_id, retry_count, paid_at, created_at
             FROM subscription_invoices
             WHERE tenant_id = :id
             ORDER BY created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Returns active tenants whose current_period_end is within 1 day OR
     * whose status is past_due with next_retry_at <= NOW(). Worker iterates
     * this and creates/charges invoices.
     */
    public static function getDueForCharge(int $batchSize = 50): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT t.id, t.plan_id, t.subscription_status, t.current_period_end, t.owner_email
             FROM tenants t
             WHERE t.is_active = 1
               AND t.subscription_status IN ('active', 'past_due')
               AND (
                 (t.subscription_status = 'active' AND t.current_period_end <= DATE_ADD(NOW(), INTERVAL 1 DAY))
                 OR EXISTS (
                   SELECT 1 FROM subscription_invoices i
                   WHERE i.tenant_id = t.id
                     AND i.status = 'failed'
                     AND i.next_retry_at IS NOT NULL
                     AND i.next_retry_at <= NOW()
                 )
               )
             LIMIT {$batchSize}"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getRetryableInvoice(int $tenantId): ?array
    {
        $stmt = self::pdo()->prepare(
            "SELECT id, plan_id, period_start, period_end, amount_kop, retry_count
             FROM subscription_invoices
             WHERE tenant_id = :id
               AND status = 'failed'
               AND next_retry_at IS NOT NULL
               AND next_retry_at <= NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function logEvent(int $tenantId, string $eventType, array $payload = []): void
    {
        try {
            self::pdo()->prepare(
                'INSERT INTO subscription_events (tenant_id, event_type, payload)
                 VALUES (:tid, :type, :pl)'
            )->execute([
                ':tid'  => $tenantId,
                ':type' => $eventType,
                ':pl'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            error_log('SubscriptionStore::logEvent: ' . $e->getMessage());
        }
    }

    /**
     * Called from payment-webhook.php when YK payment metadata.kind = 'subscription_invoice'.
     * Updates the invoice + saves payment_method on first success.
     *
     * @param array $ykPayment full /v3/payments/{id} response body decoded
     */
    public static function onWebhook(string $paymentId, string $apiStatus, array $ykPayment): void
    {
        $metadata = $ykPayment['metadata'] ?? [];
        $tenantId = (int)($metadata['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            error_log('SubscriptionStore::onWebhook: missing tenant_id in metadata');
            return;
        }

        if ($apiStatus === 'succeeded') {
            // Save payment method on FIRST successful charge (initial phase).
            $pmInfo = $ykPayment['payment_method'] ?? [];
            if (!empty($pmInfo['id']) && !empty($pmInfo['saved'])) {
                $card = $pmInfo['card'] ?? [];
                self::savePaymentMethod($tenantId, [
                    'yk_payment_method_id' => (string)$pmInfo['id'],
                    'last4'                => $card['last4'] ?? null,
                    'brand'                => $card['card_type'] ?? null,
                    'expires_month'        => isset($card['expiry_month']) ? (int)$card['expiry_month'] : null,
                    'expires_year'         => isset($card['expiry_year']) ? (int)$card['expiry_year'] : null,
                ]);
            }

            // Mark invoice paid (idempotent — repeated webhooks ok).
            self::updateInvoiceByYk($paymentId, 'paid');

            // Bump tenant status to active + extend period.
            $billing = self::getTenantBilling($tenantId);
            if ($billing) {
                $newEnd = date('Y-m-d H:i:s', strtotime('+1 month'));
                self::updateTenantStatus($tenantId, 'active', $newEnd, null);
            }
            self::logEvent($tenantId, 'charge_success', [
                'yk_payment_id' => $paymentId,
                'phase'         => $metadata['phase'] ?? 'unknown',
            ]);
        } elseif ($apiStatus === 'canceled') {
            $reason = (string)(($ykPayment['cancellation_details']['reason'] ?? '') ?: 'canceled');
            self::updateInvoiceByYk($paymentId, 'failed', $reason);
            // Schedule retry per soft-dunning policy: day 1 → +24h, day 4 → +3d, day 7 → +3d, day 8+ → past_due.
            $invoice = self::pdo()->prepare(
                'SELECT id, retry_count, tenant_id FROM subscription_invoices WHERE yk_payment_id = :yk LIMIT 1'
            );
            $invoice->execute([':yk' => $paymentId]);
            $row = $invoice->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $retryCount = (int)$row['retry_count']; // already incremented by updateInvoiceByYk
                $invoiceId  = (int)$row['id'];
                if ($retryCount >= 4) {
                    // Day 30 — fully suspend.
                    self::updateTenantStatus($tenantId, 'suspended');
                } elseif ($retryCount >= 3) {
                    self::updateTenantStatus($tenantId, 'past_due');
                    self::setNextRetry($invoiceId, date('Y-m-d H:i:s', strtotime('+22 days')));
                } elseif ($retryCount === 2) {
                    self::updateTenantStatus($tenantId, 'past_due');
                    self::setNextRetry($invoiceId, date('Y-m-d H:i:s', strtotime('+3 days')));
                } elseif ($retryCount === 1) {
                    self::setNextRetry($invoiceId, date('Y-m-d H:i:s', strtotime('+3 days')));
                } else {
                    self::setNextRetry($invoiceId, date('Y-m-d H:i:s', strtotime('+24 hours')));
                }
            }
            self::logEvent($tenantId, 'charge_failed', [
                'yk_payment_id' => $paymentId,
                'reason'        => $reason,
                'retry_count'   => $row['retry_count'] ?? 0,
            ]);
        }
    }
}
