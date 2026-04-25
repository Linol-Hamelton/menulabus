<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers lib/orders/lifecycle.php — the pure-PHP state machine and
 * freshness/attention thresholds documented in
 * docs/order-lifecycle-contract.md.
 *
 * These functions have zero DB dependency, so this suite always runs and is
 * the canonical regression gate for the order state contract. Any new status
 * or threshold change in lib/orders/lifecycle.php must come with a matching
 * update here — that's the whole point of having the test file.
 */
final class OrdersLifecycleTest extends TestCase
{
    public function test_open_statuses_match_contract(): void
    {
        self::assertSame(
            ['Приём', 'готовим', 'доставляем'],
            cleanmenu_order_open_statuses(),
            'Open statuses must match docs/order-lifecycle-contract.md.'
        );
    }

    public function test_closed_statuses_match_contract(): void
    {
        self::assertSame(
            ['завершён', 'отказ'],
            cleanmenu_order_closed_statuses(),
            'Closed statuses must match docs/order-lifecycle-contract.md.'
        );
    }

    public function test_board_statuses_is_open_plus_closed(): void
    {
        $board = cleanmenu_order_board_statuses();
        self::assertSame(
            array_merge(cleanmenu_order_open_statuses(), cleanmenu_order_closed_statuses()),
            $board
        );
        self::assertCount(5, $board, 'Board contract is exactly five statuses.');
    }

    public function test_attention_threshold_is_twenty_minutes(): void
    {
        self::assertSame(20, cleanmenu_order_attention_threshold_minutes());
    }

    public function test_stale_threshold_is_forty_five_minutes(): void
    {
        self::assertSame(45, cleanmenu_order_stale_threshold_minutes());
    }

    public function test_stale_threshold_is_higher_than_attention(): void
    {
        self::assertGreaterThan(
            cleanmenu_order_attention_threshold_minutes(),
            cleanmenu_order_stale_threshold_minutes(),
            'Stale must always trail attention — otherwise the warning bucket is dead code.'
        );
    }

    #[DataProvider('provideOpenStatuses')]
    public function test_is_open_returns_true_for_open_statuses(string $status): void
    {
        self::assertTrue(cleanmenu_order_is_open($status));
        self::assertFalse(cleanmenu_order_is_closed($status));
    }

    #[DataProvider('provideClosedStatuses')]
    public function test_is_closed_returns_true_for_closed_statuses(string $status): void
    {
        self::assertTrue(cleanmenu_order_is_closed($status));
        self::assertFalse(cleanmenu_order_is_open($status));
    }

    public function test_is_open_trims_whitespace(): void
    {
        self::assertTrue(cleanmenu_order_is_open('  готовим  '));
        self::assertTrue(cleanmenu_order_is_closed(" завершён\n"));
    }

    public function test_is_open_returns_false_for_unknown_status(): void
    {
        self::assertFalse(cleanmenu_order_is_open('pending'));
        self::assertFalse(cleanmenu_order_is_closed('pending'));
        self::assertFalse(cleanmenu_order_is_open(''));
    }

    public function test_next_action_labels(): void
    {
        self::assertSame('На кухню',   cleanmenu_order_next_action_label('Приём'));
        self::assertSame('В доставку', cleanmenu_order_next_action_label('готовим'));
        self::assertSame('Принято',    cleanmenu_order_next_action_label('доставляем'));
    }

    public function test_next_action_label_for_closed_status_echoes_input(): void
    {
        self::assertSame('завершён', cleanmenu_order_next_action_label('завершён'));
        self::assertSame('отказ',    cleanmenu_order_next_action_label('отказ'));
    }

    public function test_next_action_label_trims_input(): void
    {
        self::assertSame('На кухню', cleanmenu_order_next_action_label('  Приём '));
    }

    public function test_age_minutes_from_recent_timestamp(): void
    {
        $createdAt = date('Y-m-d H:i:s', time() - 300); // 5 minutes ago
        self::assertSame(5, cleanmenu_order_age_minutes($createdAt));
    }

    public function test_age_minutes_returns_zero_for_null(): void
    {
        self::assertSame(0, cleanmenu_order_age_minutes(null));
    }

    public function test_age_minutes_returns_zero_for_unparseable(): void
    {
        self::assertSame(0, cleanmenu_order_age_minutes('not-a-date'));
    }

    public function test_age_minutes_floors_to_whole_minutes(): void
    {
        // Use 115s (1:55) instead of the exact 1:59 boundary so that a
        // second rolling over between this time() call and the time() call
        // inside cleanmenu_order_age_minutes() cannot push us across 120s
        // and flip the result to 2. Still well inside the "1 minute" bucket.
        $createdAt = date('Y-m-d H:i:s', time() - 115);
        self::assertSame(1, cleanmenu_order_age_minutes($createdAt));
    }

    public function test_age_minutes_never_goes_negative_for_future_timestamps(): void
    {
        $createdAt = date('Y-m-d H:i:s', time() + 60); // 1 minute in the future
        self::assertSame(0, cleanmenu_order_age_minutes($createdAt));
    }

    public function test_age_label_under_one_hour(): void
    {
        self::assertSame('0 мин', cleanmenu_order_age_label(0));
        self::assertSame('19 мин', cleanmenu_order_age_label(19));
        self::assertSame('59 мин', cleanmenu_order_age_label(59));
    }

