<?php
/**
 * Tests for WC_Multi_Store_Review_Sync lookup helpers:
 * find_remote_review, is_review and resolve_remote_product_id.
 */

use Brain\Monkey\Functions;

if (!class_exists('WP_Comment')) {
    // Kept identical to the guarded stub in ReviewSyncTest.php.
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

class ReviewSyncResolveTest extends WC_Multi_Store_TestCase
{
    private const STORE = 'https://store-a.example.com';

    /** @var string[] */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Settings::clear_static_cache();
        $this->logged = [];
        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_option')->alias(fn($key, $default = false) => $key === 'wc_multi_store_sync_settings'
            ? ['enabled' => true]
            : $default);
        $sink = new class ($this->logged) {
            public function __construct(public array &$messages) {}
            public function log($level, $message, $context = []): void { $this->messages[] = $message; }
        };
        Functions\when('wc_get_logger')->justReturn($sink);
    }

    private function sync(): WC_Multi_Store_Review_Sync
    {
        return (new ReflectionClass(WC_Multi_Store_Review_Sync::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(WC_Multi_Store_Review_Sync::class, $method))->invoke($this->sync(), ...$args);
    }

    private function client(): \Mockery\MockInterface
    {
        return \Mockery::mock(WC_Multi_Store_API_Client::class);
    }

    private function cacheKey(): string
    {
        return 'wc_mss_review_sku_map_' . md5(self::STORE);
    }

    // ─── is_review() ────────────────────────────────────────────────────────

    public function test_is_review_true_for_review_comment(): void
    {
        Functions\when('get_comment')->justReturn(new WP_Comment(['comment_type' => 'review']));

        $this->assertTrue($this->call('is_review', 1));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonReviewComments')]
    public function test_is_review_false_for_everything_else(mixed $comment): void
    {
        Functions\when('get_comment')->justReturn($comment);

        $this->assertFalse($this->call('is_review', 1));
    }

    public static function nonReviewComments(): array
    {
        return [
            'regular comment' => [new WP_Comment(['comment_type' => 'comment'])],
            'empty type (legacy comment)' => [new WP_Comment(['comment_type' => ''])],
            'type is case-sensitive' => [new WP_Comment(['comment_type' => 'Review'])],
            'comment not found (null)' => [null],
            'comment not found (false)' => [false],
            'array output instead of object' => [['comment_type' => 'review']],
        ];
    }

    public function test_is_review_looks_up_the_given_comment_id(): void
    {
        $asked = null;
        Functions\when('get_comment')->alias(function ($id) use (&$asked) {
            $asked = $id;
            return null;
        });

        $this->call('is_review', 321);

        $this->assertSame(321, $asked);
    }

    public function test_on_review_changed_ignores_non_reviews_and_queues_reviews(): void
    {
        $sync = $this->sync();

        Functions\when('get_comment')->justReturn(new WP_Comment(['comment_type' => 'comment']));
        $sync->on_review_changed(5);
        $this->assertSame([], $this->logged, 'A plain comment must not be queued');

        Functions\when('get_comment')->justReturn(new WP_Comment(['comment_type' => 'review']));
        $sync->on_review_changed(5);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString(WC_Multi_Store_Review_Sync::ASYNC_SYNC_HOOK, $this->logged[0]);
    }

    public function test_on_review_deleted_ignores_non_reviews_without_loading_the_product(): void
    {
        Functions\when('get_comment')->justReturn(new WP_Comment(['comment_type' => 'comment']));
        $productLookups = 0;
        Functions\when('wc_get_product')->alias(function () use (&$productLookups) {
            $productLookups++;
            return false;
        });

        $this->sync()->on_review_deleted(5);

        $this->assertSame(0, $productLookups);
        $this->assertSame([], $this->logged);
    }

    // ─── find_remote_review() ───────────────────────────────────────────────

    public function test_find_remote_review_queries_by_product_and_exact_reviewer_email_across_statuses(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->once()->with('products/reviews', [
            'product' => [42],
            'reviewer_email' => 'Jane@Example.com',
            'status' => 'all',
            'per_page' => 1,
        ])->andReturn([['id' => 9, 'reviewer_email' => 'jane@example.com']]);

        $this->assertSame(
            ['id' => 9, 'reviewer_email' => 'jane@example.com'],
            $this->call('find_remote_review', $client, 42, 'Jane@Example.com')
        );
    }

    public function test_find_remote_review_returns_null_when_none_exist(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->andReturn([]);

        $this->assertNull($this->call('find_remote_review', $client, 42, 'jane@example.com'));
    }

    public function test_find_remote_review_returns_only_first_when_api_returns_several(): void
    {
        $client = $this->client();
        $client->shouldReceive('get')->andReturn([['id' => 1], ['id' => 2]]);

        $this->assertSame(['id' => 1], $this->call('find_remote_review', $client, 42, 'a@b.c'));
    }

    public function test_find_remote_review_propagates_wp_error(): void
    {
        $error = new WP_Error('http_500', 'boom');
        $client = $this->client();
        $client->shouldReceive('get')->andReturn($error);

        $this->assertSame($error, $this->call('find_remote_review', $client, 42, 'a@b.c'));
    }

    // ─── resolve_remote_product_id() ────────────────────────────────────────

    public function test_resolve_remote_product_id_uses_cache_without_api_call(): void
    {
        $keys = [];
        Functions\when('get_transient')->alias(function ($key) use (&$keys) {
            $keys[] = $key;
            return ['SKU-1' => 77, 'SKU-2' => 88];
        });
        $writes = 0;
        Functions\when('set_transient')->alias(function () use (&$writes) {
            $writes++;
            return true;
        });
        $client = $this->client();
        $client->shouldReceive('get')->never();

        $this->assertSame(88, $this->call('resolve_remote_product_id', $client, 'SKU-2', self::STORE));
        $this->assertSame([$this->cacheKey()], $keys);
        $this->assertSame(0, $writes);
    }

    public function test_resolve_remote_product_id_fetches_casts_to_int_and_merges_into_cache(): void
    {
        Functions\when('get_transient')->justReturn(['OTHER' => 5]);
        $writes = [];
        Functions\when('set_transient')->alias(function (...$args) use (&$writes) {
            $writes[] = $args;
            return true;
        });
        $client = $this->client();
        $client->shouldReceive('get')->once()->with('products', ['sku' => 'SKU-1', 'per_page' => 1])->andReturn([['id' => '123']]);

        $result = $this->call('resolve_remote_product_id', $client, 'SKU-1', self::STORE);

        $this->assertSame(123, $result);
        $this->assertSame([[$this->cacheKey(), ['OTHER' => 5, 'SKU-1' => 123], HOUR_IN_SECONDS]], $writes);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badCacheValues')]
    public function test_resolve_remote_product_id_recovers_from_corrupt_cache(mixed $cached): void
    {
        Functions\when('get_transient')->justReturn($cached);
        $written = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$written) {
            $written = $value;
            return true;
        });
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn([['id' => 4]]);

        $this->assertSame(4, $this->call('resolve_remote_product_id', $client, 'SKU-1', self::STORE));
        $this->assertSame(['SKU-1' => 4], $written);
    }

    public static function badCacheValues(): array
    {
        return [
            'miss' => [false],
            'string' => ['oops'],
            'null' => [null],
            'int' => [1],
        ];
    }

    public function test_resolve_remote_product_id_returns_wp_error_and_does_not_cache(): void
    {
        Functions\when('get_transient')->justReturn(false);
        $writes = 0;
        Functions\when('set_transient')->alias(function () use (&$writes) {
            $writes++;
            return true;
        });
        $error = new WP_Error('http_503', 'down');
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn($error);

        $this->assertSame($error, $this->call('resolve_remote_product_id', $client, 'SKU-1', self::STORE));
        $this->assertSame(0, $writes);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notFoundResponses')]
    public function test_resolve_remote_product_id_returns_null_and_does_not_cache_when_not_found(array $response): void
    {
        Functions\when('get_transient')->justReturn(false);
        $writes = 0;
        Functions\when('set_transient')->alias(function () use (&$writes) {
            $writes++;
            return true;
        });
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn($response);

        $this->assertNull($this->call('resolve_remote_product_id', $client, 'SKU-1', self::STORE));
        $this->assertSame(0, $writes);
    }

    public static function notFoundResponses(): array
    {
        return [
            'empty list' => [[]],
            'row without id' => [[['sku' => 'SKU-1']]],
            'id zero' => [[['id' => 0]]],
            'id null' => [[['id' => null]]],
        ];
    }

    public function test_resolve_remote_product_id_refetches_when_cached_id_is_falsy(): void
    {
        Functions\when('get_transient')->justReturn(['SKU-1' => 0]);
        Functions\when('set_transient')->justReturn(true);
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn([['id' => 6]]);

        $this->assertSame(6, $this->call('resolve_remote_product_id', $client, 'SKU-1', self::STORE));
    }

    public function test_resolve_remote_product_id_sku_lookup_is_case_sensitive(): void
    {
        Functions\when('get_transient')->justReturn(['sku-1' => 10]);
        Functions\when('set_transient')->justReturn(true);
        $client = $this->client();
        $client->shouldReceive('get')->once()->with('products', ['sku' => 'SKU-1', 'per_page' => 1])->andReturn([['id' => 11]]);

        $this->assertSame(11, $this->call('resolve_remote_product_id', $client, 'SKU-1', self::STORE));
    }

    public function test_resolve_remote_product_id_cache_is_scoped_per_store(): void
    {
        $seen = [];
        Functions\when('get_transient')->alias(function ($key) use (&$seen) {
            $seen[] = $key;
            return $key === 'wc_mss_review_sku_map_' . md5('https://one.example') ? ['SKU-1' => 1] : false;
        });
        Functions\when('set_transient')->justReturn(true);
        $client = $this->client();
        $client->shouldReceive('get')->once()->andReturn([['id' => 2]]);

        $this->assertSame(1, $this->call('resolve_remote_product_id', $this->client(), 'SKU-1', 'https://one.example'));
        $this->assertSame(2, $this->call('resolve_remote_product_id', $client, 'SKU-1', 'https://two.example'));
        $this->assertCount(2, array_unique($seen));
    }
}
