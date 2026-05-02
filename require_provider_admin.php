<?php
/**
 * require_provider_admin.php — gate for /provider/* surfaces (Phase 14.7).
 *
 * Conditions to pass:
 *   1. Running on the provider host (menu.labus.pro itself, NOT a tenant
 *      subdomain). isProviderMode global is set by tenant_runtime.php.
 *   2. User is logged in (session has user_id).
 *   3. User role is 'owner' (admin alone is not enough — provider-side
 *      operations affect every tenant).
 *   4. User email is in the BILLING_PROVIDER_ADMINS allowlist (defined in
 *      config_copy.php). Acts as a poor-man's whitelist; v2 will move to
 *      a `provider_admins` table in the control plane.
 *
 * On failure: 403 with a generic "forbidden" message — does NOT reveal
 * whether the wall failed because of host, role, or whitelist.
 */

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

$forbidden = false;

if (empty($GLOBALS['isProviderMode'])) {
    $forbidden = true;
}

if (!$forbidden && empty($_SESSION['user_id'])) {
    header('Location: /auth.php?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

if (!$forbidden) {
    $db = Database::getInstance();
    $user = $db->getUserById((int)$_SESSION['user_id']);
    if (!$user || !$user['is_active'] || $user['role'] !== 'owner') {
        $forbidden = true;
    }
}

if (!$forbidden) {
    $allowlist = defined('BILLING_PROVIDER_ADMINS') ? BILLING_PROVIDER_ADMINS : [];
    if (!is_array($allowlist) || empty($allowlist)) {
        // No allowlist configured → fall through to "any owner on provider host
        // is a provider admin", which is acceptable until first competing
        // operator joins. Document in docs/billing.md.
    } else {
        $email = strtolower((string)($user['email'] ?? ''));
        $allowedSet = array_map('strtolower', $allowlist);
        if (!in_array($email, $allowedSet, true)) {
            $forbidden = true;
        }
    }
}

if ($forbidden) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><body><h1>403 Forbidden</h1><p>Этот раздел только для провайдер-админов.</p></body></html>';
    exit;
}
