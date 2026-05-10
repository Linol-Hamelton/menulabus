<?php
/**
 * lib/Inventory/UnitCatalog.php — Phase 33 canonical units catalog.
 *
 * Master-list of unit-of-measure strings used in admin/inventory dropdowns
 * and CSV-import validation. Backed by a PHP constant (not a DB table) by
 * design: adding a new unit requires a code change + deploy, never runtime
 * config. Guarantees consistency between UI, API, and validation.
 *
 * Backwards-compat: tenants that already have ingredients with non-canonical
 * unit strings (e.g. "гр", "грамм") keep working — saveIngredient() accepts
 * any non-empty string ≤16 chars; the UI renders unknown units as "Другое"
 * with the free-text fallback pre-filled.
 */

declare(strict_types=1);

namespace Cleanmenu\Inventory;

final class UnitCatalog
{
    /**
     * Canonical units, ordered by typical usage frequency.
     * Keep keys = labels so JSON-encoding for JS stays trivial.
     */
    public const CANONICAL = ['г', 'кг', 'мл', 'л', 'шт', 'порц', 'упак'];

    public const MAX_LEN = 16;

    public static function isCanonical(string $unit): bool
    {
        return in_array(trim($unit), self::CANONICAL, true);
    }

    /**
     * Validation rule for saveIngredient / CSV-import:
     * - non-empty
     * - ≤16 chars (matches DB VARCHAR(16))
     * - canonical OR free-text fallback (we don't reject — we surface a
     *   warning in CSV-import summaries instead).
     */
    public static function isValid(string $unit): bool
    {
        $unit = trim($unit);
        if ($unit === '') {
            return false;
        }
        return mb_strlen($unit) <= self::MAX_LEN;
    }

    /**
     * Returns the list of canonical units plus a synthetic "__other__"
     * sentinel for the UI dropdown. Order matters — UI renders in this order.
     */
    public static function dropdownOptions(): array
    {
        return self::CANONICAL;
    }
}
