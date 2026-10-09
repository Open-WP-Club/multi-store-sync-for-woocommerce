<?php

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

// Reuse the existing WooCommerce object fixtures in isolated scheduler tests.
require_once __DIR__ . '/CouponSyncTest.php';
require_once __DIR__ . '/ReviewSyncTest.php';
require_once __DIR__ . '/AttributeSyncTest.php';

class AsyncSyncTest extends WC_Multi_Store_TestCase
{
    private bool $enabled = true;
    private WP_Comment $review;
    private array $stores = ['https://store.example' => ['status' => 'active', 'store_url' => 'https://store.example']];

    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(fn($key, $default = false) => match ($key) {
            'wc_multi_store_sync_settings' => ['enabled' => $this->enabled, 'shipping_class_sync_enabled' => true],
            'wc_multi_store_sync_stores' => $this->stores,
            'wc_multi_store_sync_coupon_settings', 'wc_mss_review_sync_settings', 'wc_mss_attribute_sync_settings' => ['enabled' => true],
            default => $default,
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_comment_meta')->justReturn('');
        $this->review = new WP_Comment();
        Functions\when('get_comment')->alias(fn() => $this->review);
        Functions\when('wc_get_product')->alias(fn() => new WC_MSS_Review_Test_Product_Stub());
        Functions\when('wc_attribute_taxonomy_slug')->alias(fn($slug) => str_starts_with($slug, 'pa_') ? substr($slug, 3) : $slug);
        Functions\when('wc_get_attribute_taxonomies')->justReturn([(object) [
            'attribute_id' => 1, 'attribute_name' => 'color', 'attribute_label' => 'Color',
            'attribute_type' => 'select', 'attribute_orderby' => 'menu_order', 'attribute_public' => 0,
        ]]);
        Functions\when('get_term')->alias(function () {
            $term = new WP_Term();
            $term->name = 'Red';
            $term->slug = 'red';
            $term->description = '';
            return $term;
        });
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($response) => $response['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($response) => $response['body']);
    }

    public static function callbacks(): array
    {
        return [
            'coupon sync' => [WC_Multi_Store_Coupon_Sync::class, 'sync_coupon_by_id', [1], WC_Multi_Store_Coupon_Sync::ASYNC_SYNC_HOOK],
            'coupon delete' => [WC_Multi_Store_Coupon_Sync::class, 'delete_coupon_by_code', ['TEST10'], WC_Multi_Store_Coupon_Sync::ASYNC_DELETE_HOOK],
            'review sync' => [WC_Multi_Store_Review_Sync::class, 'sync_review_by_id', [1], WC_Multi_Store_Review_Sync::ASYNC_SYNC_HOOK],
            'review delete' => [WC_Multi_Store_Review_Sync::class, 'delete_review_by_data', ['SKU-100', 'jane@example.com'], WC_Multi_Store_Review_Sync::ASYNC_DELETE_HOOK],
            'shipping sync' => [WC_Multi_Store_Shipping_Class_Sync::class, 'sync_shipping_class_by_term_id', [1], WC_Multi_Store_Shipping_Class_Sync::ASYNC_SYNC_HOOK],
            'shipping delete' => [WC_Multi_Store_Shipping_Class_Sync::class, 'delete_shipping_class_by_data', ['Heavy', 'heavy'], WC_Multi_Store_Shipping_Class_Sync::ASYNC_DELETE_HOOK],
            'attribute sync' => [WC_Multi_Store_Attribute_Sync::class, 'sync_attribute_by_id', [1], WC_Multi_Store_Attribute_Sync::ASYNC_SYNC_ATTRIBUTE_HOOK],
            'attribute delete' => [WC_Multi_Store_Attribute_Sync::class, 'delete_attribute_by_slug', ['color'], WC_Multi_Store_Attribute_Sync::ASYNC_DELETE_ATTRIBUTE_HOOK],
            'term sync' => [WC_Multi_Store_Attribute_Sync::class, 'sync_term_by_id', [1, 'pa_color'], WC_Multi_Store_Attribute_Sync::ASYNC_SYNC_TERM_HOOK],
            'term delete' => [WC_Multi_Store_Attribute_Sync::class, 'delete_term_by_slug', ['red', 'pa_color'], WC_Multi_Store_Attribute_Sync::ASYNC_DELETE_TERM_HOOK],
        ];
    }

    #[DataProvider('callbacks')]
    public function test_lookup_failure_marks_real_client_job_failed_and_schedules_retry(string $class, string $method, array $args, string $hook): void
    {
        Functions\expect('wp_remote_get')->once()->andReturn([
            'response' => ['code' => 403], 'body' => '{"message":"Forbidden"}',
        ]);
        Functions\expect('wp_remote_post')->never();
        Functions\expect('wp_remote_request')->never();
        Functions\expect('as_schedule_single_action')->once()->with(
            \Mockery::on(fn($time) => $time >= time() + 59 && $time <= time() + 61),
            $hook, [...$args, 1, ['https://store.example']], WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP
        )->andReturn(123);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed for https://store.example; retry scheduled.');
        (new $class())->$method(...$args);
    }

    #[DataProvider('callbacks')]
    public function test_disabled_feature_registers_callback_but_does_no_work(string $class, string $method, array $args, string $hook): void
    {
        Functions\when('get_option')->alias(fn($key, $default = false) => $key === 'wc_multi_store_sync_settings' ? ['enabled' => true] : $default);
        Functions\expect('wp_remote_get')->never();
        Functions\expect('as_schedule_single_action')->never();
        $registered = [];
        Functions\when('add_action')->alias(function ($name, $callback, $priority = 10, $accepted = 1) use (&$registered) {
            $registered[$name] = $accepted;
        });
        (new $class())->$method(...$args);
        $this->assertSame(count($args) + 2, $registered[$hook]);
    }

    public function test_retries_back_off_and_stop_after_three_attempts(): void
    {
        $sync = new class {
            use WC_Multi_Store_Async_Sync { finish_async as public; }
        };
        $scheduled = [];
        Functions\when('as_schedule_single_action')->alias(function ($time, $hook, $args, $group) use (&$scheduled) {
            $scheduled[] = [$time, $args];
            return 123;
        });
        $before = time();
        for ($attempt = 0; $attempt <= 3; $attempt++) {
            try {
                $sync->finish_async(['ok' => true, 'offline' => false], 'sync_hook', [42], $attempt);
                $this->fail('Failed jobs must throw for Action Scheduler.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('failed for offline;', $e->getMessage());
                $this->assertStringContainsString($attempt < 3 ? '; retry scheduled.' : '; no further retry scheduled.', $e->getMessage());
            }
        }
        $this->assertCount(3, $scheduled);
        foreach ($scheduled as $attempt => [$time, $args]) {
            $this->assertSame([42, $attempt + 1, ['offline']], $args);
            $this->assertGreaterThanOrEqual($before + 60 * (2 ** $attempt), $time);
            $this->assertLessThanOrEqual(time() + 60 * (2 ** $attempt), $time);
        }
        $sync->finish_async(['ok' => true], 'sync_hook', [42], 0);
        $sync->finish_async([], 'sync_hook', [42], 0);
        $this->assertCount(3, $scheduled);
    }
    #[DataProvider('callbacks')]
    public function test_partial_failure_retries_only_failed_store_without_touching_successful_store(string $class, string $method, array $args, string $hook): void
    {
        $this->stores['https://second.example'] = ['status' => 'active', 'store_url' => 'https://second.example'];
        $deleting = str_starts_with($method, 'delete_');
        $endpoint = match ($class) {
            WC_Multi_Store_Coupon_Sync::class => 'coupons',
            WC_Multi_Store_Review_Sync::class => 'products/reviews',
            WC_Multi_Store_Shipping_Class_Sync::class => 'products/shipping_classes',
            default => str_contains($method, 'term_') ? 'products/attributes/5/terms' : 'products/attributes',
        };
        $slug = $class === WC_Multi_Store_Attribute_Sync::class && !str_contains($method, 'term_') ? 'pa_color' : 'red';
        if ($class === WC_Multi_Store_Shipping_Class_Sync::class && $deleting) {
            $slug = 'heavy';
        }
        $record = ['id' => 42, 'slug' => $slug];
        $records = ['store.example' => $deleting ? $record : null, 'second.example' => $deleting ? $record : null];
        $requests = [];
        $fail = true;
        $http = function ($url, $request, $verb) use (&$records, &$requests, &$fail, $endpoint, $record) {
            $host = parse_url($url, PHP_URL_HOST);
            $path = substr(parse_url($url, PHP_URL_PATH), strlen('/wp-json/wc/v3/'));
            $requests[] = [$host, $verb, $path];
            if ($verb === 'GET') {
                if ($path === 'products') {
                    $data = [['id' => 5]];
                } elseif ($path === 'products/attributes' && $endpoint !== $path) {
                    $data = [['id' => 5, 'slug' => 'pa_color']];
                } else {
                    $this->assertSame($endpoint, $path);
                    $data = $records[$host] ? [$records[$host]] : [];
                }
                return ['response' => ['code' => 200], 'body' => json_encode($data)];
            }
            $this->assertSame($verb === 'POST' ? $endpoint : $endpoint . '/42', $path);
            if ($host === 'second.example' && $fail) {
                return ['response' => ['code' => 403], 'body' => '{"message":"Write denied"}'];
            }
            if ($verb === 'DELETE') {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame('1', $query['force']);
                $records[$host] = null;
            } else {
                $records[$host] = array_merge($record, json_decode($request['body'], true));
            }
            return ['response' => ['code' => 200], 'body' => json_encode($record)];
        };
        Functions\when('wp_remote_get')->alias(fn($url, $request) => $http($url, $request, 'GET'));
        Functions\when('wp_remote_post')->alias(fn($url, $request) => $http($url, $request, 'POST'));
        Functions\when('wp_remote_request')->alias(fn($url, $request) => $http($url, $request, $request['method']));
        $retry = null;
        Functions\expect('as_schedule_single_action')->once()->andReturnUsing(function ($time, $name, $queued_args, $group) use (&$retry, $hook) {
            $this->assertSame($hook, $name);
            $retry = $queued_args;
            return 123;
        });
        $sync = new $class();
        try {
            $sync->$method(...$args);
            $this->fail('Partial failure must fail the job.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed for https://second.example;', $e->getMessage());
        }
        $this->assertSame($deleting, $records['store.example'] === null);
        $this->assertSame(!$deleting, $records['second.example'] === null);

        // A later manual edit/recreation on the successful store must survive the retry.
        $records['store.example'] = ['id' => 42, 'slug' => $slug, 'description' => 'Manual change'];
        $request_count = count($requests);
        $this->review->comment_content = 'Newer source edit';
        $fail = false;
        $sync->$method(...$retry);
        $this->assertSame('Manual change', $records['store.example']['description'] ?? null);
        $this->assertSame($deleting, $records['second.example'] === null);
        if ($class === WC_Multi_Store_Review_Sync::class && !$deleting) {
            $this->assertSame('Newer source edit', $records['second.example']['review']);
        }
        foreach (array_slice($requests, $request_count) as $request) {
            $this->assertSame('second.example', $request[0], 'Retry must not access successful stores.');
        }
    }

    public static function events(): array
    {
        return [
            'coupon saved' => [WC_Multi_Store_Coupon_Sync::class, 'on_coupon_saved', [1, '@coupon'], WC_Multi_Store_Coupon_Sync::ASYNC_SYNC_HOOK, [1]],
            'coupon deleted' => [WC_Multi_Store_Coupon_Sync::class, 'on_coupon_deleted', [1], WC_Multi_Store_Coupon_Sync::ASYNC_DELETE_HOOK, ['TEST10']],
            'review posted' => [WC_Multi_Store_Review_Sync::class, 'on_review_posted', [1, 1, ['comment_type' => 'review']], WC_Multi_Store_Review_Sync::ASYNC_SYNC_HOOK, [1]],
            'review edited' => [WC_Multi_Store_Review_Sync::class, 'on_review_changed', [1], WC_Multi_Store_Review_Sync::ASYNC_SYNC_HOOK, [1]],
            'review status' => [WC_Multi_Store_Review_Sync::class, 'on_review_status_changed', ['approved', 'hold', '@review'], WC_Multi_Store_Review_Sync::ASYNC_SYNC_HOOK, [1]],
            'review deleted' => [WC_Multi_Store_Review_Sync::class, 'on_review_deleted', [1], WC_Multi_Store_Review_Sync::ASYNC_DELETE_HOOK, ['SKU-100', 'jane@example.com']],
            'shipping created' => [WC_Multi_Store_Shipping_Class_Sync::class, 'on_shipping_class_created', [1, 1], WC_Multi_Store_Shipping_Class_Sync::ASYNC_SYNC_HOOK, [1]],
            'shipping edited' => [WC_Multi_Store_Shipping_Class_Sync::class, 'on_shipping_class_edited', [1, 1], WC_Multi_Store_Shipping_Class_Sync::ASYNC_SYNC_HOOK, [1]],
            'shipping deleted' => [WC_Multi_Store_Shipping_Class_Sync::class, 'on_shipping_class_deleted', [1, 1, '@term', []], WC_Multi_Store_Shipping_Class_Sync::ASYNC_DELETE_HOOK, ['Red', 'red']],
            'attribute saved' => [WC_Multi_Store_Attribute_Sync::class, 'on_attribute_saved', [1], WC_Multi_Store_Attribute_Sync::ASYNC_SYNC_ATTRIBUTE_HOOK, [1]],
            'attribute deleted' => [WC_Multi_Store_Attribute_Sync::class, 'on_attribute_deleted', [1, 'color'], WC_Multi_Store_Attribute_Sync::ASYNC_DELETE_ATTRIBUTE_HOOK, ['color']],
            'term saved' => [WC_Multi_Store_Attribute_Sync::class, 'on_term_saved', [1, 1, 'pa_color'], WC_Multi_Store_Attribute_Sync::ASYNC_SYNC_TERM_HOOK, [1, 'pa_color']],
            'term deleted' => [WC_Multi_Store_Attribute_Sync::class, 'on_term_deleted', [1, 1, 'pa_color', '@term'], WC_Multi_Store_Attribute_Sync::ASYNC_DELETE_TERM_HOOK, ['red', 'pa_color']],
            'unrelated term saved' => [WC_Multi_Store_Attribute_Sync::class, 'on_term_saved', [1, 1, 'product_cat'], null, []],
            'unrelated term deleted' => [WC_Multi_Store_Attribute_Sync::class, 'on_term_deleted', [1, 1, 'product_cat', '@term'], null, []],
            'invalid deleted term' => [WC_Multi_Store_Attribute_Sync::class, 'on_term_deleted', [1, 1, 'pa_color', null], null, []],
            'ordinary comment' => [WC_Multi_Store_Review_Sync::class, 'on_review_posted', [1, 1, ['comment_type' => 'comment']], null, []],
            'disabled coupon sync' => [WC_Multi_Store_Coupon_Sync::class, 'on_coupon_saved', [1, '@coupon'], null, [], false],
            'synced coupon' => [WC_Multi_Store_Coupon_Sync::class, 'on_coupon_saved', [1, '@coupon'], null, [], true, true],
            'unchanged review status' => [WC_Multi_Store_Review_Sync::class, 'on_review_status_changed', ['approved', 'approved', '@review'], null, []],
        ];
    }

    #[DataProvider('events')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_events_queue_exact_job_without_inline_http(string $class, string $method, array $args, ?string $hook, array $queued_args, bool $enabled = true, bool $syncing = false): void
    {
        // Isolated because defining ActionScheduler must not leak into availability tests.
        class_alias(get_class(new class {}), 'ActionScheduler');
        Functions\when('as_schedule_recurring_action')->justReturn(1);
        $queued = [];
        Functions\when('as_schedule_single_action')->alias(function ($time, $name, $payload, $group) use (&$queued) {
            $this->assertEqualsWithDelta(time(), $time, 1);
            $queued[] = [$name, $payload, $group];
            return 123;
        });
        Functions\expect('wp_remote_get')->never();
        Functions\expect('wp_remote_post')->never();
        Functions\expect('wp_remote_request')->never();
        $this->enabled = $enabled;
        Functions\when('get_post_meta')->justReturn($syncing ? '1' : '');
        $args = array_map(fn($arg) => match ($arg) {
            '@coupon' => new WC_Coupon(1),
            '@review' => new WP_Comment(),
            '@term' => get_term(1),
            default => $arg,
        }, $args);
        (new $class())->$method(...$args);
        $this->assertSame($hook === null ? [] : [[$hook, $queued_args, WC_Multi_Store_Action_Scheduler_Manager::ACTION_GROUP]], $queued);
    }

    #[DataProvider('callbacks')]
    public function test_retry_skips_removed_and_inactive_stores(string $class, string $method, array $args, string $hook): void
    {
        $this->stores['https://store.example']['status'] = 'inactive';
        Functions\expect('wp_remote_get')->never();
        Functions\expect('wp_remote_post')->never();
        Functions\expect('wp_remote_request')->never();
        Functions\expect('as_schedule_single_action')->never();
        $this->assertNull((new $class())->$method(...[...$args, 1, ['https://store.example', 'https://removed.example']]));
    }

    #[DataProvider('callbacks')]
    public function test_global_disable_stops_queued_work(string $class, string $method, array $args, string $hook): void
    {
        $this->enabled = false;
        Functions\expect('wp_remote_get')->never();
        Functions\expect('wp_remote_post')->never();
        Functions\expect('wp_remote_request')->never();
        Functions\expect('as_schedule_single_action')->never();
        $this->assertNull((new $class())->$method(...$args));
    }

    public function test_retry_scheduler_failure_still_marks_job_failed(): void
    {
        $sync = new class {
            use WC_Multi_Store_Async_Sync { finish_async as public; }
        };
        Functions\expect('as_schedule_single_action')->once()->andReturn(0);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no further retry scheduled.');
        $sync->finish_async(['https://store.example' => false], 'sync_hook', [1], 0);
    }

}
