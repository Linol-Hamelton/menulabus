<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\OData\OData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Phase 37 — pure-PHP unit tests for OData query parser.
 *
 * Invariants:
 *   - $top/$skip clamping (1..1000 for top, 0..1M for skip).
 *   - $select honours field whitelist.
 *   - $filter compiles to parametrised SQL — no string-concat of user input.
 *   - Unknown field in filter → 400 InvalidArgumentException.
 *   - substringof translates to LIKE with PDO param.
 *   - AND-only composition (no OR / NOT in MVP).
 */
final class ODataQueryParserTest extends TestCase
{
    private array $allowedFields;

    public function setUp(): void
    {
        require_once dirname(__DIR__) . '/lib/OData/OData.php';
        $this->allowedFields = [
            'Id'      => 'o.id',
            'Total'   => 'o.total',
            'Status'  => 'o.status',
            'Created' => 'o.created_at',
        ];
    }

    public function testDefaultTopAndSkip(): void
    {
        $q = OData::parseQuery([], $this->allowedFields);
        $this->assertSame(100, $q['top']);
        $this->assertSame(0, $q['skip']);
        $this->assertFalse($q['count']);
    }

    public function testTopClamping(): void
    {
        $q1 = OData::parseQuery(['$top' => '0'], $this->allowedFields);
        $this->assertSame(1, $q1['top']);

        $q2 = OData::parseQuery(['$top' => '5000'], $this->allowedFields);
        $this->assertSame(1000, $q2['top']);
    }

    public function testSkipClamping(): void
    {
        $q = OData::parseQuery(['$skip' => '-50'], $this->allowedFields);
        $this->assertSame(0, $q['skip']);

        $q2 = OData::parseQuery(['$skip' => '9999999'], $this->allowedFields);
        $this->assertSame(1000000, $q2['skip']);
    }

    public function testCountAccepted(): void
    {
        foreach (['true', 'TRUE', '1', 'yes'] as $v) {
            $q = OData::parseQuery(['$count' => $v], $this->allowedFields);
            $this->assertTrue($q['count'], "expected count true for value '{$v}'");
        }
        $this->assertFalse(OData::parseQuery(['$count' => 'no'], $this->allowedFields)['count']);
    }

    public function testSelectWhitelistsFields(): void
    {
        $q = OData::parseQuery(['$select' => 'Id,Total,UNKNOWN'], $this->allowedFields);
        $this->assertSame(['Id', 'Total'], $q['select']);
    }

    public function testSelectDefaultsToAllAllowed(): void
    {
        $q = OData::parseQuery([], $this->allowedFields);
        $this->assertSame(['Id', 'Total', 'Status', 'Created'], $q['select']);
    }

    public function testFilterEqWithString(): void
    {
        $q = OData::parseQuery(["\$filter" => "Status eq 'paid'"], $this->allowedFields);
        $this->assertSame('o.status = :f1', $q['filter_sql']);
        $this->assertSame(['paid'], array_values($q['filter_params']));
    }

    public function testFilterCombinedAnd(): void
    {
        $q = OData::parseQuery(["\$filter" => "Status eq 'paid' and Total gt 100"], $this->allowedFields);
        $this->assertStringContainsString('o.status = :f1', $q['filter_sql']);
        $this->assertStringContainsString('o.total > :f2', $q['filter_sql']);
        $this->assertStringContainsString('AND', $q['filter_sql']);
        $this->assertSame(['paid', 100], array_values($q['filter_params']));
    }

    public function testFilterSubstringofTranslatesToLike(): void
    {
        $q = OData::parseQuery(["\$filter" => "substringof('paid', Status)"], $this->allowedFields);
        $this->assertSame('o.status LIKE :f1', $q['filter_sql']);
        $this->assertSame(['%paid%'], array_values($q['filter_params']));
    }

    public function testFilterRejectsUnknownField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field in filter: SecretCol');
        OData::parseQuery(["\$filter" => "SecretCol eq 1"], $this->allowedFields);
    }

    public function testFilterRejectsUnknownOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OData::parseQuery(["\$filter" => "Total ~~ 100"], $this->allowedFields);
    }

    public function testFilterParsesDatetimeLiteral(): void
    {
        $q = OData::parseQuery(["\$filter" => "Created ge datetime'2026-05-01T00:00:00'"], $this->allowedFields);
        $this->assertSame('o.created_at >= :f1', $q['filter_sql']);
        $this->assertSame('2026-05-01 00:00:00', array_values($q['filter_params'])[0]);
    }

    public function testFilterParsesBooleanLiteral(): void
    {
        $allowed = $this->allowedFields + ['IsActive' => 'o.is_active'];
        $q = OData::parseQuery(["\$filter" => "IsActive eq true"], $allowed);
        $this->assertSame(1, array_values($q['filter_params'])[0]);
        $q2 = OData::parseQuery(["\$filter" => "IsActive eq false"], $allowed);
        $this->assertSame(0, array_values($q2['filter_params'])[0]);
    }

    public function testFilterPdoParamsArePrefixed(): void
    {
        // Regression: filter_params keys must be valid PDO placeholders (:fN).
        $q = OData::parseQuery(["\$filter" => "Total gt 0 and Status eq 'x'"], $this->allowedFields);
        foreach (array_keys($q['filter_params']) as $key) {
            $this->assertStringStartsWith(':f', $key);
        }
    }
}
