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

            // Phase 7.2: fiscalisation. Best-effort — failures must not
            // block payment confirmation. The fiscal worker polls
            // pending receipts to fill in the URL once the provider
            // finishes processing.
            cleanmenu_emit_fiscal_receipt($db, $order);

            require_once __DIR__ . '/WebhookDispatcher.php';
            WebhookDispatcher::dispatch('payment.received', $order, $db);
        } catch (Throwable $e) {
            error_log('cleanmenu_on_order_paid error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('cleanmenu_emit_fiscal_receipt')) {
    function cleanmenu_emit_fiscal_receipt(Database $db, array $order): void
    {
        try {
            if (!empty($order['fiscal_receipt_uuid'])) {
                // Already fiscalised in a prior call (idempotent for
                // payment-webhook retries). Skip.
                return;
            }
            $provider = (string)json_decode($db->getSetting('fiscal_provider') ?? '""', true);
            if ($provider !== 'atol') return;

            $cfg = [
                'login'           => (string)json_decode($db->getSetting('fiscal_atol_login') ?? '""', true),
                'password'        => (string)json_decode($db->getSetting('fiscal_atol_password') ?? '""', true),
                'group_code'      => (string)json_decode($db->getSetting('fiscal_atol_group_code') ?? '""', true),
                'inn'             => (string)json_decode($db->getSetting('fiscal_atol_inn') ?? '""', true),
                'payment_address' => (string)json_decode($db->getSetting('fiscal_atol_payment_address') ?? '""', true),
                'sno'             => (string)json_decode($db->getSetting('fiscal_atol_sno') ?? '"usn_income"', true),
                'sandbox'         => (string)json_decode($db->getSetting('fiscal_atol_sandbox') ?? '"0"', true) === '1',
            ];
            foreach (['login', 'password', 'group_code', 'inn', 'payment_address'] as $req) {
                if ($cfg[$req] === '') return; // not yet configured — silently skip
            }

            require_once __DIR__ . '/Fiscal/AtolOnline.php';
            $atol = new \Cleanmenu\Fiscal\AtolOnline($cfg);

            $items = $order['items'] ?? [];
            if (is_string($items)) {
                $items = json_decode($items, true) ?: [];
            }

            $email = '';
            $userId = (int)($order['user_id'] ?? 0);
            if ($userId > 0) {
                $u = $db->getUserById($userId);
                if ($u && !empty($u['email'])) $email = (string)$u['email'];
            }

            $idemKey = 'order_' . (int)$order['id'] . '_' . substr(md5((string)microtime(true)), 0, 12);
            $resp = $atol->emitSaleReceipt(
                array_merge($order, ['items' => $items]),
                $email,
                $idemKey
            );

            // Persist uuid right away. URL is fetched later by the worker.
            $r = new ReflectionClass($db);
            $p = $r->getProperty('connection');
            $p->setAccessible(true);
            $pdo = $p->getValue($db);

            $stmt = $pdo->prepare('UPDATE orders SET fiscal_receipt_uuid = :uuid WHERE id = :id');
            $stmt->execute([
                ':uuid' => $resp['uuid'],
                ':id'   => (int)$order['id'],
            ]);
        } catch (Throwable $e) {
            error_log('cleanmenu_emit_fiscal_receipt error: ' . $e->getMessage());
        }
    }
}
