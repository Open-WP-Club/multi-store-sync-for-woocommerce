<?php
/**
 * Per-strategy allocation tests for WC_Multi_Store_Stock_Allocator.
 *
 * Calls the private calculate_*_allocation methods directly via reflection
 * and also exercises them through the public calculate_allocation() entry.
 */

use PHPUnit\Framework\Attributes\DataProvider;

class StockAllocatorAllocationsTest extends WC_Multi_Store_TestCase
{
    /**
     * Invoke a private static allocator method.
     *
     * @param string $method Method name.
     * @param mixed  ...$args Arguments.
     * @return mixed
     */
    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(WC_Multi_Store_Stock_Allocator::class, $method);
        return $ref->invoke(null, ...$args);
    }

    // ── percentage ───────────────────────────────────────────────

    public static function percentageProvider(): array
    {
        return [
            'half of even'          => [100, 50, 50],
            'floors fraction'       => [10, 33, 3],
            'floors 99.9 to 99'     => [999, 10, 99],
            'one unit at 1 pct'     => [100, 1, 1],
            'below one unit is 0'   => [99, 1, 0],
            'fractional percentage' => [200, 12.5, 25],
            'zero percent'          => [100, 0, 0],
            'full'                  => [77, 100, 77],
            'negative clamped'      => [100, -20, 0],
            'over 100 clamped'      => [100, 250, 100],
            'numeric string'        => [100, '25', 25],
            'negative stock is 0'   => [-10, 50, 0],
        ];
    }

    #[DataProvider('percentageProvider')]
    public function test_percentage_allocation(int $stock, int|float|string $pct, int $expected): void
    {
        $this->assertSame($expected, $this->call('calculate_percentage_allocation', $stock, ['percentage' => $pct]));
    }

    public function test_percentage_missing_config_defaults_to_full_stock(): void
    {
        $this->assertSame(42, $this->call('calculate_percentage_allocation', 42, []));
    }

    public function test_percentage_non_numeric_value_becomes_zero(): void
    {
        $this->assertSame(0, $this->call('calculate_percentage_allocation', 100, ['percentage' => 'abc']));
    }

    public function test_two_stores_percentages_over_100_total_over_allocate(): void
    {
        $rules = [
            'a' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 70],
            'b' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 60],
        ];
        $preview = WC_Multi_Store_Stock_Allocator::preview_allocation(100, $rules);

        $this->assertSame(70, $preview['a']['allocated']);
        $this->assertSame(60, $preview['b']['allocated']);
        $this->assertSame(130, $preview['_summary']['total_allocated']);
        $this->assertSame(-30, $preview['_summary']['unallocated']);
        $this->assertTrue($preview['_summary']['over_allocated']);
    }

    public function test_percentage_split_that_rounds_down_leaves_unallocated_units(): void
    {
        $rules = [
            'a' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 33],
            'b' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 33],
            'c' => ['enabled' => true, 'type' => 'percentage', 'percentage' => 34],
        ];
        $preview = WC_Multi_Store_Stock_Allocator::preview_allocation(10, $rules);

        $this->assertSame(3, $preview['a']['allocated']);
        $this->assertSame(3, $preview['b']['allocated']);
        $this->assertSame(3, $preview['c']['allocated']);
        $this->assertSame(1, $preview['_summary']['unallocated']);
        $this->assertFalse($preview['_summary']['over_allocated']);
    }

    // ── fixed ────────────────────────────────────────────────────

    public static function fixedProvider(): array
    {
        return [
            'less than stock'        => [100, 30, 30],
            'equal to stock'         => [30, 30, 30],
            'capped at stock'        => [20, 50, 20],
            'zero fixed'             => [100, 0, 0],
            'negative fixed is zero' => [100, -5, 0],
            'zero stock'             => [0, 10, 0],
            'negative stock is zero' => [-10, 5, 0],
            'string fixed'           => [100, '12', 12],
            'float truncated'        => [100, 7.9, 7],
        ];
    }

    #[DataProvider('fixedProvider')]
    public function test_fixed_allocation(int $stock, int|float|string $qty, int $expected): void
    {
        $this->assertSame($expected, $this->call('calculate_fixed_allocation', $stock, ['fixed_quantity' => $qty]));
    }

    public function test_fixed_missing_config_allocates_nothing(): void
    {
        $this->assertSame(0, $this->call('calculate_fixed_allocation', 100, []));
    }

    public function test_fixed_stores_summing_beyond_stock_each_capped_individually(): void
    {
        $rules = [
            'a' => ['enabled' => true, 'type' => 'fixed', 'fixed_quantity' => 80],
            'b' => ['enabled' => true, 'type' => 'fixed', 'fixed_quantity' => 80],
        ];
        $preview = WC_Multi_Store_Stock_Allocator::preview_allocation(100, $rules);

        $this->assertSame(80, $preview['a']['allocated']);
        $this->assertSame(80, $preview['b']['allocated']);
        $this->assertTrue($preview['_summary']['over_allocated']);
    }

    // ── priority ─────────────────────────────────────────────────

    public function test_priority_weighted_share(): void
    {
        $all = [
            'a' => ['enabled' => true, 'priority' => 6],
            'b' => ['enabled' => true, 'priority' => 3],
            'c' => ['enabled' => true, 'priority' => 1],
        ];
        $this->assertSame(60, $this->call('calculate_priority_allocation', 100, $all['a'], $all));
        $this->assertSame(30, $this->call('calculate_priority_allocation', 100, $all['b'], $all));
        $this->assertSame(10, $this->call('calculate_priority_allocation', 100, $all['c'], $all));
    }

    public function test_priority_floors_each_share(): void
    {
        $all = [
            'a' => ['enabled' => true, 'priority' => 1],
            'b' => ['enabled' => true, 'priority' => 1],
            'c' => ['enabled' => true, 'priority' => 1],
        ];
        $this->assertSame(3, $this->call('calculate_priority_allocation', 10, $all['a'], $all));
    }

    public function test_priority_disabled_stores_excluded_from_total(): void
    {
        $all = [
            'a' => ['enabled' => true, 'priority' => 5],
            'b' => ['enabled' => false, 'priority' => 5],
            'c' => ['enabled' => true, 'priority' => 5],
        ];
        $this->assertSame(50, $this->call('calculate_priority_allocation', 100, $all['a'], $all));
    }

    public function test_priority_stores_without_priority_key_are_ignored(): void
    {
        $all = [
            'a' => ['enabled' => true, 'priority' => 2],
            'b' => ['enabled' => true],
        ];
        $this->assertSame(100, $this->call('calculate_priority_allocation', 100, $all['a'], $all));
    }

    public function test_priority_defaults_to_five_when_current_store_has_none(): void
    {
        $all = [
            'a' => ['enabled' => true, 'priority' => 5],
            'b' => ['enabled' => true, 'priority' => 5],
        ];
        $this->assertSame(50, $this->call('calculate_priority_allocation', 100, ['enabled' => true], $all));
    }

    public function test_priority_no_stores_returns_full_stock(): void
    {
        $this->assertSame(80, $this->call('calculate_priority_allocation', 80, ['priority' => 3], []));
    }

    public function test_priority_zero_total_returns_full_stock(): void
    {
        $all = ['a' => ['enabled' => true, 'priority' => 0]];
        $this->assertSame(80, $this->call('calculate_priority_allocation', 80, $all['a'], $all));
    }

    public function test_priority_zero_stock_yields_zero(): void
    {
        $all = ['a' => ['enabled' => true, 'priority' => 5]];
        $this->assertSame(0, $this->call('calculate_priority_allocation', 0, $all['a'], $all));
    }

    // ── reserve ──────────────────────────────────────────────────

    public static function reserveProvider(): array
    {
        return [
            'fixed reserve'                   => [100, ['reserve_quantity' => 30], 70],
            'no reserve configured'           => [100, [], 100],
            'reserve equals stock'            => [50, ['reserve_quantity' => 50], 0],
            'reserve exceeds stock'           => [50, ['reserve_quantity' => 80], 0],
            'percentage reserve exact'        => [100, ['reserve_percentage' => 20], 80],
            'percentage reserve ceils'        => [25, ['reserve_percentage' => 10], 22],
            'tiny percentage reserves 1 unit' => [100, ['reserve_percentage' => 0.1], 99],
            'full percentage reserve'         => [100, ['reserve_percentage' => 100], 0],
            'percentage beats fixed'          => [100, ['reserve_percentage' => 10, 'reserve_quantity' => 50], 90],
            'zero pct falls back to fixed'    => [100, ['reserve_percentage' => 0, 'reserve_quantity' => 25], 75],
            'extra percentage after reserve'  => [100, ['reserve_quantity' => 20, 'percentage' => 50], 40],
            'extra percentage floors'         => [100, ['reserve_quantity' => 1, 'percentage' => 50], 49],
            'zero extra percentage ignored'   => [100, ['reserve_quantity' => 20, 'percentage' => 0], 80],
            'negative extra pct ignored'      => [100, ['reserve_quantity' => 20, 'percentage' => -10], 80],
            'zero stock'                      => [0, ['reserve_quantity' => 5], 0],
            'negative stock'                  => [-10, ['reserve_quantity' => 0], 0],
            'negative reserve is ignored'      => [100, ['reserve_quantity' => -10], 100],
            'extra pct capped at 100'         => [100, ['reserve_quantity' => 0, 'percentage' => 200], 100],
            'negative stock percentage'       => [-10, ['reserve_percentage' => 0, 'percentage' => 50], 0],
        ];
    }

    #[DataProvider('reserveProvider')]
    public function test_reserve_allocation(int $stock, array $rules, int $expected): void
    {
        $this->assertSame($expected, $this->call('calculate_reserve_allocation', $stock, $rules));
    }

    // ── equal ────────────────────────────────────────────────────

    public static function equalProvider(): array
    {
        return [
            'two stores'            => [100, 2, 50],
            'three stores floors'   => [100, 3, 33],
            'more stores than units' => [2, 5, 0],
            'single store'          => [9, 1, 9],
            'zero stock'            => [0, 3, 0],
            'negative stock is 0'   => [-10, 2, 0],
        ];
    }

    #[DataProvider('equalProvider')]
    public function test_equal_allocation(int $stock, int $enabledStores, int $expected): void
    {
        $all = [];
        for ($i = 0; $i < $enabledStores; $i++) {
            $all["store$i"] = ['enabled' => true];
        }
        $this->assertSame($expected, $this->call('calculate_equal_allocation', $stock, $all));
    }

    public function test_equal_allocation_ignores_disabled_stores(): void
    {
        $all = [
            'a' => ['enabled' => true],
            'b' => ['enabled' => false],
            'c' => [],
            'd' => ['enabled' => true],
        ];
        $this->assertSame(50, $this->call('calculate_equal_allocation', 100, $all));
    }

    public function test_equal_allocation_without_store_list_returns_full_stock(): void
    {
        $this->assertSame(64, $this->call('calculate_equal_allocation', 64, []));
    }

    public function test_equal_split_total_never_exceeds_stock(): void
    {
        $all = ['a' => ['enabled' => true, 'type' => 'equal'], 'b' => ['enabled' => true, 'type' => 'equal'], 'c' => ['enabled' => true, 'type' => 'equal']];
        $preview = WC_Multi_Store_Stock_Allocator::preview_allocation(100, $all);

        $this->assertSame(99, $preview['_summary']['total_allocated']);
        $this->assertSame(1, $preview['_summary']['unallocated']);
        $this->assertFalse($preview['_summary']['over_allocated']);
    }

    // ── dispatch via calculate_allocation ────────────────────────

    public function test_dispatch_routes_each_type_to_its_strategy(): void
    {
        $all = ['a' => ['enabled' => true, 'priority' => 5], 'b' => ['enabled' => true, 'priority' => 5]];
        $calc = fn(array $r) => WC_Multi_Store_Stock_Allocator::calculate_allocation(100, $r + ['enabled' => true], $all);

        $this->assertSame(25, $calc(['type' => 'percentage', 'percentage' => 25]));
        $this->assertSame(10, $calc(['type' => 'fixed', 'fixed_quantity' => 10]));
        $this->assertSame(50, $calc(['type' => 'priority', 'priority' => 5]));
        $this->assertSame(85, $calc(['type' => 'reserve', 'reserve_quantity' => 15]));
        $this->assertSame(50, $calc(['type' => 'equal']));
    }

    public function test_unknown_or_non_string_type_returns_full_stock(): void
    {
        foreach (['bogus', '', 123, null, ['percentage'], 'PERCENTAGE'] as $type) {
            $result = WC_Multi_Store_Stock_Allocator::calculate_allocation(100, [
                'enabled' => true,
                'type' => $type,
                'percentage' => 10,
            ]);
            $this->assertSame(100, $result, 'type=' . var_export($type, true));
        }
    }

    public function test_missing_type_key_returns_full_stock(): void
    {
        $this->assertSame(100, WC_Multi_Store_Stock_Allocator::calculate_allocation(100, ['enabled' => true, 'percentage' => 10]));
    }

    public function test_missing_enabled_key_returns_full_stock(): void
    {
        $this->assertSame(100, WC_Multi_Store_Stock_Allocator::calculate_allocation(100, ['type' => 'fixed', 'fixed_quantity' => 1]));
    }

    public function test_apply_stock_allocation_sets_status_from_allocated_value(): void
    {
        $rules = ['enabled' => true, 'type' => 'reserve', 'reserve_quantity' => 10];

        $low = WC_Multi_Store_Stock_Allocator::apply_stock_allocation(['stock_quantity' => 10, 'stock_status' => 'instock', 'sku' => 'X'], $rules, 10);
        $this->assertSame(0, $low['stock_quantity']);
        $this->assertSame('outofstock', $low['stock_status']);
        $this->assertSame('X', $low['sku']);

        $ok = WC_Multi_Store_Stock_Allocator::apply_stock_allocation(['stock_quantity' => 11, 'stock_status' => 'outofstock'], $rules, 11);
        $this->assertSame(1, $ok['stock_quantity']);
        $this->assertSame('instock', $ok['stock_status']);
    }
}
