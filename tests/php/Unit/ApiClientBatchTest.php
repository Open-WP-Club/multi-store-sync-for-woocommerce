<?php
/**
 * Tests for WC_Multi_Store_API_Client: batch validation, tag creation,
 * variation deletion and the two rate-limit window recorders called directly.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class ApiClientBatchTest extends WC_Multi_Store_TestCase
{
    private const STORE = 'https://batch-store.test';

    private WC_Multi_Store_API_Client $client;

    /** @var list<array{string, array}> */
    private array $posts = [];

    /** @var list<array{string, array}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wc_mss_test_use_object_cache'] = false;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = null;

        Functions\when('get_option')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('add_query_arg')->alias(function ($args, $url = '') {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $this->posts = [];
        $this->requests = [];
        Functions\when('wp_remote_post')->alias(function ($url, $args = []) {
            $this->posts[] = [$url, $args];
            return ['response' => ['code' => 201], 'body' => json_encode(['id' => 5, 'name' => 'ok'])];
        });
        Functions\when('wp_remote_request')->alias(function ($url, $args = []) {
            $this->requests[] = [$url, $args];
            return ['response' => ['code' => 200], 'body' => json_encode(['id' => 9, 'deleted' => true])];
        });

        $this->client = new WC_Multi_Store_API_Client(self::STORE, 'ck_x', 'cs_x', 'query_string');
    }

    protected function tearDown(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache'] = false;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = null;
        parent::tearDown();
    }

    private function validate(array $batch): ?WP_Error
    {
        return (new ReflectionMethod(WC_Multi_Store_API_Client::class, 'validate_batch'))->invoke(null, $batch);
    }

    // ---- validate_batch ---------------------------------------------------

    public function test_validate_batch_rejects_empty_batch(): void
    {
        $err = $this->validate([]);

        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('invalid_batch', $err->get_error_code());
        $this->assertSame('Invalid batch data', $err->get_error_message());
    }

    /**
     * @return array<string, array{array, bool}>
     */
    public static function batchSizes(): array
    {
        $mk = static fn(int $n): array => $n === 0 ? [] : array_fill(0, $n, ['name' => 'x']);
        return [
            '1 create' => [['create' => $mk(1)], true],
            '100 creates (limit)' => [['create' => $mk(100)], true],
            '101 creates' => [['create' => $mk(101)], false],
            '50+50 mixed (limit)' => [['create' => $mk(50), 'update' => $mk(50)], true],
            '50+50+1 mixed' => [['create' => $mk(50), 'update' => $mk(50), 'delete' => $mk(1)], false],
            '40/30/30 all three keys (limit)' => [['create' => $mk(40), 'update' => $mk(30), 'delete' => $mk(30)], true],
            '101 deletes' => [['delete' => $mk(101)], false],
            'non-empty array with only empty lists' => [['create' => []], true],
            'unknown keys are not counted' => [['other' => $mk(500), 'create' => $mk(1)], true],
        ];
    }

    #[DataProvider('batchSizes')]
    public function test_validate_batch_enforces_combined_100_item_limit(array $batch, bool $valid): void
    {
        $err = $this->validate($batch);

        if ($valid) {
            $this->assertNull($err);
        } else {
            $this->assertInstanceOf(WP_Error::class, $err);
            $this->assertSame('batch_too_large', $err->get_error_code());
            $this->assertSame('Batch size exceeds 100 items', $err->get_error_message());
        }
    }

    public function test_batch_categories_and_tags_do_not_hit_http_when_invalid(): void
    {
        $tooBig = ['create' => array_fill(0, 101, ['name' => 'x'])];

        $this->assertSame('batch_too_large', $this->client->batch_categories($tooBig)->get_error_code());
        $this->assertSame('invalid_batch', $this->client->batch_tags([])->get_error_code());
        $this->assertSame([], $this->posts);
    }

    public function test_batch_categories_posts_valid_batch_to_categories_batch_endpoint(): void
    {
        $batch = ['create' => [['name' => 'A']], 'update' => [['id' => 3, 'name' => 'B']]];

        $result = $this->client->batch_categories($batch);

        $this->assertSame(5, $result['id']);
        $this->assertCount(1, $this->posts);
        $this->assertStringContainsString('/wp-json/', $this->posts[0][0]);
        $this->assertStringContainsString('products/categories/batch', $this->posts[0][0]);
        $this->assertSame($batch, json_decode($this->posts[0][1]['body'], true));
    }

    public function test_batch_tags_posts_to_tags_batch_endpoint(): void
    {
        $this->client->batch_tags(['delete' => [1, 2]]);

        $this->assertStringContainsString('products/tags/batch', $this->posts[0][0]);
        $this->assertSame(['delete' => [1, 2]], json_decode($this->posts[0][1]['body'], true));
    }

    // ---- create_tag -------------------------------------------------------

    public function test_create_tag_posts_json_body_to_tags_endpoint(): void
    {
        $result = $this->client->create_tag(['name' => 'Hot', 'slug' => 'hot']);

        $this->assertSame(5, $result['id']);
        $this->assertCount(1, $this->posts);
        [$url, $args] = $this->posts[0];
        $this->assertStringContainsString(self::STORE . '/wp-json/', $url);
        $this->assertMatchesRegularExpression('#/products/tags\?#', $url);
        $this->assertStringContainsString('consumer_key=ck_x', $url);
        $this->assertSame(['name' => 'Hot', 'slug' => 'hot'], json_decode($args['body'], true));
    }

    public function test_create_tag_returns_wp_error_for_api_error(): void
    {
        Functions\when('wp_remote_post')->justReturn([
            'response' => ['code' => 400],
            'body' => json_encode(['code' => 'term_exists', 'message' => 'A term with the name provided already exists.']),
        ]);

        $result = $this->client->create_tag(['name' => 'Hot']);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringContainsString('already exists', $result->get_error_message());
    }

    // ---- delete_product_variation ----------------------------------------

    /**
     * @return array<string, array{bool}>
     */
    public static function forceFlags(): array
    {
        return ['force' => [true], 'no force' => [false]];
    }

    #[DataProvider('forceFlags')]
    public function test_delete_product_variation_uses_delete_method_and_endpoint(bool $force): void
    {
        $result = $this->client->delete_product_variation(12, 34, $force);

        $this->assertSame(9, $result['id']);
        $this->assertCount(1, $this->requests);
        [$url, $args] = $this->requests[0];
        $this->assertSame('DELETE', $args['method']);
        $this->assertStringContainsString('products/12/variations/34', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($force ? '1' : '0', $query['force']);
    }

    public function test_delete_product_variation_defaults_to_no_force_and_propagates_errors(): void
    {
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 404],
            'body' => json_encode(['code' => 'woocommerce_rest_invalid_id', 'message' => 'Invalid ID.']),
        ]);

        $result = $this->client->delete_product_variation(1, 2);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringContainsString('Invalid ID', $result->get_error_message());
    }

    // ---- cache_window_record ---------------------------------------------

    private function cacheWindow(float $now): float
    {
        return (new ReflectionMethod($this->client, 'cache_window_record'))->invoke($this->client, md5(self::STORE), $now);
    }

    public function test_cache_window_uses_bucket_key_group_and_increments_by_one(): void
    {
        $seen = null;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = function ($key, $offset, $group) use (&$seen) {
            $seen = [$key, $offset, $group];
            return 1;
        };

        $wait = $this->cacheWindow(1005.5);

        $window = WC_Multi_Store_API_Client::RATE_LIMIT_WINDOW;
        $this->assertSame(0.0, $wait);
        $this->assertSame(['rl_' . md5(self::STORE) . '_' . (int) floor(1005.5 / $window), 1, WC_Multi_Store_API_Client::RATE_LIMIT_CACHE_GROUP], $seen);
    }

    public function test_cache_window_bucket_rotates_between_windows(): void
    {
        $keys = [];
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = function ($key) use (&$keys) {
            $keys[] = $key;
            return 1;
        };

        $this->cacheWindow(1000.0);
        $this->cacheWindow(1009.9);
        $this->cacheWindow(1010.0);

        $this->assertSame($keys[0], $keys[1], 'Same window shares a counter');
        $this->assertNotSame($keys[1], $keys[2], 'Next window starts a new counter');
    }

    public function test_cache_window_allows_exactly_the_limit_and_blocks_one_over(): void
    {
        $limit = WC_Multi_Store_API_Client::RATE_LIMIT_REQUESTS;

        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = $limit;
        $this->assertSame(0.0, $this->cacheWindow(1000.0));

        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = $limit + 1;
        $this->assertGreaterThan(0.0, $this->cacheWindow(1000.0));
    }

    public function test_cache_window_wait_runs_to_end_of_bucket_plus_margin(): void
    {
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = WC_Multi_Store_API_Client::RATE_LIMIT_REQUESTS + 5;

        // Bucket [1000, 1010); at 1002.0 -> 8.1s remain, above the 1s minimum pause.
        $this->assertEqualsWithDelta(8.1, $this->cacheWindow(1002.0), 0.0001);
        // Near the end the wait is floored at RATE_LIMIT_PAUSE.
        $this->assertSame((float) WC_Multi_Store_API_Client::RATE_LIMIT_PAUSE, $this->cacheWindow(1009.95));
    }

    public function test_cache_window_fails_open_when_counter_unsupported(): void
    {
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = false;

        $this->assertSame(0.0, $this->cacheWindow(1000.0));
    }

    // ---- transient_window_record -----------------------------------------

    private function transientWindow(float $now): float
    {
        $store = md5(self::STORE);
        return (new ReflectionMethod($this->client, 'transient_window_record'))
            ->invoke($this->client, $store, WC_Multi_Store_API_Client::RATE_LIMIT_TRANSIENT_PREFIX . $store, $now);
    }

    private function localTimestamps(): array
    {
        $p = new ReflectionProperty(WC_Multi_Store_API_Client::class, 'request_timestamps');
        return $p->getValue()[md5(self::STORE)] ?? [];
    }

    public function test_transient_window_records_request_and_persists_with_ttl(): void
    {
        $written = null;
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use (&$written) {
            $written = [$key, $value, $ttl];
            return true;
        });

        $wait = $this->transientWindow(5000.0);

        $this->assertSame(0.0, $wait);
        $this->assertSame([5000.0], $this->localTimestamps());
        $this->assertSame(
            [WC_Multi_Store_API_Client::RATE_LIMIT_TRANSIENT_PREFIX . md5(self::STORE), [5000.0], WC_Multi_Store_API_Client::RATE_LIMIT_WINDOW + 2],
            $written
        );
    }

    public function test_transient_window_blocks_at_limit_with_wait_until_oldest_expires(): void
    {
        $now = 5000.0;
        $persisted = [];
        for ($i = 0; $i < WC_Multi_Store_API_Client::RATE_LIMIT_REQUESTS; $i++) {
            $persisted[] = $now - 9.0 + ($i * 0.01); // oldest = 4991.0
        }
        Functions\when('get_transient')->justReturn($persisted);
        Functions\expect('set_transient')->never();

        $wait = $this->transientWindow($now);

        // oldest + 10 - now + 0.1 = 1.1s
        $this->assertEqualsWithDelta(1.1, $wait, 0.0001);
        $this->assertSame([], $this->localTimestamps(), 'A blocked request is not recorded');
    }

    public function test_transient_window_wait_is_floored_at_pause(): void
    {
        $now = 5000.0;
        // 20 distinct stamps just inside the window: oldest expires in 0.05s, so the raw wait is 0.15s.
        $distinct = [];
        for ($i = 0; $i < WC_Multi_Store_API_Client::RATE_LIMIT_REQUESTS; $i++) {
            $distinct[] = $now - 9.95 + ($i * 0.001);
        }
        Functions\when('get_transient')->justReturn($distinct);

        $this->assertSame((float) WC_Multi_Store_API_Client::RATE_LIMIT_PAUSE, $this->transientWindow($now));
    }

    public function test_transient_window_identical_timestamps_are_deduplicated_and_do_not_block(): void
    {
        $now = 5000.0;
        Functions\when('get_transient')->justReturn(array_fill(0, 50, $now - 1.0));

        $this->assertSame(0.0, $this->transientWindow($now));
        $this->assertSame([$now - 1.0, $now], $this->localTimestamps());
    }

    public function test_transient_window_prunes_expired_entries_and_ignores_non_array_transient(): void
    {
        $now = 5000.0;
        Functions\when('get_transient')->justReturn(array_fill(0, 30, $now - 60.0));
        $this->assertSame(0.0, $this->transientWindow($now));
        $this->assertSame([$now], $this->localTimestamps(), 'Expired entries are dropped from the stored window');

        $ref = new ReflectionProperty(WC_Multi_Store_API_Client::class, 'request_timestamps');
        $ref->setValue(null, []);
        Functions\when('get_transient')->justReturn('corrupt');
        $this->assertSame(0.0, $this->transientWindow($now));
        $this->assertSame([$now], $this->localTimestamps());
    }

    public function test_transient_window_merges_local_and_persisted_without_duplicates(): void
    {
        $now = 5000.0;
        $ref = new ReflectionProperty(WC_Multi_Store_API_Client::class, 'request_timestamps');
        $ref->setValue(null, [md5(self::STORE) => [4995.0, 4996.0]]);
        Functions\when('get_transient')->justReturn([4996.0, 4997.0]);

        $this->assertSame(0.0, $this->transientWindow($now));

        $this->assertSame([4995.0, 4996.0, 4997.0, $now], $this->localTimestamps());
    }
}
