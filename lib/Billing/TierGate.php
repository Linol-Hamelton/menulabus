<?php
/**
 * TierGate — API-side hard-stop helper for Phase L103 tier gating.
 *
 * Companion to partials/tier_paywall.php — paywall.php is for HTML routes,
 * TierGate is for API endpoints (returns 402 JSON instead of HTML).
 *
 * Usage at the top of an API endpoint (AFTER session_init + auth + CSRF):
 *   require_once __DIR__ . '/../lib/Billing/TierGate.php';
 *   \Cleanmenu\Billing\TierGate::requireFeature(
 *       \Cleanmenu\Billing\Features::ORDER_COURIER_ASSIGN,
 *       'Назначение курьера'
 *   );
 *
 * If the active location's tariff includes the feature → return silently.
 * Otherwise emits 402 JSON and exits:
 *   {
 *     "success": false,
 *     "error": "tier_upgrade_required",
 *     "feature": "order.courier_assign",
 *     "label": "Назначение курьера",
 *     "required_tier": 3,
 *     "current_tier": 2,
 *     "upgrade_url": "/owner.php?tab=billing",
 *     "message": "..."
 *   }
 *
 * Legacy single-DB mode (no control plane) → no-op via Database::hasFeature
 * fail-open semantics.
 */
declare(strict_types=1);

namespace Cleanmenu\Billing;

require_once __DIR__ . '/Features.php';

final class TierGate
{
    public static function requireFeature(string $featureKey, ?string $label = null): void
    {
        if (!class_exists('Database')) {
            return; // bootstrap missing — fail open
        }
        $db = \Database::getInstance();
        $locId = $db->activeLocationId();

        if ($db->hasFeature($featureKey, $locId)) {
            return; // pass through
        }

        $required    = Features::minTierRank($featureKey);
        $tariff      = $db->getActiveTariff($locId);
        $currentRank = isset($tariff['tier_rank']) ? (int)$tariff['tier_rank'] : 0;
        $currentName = isset($tariff['display_name']) ? (string)$tariff['display_name'] : null;

        if (!headers_sent()) {
            http_response_code(402);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success'        => false,
            'error'          => 'tier_upgrade_required',
            'feature'        => $featureKey,
            'label'          => $label,
            'required_tier'  => $required,
            'current_tier'   => $currentRank,
            'current_tariff' => $currentName,
            'upgrade_url'    => '/owner.php?tab=billing',
            'message'        => 'Эта функция требует более высокого тарифа. Откройте /owner.php?tab=billing для смены.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Non-exit variant — return true if the feature is unlocked.
     * Use when the caller wants to render a conditional response instead
     * of a hard stop (e.g. menu-content templates hiding the cart button).
     */
    public static function isAllowed(string $featureKey, ?int $locationId = null): bool
    {
        if (!class_exists('Database')) {
            return true;
        }
        $db = \Database::getInstance();
        $loc = $locationId !== null ? (int)$locationId : $db->activeLocationId();
        return $db->hasFeature($featureKey, $loc);
    }
}
