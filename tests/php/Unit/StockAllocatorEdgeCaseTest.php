<?php
/**
 * Edge case tests for WC_Multi_Store_Stock_Allocator
 *
 * Covers: over-allocation enforcement, zero stock, conflicting reserve configs,
 * single store equal allocation, zero/negative priorities, boundary conditions.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class StockAllocatorEdgeCaseTest extends WC_Multi_Store_TestCase
{
    // ── Zero stock scenarios ─────────────────────────────────────

    public function test_percentage_allocation_with_zero_stock(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(0, [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 60,
        ]);

        $this->assertEquals(0, $result);
    }

    public function test_fixed_allocation_with_zero_stock(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(0, [
            'enabled' => true,
            'type' => 'fixed',
            'fixed_quantity' => 50,
        ]);

        $this->assertEquals(0, $result);
    }

    public function test_reserve_allocation_with_zero_stock(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(0, [
            'enabled' => true,
            'type' => 'reserve',
            'reserve_quantity' => 10,
        ]);

        $this->assertEquals(0, $result);
    }

    public function test_equal_allocation_with_zero_stock(): void
    {
        $stores = [
            'store1' => ['enabled' => true],
            'store2' => ['enabled' => true],
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(0, [
            'enabled' => true,
            'type' => 'equal',
        ], $stores);

        $this->assertEquals(0, $result);
    }

    public function test_priority_allocation_with_zero_stock(): void
    {
        $stores = [
            'store1' => ['enabled' => true, 'priority' => 8],
            'store2' => ['enabled' => true, 'priority' => 2],
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(0, [
            'enabled' => true,
            'type' => 'priority',
            'priority' => 8,
        ], $stores);

        $this->assertEquals(0, $result);
    }

    // ── Over-allocation detection ────────────────────────────────

    public function test_preview_allocation_detects_over_allocation(): void
    {
        $stores = [
            'store_a' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 70],
            'store_b' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 50],
        ];

        $preview = WC_Multi_Store_Stock_Allocator::preview_allocation(100, $stores);

        $this->assertTrue($preview['_summary']['over_allocated']);
        $this->assertGreaterThan(100, $preview['_summary']['total_allocated']);
    }

    public function test_validate_total_allocation_returns_warning_on_over_allocation(): void
    {
        $stores = [
            'store_a' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 70],
            'store_b' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 50],
        ];

        $validation = WC_Multi_Store_Stock_Allocator::validate_total_allocation(100, $stores);

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['warnings']);
        $this->assertStringContainsString('exceeds', $validation['warnings'][0]);
    }

    public function test_validate_total_allocation_warns_on_unallocated_stock(): void
    {
        $stores = [
            'store_a' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 30],
        ];

        $validation = WC_Multi_Store_Stock_Allocator::validate_total_allocation(100, $stores);

        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('not be allocated', $validation['warnings'][0]);
    }

    public function test_actual_allocation_values_when_over_allocated(): void
    {
        // Store A gets 70%, Store B gets 50% — total 120% of stock
        $alloc_a = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 70,
        ]);
        $alloc_b = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 50,
        ]);

        // Each allocation is calculated independently — no capping
        $this->assertEquals(70, $alloc_a);
        $this->assertEquals(50, $alloc_b);
        $this->assertGreaterThan(100, $alloc_a + $alloc_b);
    }

    // ── Priority edge cases ──────────────────────────────────────

    public function test_priority_allocation_with_all_zero_priorities(): void
    {
        $stores = [
            'store1' => ['enabled' => true, 'priority' => 0],
            'store2' => ['enabled' => true, 'priority' => 0],
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'priority',
            'priority' => 0,
        ], $stores);

        // total_priority <= 0 returns full stock
        $this->assertEquals(100, $result);
    }

    public function test_priority_allocation_single_store(): void
    {
        $stores = [
            'store1' => ['enabled' => true, 'priority' => 5],
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'priority',
            'priority' => 5,
        ], $stores);

        // 5/5 = 100%
        $this->assertEquals(100, $result);
    }

    // ── Reserve allocation conflicts ─────────────────────────────

    public function test_reserve_with_both_percentage_and_fixed_uses_percentage(): void
    {
        // When both reserve_percentage and reserve_quantity are set,
        // percentage takes priority (line 134-138)
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'reserve',
            'reserve_percentage' => 20,
            'reserve_quantity' => 30,
        ]);

        // reserve = ceil(100 * 20/100) = 20, available = 80
        $this->assertEquals(80, $result);
    }

    public function test_reserve_with_zero_percentage_falls_back_to_fixed(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'reserve',
            'reserve_percentage' => 0,
            'reserve_quantity' => 30,
        ]);

        // reserve = 30, available = 70
        $this->assertEquals(70, $result);
    }

    public function test_reserve_with_additional_percentage(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'reserve',
            'reserve_quantity' => 20,
            'reserve_percentage' => 0,
            'percentage' => 50, // Additional percentage on available stock
        ]);

        // reserve=20, available=80, then 50% of 80 = 40
        $this->assertEquals(40, $result);
    }

    public function test_reserve_larger_than_stock_returns_zero(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(10, [
            'enabled' => true,
            'type' => 'reserve',
            'reserve_quantity' => 50,
        ]);

        // reserve=50, available=10-50=-40, max(0, -40) = 0
        $this->assertEquals(0, $result);
    }

    // ── Equal allocation edge cases ──────────────────────────────

    public function test_equal_allocation_single_enabled_store(): void
    {
        $stores = [
            'store1' => ['enabled' => true],
            'store2' => ['enabled' => false],
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'equal',
        ], $stores);

        $this->assertEquals(100, $result);
    }

    public function test_equal_allocation_no_enabled_stores(): void
    {
        $stores = [
            'store1' => ['enabled' => false],
            'store2' => ['enabled' => false],
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'equal',
        ], $stores);

        // active_stores <= 0 returns full stock
        $this->assertEquals(100, $result);
    }

    public function test_equal_allocation_with_odd_stock(): void
    {
        $stores = [
            'store1' => ['enabled' => true],
            'store2' => ['enabled' => true],
            'store3' => ['enabled' => true],
        ];

        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(10, [
            'enabled' => true,
            'type' => 'equal',
        ], $stores);

        // floor(10/3) = 3 per store
        $this->assertEquals(3, $result);
    }

    // ── Percentage boundary conditions ───────────────────────────

    public function test_percentage_allocation_zero_percent(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 0,
        ]);

        $this->assertEquals(0, $result);
    }

    public function test_percentage_allocation_hundred_percent(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 100,
        ]);

        $this->assertEquals(100, $result);
    }

    public function test_percentage_allocation_negative_clamped_to_zero(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => -10,
        ]);

        $this->assertEquals(0, $result);
    }

    public function test_percentage_allocation_over_hundred_clamped(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 150,
        ]);

        // Clamped to 100%
        $this->assertEquals(100, $result);
    }

    // ── Stock allocation application ─────────────────────────────

    public function test_apply_stock_allocation_sets_outofstock_when_zero(): void
    {
        $data = ['stock_quantity' => 100, 'stock_status' => 'instock'];

        $result = WC_Multi_Store_Stock_Allocator::apply_stock_allocation($data, [
            'enabled' => true,
            'type' => 'fixed',
            'fixed_quantity' => 0,
        ], 100);

        $this->assertEquals(0, $result['stock_quantity']);
        $this->assertEquals('outofstock', $result['stock_status']);
    }

    public function test_apply_stock_allocation_preserves_data_without_stock_key(): void
    {
        $data = ['name' => 'Test Product'];

        $result = WC_Multi_Store_Stock_Allocator::apply_stock_allocation($data, [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 50,
        ], 100);

        $this->assertEquals($data, $result);
    }

    // ── Validation edge cases ────────────────────────────────────

    public function test_validate_rules_with_non_array_returns_defaults(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::validate_rules('invalid');
        $this->assertEquals(WC_Multi_Store_Stock_Allocator::get_default_rules(), $result);
    }

    public function test_validate_rules_clamps_invalid_values(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::validate_rules([
            'percentage' => 200,
            'fixed_quantity' => -10,
            'priority' => 50,
            'reserve_percentage' => -5,
        ]);

        $this->assertEquals(100, $result['percentage']);
        $this->assertEquals(0, $result['fixed_quantity']);
        $this->assertEquals(10, $result['priority']);
        $this->assertEquals(0, $result['reserve_percentage']);
    }

    public function test_validate_rules_rejects_invalid_type(): void
    {
        $result = WC_Multi_Store_Stock_Allocator::validate_rules([
            'type' => 'invalid_type',
        ]);

        $this->assertEquals('none', $result['type']);
    }

    // ── Preview with zero stock ──────────────────────────────────

    public function test_preview_allocation_zero_stock_shows_zero_percentage(): void
    {
        $stores = [
            'store_a' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 60],
        ];

        $preview = WC_Multi_Store_Stock_Allocator::preview_allocation(0, $stores);

        $this->assertEquals(0, $preview['store_a']['percentage']);
        $this->assertEquals(0, $preview['_summary']['total_allocated']);
        $this->assertFalse($preview['_summary']['over_allocated']);
    }
}
