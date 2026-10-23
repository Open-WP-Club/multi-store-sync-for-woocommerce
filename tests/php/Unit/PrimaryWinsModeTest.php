<?php
/**
 * "Primary Site Wins" mode: the primary site is the single source of truth, so
 * conflicts resolve themselves, stock discrepancies are corrected from the primary
 * and weekly verification always auto-corrects.
 */

use Brain\Monkey\Functions;

class PrimaryWinsModeTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('wp_json_encode')->alias(fn($v) => json_encode($v));
        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
    }

    private function primary(bool $on): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['primary_authoritative' => $on];
        WC_Multi_Store_Settings::clear_static_cache();
    }

    // ---- the setting ------------------------------------------------------

    public function test_primary_mode_is_off_by_default(): void
    {
        $this->assertFalse(WC_Multi_Store_Settings::is_primary_authoritative());
        $this->assertFalse(WC_Multi_Store_Settings::get_settings()['primary_authoritative']);
    }

    public function test_primary_mode_follows_the_saved_setting(): void
    {
        $this->primary(true);
        $this->assertTrue(WC_Multi_Store_Settings::is_primary_authoritative());

        $this->primary(false);
        $this->assertFalse(WC_Multi_Store_Settings::is_primary_authoritative());
    }

    // ---- conflict settings ------------------------------------------------

    public function test_conflict_action_is_forced_to_primary_wins_whatever_was_configured(): void
    {
        $this->options['wc_mss_conflict_settings'] = ['enabled' => true, 'action_on_conflict' => 'block'];
        $this->primary(true);

        $this->assertSame('primary_wins', WC_Multi_Store_Conflict_Detector::get_settings()['action_on_conflict']);
    }

    public function test_conflict_action_is_left_alone_when_the_mode_is_off(): void
    {
        $this->options['wc_mss_conflict_settings'] = ['enabled' => true, 'action_on_conflict' => 'block'];
        $this->primary(false);

        $this->assertSame('block', WC_Multi_Store_Conflict_Detector::get_settings()['action_on_conflict']);
        $this->options['wc_mss_conflict_settings'] = ['enabled' => true];
        $this->assertSame('warn', WC_Multi_Store_Conflict_Detector::get_settings()['action_on_conflict']);
    }

    public function test_primary_mode_does_not_switch_conflict_detection_itself_on_or_off(): void
    {
        $this->options['wc_mss_conflict_settings'] = ['enabled' => false];
        $this->primary(true);

        $this->assertFalse(WC_Multi_Store_Conflict_Detector::get_settings()['enabled']);
    }

    // ---- check_for_conflicts ----------------------------------------------

    private function detectConflict(): array
    {
        $this->options['wc_mss_conflict_settings'] = ['enabled' => true];
        $remote = ['id' => 123, 'name' => 'Edited remotely', 'sku' => 'A', 'description' => '', 'short_description' => '',
            'regular_price' => '10', 'sale_price' => '', 'stock_quantity' => 1, 'stock_status' => 'instock',
            'status' => 'publish', 'weight' => '', 'categories' => []];
        $client = new class($remote) extends WC_Multi_Store_API_Client {
            public function __construct(private array $product) {} // skip the HTTP-credential constructor

            public function get_product(int $product_id): array|\WP_Error
            {
                return $this->product;
            }
        };

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->insert_id = 55;
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($sql) => $sql);
        $wpdb->shouldReceive('get_var')->andReturn(md5('stale'), json_encode(['name' => 'Original']));
        $wpdb->shouldReceive('insert')->once()->andReturn(1);

        return WC_Multi_Store_Conflict_Detector::check_for_conflicts($client, 123, 1, 'https://remote.store');
    }

    public function test_conflict_in_primary_mode_returns_its_id_and_sends_no_email(): void
    {
        $this->primary(true);
        Functions\expect('do_action')->never()->with('wc_mss_conflict_detected', \Mockery::any());
        $emails = 0;
        Functions\when('wc_get_product')->alias(function () use (&$emails) {
            $emails++;
            return false;
        });

        $result = $this->detectConflict();

        $this->assertTrue($result['has_conflict']);
        $this->assertSame(55, $result['conflict_id']);
        $this->assertSame(0, $emails, 'No notification may be prepared for a conflict the primary resolves itself');
    }

    public function test_conflict_outside_primary_mode_still_returns_its_id(): void
    {
        $this->primary(false);
        Functions\when('wc_get_product')->justReturn(false);

        $result = $this->detectConflict();

        $this->assertTrue($result['has_conflict']);
        $this->assertSame(55, $result['conflict_id']);
    }

    // ---- mark_auto_resolved -----------------------------------------------

    public function test_mark_auto_resolved_closes_only_an_open_conflict_with_the_auto_resolution(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $captured = null;
        $wpdb->shouldReceive('update')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;
            return 1;
        });

        $this->assertTrue(WC_Multi_Store_Conflict_Detector::mark_auto_resolved(55));

        $this->assertSame('wp_wc_mss_conflict_log', $captured[0]);
        $this->assertSame(1, $captured[1]['resolved']);
        $this->assertSame('auto_overwrite', $captured[1]['resolution']);
        $this->assertSame(['id' => 55, 'resolved' => 0], $captured[2], 'An already-resolved conflict must not be touched');
    }

    public function test_mark_auto_resolved_ignores_invalid_ids_and_reports_no_change(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('update')->once()->andReturn(0);

        $this->assertFalse(WC_Multi_Store_Conflict_Detector::mark_auto_resolved(0));
        $this->assertFalse(WC_Multi_Store_Conflict_Detector::mark_auto_resolved(-3));
        $this->assertFalse(WC_Multi_Store_Conflict_Detector::mark_auto_resolved(9), 'Nothing updated (already resolved) is not a success');
    }

    // ---- weekly verification ----------------------------------------------

    public function test_weekly_verification_always_auto_corrects_in_primary_mode(): void
    {
        $this->options['wc_multi_store_sync_weekly_verification'] = ['auto_correct' => false];
        $this->primary(true);

        $this->assertTrue(WC_Multi_Store_Weekly_Verification_Scheduler::get_settings()['auto_correct']);
    }

    public function test_weekly_verification_keeps_its_own_choice_when_the_mode_is_off(): void
    {
        $this->options['wc_multi_store_sync_weekly_verification'] = ['auto_correct' => false];
        $this->primary(false);
        $this->assertFalse(WC_Multi_Store_Weekly_Verification_Scheduler::get_settings()['auto_correct']);

        $this->options['wc_multi_store_sync_weekly_verification'] = ['auto_correct' => true];
        $this->assertTrue(WC_Multi_Store_Weekly_Verification_Scheduler::get_settings()['auto_correct']);
    }

    // ---- stock discrepancies ----------------------------------------------

    private function autoCorrect(int|false $id): bool
    {
        return (new ReflectionMethod(WC_Multi_Store_Stock_Verifier::class, 'maybe_auto_correct_from_primary'))
            ->invoke(null, $id, 10, 'https://store1.com/');
    }

    public function test_stock_discrepancy_is_left_alone_when_the_mode_is_off(): void
    {
        $this->primary(false);
        $counted = false;
        Functions\when('set_transient')->alias(function () use (&$counted) {
            $counted = true;
            return true;
        });

        $this->assertFalse($this->autoCorrect(7));
        $this->assertFalse($counted, 'Nothing may be queued or counted without the mode');
    }

    public function test_stock_discrepancy_without_a_saved_row_is_skipped(): void
    {
        $this->primary(true);

        $this->assertFalse($this->autoCorrect(false));
    }

    public function test_stock_discrepancy_stops_after_three_attempts_a_day(): void
    {
        $this->primary(true);
        Functions\when('get_transient')->justReturn(3);
        $counted = false;
        Functions\when('set_transient')->alias(function () use (&$counted) {
            $counted = true;
            return true;
        });
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('get_row');

        $this->assertFalse($this->autoCorrect(7));
        $this->assertFalse($counted, 'The attempt counter must not keep growing past the cap');
    }

    public function test_stock_discrepancy_queues_a_correction_and_counts_the_attempt(): void
    {
        $this->primary(true);
        $counter = [];
        Functions\when('get_transient')->justReturn(1);
        Functions\when('set_transient')->alias(function (...$args) use (&$counter) {
            $counter = $args;
            return true;
        });
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('SKU-10');
        Functions\when('wc_get_product')->justReturn($product);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 31;
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q) => $q);
        // 1: discrepancy row, 2: Queue_Table::add() "already queued?" lookup.
        $wpdb->shouldReceive('get_row')->andReturn(
            ['id' => 7, 'product_id' => 10, 'store_url' => 'https://store1.com', 'status' => 'pending'],
            null
        );
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $queued = null;
        $wpdb->shouldReceive('insert')->andReturnUsing(function ($table, $data) use (&$queued) {
            $queued = $data;
            return 1;
        });
        $wpdb->shouldReceive('update')->andReturn(1);
        $this->options['wc_multi_store_sync_stores'] = ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'k', 'consumer_secret' => 's']];

        $this->assertTrue($this->autoCorrect(7));

        $this->assertSame('stock_correction', $queued['sync_type']);
        $this->assertSame(10, $queued['product_id']);
        $this->assertSame('stock_discrepancy_7', $queued['source']);
        $this->assertSame(2, $counter[1], 'The attempt is counted (1 previous + this one)');
        $this->assertSame(DAY_IN_SECONDS, $counter[2]);
    }
}
