<?php
/**
 * Unit tests for WC_Multi_Store_Stock_Allocator
 */

class StockAllocatorTest extends WC_Multi_Store_TestCase
{
    /**
     * Test that disabled rules return original stock
     */
    public function test_disabled_rules_return_original_stock(): void
    {
        $allocation_rules = [
            'enabled' => false,
            'type' => 'percentage',
            'percentage' => 50,
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $allocation_rules);

        $this->assertEquals(100, $result);
    }

    /**
     * Test that empty rules return original stock
     */
    public function test_empty_rules_return_original_stock(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, []);

        $this->assertEquals(100, $result);
    }

    /**
     * Test percentage allocation
     */
    public function test_percentage_allocation(): void
    {
        $allocation_rules = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 60,
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $allocation_rules);

        $this->assertEquals(60, $result);
    }

    /**
     * Test percentage allocation with decimal result floors down
     */
    public function test_percentage_allocation_floors_result(): void
    {
        $allocation_rules = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 33,
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $allocation_rules);

        $this->assertEquals(33, $result);
    }

    /**
     * Test percentage allocation clamps to 0-100
     */
    public function test_percentage_allocation_clamps_values(): void
    {
        $rules_over = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 150, // Over 100%
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $rules_over);
        $this->assertEquals(100, $result);

        $rules_negative = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => -10, // Negative
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $rules_negative);
        $this->assertEquals(0, $result);
    }

    /**
     * Test fixed quantity allocation
     */
    public function test_fixed_allocation(): void
    {
        $allocation_rules = [
            'enabled' => true,
            'type' => 'fixed',
            'fixed_quantity' => 50,
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $allocation_rules);

        $this->assertEquals(50, $result);
    }

    /**
     * Test fixed allocation does not exceed total stock
     */
    public function test_fixed_allocation_capped_by_stock(): void
    {
        $allocation_rules = [
            'enabled' => true,
            'type' => 'fixed',
            'fixed_quantity' => 200, // More than available
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $allocation_rules);

        $this->assertEquals(100, $result);
    }

    /**
     * Test reserve allocation with fixed quantity
     */
    public function test_reserve_allocation_fixed(): void
    {
        $allocation_rules = [
            'enabled' => true,
            'type' => 'reserve',
            'reserve_quantity' => 20, // Reserve 20 for main store
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $allocation_rules);

        $this->assertEquals(80, $result); // 100 - 20 reserved
    }

    /**
     * Test reserve allocation with percentage
     */
    public function test_reserve_allocation_percentage(): void
    {
        $allocation_rules = [
            'enabled' => true,
            'type' => 'reserve',
            'reserve_percentage' => 30, // Reserve 30% for main store
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $allocation_rules);

        $this->assertEquals(70, $result); // 100 - 30 reserved
    }

    /**
     * Test equal allocation across stores
     */
    public function test_equal_allocation(): void
    {
        $all_stores_rules = [
            'store1.com' => ['enabled' => true, 'type' => 'equal'],
            'store2.com' => ['enabled' => true, 'type' => 'equal'],
            'store3.com' => ['enabled' => true, 'type' => 'equal'],
        ];

        $allocation_rules = [
            'enabled' => true,
            'type' => 'equal',
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(90, $allocation_rules, $all_stores_rules);

        $this->assertEquals(30, $result); // 90 / 3 stores
    }

    /**
     * Test priority allocation
     */
    public function test_priority_allocation(): void
    {
        $all_stores_rules = [
            'store1.com' => ['enabled' => true, 'type' => 'priority', 'priority' => 7],
            'store2.com' => ['enabled' => true, 'type' => 'priority', 'priority' => 3],
        ];

        // Test store1 with priority 7 out of total 10
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(
            100,
            $all_stores_rules['store1.com'],
            $all_stores_rules
        );

        $this->assertEquals(70, $result); // 7/10 * 100 = 70
    }

    /**
     * Test 'none' type returns full stock
     */
    public function test_none_type_returns_full_stock(): void
    {
        $allocation_rules = [
            'enabled' => true,
            'type' => 'none',
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $allocation_rules);

        $this->assertEquals(100, $result);
    }

    /**
     * Test apply_stock_allocation updates product data
     */
    public function test_apply_stock_allocation(): void
    {
        $product_data = [
            'stock_quantity' => 100,
            'stock_status' => 'instock',
        ];

        $allocation_rules = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 40,
        ];

        $result = WC_Multi_Store_Stock_Allocator::apply_stock_allocation(
            $product_data,
            $allocation_rules,
            100
        );

        $this->assertEquals(40, $result['stock_quantity']);
        $this->assertEquals('instock', $result['stock_status']);
    }

    /**
     * Test apply_stock_allocation sets outofstock when 0
     */
    public function test_apply_stock_allocation_zero_is_outofstock(): void
    {
        $product_data = [
            'stock_quantity' => 100,
            'stock_status' => 'instock',
        ];

        $allocation_rules = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 0,
        ];

        $result = WC_Multi_Store_Stock_Allocator::apply_stock_allocation(
            $product_data,
            $allocation_rules,
            100
        );

        $this->assertEquals(0, $result['stock_quantity']);
        $this->assertEquals('outofstock', $result['stock_status']);
    }

    /**
     * Test get_default_rules structure
     */
    public function test_get_default_rules(): void
    {
        $defaults = WC_Multi_Store_Stock_Allocator::get_default_rules();

        $this->assertArrayHasKey('enabled', $defaults);
        $this->assertArrayHasKey('type', $defaults);
        $this->assertArrayHasKey('percentage', $defaults);
        $this->assertArrayHasKey('fixed_quantity', $defaults);
        $this->assertArrayHasKey('priority', $defaults);
        $this->assertArrayHasKey('reserve_quantity', $defaults);

        $this->assertFalse($defaults['enabled']);
        $this->assertEquals('none', $defaults['type']);
        $this->assertEquals(100, $defaults['percentage']);
    }

    /**
     * Test validate_rules with invalid type
     */
    public function test_validate_rules_invalid_type(): void
    {
        $rules = [
            'enabled' => true,
            'type' => 'invalid_type',
        ];

        $validated = WC_Multi_Store_Stock_Allocator::validate_rules($rules);

        $this->assertEquals('none', $validated['type']);
    }

    /**
     * Test validate_rules clamps percentage
     */
    public function test_validate_rules_clamps_percentage(): void
    {
        $rules = [
            'enabled' => true,
            'percentage' => 150,
        ];

        $validated = WC_Multi_Store_Stock_Allocator::validate_rules($rules);

        $this->assertEquals(100, $validated['percentage']);
    }

    /**
     * Test validate_rules clamps priority 1-10
     */
    public function test_validate_rules_clamps_priority(): void
    {
        $rules_high = ['priority' => 15];
        $validated = WC_Multi_Store_Stock_Allocator::validate_rules($rules_high);
        $this->assertEquals(10, $validated['priority']);

        $rules_low = ['priority' => 0];
        $validated = WC_Multi_Store_Stock_Allocator::validate_rules($rules_low);
        $this->assertEquals(1, $validated['priority']);
    }

    /**
     * Test preview_allocation returns correct structure
     */
    public function test_preview_allocation(): void
    {
        $all_stores = [
            'store1.com' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 60],
            'store2.com' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 40],
        ];

        $preview = WC_Multi_Store_Stock_Allocator::preview_allocation(100, $all_stores);

        $this->assertEquals(60, $preview['store1.com']['allocated']);
        $this->assertEquals(40, $preview['store2.com']['allocated']);
        $this->assertArrayHasKey('_summary', $preview);
        $this->assertEquals(100, $preview['_summary']['total_stock']);
        $this->assertEquals(100, $preview['_summary']['total_allocated']);
    }

    /**
     * Test validate_total_allocation detects over-allocation
     */
    public function test_validate_total_allocation_over_allocated(): void
    {
        $all_stores = [
            'store1.com' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 70],
            'store2.com' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 50],
        ];

        $validation = WC_Multi_Store_Stock_Allocator::validate_total_allocation(100, $all_stores);

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['warnings']);
    }
}
