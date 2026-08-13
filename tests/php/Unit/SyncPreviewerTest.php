<?php
/**
 * Unit tests for WC_Multi_Store_Sync_Previewer
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SyncPreviewerTest extends WC_Multi_Store_TestCase
{
    private ?WC_Multi_Store_Sync_Previewer $previewer = null;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);

        $this->previewer = new WC_Multi_Store_Sync_Previewer();
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Sync_Previewer'));
    }

    public function test_has_required_methods(): void
    {
        $methods = [
            'preview_product_sync',
            'format_preview_html',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists('WC_Multi_Store_Sync_Previewer', $method),
                "Missing method: {$method}"
            );
        }
    }

    // ─── Constructor ───────────────────────────────

    public function test_constructor_accepts_null_api_client(): void
    {
        $previewer = new WC_Multi_Store_Sync_Previewer(null);

        $this->assertInstanceOf(WC_Multi_Store_Sync_Previewer::class, $previewer);
    }

    // ─── preview_product_sync ──────────────────────

    public function test_preview_product_sync_returns_error_for_invalid_product(): void
    {
        Functions\when('wc_get_product')->justReturn(null);

        $result = $this->previewer->preview_product_sync(9999, []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    public function test_preview_product_sync_skips_inactive_stores(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Test Product');
        $product->shouldReceive('get_sku')->andReturn('TEST-001');

        Functions\when('wc_get_product')->justReturn($product);

        $stores = [
            'https://store.com' => [
                'status' => 'inactive',
                'name' => 'Inactive Store',
            ],
        ];

        $result = $this->previewer->preview_product_sync(1, $stores);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['previews']);
    }

    // ─── compare_data (private) ────────────────────

    public function test_compare_data_new_product(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('compare_data');

        $after = [
            'name' => 'Test Product',
            'regular_price' => '29.99',
            'stock_quantity' => 10,
        ];

        $changes = $method->invoke($this->previewer, null, $after, 'full_product');

        $this->assertCount(3, $changes);
        $this->assertEquals('new', $changes['name']['type']);
        $this->assertNull($changes['name']['before']);
        $this->assertEquals('Test Product', $changes['name']['after']);
    }

    public function test_compare_data_existing_product_with_changes(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('compare_data');

        $before = [
            'name' => 'Old Name',
            'regular_price' => '19.99',
            'stock_quantity' => 5,
        ];

        $after = [
            'name' => 'New Name',
            'regular_price' => '29.99',
            'stock_quantity' => 5,
        ];

        $changes = $method->invoke($this->previewer, $before, $after, 'full_product');

        $this->assertCount(2, $changes); // name and price changed, stock same
        $this->assertArrayHasKey('name', $changes);
        $this->assertArrayHasKey('regular_price', $changes);
        $this->assertArrayNotHasKey('stock_quantity', $changes);
        $this->assertEquals('modified', $changes['name']['type']);
    }

    public function test_compare_data_no_changes(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('compare_data');

        $data = [
            'name' => 'Same Product',
            'regular_price' => '29.99',
        ];

        $changes = $method->invoke($this->previewer, $data, $data, 'full_product');

        $this->assertEmpty($changes);
    }

    public function test_compare_data_normalizes_numeric_values(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('compare_data');

        $before = ['regular_price' => '29.99'];
        $after = ['regular_price' => 29.99];

        $changes = $method->invoke($this->previewer, $before, $after, 'full_product');

        // Should be no changes after normalization
        $this->assertEmpty($changes);
    }

    // ─── normalize_value (private) ─────────────────

    public function test_normalize_value_numeric_string(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('normalize_value');

        $this->assertEquals(29.99, $method->invoke($this->previewer, '29.99'));
    }

    public function test_normalize_value_float(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('normalize_value');

        $this->assertEquals(29.99, $method->invoke($this->previewer, 29.99));
    }

    public function test_normalize_value_string_trims(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('normalize_value');

        $this->assertEquals('hello', $method->invoke($this->previewer, '  hello  '));
    }

    public function test_normalize_value_null_passthrough(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('normalize_value');

        $this->assertNull($method->invoke($this->previewer, null));
    }

    public function test_normalize_value_bool_passthrough(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('normalize_value');

        $this->assertTrue($method->invoke($this->previewer, true));
        $this->assertFalse($method->invoke($this->previewer, false));
    }

    // ─── detect_conflicts (private) ────────────────

    public function test_detect_conflicts_no_remote_product(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('detect_conflicts');

        $conflicts = $method->invoke($this->previewer, null, ['stock_quantity' => 10]);

        $this->assertEmpty($conflicts);
    }

    public function test_detect_conflicts_stock_decrease_warning(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('detect_conflicts');

        $remote = ['stock_quantity' => 20];
        $sync_data = ['stock_quantity' => 5];

        $conflicts = $method->invoke($this->previewer, $remote, $sync_data);

        $this->assertCount(1, $conflicts);
        $this->assertEquals('stock_quantity', $conflicts[0]['field']);
        $this->assertEquals('warning', $conflicts[0]['type']);
    }

    public function test_detect_conflicts_no_stock_warning_when_increasing(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('detect_conflicts');

        $remote = ['stock_quantity' => 5];
        $sync_data = ['stock_quantity' => 20];

        $conflicts = $method->invoke($this->previewer, $remote, $sync_data);

        // Stock increase doesn't trigger warning
        $stock_conflicts = array_filter($conflicts, fn($c) => $c['field'] === 'stock_quantity');
        $this->assertEmpty($stock_conflicts);
    }

    public function test_detect_conflicts_significant_price_change(): void
    {
        Functions\when('wc_price')->alias(fn($price) => '$' . number_format((float)$price, 2));

        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('detect_conflicts');

        $remote = ['regular_price' => 100.00];
        $sync_data = ['regular_price' => 50.00]; // 50% change

        $conflicts = $method->invoke($this->previewer, $remote, $sync_data);

        $this->assertNotEmpty($conflicts);
        $price_conflicts = array_filter($conflicts, fn($c) => $c['field'] === 'regular_price');
        $this->assertNotEmpty($price_conflicts);
    }

    public function test_detect_conflicts_no_warning_for_small_price_change(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('detect_conflicts');

        $remote = ['regular_price' => 100.00];
        $sync_data = ['regular_price' => 95.00]; // 5% change (under 20% threshold)

        $conflicts = $method->invoke($this->previewer, $remote, $sync_data);

        $price_conflicts = array_filter($conflicts, fn($c) => $c['field'] === 'regular_price');
        $this->assertEmpty($price_conflicts);
    }

    // ─── build_sync_data (private) ─────────────────

    public function test_build_sync_data_full_product(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('build_sync_data');

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_name')->andReturn('Test Product');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_sku')->andReturn('TEST-001');
        // Empty prices to avoid triggering PricingRules class calls
        $product->shouldReceive('get_regular_price')->andReturn('');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_description')->andReturn('Description');
        $product->shouldReceive('get_short_description')->andReturn('Short');
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        // Null stock to avoid triggering StockAllocator class calls
        $product->shouldReceive('get_stock_quantity')->andReturn(null);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_weight')->andReturn('1.5');
        $product->shouldReceive('get_length')->andReturn('10');
        $product->shouldReceive('get_width')->andReturn('5');
        $product->shouldReceive('get_height')->andReturn('3');

        $result = $method->invoke($this->previewer, $product, 'full_product', []);

        $this->assertEquals('Test Product', $result['name']);
        $this->assertEquals('simple', $result['type']);
        $this->assertEquals('TEST-001', $result['sku']);
        $this->assertEquals(true, $result['manage_stock']);
        $this->assertEquals('Description', $result['description']);
        $this->assertEquals('1.5', $result['weight']);
    }

    public function test_build_sync_data_quantity_only(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('build_sync_data');

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('get_stock_quantity')->andReturn(null);
        $product->shouldReceive('get_stock_status')->andReturn('instock');

        $result = $method->invoke($this->previewer, $product, 'quantity', []);

        $this->assertArrayHasKey('stock_quantity', $result);
        $this->assertArrayHasKey('stock_status', $result);
        $this->assertArrayNotHasKey('name', $result);
        $this->assertArrayNotHasKey('regular_price', $result);
    }

    public function test_build_sync_data_price_quantity(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('build_sync_data');

        $product = \Mockery::mock('WC_Product');
        // Empty prices to avoid triggering PricingRules
        $product->shouldReceive('get_regular_price')->andReturn('');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(null);
        $product->shouldReceive('get_stock_status')->andReturn('instock');

        $result = $method->invoke($this->previewer, $product, 'price_quantity', []);

        $this->assertArrayHasKey('regular_price', $result);
        $this->assertArrayHasKey('sale_price', $result);
        $this->assertArrayHasKey('stock_quantity', $result);
        $this->assertArrayNotHasKey('name', $result);
        $this->assertArrayNotHasKey('description', $result);
    }

    public function test_build_sync_data_unknown_type_returns_empty(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('build_sync_data');

        $product = \Mockery::mock('WC_Product');

        $result = $method->invoke($this->previewer, $product, 'invalid_type', []);

        $this->assertEmpty($result);
    }

    // ─── format_preview_html ───────────────────────

    public function test_format_preview_html_error(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $preview = [
            'success' => false,
            'message' => 'Connection failed',
        ];

        $html = $this->previewer->format_preview_html($preview);

        $this->assertStringContainsString('wc-mss-preview-error', $html);
        $this->assertStringContainsString('Connection failed', $html);
    }

    public function test_format_preview_html_no_changes(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $preview = [
            'success' => true,
            'store_name' => 'Test Store',
            'action' => 'update',
            'conflicts' => [],
            'changes' => [],
        ];

        $html = $this->previewer->format_preview_html($preview);

        $this->assertStringContainsString('wc-mss-preview', $html);
        $this->assertStringContainsString('Test Store', $html);
        $this->assertStringContainsString('wc-mss-no-changes', $html);
    }

    public function test_format_preview_html_with_changes(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $preview = [
            'success' => true,
            'store_name' => 'Test Store',
            'action' => 'update',
            'conflicts' => [],
            'changes' => [
                'name' => ['before' => 'Old Name', 'after' => 'New Name', 'type' => 'modified'],
            ],
        ];

        $html = $this->previewer->format_preview_html($preview);

        $this->assertStringContainsString('wc-mss-preview-table', $html);
        $this->assertStringContainsString('Old Name', $html);
        $this->assertStringContainsString('New Name', $html);
    }

    public function test_format_preview_html_create_action(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $preview = [
            'success' => true,
            'store_name' => 'Test Store',
            'action' => 'create',
            'conflicts' => [],
            'changes' => [],
        ];

        $html = $this->previewer->format_preview_html($preview);

        $this->assertStringContainsString('Create new product', $html);
    }

    public function test_format_preview_html_with_conflicts(): void
    {
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        $preview = [
            'success' => true,
            'store_name' => 'Test Store',
            'action' => 'update',
            'conflicts' => [
                [
                    'field' => 'stock_quantity',
                    'type' => 'warning',
                    'message' => 'Remote stock is higher',
                ],
            ],
            'changes' => [],
        ];

        $html = $this->previewer->format_preview_html($preview);

        $this->assertStringContainsString('wc-mss-preview-conflicts', $html);
        $this->assertStringContainsString('Remote stock is higher', $html);
    }

    // ─── format_field_name (private) ───────────────

    public function test_format_field_name_known_fields(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('format_field_name');

        $this->assertEquals('Name', $method->invoke($this->previewer, 'name'));
        $this->assertEquals('Regular Price', $method->invoke($this->previewer, 'regular_price'));
        $this->assertEquals('Sale Price', $method->invoke($this->previewer, 'sale_price'));
        $this->assertEquals('Stock Quantity', $method->invoke($this->previewer, 'stock_quantity'));
        $this->assertEquals('SKU', $method->invoke($this->previewer, 'sku'));
    }

    public function test_format_field_name_unknown_field_converts_underscores(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('format_field_name');

        $this->assertEquals('Custom Field Name', $method->invoke($this->previewer, 'custom_field_name'));
    }

    // ─── format_field_value (private) ──────────────

    public function test_format_field_value_null_returns_empty(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('format_field_value');

        $this->assertEquals('(empty)', $method->invoke($this->previewer, 'name', null));
        $this->assertEquals('(empty)', $method->invoke($this->previewer, 'name', ''));
    }

    public function test_format_field_value_price_formats_with_wc_price(): void
    {
        Functions\when('wc_price')->alias(fn($price) => '$' . number_format((float)$price, 2));

        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('format_field_value');

        $result = $method->invoke($this->previewer, 'regular_price', 29.99);

        $this->assertEquals('$29.99', $result);
    }

    public function test_format_field_value_bool(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('format_field_value');

        $this->assertEquals('Yes', $method->invoke($this->previewer, 'manage_stock', true));
        $this->assertEquals('No', $method->invoke($this->previewer, 'manage_stock', false));
    }

    public function test_format_field_value_array(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('format_field_value');

        $result = $method->invoke($this->previewer, 'tags', ['tag1', 'tag2', 'tag3']);

        $this->assertEquals('tag1, tag2, tag3', $result);
    }

    public function test_format_field_value_truncates_long_text(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('format_field_value');

        $long_text = str_repeat('x', 200);
        $result = $method->invoke($this->previewer, 'description', $long_text);

        $this->assertEquals(103, strlen($result)); // 100 chars + '...'
    }

    public function test_format_field_value_short_text_not_truncated(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('format_field_value');

        $result = $method->invoke($this->previewer, 'name', 'Short text');

        $this->assertEquals('Short text', $result);
    }

    // ─── apply_pricing_rules (private) ─────────────

    public function test_apply_pricing_rules_returns_original_when_empty(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('apply_pricing_rules');

        $this->assertEquals('', $method->invoke($this->previewer, '', []));
        $this->assertEquals(0, $method->invoke($this->previewer, 0, []));
    }

    public function test_apply_pricing_rules_applies_percentage_rule_via_real_pricing_rules_class(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('apply_pricing_rules');

        $store_config = [
            'pricing_rules' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 10],
        ];

        $result = $method->invoke($this->previewer, 100.0, $store_config);

        $this->assertEquals('110.00', $result);
    }

    // ─── apply_stock_allocation (private) ──────────

    public function test_apply_stock_allocation_returns_original_when_empty(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('apply_stock_allocation');

        $this->assertNull($method->invoke($this->previewer, null, []));
        $this->assertEquals(0, $method->invoke($this->previewer, 0, []));
    }

    public function test_apply_stock_allocation_applies_percentage_rule_via_real_stock_allocator_class(): void
    {
        $ref = new ReflectionClass($this->previewer);
        $method = $ref->getMethod('apply_stock_allocation');

        $store_config = [
            'stock_allocation_rules' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 40],
        ];

        $result = $method->invoke($this->previewer, 100, $store_config);

        $this->assertSame(40, $result);
    }
}
