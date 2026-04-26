<?php

/**
 * Shared "order just transitioned to paid" hook (Phase 6.3 + 8.1).
 *
 * Called from every payment-confirmation path:
 *   - payment-webhook.php (YooKassa + T-Bank branches)
 *   - confirm-cash-payment.php (staff-confirmed cash)
 *
 * Side effects:
 *   - Accrues loyalty points (idempotent per (user_id, order_id) at the DB layer).
 *   - Dispatches the `payment.received` webhook for external integrations.
 *
 * Wrapped in `function_exists` guard so multiple require_once's from different
 * endpoints in the same request never trigger PHP "Cannot redeclare" fatal.
 * The trigger condition is unlikely in normal request flow (each endpoint
 * is a leaf), but the guard removes a class of latent failures.
 */

if (!function_exists('cleanmenu_on_order_paid')) {
    function cleanmenu_on_order_paid(Database $db, int $orderId): void
    {
        try {
            $order = $db->getOrderById($orderId);
            if (!$order) return;

            $userId = (int)($order['user_id'] ?? 0);
            $total  = (float)($order['total'] ?? 0);
            if ($userId > 0 && $total > 0) {
                $db->accrueLoyaltyPoints($userId, $orderId, $total);
            }

            require_once __DIR__ . '/WebhookDispatcher.php';
            WebhookDispatcher::dispatch('payment.received', $order, $db);
        } catch (Throwable $e) {
            error_log('cleanmenu_on_order_paid error: ' . $e->getMessage());
        }
    }
}
