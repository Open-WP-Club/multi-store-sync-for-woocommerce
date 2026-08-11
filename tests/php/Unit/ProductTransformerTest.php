<?php
/**
 * Unit tests for WC_Multi_Store_Product_Transformer
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ProductTransformerTest extends WC_Multi_Store_TestCase
{
    /**
     * @var WC_Multi_Store_Product_Transformer
     */
    private $transformer;

    /**
     * @var array<int, string>
     */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new WC_Multi_Store_Product_Transformer();

        // Needed by WC_Multi_Store_Logger::write() and
        // WC_Multi_Store_Settings::get_active_stores(), which the new
        // over-allocation warning tests below exercise.
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        // Capture everything WC_Multi_Store_Logger delegates to wc_get_logger(),
        // since it no longer buffers log lines itself.
        $this->loggedMessages = [];
        $wcLogger = \Mockery::mock();
        $wcLogger->shouldReceive('log')->andReturnUsing(function ($level, $message, $context = []) {
            $this->loggedMessages[] = $message;
        });
        Functions\when('wc_get_logger')->justReturn($wcLogger);
    }

    /**
     * Test apply_store_rules without pricing rules
     */
    public function test_apply_store_rules_no_pricing_rules(): void
    {
        $product_data = array(
            'regular_price' => '100.00',
            'stock_quantity' => 50,
        );

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);

        $store_config = array();

        $result = $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        $this->assertEquals($product_data, $result);
    }

    /**
     * Test apply_store_rules applies pricing rules
     */
    public function test_apply_store_rules_applies_pricing_rules(): void
    {
        $product_data = array(
            'regular_price' => '100.00',
        );

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);

        $store_config = array(
            'pricing_rules' => array(
                'enabled' => true,
                'type' => 'percentage',
                'percentage' => 10,
            ),
        );

        $result = $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        $this->assertEquals('110.00', $result['regular_price']);
    }

    /**
     * Test apply_store_rules without stock quantity in data
     */
    public function test_apply_store_rules_no_stock_quantity(): void
    {
        $product_data = array(
            'regular_price' => '100.00',
        );

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);

        $store_config = array(
            'stock_allocation_rules' => array(
                'enabled' => true,
                'type' => 'percentage',
                'percentage' => 50,
            ),
        );

        $result = $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        // Stock allocation should be skipped since no stock_quantity in data
        $this->assertEquals($product_data, $result);
    }

    // ── low stock notification ──────────────────────────────────────────────

    public function test_apply_store_rules_triggers_low_stock_notification(): void
    {
        $product_data = ['stock_quantity' => 3];

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);
        $mock_product->shouldReceive('get_id')->andReturn(42);

        $store_config = ['store_url' => 'https://store1.com'];

        Functions\when('get_option')->justReturn(false);

        $triggered_args = null;
        Functions\when('do_action')->alias(function ($hook, ...$args) use (&$triggered_args) {
            if ($hook === 'wc_mss_low_stock_detected') {
                $triggered_args = $args;
            }
        });

        $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        $this->assertSame([42, 'https://store1.com', 3], $triggered_args);
    }

    public function test_apply_store_rules_skips_low_stock_notification_without_store_url(): void
    {
        $product_data = ['stock_quantity' => 3];

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);

        $called = false;
        Functions\when('do_action')->alias(function ($hook) use (&$called) {
            if ($hook === 'wc_mss_low_stock_detected') {
                $called = true;
            }
        });

        $this->transformer->apply_store_rules($product_data, $mock_product, []);

        $this->assertFalse($called);
    }

    // ── over-allocation warning ─────────────────────────────────────────────
    // apply_stock_allocation() itself still computes each store's share
    // independently (unchanged, matches every test above) — these only cover
    // the new maybe_warn_over_allocation() side-effect that surfaces
    // WC_Multi_Store_Stock_Allocator::validate_total_allocation()'s existing
    // detection logic as a log warning when two+ stores' rules add up to more
    // than physical stock.

    private function getLoggerMessages(): array
    {
        return $this->loggedMessages;
    }

    private function mockActiveStoresWithAllocationRules(array $storesRules): void
    {
        Functions\when('get_option')->alias(function ($option, $default = null) use ($storesRules) {
            if ($option === 'wc_multi_store_sync_stores') {
                $stores = [];
                foreach ($storesRules as $url => $rules) {
                    $stores[$url] = ['status' => 'active', 'stock_allocation_rules' => $rules];
                }
                return $stores;
            }
            return $default;
        });
        WC_Multi_Store_Settings::clear_static_cache();
    }

    public function test_apply_store_rules_warns_when_two_stores_over_allocate(): void
    {
        // Both stores configured for 60% of the same 100 units → 120 total,
        // 20 more than physically in stock.
        $rules = ['enabled' => true, 'type' => 'percentage', 'percentage' => 60];
        $this->mockActiveStoresWithAllocationRules([
            'https://store1.com' => $rules,
            'https://store2.com' => $rules,
        ]);

        $product_data = ['stock_quantity' => 100];

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);
        $mock_product->shouldReceive('get_stock_quantity')->andReturn(100);
        $mock_product->shouldReceive('get_sku')->andReturn('SKU-OVER');
        $mock_product->shouldReceive('get_id')->andReturn(42);

        $store_config = ['stock_allocation_rules' => $rules];

        $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        $messages = implode(' ', $this->getLoggerMessages());
        $this->assertStringContainsString('over-committed', $messages);
        $this->assertStringContainsString('SKU-OVER', $messages);
    }

    public function test_apply_store_rules_no_warning_when_allocation_fits(): void
    {
        // 50% + 50% = 100, exactly matches stock — not over-allocated.
        $rules = ['enabled' => true, 'type' => 'percentage', 'percentage' => 50];
        $this->mockActiveStoresWithAllocationRules([
            'https://store1.com' => $rules,
            'https://store2.com' => $rules,
        ]);

        $product_data = ['stock_quantity' => 100];

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);
        $mock_product->shouldReceive('get_stock_quantity')->andReturn(100);
        $mock_product->shouldReceive('get_sku')->andReturn('SKU-FIT');
        $mock_product->shouldReceive('get_id')->andReturn(43);

        $store_config = ['stock_allocation_rules' => $rules];

        $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        $messages = implode(' ', $this->getLoggerMessages());
        $this->assertStringNotContainsString('over-committed', $messages);
    }

    public function test_apply_store_rules_warns_only_once_per_product_across_stores(): void
    {
        // Simulates sync-engine.php's per-store loop calling apply_store_rules()
        // once per active store for the SAME product — must not log N times.
        $rules = ['enabled' => true, 'type' => 'percentage', 'percentage' => 60];
        $this->mockActiveStoresWithAllocationRules([
            'https://store1.com' => $rules,
            'https://store2.com' => $rules,
        ]);

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);
        $mock_product->shouldReceive('get_stock_quantity')->andReturn(100);
        $mock_product->shouldReceive('get_sku')->andReturn('SKU-DUP');
        $mock_product->shouldReceive('get_id')->andReturn(44);

        $store_config = ['stock_allocation_rules' => $rules];

        $this->transformer->apply_store_rules(['stock_quantity' => 100], $mock_product, $store_config);
        $this->transformer->apply_store_rules(['stock_quantity' => 100], $mock_product, $store_config);

        $occurrences = substr_count(implode(' ', $this->getLoggerMessages()), 'over-committed');
        $this->assertSame(1, $occurrences, 'Should warn once per product, not once per store call');
    }

    public function test_apply_store_rules_no_warning_with_only_one_store_configured(): void
    {
        // Over-allocation across stores is impossible with a single store's
        // rules in play, regardless of the percentage.
        $rules = ['enabled' => true, 'type' => 'percentage', 'percentage' => 60];
        $this->mockActiveStoresWithAllocationRules([
            'https://store1.com' => $rules,
        ]);

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);
        $mock_product->shouldReceive('get_stock_quantity')->andReturn(100);
        $mock_product->shouldReceive('get_sku')->andReturn('SKU-SINGLE');
        $mock_product->shouldReceive('get_id')->andReturn(45);

        $store_config = ['stock_allocation_rules' => $rules];

        $this->transformer->apply_store_rules(['stock_quantity' => 100], $mock_product, $store_config);

        $messages = implode(' ', $this->getLoggerMessages());
        $this->assertStringNotContainsString('over-committed', $messages);
    }

    public function test_apply_variation_stock_rules_warns_on_over_allocation(): void
    {
        $rules = ['enabled' => true, 'type' => 'percentage', 'percentage' => 70];
        $this->mockActiveStoresWithAllocationRules([
            'https://store1.com' => $rules,
            'https://store2.com' => $rules,
        ]);

        $mock_variation = \Mockery::mock('WC_Product_Variation');
        $mock_variation->shouldReceive('get_stock_quantity')->andReturn(20);
        $mock_variation->shouldReceive('get_sku')->andReturn('VAR-OVER');
        $mock_variation->shouldReceive('get_id')->andReturn(46);

        $store_config = ['stock_allocation_rules' => $rules];

        $this->transformer->apply_variation_stock_rules(['stock_quantity' => 20], $mock_variation, $store_config);

        $messages = implode(' ', $this->getLoggerMessages());
        $this->assertStringContainsString('over-committed', $messages);
        $this->assertStringContainsString('VAR-OVER', $messages);
    }

    /**
     * Test apply_variation_pricing_rules applies rules
     */
    public function test_apply_variation_pricing_rules(): void
    {
        $variation_data = array(
            'regular_price' => '50.00',
            'sale_price' => '40.00',
        );

        $store_config = array(
            'pricing_rules' => array(
                'enabled' => true,
                'type' => 'fixed',
                'fixed_amount' => 5,
            ),
        );

        $result = $this->transformer->apply_variation_pricing_rules($variation_data, $store_config);

        $this->assertEquals('55.00', $result['regular_price']);
        $this->assertEquals('45.00', $result['sale_price']);
    }

    /**
     * Test apply_variation_pricing_rules without rules
     */
    public function test_apply_variation_pricing_rules_no_rules(): void
    {
        $variation_data = array(
            'regular_price' => '50.00',
        );

        $store_config = array();

        $result = $this->transformer->apply_variation_pricing_rules($variation_data, $store_config);

        $this->assertEquals($variation_data, $result);
    }

    /**
     * Test apply_variation_stock_rules without stock quantity
     */
    public function test_apply_variation_stock_rules_no_stock(): void
    {
        $variation_data = array(
            'regular_price' => '50.00',
        );

        $mock_variation = \Mockery::mock('WC_Product_Variation');

        $store_config = array(
            'stock_allocation_rules' => array(
                'enabled' => true,
                'type' => 'percentage',
                'percentage' => 30,
            ),
        );

        $result = $this->transformer->apply_variation_stock_rules($variation_data, $mock_variation, $store_config);

        $this->assertEquals($variation_data, $result);
    }

    /**
     * Test apply_variation_stock_rules without rules
     */
    public function test_apply_variation_stock_rules_no_rules(): void
    {
        $variation_data = array(
            'stock_quantity' => 100,
        );

        $mock_variation = \Mockery::mock('WC_Product_Variation');

        $store_config = array();

        $result = $this->transformer->apply_variation_stock_rules($variation_data, $mock_variation, $store_config);

        $this->assertEquals($variation_data, $result);
    }

    /**
     * Test pricing rules with percentage type
     */
    public function test_pricing_rules_percentage_type(): void
    {
        $product_data = array(
            'regular_price' => '100.00',
            'sale_price' => '80.00',
        );

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);

        $store_config = array(
            'pricing_rules' => array(
                'enabled' => true,
                'type' => 'percentage',
                'percentage' => 20, // +20%
            ),
        );

        $result = $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        $this->assertEquals('120.00', $result['regular_price']);
        $this->assertEquals('96.00', $result['sale_price']);
    }

    /**
     * Test pricing rules with fixed type
     */
    public function test_pricing_rules_fixed_type(): void
    {
        $product_data = array(
            'regular_price' => '100.00',
        );

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);

        $store_config = array(
            'pricing_rules' => array(
                'enabled' => true,
                'type' => 'fixed',
                'fixed_amount' => 15, // +15
            ),
        );

        $result = $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        $this->assertEquals('115.00', $result['regular_price']);
    }

    /**
     * Test pricing rules with multiplier type
     */
    public function test_pricing_rules_multiplier_type(): void
    {
        $product_data = array(
            'regular_price' => '100.00',
        );

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);

        $store_config = array(
            'pricing_rules' => array(
                'enabled' => true,
                'type' => 'multiplier',
                'multiplier' => 1.5, // x1.5
            ),
        );

        $result = $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        $this->assertEquals('150.00', $result['regular_price']);
    }

    /**
     * Test pricing rules disabled
     */
    public function test_pricing_rules_disabled(): void
    {
        $product_data = array(
            'regular_price' => '100.00',
        );

        $mock_product = \Mockery::mock('WC_Product');
        $mock_product->shouldReceive('get_shipping_class_id')->andReturn(0);

        $store_config = array(
            'pricing_rules' => array(
                'enabled' => false,
                'type' => 'percentage',
                'percentage' => 50,
            ),
        );

        $result = $this->transformer->apply_store_rules($product_data, $mock_product, $store_config);

        // Price should remain unchanged since rules are disabled
        $this->assertEquals('100.00', $result['regular_price']);
    }
}
