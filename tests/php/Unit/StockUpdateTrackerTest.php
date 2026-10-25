<?php
/**
 * Unit tests for WC_Multi_Store_Stock_Update_Tracker: version counting, corrupt
 * meta, source attribution and hostile store URLs reaching the log line.
 */

use Brain\Monkey\Functions;

class StockUpdateTrackerTest extends WC_Multi_Store_TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $meta = [];

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->meta = [];
        $this->log  = [];

        Functions\when('get_option')->alias(fn($o, $d = false) => $d);
        Functions\when('get_post_meta')->alias(fn($id, $key, $single = false) => $this->meta[$id][$key] ?? '');
        Functions\when('update_post_meta')->alias(function ($id, $key, $value): bool {
            $this->meta[$id][$key] = $value;
            return true;
        });
        Functions\when('wc_get_logger')->justReturn(new class ($this->log) {
            /** @param list<string> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function log(string $level, string $message, array $context = []): void
            {
                $this->sink[] = $message;
            }
        });
    }

    public function test_meta_key_constants(): void
    {
        $this->assertSame('_wc_mss_stock_last_update', WC_Multi_Store_Stock_Update_Tracker::META_LAST_UPDATE);
        $this->assertSame('_wc_mss_stock_update_source', WC_Multi_Store_Stock_Update_Tracker::META_UPDATE_SOURCE);
        $this->assertSame('_wc_mss_stock_sync_version', WC_Multi_Store_Stock_Update_Tracker::META_SYNC_VERSION);
    }

    public function test_each_webhook_update_increments_version_across_stores(): void
    {
        foreach (['https://store1.com', 'https://store2.com', 'https://store3.com'] as $url) {
            WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(100, $url);
        }

        $this->assertSame(3, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(100));
    }

    public static function corruptVersions(): array
    {
        return [
            'empty string'  => ['', 0],
            'zero'          => [0, 0],
            'zero string'   => ['0', 0],
            'text'          => ['abc', 0],
            'numeric text'  => ['41', 41],
            'float string'  => ['3.9', 3],
            'null'          => [null, 0],
            'false'         => [false, 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corruptVersions')]
    public function test_get_sync_version_coerces_any_stored_value_to_a_non_crashing_int(mixed $stored, int $expected): void
    {
        $this->meta[7][WC_Multi_Store_Stock_Update_Tracker::META_SYNC_VERSION] = $stored;

        $this->assertSame($expected, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(7));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corruptVersions')]
    public function test_webhook_update_after_corrupt_version_continues_from_the_coerced_value(mixed $stored, int $expected): void
    {
        $this->meta[7][WC_Multi_Store_Stock_Update_Tracker::META_SYNC_VERSION] = $stored;

        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(7, 'https://s.example.com');

        $this->assertSame($expected + 1, $this->meta[7][WC_Multi_Store_Stock_Update_Tracker::META_SYNC_VERSION]);
    }

    public function test_webhook_update_stamps_time_source_and_version_for_the_right_product_only(): void
    {
        $before = time();
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(7, 'https://s.example.com');

        $this->assertSame([7], array_keys($this->meta));
        $this->assertGreaterThanOrEqual($before, $this->meta[7][WC_Multi_Store_Stock_Update_Tracker::META_LAST_UPDATE]);
        $this->assertLessThanOrEqual(time(), $this->meta[7][WC_Multi_Store_Stock_Update_Tracker::META_LAST_UPDATE]);
        $this->assertSame('webhook', $this->meta[7][WC_Multi_Store_Stock_Update_Tracker::META_UPDATE_SOURCE]);
        $this->assertSame(1, $this->meta[7][WC_Multi_Store_Stock_Update_Tracker::META_SYNC_VERSION]);
    }

    public function test_sync_to_store_changes_source_but_never_bumps_the_version(): void
    {
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(7, 'https://a.example.com');
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(7, 'https://a.example.com');
        WC_Multi_Store_Stock_Update_Tracker::record_sync_to_store(7, 'https://b.example.com');

        $this->assertSame('master_sync', $this->meta[7][WC_Multi_Store_Stock_Update_Tracker::META_UPDATE_SOURCE]);
        $this->assertSame(2, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(7));

        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(7, 'https://a.example.com');
        $this->assertSame('webhook', $this->meta[7][WC_Multi_Store_Stock_Update_Tracker::META_UPDATE_SOURCE]);
        $this->assertSame(3, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(7));
    }

    public function test_sync_to_store_does_not_log_or_leak_the_target_url_into_meta(): void
    {
        WC_Multi_Store_Stock_Update_Tracker::record_sync_to_store(7, 'https://secret-b.example.com');

        $this->assertSame([], $this->log);
        $this->assertStringNotContainsString('secret-b', json_encode($this->meta));
    }

    public function test_hostile_store_url_is_stored_nowhere_but_the_log_line_and_percent_signs_are_safe(): void
    {
        $evil = "https://x.test/%s%d%n\n[ERROR] forged";

        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(7, $evil);

        $this->assertStringNotContainsString('x.test', json_encode($this->meta));
        $this->assertCount(1, $this->log);
        $this->assertStringContainsString('%s%d%n', $this->log[0], 'format specifiers in input must be printed literally');
        $this->assertStringContainsString('version=1', $this->log[0]);
    }

    public function test_versions_are_independent_per_product_including_extreme_ids(): void
    {
        foreach ([0, 1, PHP_INT_MAX] as $id) {
            WC_Multi_Store_Stock_Update_Tracker::record_webhook_update($id, 'https://s.example.com');
            WC_Multi_Store_Stock_Update_Tracker::record_webhook_update($id, 'https://s.example.com');
        }
        WC_Multi_Store_Stock_Update_Tracker::record_webhook_update(1, 'https://s.example.com');

        $this->assertSame(2, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(0));
        $this->assertSame(3, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(1));
        $this->assertSame(2, WC_Multi_Store_Stock_Update_Tracker::get_sync_version(PHP_INT_MAX));
    }
}
