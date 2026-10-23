<?php
/**
 * Tests for the stock-correction workflow of WC_Multi_Store_Stock_Verifier:
 * process_correction(), reset_correction(), log_discrepancy() and the
 * auto_correct() guards that run before anything is queued.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class StockVerifierCorrectionTest extends WC_Multi_Store_TestCase
{
    private const STORE = 'https://store.com';

    /** @var array<int, array<string, mixed>> Recorded $wpdb->update() calls. */
    private array $updates = [];

    private ?array $row = null;
    private int|false $update_result = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->updates = [];
        $this->update_result = 1;
        $this->row = [
            'id' => '5',
            'product_id' => '10',
            'store_url' => self::STORE,
            'status' => 'pending',
        ];

        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('untrailingslashit')->alias(fn($s) => rtrim($s, '/'));
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
        Functions\when('add_query_arg')->alias(function ($args, $url = '') {
            return is_array($args)
                ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args)
                : $url;
        });

        $this->mockWpdb();
    }

    private function mockWpdb(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 0;
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q, ...$a) => $q . ' /*' . json_encode($a) . '*/');
        $wpdb->shouldReceive('get_row')->andReturnUsing(fn() => $this->row);
        $wpdb->shouldReceive('update')->andReturnUsing(function ($table, $data, $where, $format = null, $wformat = null) {
            $this->updates[] = compact('table', 'data', 'where', 'format', 'wformat');
            return $this->update_result;
        });
    }

    private function product(array $o = []): \Mockery\MockInterface
    {
        $o += ['variation' => false, 'manages' => true, 'status' => 'publish', 'id' => 10, 'sku' => 'SKU-1'];
        $p = \Mockery::mock('WC_Product');
        $p->shouldReceive('is_type')->with('variation')->andReturn($o['variation']);
        $p->shouldReceive('managing_stock')->andReturn($o['manages']);
        $p->shouldReceive('get_status')->andReturn($o['status']);
        $p->shouldReceive('get_id')->andReturn($o['id']);
        $p->shouldReceive('get_sku')->andReturn($o['sku']);
        return $p;
    }

    private function engine(?array $result): \Mockery\MockInterface
    {
        $e = \Mockery::mock('WC_Multi_Store_Sync_Engine');
        if ($result === null) {
            $e->shouldNotReceive('sync_product_to_store');
        } else {
            $e->shouldReceive('sync_product_to_store')->once()->andReturn($result);
        }
        return $e;
    }

    private function config(array $extra = []): array
    {
        return $extra + ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'];
    }

    private function correct(\Mockery\MockInterface $engine, ?array $config = null, int $id = 5, int $pid = 10, string $url = self::STORE): array
    {
        return WC_Multi_Store_Stock_Verifier::process_correction($id, $pid, $url, $config ?? $this->config(), $engine);
    }

    private function remoteReturns(mixed $response): void
    {
        Functions\when('wp_remote_get')->alias(fn() => $response);
    }

    private function syncOk(int $expected = 7, int $remote_id = 99): array
    {
        return ['success' => true, 'expected_stock' => $expected, 'remote_id' => $remote_id];
    }

    // ── process_correction: target validation ────────────────────

    public function test_missing_discrepancy_is_rejected_before_any_product_lookup(): void
    {
        $this->row = null;
        Functions\expect('wc_get_product')->never();

        $result = $this->correct($this->engine(null));

        $this->assertSame(['success' => false, 'message' => 'Stock discrepancy does not match the queue target'], $result);
        $this->assertSame([], $this->updates);
    }

    public static function mismatchProvider(): array
    {
        return [
            'different product' => [5, 11, 'https://store.com'],
            'different store' => [5, 10, 'https://other.com'],
            'store is a subpath' => [5, 10, 'https://store.com/shop'],
        ];
    }

    #[DataProvider('mismatchProvider')]
    public function test_mismatched_queue_target_is_rejected(int $id, int $pid, string $url): void
    {
        Functions\expect('wc_get_product')->never();

        $result = $this->correct($this->engine(null), null, $id, $pid, $url);

        $this->assertFalse($result['success']);
        $this->assertSame('Stock discrepancy does not match the queue target', $result['message']);
        $this->assertSame([], $this->updates);
    }

    public function test_trailing_slash_difference_in_store_url_still_matches(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $result = $this->correct($this->engine(null), null, 5, 10, 'https://store.com/');

        // Passed the target check and failed later on the product precondition.
        $this->assertSame('Correction requires a published parent product with managed stock', $result['message']);
    }

    #[DataProvider('closedStatusProvider')]
    public function test_closed_discrepancy_is_a_success_noop(string $status): void
    {
        $this->row['status'] = $status;
        Functions\expect('wc_get_product')->never();

        $result = $this->correct($this->engine(null));

        $this->assertSame(['success' => true, 'message' => 'Stock discrepancy already closed'], $result);
        $this->assertSame([], $this->updates);
    }

    public static function closedStatusProvider(): array
    {
        return [['resolved'], ['ignored']];
    }

    // ── process_correction: product / store preconditions ────────

    public static function badProductProvider(): array
    {
        return [
            'product deleted' => [null],
            'variation' => [['variation' => true]],
            'stock not managed' => [['manages' => false]],
            'draft' => [['status' => 'draft']],
            'private' => [['status' => 'private']],
            'trash' => [['status' => 'trash']],
            'pending review' => [['status' => 'pending']],
        ];
    }

    #[DataProvider('badProductProvider')]
    public function test_unsuitable_products_are_never_synced(?array $overrides): void
    {
        Functions\when('wc_get_product')->justReturn($overrides === null ? false : $this->product($overrides));

        $result = $this->correct($this->engine(null));

        $this->assertSame(
            ['success' => false, 'message' => 'Correction requires a published parent product with managed stock'],
            $result
        );
        $this->assertSame([], $this->updates);
    }

    public static function inactiveConfigProvider(): array
    {
        return [
            'status missing' => [['consumer_key' => 'ck']],
            'inactive' => [['status' => 'inactive']],
            'paused' => [['status' => 'paused']],
            'empty status' => [['status' => '']],
        ];
    }

    #[DataProvider('inactiveConfigProvider')]
    public function test_inactive_store_is_never_synced(array $config): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());

        $result = $this->correct($this->engine(null), $config);

        $this->assertSame(
            ['success' => false, 'message' => 'Stock correction store is inactive or product is excluded'],
            $result
        );
    }

    public function test_product_excluded_by_category_rule_is_never_synced(): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        global $wpdb;
        $wpdb->shouldReceive('get_results')->andReturn([(object) ['term_id' => 12, 'taxonomy' => 'product_cat']]);

        $result = $this->correct($this->engine(null), $this->config(['exclude_categories' => [12]]));

        $this->assertSame('Stock correction store is inactive or product is excluded', $result['message']);
        $this->assertSame([], $this->updates);
    }

    public function test_exclusion_rules_for_other_terms_do_not_block_correction(): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        global $wpdb;
        $wpdb->shouldReceive('get_results')->andReturn([(object) ['term_id' => 99, 'taxonomy' => 'product_cat']]);
        $this->remoteReturns(['response' => ['code' => 200], 'body' => json_encode(
            ['id' => 99, 'manage_stock' => true, 'stock_quantity' => 7]
        )]);

        $result = $this->correct($this->engine($this->syncOk()), $this->config(['exclude_categories' => [12]]));

        $this->assertTrue($result['success']);
    }

    // ── process_correction: sync result handling ─────────────────

    public static function syncFailureProvider(): array
    {
        return [
            'failure with message' => [['success' => false, 'message' => 'Remote 500'], 'Remote 500'],
            'failure without message' => [['success' => false], 'Stock correction did not run'],
            'empty result' => [[], 'Stock correction did not run'],
            'success but skipped' => [['success' => true, 'skipped' => true, 'message' => 'Skipped: excluded'], 'Skipped: excluded'],
            'success but skipped, no message' => [['success' => true, 'skipped' => true], 'Stock correction did not run'],
            'success without stock info' => [['success' => true], 'Sync did not send a stock quantity'],
            'missing remote id' => [['success' => true, 'expected_stock' => 7], 'Sync did not send a stock quantity'],
            'missing expected stock' => [['success' => true, 'remote_id' => 99], 'Sync did not send a stock quantity'],
        ];
    }

    #[DataProvider('syncFailureProvider')]
    public function test_sync_outcomes_that_did_not_deliver_stock_fail_without_resolving(array $sync, string $message): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        Functions\expect('wp_remote_get')->never();

        $result = $this->correct($this->engine($sync));

        $this->assertSame(['success' => false, 'message' => $message], $result);
        $this->assertSame([], $this->updates, 'Discrepancy must stay open');
    }

    public function test_sync_is_requested_for_quantity_only_with_correction_source(): void
    {
        $product = $this->product();
        Functions\when('wc_get_product')->justReturn($product);
        $config = $this->config();
        $engine = \Mockery::mock('WC_Multi_Store_Sync_Engine');
        $engine->shouldReceive('sync_product_to_store')
            ->once()
            ->with($product, self::STORE, $config, 'quantity', 'discrepancy_correction')
            ->andReturn(['success' => false, 'message' => 'x']);

        $result = $this->correct($engine, $config);

        $this->assertSame(['success' => false, 'message' => 'x'], $result);
    }

    // ── process_correction: remote verification ──────────────────

    public function test_verification_api_error_keeps_discrepancy_open(): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        $this->remoteReturns(new \WP_Error('http', 'Connection reset'));

        $result = $this->correct($this->engine($this->syncOk()));

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('Stock verification failed: ', $result['message']);
        $this->assertStringContainsString('Connection reset', $result['message']);
        $this->assertSame([], $this->updates);
    }

    public function test_verification_fetches_the_remote_id_returned_by_sync(): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        $url = null;
        Functions\when('wp_remote_get')->alias(function ($u) use (&$url) {
            $url = $u;
            return ['response' => ['code' => 200], 'body' => json_encode(['id' => 321, 'manage_stock' => true, 'stock_quantity' => 7])];
        });

        $this->correct($this->engine($this->syncOk(7, 321)));

        $this->assertStringContainsString('store.com', $url);
        $this->assertStringContainsString('products/321', $url);
    }

    public static function remoteStillWrongProvider(): array
    {
        return [
            'stock differs' => [['id' => 99, 'manage_stock' => true, 'stock_quantity' => 8]],
            'stock differs by one below' => [['id' => 99, 'manage_stock' => true, 'stock_quantity' => 6]],
            'stock is null' => [['id' => 99, 'manage_stock' => true, 'stock_quantity' => null]],
            'stock key missing' => [['id' => 99, 'manage_stock' => true]],
            'manage_stock false' => [['id' => 99, 'manage_stock' => false, 'stock_quantity' => 7]],
            'manage_stock missing' => [['id' => 99, 'stock_quantity' => 7]],
            'remote id mismatch' => [['id' => 100, 'manage_stock' => true, 'stock_quantity' => 7]],
            'remote id missing' => [['manage_stock' => true, 'stock_quantity' => 7]],
        ];
    }

    #[DataProvider('remoteStillWrongProvider')]
    public function test_remote_state_that_does_not_match_keeps_discrepancy_open(array $remote): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        $this->remoteReturns(['response' => ['code' => 200], 'body' => json_encode($remote)]);

        $result = $this->correct($this->engine($this->syncOk(7, 99)));

        $this->assertSame(['success' => false, 'message' => 'Remote stock still differs after correction'], $result);
        $this->assertSame([], $this->updates);
    }

    public function test_remote_stock_zero_is_a_valid_match(): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        $this->remoteReturns(['response' => ['code' => 200], 'body' => json_encode(['id' => 99, 'manage_stock' => true, 'stock_quantity' => 0])]);

        $result = $this->correct($this->engine($this->syncOk(0, 99)));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->updates);
    }

    public function test_remote_values_are_compared_numerically_not_by_type(): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        $this->remoteReturns(['response' => ['code' => 200], 'body' => json_encode(['id' => '99', 'manage_stock' => 1, 'stock_quantity' => '7'])]);

        $result = $this->correct($this->engine($this->syncOk(7, 99)));

        $this->assertSame(['success' => true, 'message' => 'Stock correction verified'], $result);
    }

    // ── process_correction: persisting the result ────────────────

    public function test_verified_correction_resolves_with_status_guard_in_where_clause(): void
    {
        $this->row['status'] = 'resolving';
        Functions\when('wc_get_product')->justReturn($this->product());
        $this->remoteReturns(['response' => ['code' => 200], 'body' => json_encode(['id' => 99, 'manage_stock' => true, 'stock_quantity' => 7])]);

        $result = $this->correct($this->engine($this->syncOk()));

        $this->assertSame(['success' => true, 'message' => 'Stock correction verified'], $result);
        $this->assertCount(1, $this->updates);
        $u = $this->updates[0];
        $this->assertSame('wp_wc_multi_store_stock_discrepancies', $u['table']);
        $this->assertSame(['status' => 'resolved', 'resolved_at' => '2024-01-15 12:00:00'], $u['data']);
        // The status the row had when read guards against clobbering a concurrent change.
        $this->assertSame(['id' => 5, 'status' => 'resolving'], $u['where']);
        $this->assertSame(['%d', '%s'], $u['wformat']);
    }

    public static function updateFailureProvider(): array
    {
        return [
            'db error' => [false],
            'row changed concurrently (0 rows)' => [0],
            'multiple rows' => [2],
        ];
    }

    #[DataProvider('updateFailureProvider')]
    public function test_unconfirmed_status_update_is_reported_as_failure(int|false $update_result): void
    {
        $this->update_result = $update_result;
        Functions\when('wc_get_product')->justReturn($this->product());
        $this->remoteReturns(['response' => ['code' => 200], 'body' => json_encode(['id' => 99, 'manage_stock' => true, 'stock_quantity' => 7])]);

        $result = $this->correct($this->engine($this->syncOk()));

        $this->assertSame(
            ['success' => false, 'message' => 'Verified stock, but could not save discrepancy status'],
            $result
        );
    }

    // ── reset_correction ─────────────────────────────────────────

    public function test_reset_correction_only_reopens_resolving_rows(): void
    {
        WC_Multi_Store_Stock_Verifier::reset_correction(33);

        $this->assertCount(1, $this->updates);
        $u = $this->updates[0];
        $this->assertSame('wp_wc_multi_store_stock_discrepancies', $u['table']);
        $this->assertSame(['status' => 'pending'], $u['data']);
        $this->assertSame(['id' => 33, 'status' => 'resolving'], $u['where']);
        $this->assertSame(['%s'], $u['format']);
        $this->assertSame(['%d', '%s'], $u['wformat']);
    }

    public function test_reset_correction_swallows_db_failure(): void
    {
        $this->update_result = false;

        WC_Multi_Store_Stock_Verifier::reset_correction(33);

        $this->assertCount(1, $this->updates);
    }

    public function test_reset_correction_with_zero_id_cannot_match_a_real_row_but_is_still_scoped(): void
    {
        // queue-manager passes (int) ($extra['discrepancy_id'] ?? 0) when extra data is lost.
        WC_Multi_Store_Stock_Verifier::reset_correction(0);

        $this->assertSame(['id' => 0, 'status' => 'resolving'], $this->updates[0]['where']);
    }

    // ── log_discrepancy ──────────────────────────────────────────

    private function invokeLog(array $data): int|false
    {
        $ref = new ReflectionMethod(WC_Multi_Store_Stock_Verifier::class, 'log_discrepancy');
        return $ref->invoke(null, $data);
    }

    public function test_log_discrepancy_inserts_pending_row_with_typed_formats(): void
    {
        global $wpdb;
        $captured = null;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->andReturnUsing(function ($table, $data, $format) use (&$captured) {
            $captured = compact('table', 'data', 'format');
            return 1;
        });

        $result = $this->invokeLog([
            'product_id' => 10, 'sku' => 'SKU-1', 'store_url' => self::STORE,
            'expected_stock' => 5, 'actual_stock' => 8, 'difference' => 3,
            'verified_at' => '2024-01-15 12:00:00', 'ignored_extra' => 'x',
        ]);

        $this->assertSame(1, $result);
        $this->assertSame('wp_wc_multi_store_stock_discrepancies', $captured['table']);
        $this->assertSame([
            'product_id' => 10, 'sku' => 'SKU-1', 'store_url' => self::STORE,
            'expected_stock' => 5, 'actual_stock' => 8, 'difference' => 3,
            'detected_at' => '2024-01-15 12:00:00', 'status' => 'pending',
        ], $captured['data']);
        $this->assertSame(['%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s'], $captured['format']);
        $this->assertCount(count($captured['data']), $captured['format']);
    }

    public function test_log_discrepancy_returns_false_when_insert_fails(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->andReturn(false);

        $this->assertFalse($this->invokeLog([
            'product_id' => 1, 'sku' => 's', 'store_url' => 'u', 'expected_stock' => 1,
            'actual_stock' => 2, 'difference' => 1, 'verified_at' => 'now',
        ]));
    }

    public function test_verify_logs_negative_difference_for_understocked_remote(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('SKU-1');
        $product->shouldReceive('get_slug')->andReturn('slug');
        Functions\when('wc_get_product')->justReturn($product);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_option')->alias(fn($n, $d = false) => match ($n) {
            'wc_multi_store_sync_stores' => [self::STORE => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']],
            'wc_multi_store_sync_settings' => ['match_products_by' => 'sku'],
            default => $d,
        });
        WC_Multi_Store_Settings::clear_static_cache();
        $this->remoteReturns(['response' => ['code' => 200], 'body' => json_encode([['id' => 1, 'stock_quantity' => 2]])]);

        global $wpdb;
        $inserted = null;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->andReturnUsing(function ($t, $data) use (&$inserted) {
            $inserted = $data;
            return 1;
        });

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(10, self::STORE, 9);

        $this->assertTrue($result['has_discrepancy']);
        $this->assertSame(-7, $result['difference']);
        $this->assertSame(-7, $inserted['difference']);
        $this->assertSame(2, $inserted['actual_stock']);
        $this->assertSame(9, $inserted['expected_stock']);
        $this->assertSame('pending', $inserted['status']);
    }

    public function test_verify_does_not_log_when_stock_matches(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('SKU-1');
        $product->shouldReceive('get_slug')->andReturn('slug');
        Functions\when('wc_get_product')->justReturn($product);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_option')->alias(fn($n, $d = false) => match ($n) {
            'wc_multi_store_sync_stores' => [self::STORE => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']],
            default => $d,
        });
        WC_Multi_Store_Settings::clear_static_cache();
        $this->remoteReturns(['response' => ['code' => 200], 'body' => json_encode([['id' => 1, 'stock_quantity' => 9]])]);

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('insert');

        $result = WC_Multi_Store_Stock_Verifier::verify_product_stock(10, self::STORE, 9);

        $this->assertFalse($result['has_discrepancy']);
        $this->assertSame(0, $result['difference']);
    }

    // ── auto_correct guards that run before anything is queued ───

    #[DataProvider('closedStatusProvider')]
    public function test_auto_correct_refuses_closed_discrepancies(string $status): void
    {
        $this->row = ['id' => '5', 'product_id' => '10', 'store_url' => self::STORE, 'status' => $status];
        Functions\when('wc_get_product')->justReturn($this->product());

        $result = WC_Multi_Store_Stock_Verifier::auto_correct(5);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('closed', $result->get_error_code());
        $this->assertSame([], $this->updates, 'A closed discrepancy must not be reopened');
    }

    public function test_auto_correct_refuses_when_store_is_not_active_or_configured(): void
    {
        Functions\when('wc_get_product')->justReturn($this->product());
        Functions\when('get_option')->alias(fn($n, $d = false) => match ($n) {
            'wc_multi_store_sync_stores' => [
                'https://store.com' => ['status' => 'inactive', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
                'https://another.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            ],
            default => $d,
        });
        WC_Multi_Store_Settings::clear_static_cache();

        $result = WC_Multi_Store_Stock_Verifier::auto_correct(5);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('store_not_found', $result->get_error_code());
        $this->assertSame([], $this->updates);
    }

    public function test_auto_correct_checks_product_before_status(): void
    {
        $this->row['status'] = 'resolved';
        Functions\when('wc_get_product')->justReturn(false);

        $result = WC_Multi_Store_Stock_Verifier::auto_correct(5);

        $this->assertSame('product_not_found', $result->get_error_code());
    }
}