    public function test_age_label_over_one_hour(): void
    {
        self::assertSame('1 ч',         cleanmenu_order_age_label(60));
        self::assertSame('1 ч 30 мин',  cleanmenu_order_age_label(90));
        self::assertSame('23 ч 59 мин', cleanmenu_order_age_label(1439));
    }

    public function test_age_label_over_one_day(): void
    {
        self::assertSame('1 д 0 ч',  cleanmenu_order_age_label(1440));
        self::assertSame('2 д 5 ч',  cleanmenu_order_age_label(2 * 1440 + 5 * 60));
    }

    public function test_lifecycle_meta_for_fresh_open_order(): void
    {
        $meta = cleanmenu_order_lifecycle_meta([
            'status'     => 'Приём',
            'created_at' => date('Y-m-d H:i:s', time() - 60),
        ]);

        self::assertSame('Приём',   $meta['status']);
        self::assertTrue($meta['is_open']);
        self::assertFalse($meta['is_closed']);
        self::assertSame(1, $meta['age_minutes']);
        self::assertSame('fresh', $meta['lifecycle_bucket']);
        self::assertSame('В норме', $meta['lifecycle_label']);
        self::assertFalse($meta['needs_attention']);
        self::assertFalse($meta['is_stale']);
        self::assertSame('На кухню', $meta['next_action_label']);
    }

    public function test_lifecycle_meta_flags_attention_at_threshold(): void
    {
        $meta = cleanmenu_order_lifecycle_meta([
            'status'     => 'готовим',
            'created_at' => date('Y-m-d H:i:s', time() - (21 * 60)),
        ]);

        self::assertSame('warning', $meta['lifecycle_bucket']);
        self::assertSame('Требует внимания', $meta['lifecycle_label']);
        self::assertTrue($meta['needs_attention']);
        self::assertFalse($meta['is_stale']);
    }

    public function test_lifecycle_meta_flags_stale_past_stale_threshold(): void
    {
        $meta = cleanmenu_order_lifecycle_meta([
            'status'     => 'доставляем',
            'created_at' => date('Y-m-d H:i:s', time() - (46 * 60)),
        ]);

        self::assertSame('critical', $meta['lifecycle_bucket']);
        self::assertSame('Просрочен', $meta['lifecycle_label']);
        self::assertTrue($meta['needs_attention']);
        self::assertTrue($meta['is_stale']);
    }

    public function test_lifecycle_meta_for_closed_order_is_quiet_regardless_of_age(): void
    {
        $meta = cleanmenu_order_lifecycle_meta([
            'status'     => 'завершён',
            'created_at' => date('Y-m-d H:i:s', time() - (5 * 3600)), // 5 hours ago
        ]);

        self::assertSame('quiet',    $meta['lifecycle_bucket']);
        self::assertSame('Закрыт',   $meta['lifecycle_label']);
        self::assertFalse($meta['is_open']);
        self::assertTrue($meta['is_closed']);
        self::assertFalse($meta['needs_attention'], 'Closed orders must never need attention.');
        self::assertFalse($meta['is_stale'],        'Closed orders must never be marked stale.');
    }

    public function test_lifecycle_meta_handles_missing_fields(): void
    {
        $meta = cleanmenu_order_lifecycle_meta([]);

        self::assertSame('',      $meta['status']);
        self::assertFalse($meta['is_open']);
        self::assertFalse($meta['is_closed']);
        self::assertSame(0,       $meta['age_minutes']);
        self::assertSame('fresh', $meta['lifecycle_bucket']);
    }

    public function test_lifecycle_summary_counts_buckets(): void
    {
        $now = time();
        $orders = [
            ['status' => 'Приём',      'created_at' => date('Y-m-d H:i:s', $now - 60)],            // fresh, open
            ['status' => 'готовим',    'created_at' => date('Y-m-d H:i:s', $now - 25 * 60)],       // open, attention
            ['status' => 'доставляем', 'created_at' => date('Y-m-d H:i:s', $now - 60 * 60)],       // open, attention + stale
            ['status' => 'завершён',   'created_at' => date('Y-m-d H:i:s', $now - 3600)],          // closed
            ['status' => 'отказ',      'created_at' => date('Y-m-d H:i:s', $now - 180)],           // closed
        ];

        $summary = cleanmenu_order_lifecycle_summary($orders);

        self::assertSame(3, $summary['open']);
        self::assertSame(2, $summary['closed']);
        self::assertSame(2, $summary['attention']); // both non-fresh open orders
        self::assertSame(1, $summary['stale']);     // only the 60m-old одна
    }

    public function test_lifecycle_summary_on_empty_input(): void
    {
        self::assertSame(
            ['open' => 0, 'closed' => 0, 'attention' => 0, 'stale' => 0],
            cleanmenu_order_lifecycle_summary([])
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideOpenStatuses(): iterable
    {
        yield 'приём'      => ['Приём'];
        yield 'готовим'    => ['готовим'];
        yield 'доставляем' => ['доставляем'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideClosedStatuses(): iterable
    {
        yield 'завершён' => ['завершён'];
        yield 'отказ'    => ['отказ'];
    }
}
