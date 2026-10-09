<?php
/**
 * Unit tests for WC_Multi_Store_Review_Sync
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

if (!class_exists('WP_Comment')) {
    class WP_Comment {
        public int $comment_ID;
        public int $comment_post_ID;
        public string $comment_type;
        public string $comment_content;
        public string $comment_author;
        public string $comment_author_email;
        public string $comment_approved;

        public function __construct(array $props = []) {
            $this->comment_ID = $props['comment_ID'] ?? 1;
            $this->comment_post_ID = $props['comment_post_ID'] ?? 10;
            $this->comment_type = $props['comment_type'] ?? 'review';
            $this->comment_content = $props['comment_content'] ?? 'Great product!';
            $this->comment_author = $props['comment_author'] ?? 'Jane Doe';
            $this->comment_author_email = $props['comment_author_email'] ?? 'jane@example.com';
            $this->comment_approved = $props['comment_approved'] ?? '1';
        }
    }
}

if (!class_exists('WC_MSS_Review_Test_Product_Stub')) {
    class WC_MSS_Review_Test_Product_Stub {
        public function __construct(private string $sku = 'SKU-100') {}
        public function get_sku(): string { return $this->sku; }
    }
}

/**
 * Same rationale as WC_MSS_Test_API_Client_Stub in CouponSyncTest: exposes
 * the get/post/put/delete methods, mockable per test.
 */
if (!class_exists('WC_MSS_Review_Test_API_Client_Stub')) {
    class WC_MSS_Review_Test_API_Client_Stub extends WC_Multi_Store_API_Client {
        public ?\Closure $get_handler    = null;
        public ?\Closure $post_handler   = null;
        public ?\Closure $put_handler    = null;
        public ?\Closure $delete_handler = null;

        public function __construct() {}

        public function get(string $endpoint, array $params = []): array|\WP_Error {
            return ($this->get_handler)($endpoint, $params);
        }

        public function post(string $endpoint, array $data = []): array|\WP_Error {
            return ($this->post_handler)($endpoint, $data);
        }

        public function put(string $endpoint, array $data = []): array|\WP_Error {
            return ($this->put_handler)($endpoint, $data);
        }

        public function delete(string $endpoint, array $params = []): array|\WP_Error {
            return ($this->delete_handler)($endpoint, $params);
        }
    }
}

class ReviewSyncTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('add_action')->justReturn(true);
        Functions\when('register_rest_field')->justReturn(true);
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings'   => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'     => ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'store_url' => 'https://store1.com']],
                'wc_mss_review_sync_settings'    => ['enabled' => true],
                default                          => $default,
            };
        });
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_comment_meta')->justReturn('');
        Functions\when('update_comment_meta')->justReturn(true);
        Functions\when('delete_comment_meta')->justReturn(true);
        Functions\when('wc_get_product')->justReturn(new WC_MSS_Review_Test_Product_Stub());
    }

    private function makeSync(): WC_Multi_Store_Review_Sync
    {
        return new WC_Multi_Store_Review_Sync();
    }

    private function makeClient(): WC_MSS_Review_Test_API_Client_Stub
    {
        return new WC_MSS_Review_Test_API_Client_Stub();
    }

    // ─── extract_review_data() ─────────────────────────────────────────────

    public function test_extract_review_data_maps_fields(): void
    {
        Functions\when('get_comment_meta')->alias(fn($id, $key, $single) => $key === 'rating' ? '5' : '');

        $comment = new WP_Comment();
        $data = $this->makeSync()->extract_review_data($comment);

        $this->assertSame('Great product!', $data['review']);
        $this->assertSame('Jane Doe', $data['reviewer']);
        $this->assertSame('jane@example.com', $data['reviewer_email']);
        $this->assertSame(5, $data['rating']);
        $this->assertSame('approved', $data['status']);
        $this->assertTrue($data['wc_mss_synced']);
    }

    public function test_extract_review_data_hold_status_when_unapproved(): void
    {
        $comment = new WP_Comment(['comment_approved' => '0']);
        $data = $this->makeSync()->extract_review_data($comment);

        $this->assertSame('hold', $data['status']);
    }

    // ─── sync_review_to_store() ────────────────────────────────────────────

    public function test_sync_to_store_creates_when_not_found(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler = fn($ep, $p) => match (true) {
            $ep === 'products'         => [['id' => 55]],
            $ep === 'products/reviews' => [],
            default                    => [],
        };
        $postCalled = false;
        $client->post_handler = function ($ep, $data) use (&$postCalled) {
            $postCalled = true;
            $this->assertSame('products/reviews', $ep);
            $this->assertSame(55, $data['product_id']);
            $this->assertTrue($data['wc_mss_synced']);
            return ['id' => 9];
        };

        $data = $sync->extract_review_data(new WP_Comment());
        $result = $sync->sync_review_to_store($client, 'SKU-100', $data, 'https://store1.com');

        $this->assertTrue($result);
        $this->assertTrue($postCalled);
    }

    public function test_sync_to_store_updates_existing_when_found(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler = fn($ep, $p) => match (true) {
            $ep === 'products'         => [['id' => 55]],
            $ep === 'products/reviews' => [['id' => 77]],
            default                    => [],
        };
        $putCalled = false;
        $client->put_handler = function ($ep, $data) use (&$putCalled) {
            $putCalled = true;
            $this->assertSame('products/reviews/77', $ep);
            return ['id' => 77];
        };

        $data = $sync->extract_review_data(new WP_Comment());
        $result = $sync->sync_review_to_store($client, 'SKU-100', $data, 'https://store1.com');

        $this->assertTrue($result);
        $this->assertTrue($putCalled);
    }

    public function test_sync_to_store_skips_when_product_not_found_remotely(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler = fn($ep, $p) => [];

        $data = $sync->extract_review_data(new WP_Comment());
        $result = $sync->sync_review_to_store($client, 'SKU-MISSING', $data, 'https://store1.com');

        $this->assertFalse($result);
    }

    public function test_sync_to_store_returns_false_on_api_error(): void
    {
        $sync   = $this->makeSync();
        $client = $this->makeClient();

        $client->get_handler  = fn($ep, $p) => $ep === 'products' ? [['id' => 55]] : [];
        $client->post_handler = fn($ep, $data) => new WP_Error('api_error', 'Connection refused');

        $data = $sync->extract_review_data(new WP_Comment());
        $result = $sync->sync_review_to_store($client, 'SKU-100', $data, 'https://store1.com');

        $this->assertFalse($result);
    }

    // ─── register_synced_field(): explicit verified-flag stripping ────────

    public function test_synced_field_marks_loop_guard_and_strips_verified(): void
    {
        $captured = null;
        Functions\when('register_rest_field')->alias(function ($type, $attr, $args) use (&$captured) {
            $captured = [$type, $attr, $args];
            return true;
        });

        WC_Multi_Store_Review_Sync::register_synced_field();

        $this->assertSame('product_review', $captured[0]);
        $this->assertSame('wc_mss_synced', $captured[1]);

        $metaSet = [];
        $metaDeleted = [];
        Functions\when('update_comment_meta')->alias(function ($id, $key, $value) use (&$metaSet) {
            $metaSet[] = [$id, $key, $value];
            return true;
        });
        Functions\when('delete_comment_meta')->alias(function ($id, $key) use (&$metaDeleted) {
            $metaDeleted[] = [$id, $key];
            return true;
        });

        $comment = (object) ['comment_ID' => 123];
        ($captured[2]['update_callback'])(true, $comment);

        $this->assertContains([123, WC_Multi_Store_Review_Sync::SYNCED_MARKER_META, 1], $metaSet);
        $this->assertContains([123, 'verified'], $metaDeleted);
    }

    public function test_synced_field_noop_when_value_falsy(): void
    {
        $captured = null;
        Functions\when('register_rest_field')->alias(function ($type, $attr, $args) use (&$captured) {
            $captured = $args;
            return true;
        });

        WC_Multi_Store_Review_Sync::register_synced_field();

        $called = false;
        Functions\when('delete_comment_meta')->alias(function () use (&$called) {
            $called = true;
            return true;
        });

        $comment = (object) ['comment_ID' => 123];
        ($captured['update_callback'])(false, $comment);

        $this->assertFalse($called);
    }

    // ─── sync_review_by_id(): mesh loop-guard ──────────────────────────────

    public function test_sync_review_by_id_skips_when_synced_marker_set(): void
    {
        Functions\when('get_comment_meta')->alias(
            fn($id, $key, $single) => $key === WC_Multi_Store_Review_Sync::SYNCED_MARKER_META ? '1' : ''
        );
        Functions\when('get_comment')->justReturn(new WP_Comment());

        $sync = $this->makeSync();

        // No API client handlers configured anywhere — reaching this point
        // without a fatal proves sync_review_to_all_stores() was never
        // reached.
        Functions\expect('wp_remote_get')->never();
        Functions\expect('as_schedule_single_action')->never();
        $this->assertNull($sync->sync_review_by_id(1));
    }

    public function test_sync_review_by_id_skips_non_review_comment_type(): void
    {
        Functions\when('get_comment')->justReturn(new WP_Comment(['comment_type' => 'comment']));

        $sync = $this->makeSync();

        Functions\expect('wp_remote_get')->never();
        Functions\expect('as_schedule_single_action')->never();
        $this->assertNull($sync->sync_review_by_id(1));
    }

    // ─── delete_review_from_all_stores() ───────────────────────────────────

    public function test_delete_from_all_stores_deletes_when_found(): void
    {
        Functions\expect('wp_remote_get')->twice()->andReturnUsing(function ($url) {
            parse_str(parse_url($url, PHP_URL_QUERY), $params);
            if (str_contains($url, '/products/reviews?')) {
                $this->assertSame(['55'], $params['product']);
                $this->assertSame('jane@example.com', $params['reviewer_email']);
                $this->assertSame('all', $params['status']);
                $data = [['id' => 77]];
            } else {
                $this->assertSame('SKU-100', $params['sku']);
                $data = [['id' => 55]];
            }
            return ['response' => ['code' => 200], 'body' => json_encode($data)];
        });
        Functions\expect('wp_remote_request')->once()->with('https://store1.com/wp-json/wc/v3/products/reviews/77?force=1', \Mockery::on(fn($args) => $args['method'] === 'DELETE'))
            ->andReturn(['response' => ['code' => 200], 'body' => '{"id":77}']);
        $this->assertSame(['https://store1.com' => true], $this->makeSync()->delete_review_from_all_stores('SKU-100', 'jane@example.com'));
    }

    // ─── ajax_toggle() ──────────────────────────────────────────────────────

    public function test_ajax_toggle_enables_and_persists(): void
    {
        $_POST['enabled'] = '1';
        $saved = null;
        Functions\when('update_option')->alias(function ($opt, $value) use (&$saved) {
            $saved = [$opt, $value];
            return true;
        });
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_success')->alias(function ($data) {
            $this->assertTrue($data['enabled']);
        });

        WC_Multi_Store_Review_Sync::ajax_toggle();

        $this->assertSame('wc_mss_review_sync_settings', $saved[0]);
        $this->assertTrue($saved[1]['enabled']);

        unset($_POST['enabled']);
    }
    public function test_review_lookup_error_does_not_create_duplicate(): void
    {
        $client = $this->makeClient();
        $client->get_handler = function ($endpoint, $params) {
            if ($endpoint === 'products') {
                return [['id' => 55]];
            }
            $this->assertSame('all', $params['status']);
            return new WP_Error('offline', 'Offline');
        };
        $sync = $this->makeSync();
        $this->assertFalse($sync->sync_review_to_store($client, 'SKU-100', $sync->extract_review_data(new WP_Comment()), 'https://store1.com'));
    }

    public function test_failed_product_lookup_is_not_cached(): void
    {
        $client = $this->makeClient();
        $client->get_handler = fn() => new WP_Error('offline', 'Offline');
        Functions\expect('set_transient')->never();
        $sync = $this->makeSync();
        $this->assertFalse($sync->sync_review_to_store($client, 'SKU-100', $sync->extract_review_data(new WP_Comment()), 'https://store1.com'));
    }

}
