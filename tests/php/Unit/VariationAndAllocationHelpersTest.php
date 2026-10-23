<?php
/**
 * Tests for two private helpers:
 *  - WC_Multi_Store_Variation_Synchronizer::light_variation_data_unchanged
 *  - WC_Multi_Store_Product_Transformer::get_all_allocation_rules
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class VariationAndAllocationHelpersTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
    private array $stores = [];

    private int $storeReads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        WC_Multi_Store_Settings::clear_static_cache();
        $this->stores = [];
        $this->storeReads = 0;

        Functions\when('get_option')->alias(function ($option, $default = false) {
            if ($option === 'wc_multi_store_sync_stores') {
                $this->storeReads++;
                return $this->stores;
            }
            return $default;
        });
    }

    // ---- light_variation_data_unchanged ----------------------------------

    private function unchanged(array $local, array $remote): bool
    {
        $sync = new WC_Multi_Store_Variation_Synchronizer();
        return (new ReflectionMethod($sync, 'light_variation_data_unchanged'))->invoke($sync, $local, $remote);
    }

    /**
     * @return array<string, array{array, array, bool}>
     */
    public static function lightComparisons(): array
    {
        return [
            'identical' => [
                ['regular_price' => '10.00', 'stock_quantity' => 5, 'stock_status' => 'instock', 'manage_stock' => true],
                ['regular_price' => '10.00', 'stock_quantity' => 5, 'stock_status' => 'instock', 'manage_stock' => true],
                true,
            ],
            'int vs numeric string quantity' => [['stock_quantity' => 5], ['stock_quantity' => '5'], true],
            'price differs' => [['regular_price' => '10.00'], ['regular_price' => '9.99'], false],
            'price formatting differs is a change' => [['regular_price' => '10'], ['regular_price' => '10.00'], false],
            'sale price cleared locally, set remotely' => [['sale_price' => ''], ['sale_price' => '5.00'], false],
            'sale price empty both sides' => [['sale_price' => ''], ['sale_price' => ''], true],
            'sale price empty locally, missing remotely' => [['sale_price' => ''], [], true],
            'stock differs' => [['stock_quantity' => 4], ['stock_quantity' => 5], false],
            'stock zero vs null remote' => [['stock_quantity' => 0], ['stock_quantity' => null], false],
            'stock null locally, missing remotely' => [['stock_quantity' => null], [], true],
            'stock status differs' => [['stock_status' => 'outofstock'], ['stock_status' => 'instock'], false],
            'manage_stock true vs "1" (string-normalised)' => [['manage_stock' => true], ['manage_stock' => '1'], true],
            'manage_stock true vs true' => [['manage_stock' => true], ['manage_stock' => true], true],
            'manage_stock false vs false' => [['manage_stock' => false], ['manage_stock' => false], true],
            'manage_stock false vs missing' => [['manage_stock' => false], [], true],
            'field absent locally is ignored even if remote differs' => [['regular_price' => '1'], ['regular_price' => '1', 'stock_quantity' => 99], true],
            'non-compared fields are ignored' => [['sku' => 'A', 'regular_price' => '1'], ['sku' => 'B', 'regular_price' => '1'], true],
            'empty local data' => [[], ['regular_price' => '5'], true],
            'one changed among several' => [
                ['regular_price' => '1', 'stock_quantity' => 3, 'stock_status' => 'instock'],
                ['regular_price' => '1', 'stock_quantity' => 3, 'stock_status' => 'onbackorder'],
                false,
            ],
        ];
    }

    #[DataProvider('lightComparisons')]
    public function test_light_variation_data_unchanged(array $local, array $remote, bool $expected): void
    {
        $this->assertSame($expected, $this->unchanged($local, $remote));
    }

    // ---- get_all_allocation_rules ----------------------------------------

    private function rules(WC_Multi_Store_Product_Transformer $t): array
    {
        return (new ReflectionMethod($t, 'get_all_allocation_rules'))->invoke($t);
    }

    public function test_allocation_rules_empty_without_stores(): void
    {
        $this->assertSame([], $this->rules(new WC_Multi_Store_Product_Transformer()));
    }

    public function test_allocation_rules_only_include_active_stores_with_rules(): void
    {
        $ruleA = ['type' => 'percentage', 'value' => 40];
        $ruleB = ['type' => 'fixed', 'value' => 7];
        $this->stores = [
            'https://a.com' => ['status' => 'active', 'stock_allocation_rules' => $ruleA],
            'https://b.com' => ['status' => 'active'],
            'https://c.com' => ['status' => 'inactive', 'stock_allocation_rules' => ['type' => 'percentage', 'value' => 90]],
            'https://d.com' => ['status' => 'active', 'stock_allocation_rules' => $ruleB],
            'https://e.com' => ['stock_allocation_rules' => ['type' => 'percentage', 'value' => 10]],
        ];

        $result = $this->rules(new WC_Multi_Store_Product_Transformer());

        $this->assertSame(['https://a.com' => $ruleA, 'https://d.com' => $ruleB], $result);
    }

    public function test_allocation_rules_are_cached_per_transformer_instance(): void
    {
        $this->stores = ['https://a.com' => ['status' => 'active', 'stock_allocation_rules' => ['v' => 1]]];
        $t = new WC_Multi_Store_Product_Transformer();

        $first = $this->rules($t);
        // Config changes after the first read must not leak into this instance...
        $this->stores = ['https://z.com' => ['status' => 'active', 'stock_allocation_rules' => ['v' => 2]]];
        WC_Multi_Store_Settings::clear_static_cache();
        $second = $this->rules($t);

        $this->assertSame($first, $second);
        $this->assertSame(['https://a.com' => ['v' => 1]], $second);

        // ...but a fresh instance sees the new configuration.
        $this->assertSame(['https://z.com' => ['v' => 2]], $this->rules(new WC_Multi_Store_Product_Transformer()));
    }

    public function test_allocation_rules_cache_remembers_an_empty_result(): void
    {
        $t = new WC_Multi_Store_Product_Transformer();
        $this->assertSame([], $this->rules($t));

        $this->stores = ['https://a.com' => ['status' => 'active', 'stock_allocation_rules' => ['v' => 1]]];
        WC_Multi_Store_Settings::clear_static_cache();

        $this->assertSame([], $this->rules($t), 'An empty array is a valid cached value, not "unset"');
    }
}
