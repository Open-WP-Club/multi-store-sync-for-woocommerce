<?php
/**
 * Edge case tests for WC_Multi_Store_Sync_Engine
 * Covers: image upload failures, term pagination, partial sync failures
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SyncEngineImageTermTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMocks();
    }

    protected function setUpMocks(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_settings') {
                return [
                    'enabled' => true,
                    'sync_type_default' => 'full_product',
                    'auth_method' => 'query_string',
                    'match_products_by' => 'sku',
                    'category_auto_create' => true,
                    'category_match_by' => 'slug',
                ];
            }
            if ($option === 'wc_multi_store_sync_stores') {
                return [];
            }
            return $default;
        });

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_get_post_terms')->justReturn([]);
        Functions\when('esc_url_raw')->alias(fn($url) => $url);
        Functions\when('sanitize_textarea_field')->alias(fn($str) => strip_tags($str));
        Functions\when('sanitize_title')->alias(fn($str) => strtolower(preg_replace('/[^a-z0-9-]/', '-', strtolower($str))));
        Functions\when('add_query_arg')->alias(function () {
            $args = func_get_args();
            if (count($args) === 2 && is_array($args[0])) {
                return $args[1] . '?' . http_build_query($args[0]);
            }
            return $args[count($args) - 1] ?? '';
        });
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 200],
            'body' => '[]',
        ]);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
        Functions\when('absint')->alias(fn($val) => abs((int) $val));
        Functions\when('wp_remote_get')->justReturn([
            'response' => ['code' => 200],
            'body' => '[]',
        ]);
        Functions\when('wp_remote_post')->justReturn([
            'response' => ['code' => 200],
            'body' => '{}',
        ]);
        Functions\when('trailingslashit')->alias(fn($s) => rtrim($s, '/') . '/');
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wp_cache_flush')->justReturn(true);
        Functions\when('rest_url')->alias(fn($path = '') => 'https://example.com/wp-json/' . ltrim($path, '/'));
        Functions\when('get_terms')->justReturn([]);
        Functions\when('wp_get_attachment_url')->justReturn('');
        Functions\when('get_the_terms')->justReturn([]);
    }

    // ── is_image_download_error ───────────────────────────────────

    public function test_is_image_download_error_detects_403_with_image_url(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'is_image_download_error');

        $this->assertTrue(
            $method->invoke($engine, 'Forbidden: https://cdn.example.com/wp-content/uploads/image.jpg')
        );
    }

    public function test_is_image_download_error_detects_403_status_code(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'is_image_download_error');

        $this->assertTrue(
            $method->invoke($engine, '403 error downloading https://example.com/wp-content/uploads/photo.png')
        );
    }

    public function test_is_image_download_error_detects_406_cdn_block(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'is_image_download_error');

        $this->assertTrue(
            $method->invoke($engine, 'Not Acceptable: https://example.com/image/product.webp')
        );
    }

    public function test_is_image_download_error_ignores_non_image_urls(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'is_image_download_error');

        // 403 error but not an image URL
        $this->assertFalse(
            $method->invoke($engine, 'Forbidden: https://example.com/api/products')
        );
    }

    public function test_is_image_download_error_ignores_non_error_image_urls(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'is_image_download_error');

        // Image URL but no error pattern
        $this->assertFalse(
            $method->invoke($engine, 'Downloaded https://example.com/wp-content/uploads/photo.jpg successfully')
        );
    }

    public function test_is_image_download_error_detects_various_extensions(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'is_image_download_error');

        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
        foreach ($extensions as $ext) {
            $this->assertTrue(
                $method->invoke($engine, "403 error at https://example.com/photo.{$ext}"),
                "Failed for extension: {$ext}"
            );
        }
    }

    // ── upload_images_via_api ──────────────────────────────────────

    public function test_upload_images_skips_on_upload_error(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'upload_images_via_api');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('upload_image')->andReturn(
            new \WP_Error('upload_failed', 'Request Entity Too Large')
        );

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(10);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_sku')->andReturn('IMG-TEST');

        // Mock the WP functions that Image Proxy uses internally
        $tmpFile = tempnam(sys_get_temp_dir(), 'img_test_');
        file_put_contents($tmpFile, 'fake image data');
        Functions\when('get_attached_file')->justReturn($tmpFile);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');

        $images = [['src' => 'https://example.com/image.jpg', 'position' => 0]];

        $result = $method->invoke($engine, $api, $product, $images);
        @unlink($tmpFile);

        // Upload error → image is skipped entirely (no fallback to src to avoid orphaned remote media)
        $this->assertCount(0, $result);
    }

    public function test_upload_images_returns_remote_id_on_success(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'upload_images_via_api');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('upload_image')->andReturn(['id' => 555]);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(10);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_sku')->andReturn('IMG-OK');

        $tmpFile = tempnam(sys_get_temp_dir(), 'img_test_');
        file_put_contents($tmpFile, 'fake image data');
        Functions\when('get_attached_file')->justReturn($tmpFile);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');

        $images = [['src' => 'https://example.com/image.jpg', 'position' => 0]];

        $result = $method->invoke($engine, $api, $product, $images);
        @unlink($tmpFile);

        $this->assertCount(1, $result);
        $this->assertEquals(555, $result[0]['id']);
        $this->assertEquals(0, $result[0]['position']);
    }

    public function test_upload_images_skips_when_image_data_null(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'upload_images_via_api');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(10);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_sku')->andReturn('IMG-NULL');

        // Image file doesn't exist → get_image_data returns null
        Functions\when('get_attached_file')->justReturn('/nonexistent/path/deleted.jpg');
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');

        $images = [['src' => 'https://example.com/deleted.jpg', 'position' => 0]];

        $result = $method->invoke($engine, $api, $product, $images);

        // Null image data → image is skipped entirely (no fallback to src)
        $this->assertCount(0, $result);
    }

    public function test_upload_images_skips_zero_attachment_id(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'upload_images_via_api');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(0);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([]);
        $product->shouldReceive('get_sku')->andReturn('IMG-ZERO');

        $images = [['src' => 'https://example.com/external.jpg', 'position' => 0]];

        $result = $method->invoke($engine, $api, $product, $images);

        // No attachment ID → image is skipped entirely (no fallback to src)
        $this->assertCount(0, $result);
    }

    public function test_upload_images_handles_mixed_success_and_failure(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'upload_images_via_api');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        // First image succeeds, second fails
        $api->shouldReceive('upload_image')->andReturn(
            ['id' => 100],
            new \WP_Error('timeout', 'Request timed out')
        );

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_image_id')->andReturn(10);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([20]);
        $product->shouldReceive('get_sku')->andReturn('IMG-MIX');

        $tmpFile = tempnam(sys_get_temp_dir(), 'img_test_');
        file_put_contents($tmpFile, 'fake image data');
        Functions\when('get_attached_file')->justReturn($tmpFile);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');

        $images = [
            ['src' => 'https://example.com/main.jpg', 'position' => 0],
            ['src' => 'https://example.com/gallery.jpg', 'position' => 1],
        ];

        $result = $method->invoke($engine, $api, $product, $images);
        @unlink($tmpFile);

        $this->assertCount(1, $result);
        // First image uploaded successfully → has 'id'
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertEquals(100, $result[0]['id']);
        // Second image failed → skipped entirely (no fallback to src)
    }

    // ── Term pagination (fetch_all_terms) ─────────────────────────

    public function test_fetch_all_terms_paginates_categories(): void
    {
        $method = new \ReflectionMethod(WC_Multi_Store_Sync_Engine::class, 'fetch_all_terms');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        // Page 1: full page (100 items)
        $page1 = array_map(fn($i) => ['id' => $i, 'name' => "Cat {$i}", 'slug' => "cat-{$i}"], range(1, 100));
        // Page 2: partial page (30 items) → signals last page
        $page2 = array_map(fn($i) => ['id' => $i, 'name' => "Cat {$i}", 'slug' => "cat-{$i}"], range(101, 130));

        $api->shouldReceive('get_categories')
            ->with('', ['per_page' => 100, 'page' => 1])
            ->once()
            ->andReturn($page1);
        $api->shouldReceive('get_categories')
            ->with('', ['per_page' => 100, 'page' => 2])
            ->once()
            ->andReturn($page2);

        $result = $method->invoke(null, $api, 'categories');

        $this->assertCount(130, $result);
        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals(130, $result[129]['id']);
    }

    public function test_fetch_all_terms_paginates_tags(): void
    {
        $method = new \ReflectionMethod(WC_Multi_Store_Sync_Engine::class, 'fetch_all_terms');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $page1 = array_map(fn($i) => ['id' => $i, 'name' => "Tag {$i}", 'slug' => "tag-{$i}"], range(1, 100));
        $page2 = array_map(fn($i) => ['id' => $i, 'name' => "Tag {$i}", 'slug' => "tag-{$i}"], range(101, 150));

        $api->shouldReceive('get_tags')
            ->with('', ['per_page' => 100, 'page' => 1])
            ->once()
            ->andReturn($page1);
        $api->shouldReceive('get_tags')
            ->with('', ['per_page' => 100, 'page' => 2])
            ->once()
            ->andReturn($page2);

        $result = $method->invoke(null, $api, 'tags');

        $this->assertCount(150, $result);
    }

    public function test_fetch_all_terms_stops_on_api_error(): void
    {
        $method = new \ReflectionMethod(WC_Multi_Store_Sync_Engine::class, 'fetch_all_terms');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $api->shouldReceive('get_categories')
            ->with('', ['per_page' => 100, 'page' => 1])
            ->once()
            ->andReturn(new \WP_Error('api_error', 'Connection timeout'));

        $result = $method->invoke(null, $api, 'categories');

        $this->assertEmpty($result);
    }

    public function test_fetch_all_terms_stops_on_empty_result(): void
    {
        $method = new \ReflectionMethod(WC_Multi_Store_Sync_Engine::class, 'fetch_all_terms');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        $api->shouldReceive('get_categories')
            ->with('', ['per_page' => 100, 'page' => 1])
            ->once()
            ->andReturn([]);

        $result = $method->invoke(null, $api, 'categories');

        $this->assertEmpty($result);
    }

    public function test_fetch_all_terms_caps_at_50_pages(): void
    {
        $method = new \ReflectionMethod(WC_Multi_Store_Sync_Engine::class, 'fetch_all_terms');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');

        // Always returns exactly 100 items (full page) — would loop forever without cap
        $fullPage = array_map(fn($i) => ['id' => $i, 'name' => "Cat {$i}", 'slug' => "cat-{$i}"], range(1, 100));
        $api->shouldReceive('get_categories')->andReturn($fullPage);

        $result = $method->invoke(null, $api, 'categories');

        // 50 pages × 100 = 5000 max
        $this->assertCount(5000, $result);
    }

    // ── ensure_terms_exist ────────────────────────────────────────

    public function test_ensure_terms_exist_maps_existing_terms(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        // Return cached terms
        $api->shouldReceive('get_categories')->andReturn([
            ['id' => 10, 'name' => 'Shoes', 'slug' => 'shoes'],
            ['id' => 20, 'name' => 'Bags', 'slug' => 'bags'],
        ]);

        $terms = [
            ['name' => 'Shoes', 'slug' => 'shoes'],
            ['name' => 'Bags', 'slug' => 'bags'],
        ];

        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(2, $result);
        $this->assertEquals(10, $result[0]['id']);
        $this->assertEquals(20, $result[1]['id']);
    }

    public function test_ensure_terms_exist_batch_creates_missing_terms(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        // No existing categories
        $api->shouldReceive('get_categories')->with('', \Mockery::any())->andReturn([]);
        // Batch create returns new IDs
        $api->shouldReceive('batch_categories')->once()->andReturn([
            'create' => [
                ['id' => 50, 'name' => 'New Cat', 'slug' => 'new-cat'],
            ],
        ]);

        $terms = [['name' => 'New Cat', 'slug' => 'new-cat']];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(1, $result);
        $this->assertEquals(50, $result[0]['id']);
    }

    public function test_ensure_terms_exist_falls_back_to_individual_create_on_batch_error(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        $api->shouldReceive('get_categories')->with('', \Mockery::any())->andReturn([]);
        // Batch fails
        $api->shouldReceive('batch_categories')->once()->andReturn(
            new \WP_Error('batch_error', 'Batch endpoint not supported')
        );
        // Fallback to individual creation
        $api->shouldReceive('create_category')->once()->andReturn([
            'id' => 60, 'name' => 'Fallback Cat', 'slug' => 'fallback-cat',
        ]);

        $terms = [['name' => 'Fallback Cat', 'slug' => 'fallback-cat']];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(1, $result);
        $this->assertEquals(60, $result[0]['id']);
    }

    public function test_ensure_terms_exist_uses_chunked_batches_when_full_batch_fails(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        $api->shouldReceive('get_categories')->with('', \Mockery::any())->andReturn([]);

        // Build 25 terms (> TERM_FALLBACK_CHUNK_SIZE = 10) so chunked path triggers.
        $terms = [];
        for ($i = 1; $i <= 25; $i++) {
            $terms[] = ['name' => "Cat $i", 'slug' => "cat-$i"];
        }

        // First call: full batch fails.
        $api->shouldReceive('batch_categories')
            ->once()
            ->with(\Mockery::on(fn($payload) => isset($payload['create']) && count($payload['create']) === 25))
            ->andReturn(new \WP_Error('server_error', 'Batch endpoint 500'));

        // Then 3 chunked batches (10 + 10 + 5) each succeed.
        $next_id = 100;
        $api->shouldReceive('batch_categories')
            ->times(3)
            ->andReturnUsing(function ($payload) use (&$next_id) {
                $created = [];
                foreach ($payload['create'] as $t) {
                    $created[] = ['id' => $next_id++, 'name' => $t['name'], 'slug' => $t['slug']];
                }
                return ['create' => $created];
            });

        // Per-item fallback must NOT be touched.
        $api->shouldNotReceive('create_category');

        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(25, $result);
        // Spot-check that all entries got an id.
        foreach ($result as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertIsInt($entry['id']);
        }
    }

    public function test_ensure_terms_exist_aborts_per_item_fallback_after_max_failures(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        $api->shouldReceive('get_categories')->with('', \Mockery::any())->andReturn([]);

        // 4 terms — below chunk size, so chunked pass is skipped.
        $terms = [
            ['name' => 'A', 'slug' => 'a'],
            ['name' => 'B', 'slug' => 'b'],
            ['name' => 'C', 'slug' => 'c'],
            ['name' => 'D', 'slug' => 'd'],
        ];

        // Full batch fails.
        $api->shouldReceive('batch_categories')->once()->andReturn(
            new \WP_Error('server_error', 'Batch 500')
        );

        // Per-item: only first MAX_FAILURES (= 5) should be attempted before abort.
        // We have only 4 terms, so all 4 should be attempted; all fail.
        $api->shouldReceive('create_category')
            ->times(4)
            ->andReturn(new \WP_Error('item_failed', 'Per-item also failing'));

        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        // No terms got IDs because all per-item calls failed.
        $this->assertCount(0, $result);
    }

    public function test_ensure_terms_exist_aborts_after_five_consecutive_per_item_failures(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        $api->shouldReceive('get_categories')->with('', \Mockery::any())->andReturn([]);

        // 8 terms — below chunk size, chunked pass skipped → straight to per-item.
        $terms = [];
        for ($i = 1; $i <= 8; $i++) {
            $terms[] = ['name' => "T$i", 'slug' => "t$i"];
        }

        $api->shouldReceive('batch_categories')->once()->andReturn(
            new \WP_Error('server_error', 'Batch 500')
        );

        // After 5 consecutive failures the loop must abort — 6th call onward
        // should NOT happen. Mockery's exact count enforces this.
        $api->shouldReceive('create_category')
            ->times(5)
            ->andReturn(new \WP_Error('item_failed', 'Down'));

        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(0, $result);
    }

    public function test_ensure_terms_exist_skips_empty_names(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        $api->shouldReceive('get_categories')->with('', \Mockery::any())->andReturn([
            ['id' => 10, 'name' => 'Valid', 'slug' => 'valid'],
        ]);

        $terms = [
            ['name' => '', 'slug' => ''],
            ['name' => 'Valid', 'slug' => 'valid'],
        ];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(1, $result);
        $this->assertEquals(10, $result[0]['id']);
    }

    // ── ensure_terms_exist: rename drift when the match key stayed stable ──

    /**
     * slug-match mode: the remote term is found via a stable slug, but its
     * display name is stale (local category was renamed). Without an
     * explicit rename push, the remote label drifts forever.
     */
    public function test_ensure_terms_exist_renames_remote_category_when_local_name_drifted(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        $api->shouldReceive('get_categories')->andReturn([
            ['id' => 10, 'name' => 'Shoes', 'slug' => 'shoes'],
        ]);
        $api->shouldReceive('batch_categories')
            ->once()
            ->with(['update' => [['id' => 10, 'name' => 'Dress Shoes']]])
            ->andReturn(['update' => [['id' => 10, 'name' => 'Dress Shoes', 'slug' => 'shoes']]]);

        $terms = [['name' => 'Dress Shoes', 'slug' => 'shoes']];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertEquals(10, $result[0]['id']);
    }

    /**
     * name-match mode: the remote term is found via its (unchanged) name,
     * but its slug is stale relative to the local term's current slug.
     */
    public function test_ensure_terms_exist_updates_remote_tag_slug_when_local_slug_drifted_in_name_match_mode(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');
        $api->shouldReceive('get_tags')->andReturn([
            ['id' => 30, 'name' => 'Sale', 'slug' => 'sale'],
        ]);
        $api->shouldReceive('batch_tags')
            ->once()
            ->with(['update' => [['id' => 30, 'slug' => 'clearance-sale']]])
            ->andReturn(['update' => [['id' => 30, 'name' => 'Sale', 'slug' => 'clearance-sale']]]);

        $terms = [['name' => 'Sale', 'slug' => 'clearance-sale']];
        $settings = ['category_match_by' => 'name'];

        $result = $method->invoke($engine, $terms, $api, 'tags', $settings);

        $this->assertEquals(30, $result[0]['id']);
    }

    // ── ensure_terms_exist: slug-collision detection ──────────────

    /**
     * Core scenario: batch_categories returns a "-2" slug because a parallel
     * worker already created the category a few milliseconds earlier.
     * The fix must: look up the real category, return its ID, delete the duplicate.
     */
    public function test_slug_collision_resolves_to_existing_id(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');

        // Initial cache fetch returns nothing (cache miss, empty remote store).
        $api->shouldReceive('get_categories')
            ->with('', \Mockery::on(fn($p) => isset($p['page'])))
            ->once()
            ->andReturn([]);

        // Batch create: WooCommerce appended "-2" because slug was already taken.
        $api->shouldReceive('batch_categories')
            ->once()
            ->andReturn([
                'create' => [
                    ['id' => 999, 'name' => 'Балони за бебе', 'slug' => 'baloni-za-bebe-2'],
                ],
            ]);

        // Collision lookup: fetch real existing category by original slug.
        $api->shouldReceive('get_categories')
            ->with('', ['slug' => 'baloni-za-bebe', 'per_page' => 1])
            ->once()
            ->andReturn([
                ['id' => 42, 'name' => 'Балони за бебе', 'slug' => 'baloni-za-bebe'],
            ]);

        // Duplicate must be deleted.
        $api->shouldReceive('delete_category')->with(999, true)->once();

        $terms    = [['name' => 'Балони за бебе', 'slug' => 'baloni-za-bebe']];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(1, $result);
        $this->assertEquals(42, $result[0]['id'], 'Should return real existing ID, not the duplicate.');
    }

    /**
     * When the collision-resolution GET finds nothing (perhaps the "-2" slug
     * was legitimately intended), fall through and keep the created ID.
     */
    public function test_slug_collision_falls_through_when_real_category_not_found(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');

        $api->shouldReceive('get_categories')
            ->with('', \Mockery::on(fn($p) => isset($p['page'])))
            ->once()
            ->andReturn([]);

        $api->shouldReceive('batch_categories')
            ->once()
            ->andReturn([
                'create' => [
                    ['id' => 777, 'name' => 'New Tag', 'slug' => 'new-tag-2'],
                ],
            ]);

        // Lookup returns empty — can't determine original.
        $api->shouldReceive('get_categories')
            ->with('', ['slug' => 'new-tag', 'per_page' => 1])
            ->once()
            ->andReturn([]);

        // No deletion should happen when we can't find the original.
        $api->shouldNotReceive('delete_category');

        $terms    = [['name' => 'New Tag', 'slug' => 'new-tag']];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        // Falls through: uses the "-2" ID as best-effort.
        $this->assertCount(1, $result);
        $this->assertEquals(777, $result[0]['id']);
    }

    /**
     * Multiple categories in one batch: only one has a slug collision,
     * the others succeed normally.
     */
    public function test_slug_collision_only_affects_colliding_term(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');

        $api->shouldReceive('get_categories')
            ->with('', \Mockery::on(fn($p) => isset($p['page'])))
            ->once()
            ->andReturn([]);

        // Batch: "shoes" OK, "baloni-za-bebe" collides.
        $api->shouldReceive('batch_categories')
            ->once()
            ->andReturn([
                'create' => [
                    ['id' => 100, 'name' => 'Shoes',          'slug' => 'shoes'],
                    ['id' => 999, 'name' => 'Балони за бебе', 'slug' => 'baloni-za-bebe-2'],
                ],
            ]);

        // Collision resolution for the one colliding term.
        $api->shouldReceive('get_categories')
            ->with('', ['slug' => 'baloni-za-bebe', 'per_page' => 1])
            ->once()
            ->andReturn([
                ['id' => 42, 'name' => 'Балони за бебе', 'slug' => 'baloni-za-bebe'],
            ]);

        $api->shouldReceive('delete_category')->with(999, true)->once();

        $terms = [
            ['name' => 'Shoes',          'slug' => 'shoes'],
            ['name' => 'Балони за бебе', 'slug' => 'baloni-za-bebe'],
        ];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]['id'], 'Non-colliding term keeps its ID.');
        $this->assertEquals(42,  $result[1]['id'], 'Colliding term uses the real existing ID.');
    }

    /**
     * Collision detected in the per-item fallback path (batch failed entirely,
     * individual create_category returns a "-2" slug).
     */
    public function test_slug_collision_detected_in_per_item_fallback(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');

        $api->shouldReceive('get_categories')
            ->with('', \Mockery::on(fn($p) => isset($p['page'])))
            ->once()
            ->andReturn([]);

        // Full batch fails.
        $api->shouldReceive('batch_categories')
            ->once()
            ->andReturn(new \WP_Error('error', 'Batch failed'));

        // Per-item create also returns a "-2" slug (race condition on fallback too).
        $api->shouldReceive('create_category')
            ->once()
            ->andReturn(['id' => 888, 'name' => 'Балони за бебе', 'slug' => 'baloni-za-bebe-2']);

        // Collision resolution.
        $api->shouldReceive('get_categories')
            ->with('', ['slug' => 'baloni-za-bebe', 'per_page' => 1])
            ->once()
            ->andReturn([
                ['id' => 42, 'name' => 'Балони за бебе', 'slug' => 'baloni-za-bebe'],
            ]);

        $api->shouldReceive('delete_category')->with(888, true)->once();

        $terms    = [['name' => 'Балони за бебе', 'slug' => 'baloni-za-bebe']];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(1, $result);
        $this->assertEquals(42, $result[0]['id'], 'Fallback path must also resolve collision to real ID.');
    }

    /**
     * Slug collision works identically for tags (type = 'tags').
     */
    public function test_slug_collision_works_for_tags(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');

        $api->shouldReceive('get_tags')
            ->with('', \Mockery::on(fn($p) => isset($p['page'])))
            ->once()
            ->andReturn([]);

        $api->shouldReceive('batch_tags')
            ->once()
            ->andReturn([
                'create' => [
                    ['id' => 555, 'name' => 'Sale', 'slug' => 'sale-2'],
                ],
            ]);

        $api->shouldReceive('get_tags')
            ->with('', ['slug' => 'sale', 'per_page' => 1])
            ->once()
            ->andReturn([
                ['id' => 11, 'name' => 'Sale', 'slug' => 'sale'],
            ]);

        $api->shouldReceive('delete_tag')->with(555, true)->once();

        $terms    = [['name' => 'Sale', 'slug' => 'sale']];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'tags', $settings);

        $this->assertCount(1, $result);
        $this->assertEquals(11, $result[0]['id']);
    }

    /**
     * No collision when returned slug exactly matches requested slug — normal path.
     * Ensures the collision guard doesn't fire for legitimate new creations.
     */
    public function test_no_collision_when_slugs_match(): void
    {
        $engine = new WC_Multi_Store_Sync_Engine();
        $method = new \ReflectionMethod($engine, 'ensure_terms_exist');

        $api = \Mockery::mock('WC_Multi_Store_API_Client');
        $api->shouldReceive('get_store_url')->andReturn('https://store1.com');

        $api->shouldReceive('get_categories')
            ->with('', \Mockery::on(fn($p) => isset($p['page'])))
            ->once()
            ->andReturn([]);

        $api->shouldReceive('batch_categories')
            ->once()
            ->andReturn([
                'create' => [
                    ['id' => 200, 'name' => 'Нова категория', 'slug' => 'nova-kategoriya'],
                ],
            ]);

        // No collision lookup or deletion should occur.
        $api->shouldNotReceive('delete_category');
        // get_categories should NOT be called with a 'slug' param.
        $api->shouldNotReceive('get_categories')->with('', \Mockery::on(fn($p) => isset($p['slug'])));

        $terms    = [['name' => 'Нова категория', 'slug' => 'nova-kategoriya']];
        $settings = ['category_match_by' => 'slug'];

        $result = $method->invoke($engine, $terms, $api, 'categories', $settings);

        $this->assertCount(1, $result);
        $this->assertEquals(200, $result[0]['id']);
    }

    // ── ensure_term_cache ─────────────────────────────────────────

    public function test_ensure_term_cache_skips_when_both_caches_exist(): void
    {
        Functions\when('get_transient')->justReturn(['some_cached_data']);

        $result = WC_Multi_Store_Sync_Engine::ensure_term_cache(
            'https://store1.com',
            ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test']
        );

        $this->assertFalse($result);
    }
}
