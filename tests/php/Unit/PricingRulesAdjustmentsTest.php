<?php
/**
 * Adjustment-level tests for WC_Multi_Store_Pricing_Rules.
 *
 * Private apply_* methods and validate_prices() are reached via reflection.
 */

use PHPUnit\Framework\Attributes\DataProvider;

class PricingRulesAdjustmentsTest extends WC_Multi_Store_TestCase
{
    /**
     * Invoke a private static pricing method.
     *
     * @param string $method Method name.
     * @param mixed  ...$args Arguments.
     * @return mixed
     */
    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(WC_Multi_Store_Pricing_Rules::class, $method);
        return $ref->invoke(null, ...$args);
    }

    // ── fixed ────────────────────────────────────────────────────

    public static function fixedProvider(): array
    {
        return [
            'add'              => ['100.00', 10, '110.00'],
            'subtract'         => ['100.00', -15.5, '84.50'],
            'string amount'    => ['20', '2.5', '22.50'],
            'rounds up'        => ['10.00', 0.006, '10.01'],
            'rounds down'      => ['10.00', 0.004, '10.00'],
            'to negative'      => ['5.00', -8, '-3.00'],
            'to exactly zero'  => ['5.00', -5, '0.00'],
        ];
    }

    #[DataProvider('fixedProvider')]
    public function test_fixed_adjustment(string $price, int|float|string $amount, string $expected): void
    {
        $result = $this->call('apply_fixed_adjustment', ['regular_price' => $price], ['fixed_amount' => $amount]);
        $this->assertSame($expected, $result['regular_price']);
    }

    public function test_fixed_adjustment_applies_to_both_prices(): void
    {
        $result = $this->call('apply_fixed_adjustment', ['regular_price' => '50', 'sale_price' => '40'], ['fixed_amount' => 5]);
        $this->assertSame('55.00', $result['regular_price']);
        $this->assertSame('45.00', $result['sale_price']);
    }

    public function test_fixed_adjustment_zero_or_missing_leaves_data_untouched(): void
    {
        $data = ['regular_price' => '12.3', 'name' => 'x'];
        $this->assertSame($data, $this->call('apply_fixed_adjustment', $data, ['fixed_amount' => 0]));
        $this->assertSame($data, $this->call('apply_fixed_adjustment', $data, []));
        $this->assertSame($data, $this->call('apply_fixed_adjustment', $data, ['fixed_amount' => 'abc']));
    }

    // ── percentage ───────────────────────────────────────────────

    public static function percentageProvider(): array
    {
        return [
            'plus 15'            => ['100.00', 15, '115.00'],
            'minus 10'           => ['100.00', -10, '90.00'],
            'rounds to cents'    => ['19.99', 10, '21.99'],
            'third'              => ['10.00', 33.333, '13.33'],
            'minus 100 is zero'  => ['80.00', -100, '0.00'],
            'below minus 100'    => ['80.00', -150, '-40.00'],
            'double'             => ['7.50', 100, '15.00'],
            'string pct'         => ['200', '5', '210.00'],
        ];
    }

    #[DataProvider('percentageProvider')]
    public function test_percentage_adjustment(string $price, int|float|string $pct, string $expected): void
    {
        $result = $this->call('apply_percentage_adjustment', ['regular_price' => $price], ['percentage' => $pct]);
        $this->assertSame($expected, $result['regular_price']);
    }

    public function test_percentage_adjustment_applies_to_sale_price_too(): void
    {
        $result = $this->call('apply_percentage_adjustment', ['regular_price' => '100', 'sale_price' => '60'], ['percentage' => 50]);
        $this->assertSame('150.00', $result['regular_price']);
        $this->assertSame('90.00', $result['sale_price']);
    }

    public function test_percentage_adjustment_zero_or_missing_is_noop(): void
    {
        $data = ['regular_price' => '10'];
        $this->assertSame($data, $this->call('apply_percentage_adjustment', $data, ['percentage' => 0]));
        $this->assertSame($data, $this->call('apply_percentage_adjustment', $data, []));
    }

    // ── multiplier / currency ────────────────────────────────────

    public static function multiplierProvider(): array
    {
        return [
            'increase'        => ['100', 1.15, '115.00'],
            'decrease'        => ['100', 0.9, '90.00'],
            'string'          => ['40', '2', '80.00'],
            'tiny multiplier' => ['100', 0.001, '0.10'],
            'rounds up'       => ['3.33', 1.5, '5.00'],
            'rounds down'     => ['3.31', 1.5, '4.97'],
        ];
    }

    #[DataProvider('multiplierProvider')]
    public function test_multiplier_adjustment(string $price, int|float|string $mult, string $expected): void
    {
        $result = $this->call('apply_multiplier_adjustment', ['regular_price' => $price], ['multiplier' => $mult]);
        $this->assertSame($expected, $result['regular_price']);
    }

    #[DataProvider('multiplierProvider')]
    public function test_currency_conversion_matches_multiplier_math(string $price, int|float|string $rate, string $expected): void
    {
        $result = $this->call('apply_currency_conversion', ['regular_price' => $price], ['exchange_rate' => $rate]);
        $this->assertSame($expected, $result['regular_price']);
    }

    public static function inertFactorProvider(): array
    {
        return [
            'one'      => [1],
            'zero'     => [0],
            'negative' => [-2],
            'string'   => ['abc'],
        ];
    }

    #[DataProvider('inertFactorProvider')]
    public function test_multiplier_zero_negative_or_one_is_ignored(int|string $factor): void
    {
        $data = ['regular_price' => '100.00', 'sale_price' => '80.00'];
        $this->assertSame($data, $this->call('apply_multiplier_adjustment', $data, ['multiplier' => $factor]));
    }

    #[DataProvider('inertFactorProvider')]
    public function test_exchange_rate_zero_negative_or_one_is_ignored(int|string $factor): void
    {
        $data = ['regular_price' => '100.00', 'sale_price' => '80.00'];
        $this->assertSame($data, $this->call('apply_currency_conversion', $data, ['exchange_rate' => $factor]));
    }

    public function test_multiplier_and_rate_missing_are_noops(): void
    {
        $data = ['regular_price' => '9.99'];
        $this->assertSame($data, $this->call('apply_multiplier_adjustment', $data, []));
        $this->assertSame($data, $this->call('apply_currency_conversion', $data, []));
    }

    public function test_currency_conversion_converts_sale_and_regular(): void
    {
        $result = $this->call('apply_currency_conversion', ['regular_price' => '100', 'sale_price' => '75'], ['exchange_rate' => 0.92]);
        $this->assertSame('92.00', $result['regular_price']);
        $this->assertSame('69.00', $result['sale_price']);
    }

    // ── shared price-field handling ──────────────────────────────

    public function test_empty_zero_and_missing_prices_are_not_touched(): void
    {
        foreach (['', '0', 0, null] as $empty) {
            $result = $this->call('apply_fixed_adjustment', ['regular_price' => '10', 'sale_price' => $empty], ['fixed_amount' => 5]);
            $this->assertSame('15.00', $result['regular_price']);
            $this->assertSame($empty, $result['sale_price'], 'sale=' . var_export($empty, true));
        }

        $result = $this->call('apply_fixed_adjustment', ['sale_price' => '10'], ['fixed_amount' => 5]);
        $this->assertArrayNotHasKey('regular_price', $result);
        $this->assertSame('15.00', $result['sale_price']);
    }

    public function test_non_price_fields_are_preserved(): void
    {
        $result = $this->call('apply_percentage_adjustment', ['regular_price' => '10', 'sku' => 'A-1', 'stock_quantity' => 3], ['percentage' => 10]);
        $this->assertSame('A-1', $result['sku']);
        $this->assertSame(3, $result['stock_quantity']);
        $this->assertSame('11.00', $result['regular_price']);
    }

    public function test_thousands_are_not_formatted_with_separators(): void
    {
        $result = $this->call('apply_multiplier_adjustment', ['regular_price' => '1000'], ['multiplier' => 1500]);
        $this->assertSame('1500000.00', $result['regular_price']);
    }

    // ── validate_prices ──────────────────────────────────────────

    public function test_validate_prices_clamps_negative_regular_to_zero_string(): void
    {
        $this->assertSame('0', $this->call('validate_prices', ['regular_price' => '-0.01'])['regular_price']);
    }

    public function test_validate_prices_clamps_negative_sale_to_zero_but_keeps_it_below_regular(): void
    {
        $result = $this->call('validate_prices', ['regular_price' => '10.00', 'sale_price' => '-3.00']);
        $this->assertSame('10.00', $result['regular_price']);
        $this->assertSame('0', $result['sale_price']);
    }

    public function test_validate_prices_negative_regular_and_sale_both_become_zero(): void
    {
        // Both clamp to '0'; a sale price equal to the regular price is not a sale and is removed.
        $result = $this->call('validate_prices', ['regular_price' => '-5', 'sale_price' => '-2']);
        $this->assertSame('0', $result['regular_price']);
        $this->assertArrayNotHasKey('sale_price', $result);
    }

    public static function saleRelationProvider(): array
    {
        return [
            'sale lower kept'      => ['100.00', '99.99', true],
            'sale equal removed'   => ['100.00', '100.00', false],
            'sale higher removed'  => ['100.00', '100.01', false],
            'numeric-equal removed' => ['100', '100.00', false],
            'empty sale kept as is' => ['100.00', '', true],
        ];
    }

    #[DataProvider('saleRelationProvider')]
    public function test_validate_prices_sale_vs_regular(string $regular, string $sale, bool $saleKept): void
    {
        $result = $this->call('validate_prices', ['regular_price' => $regular, 'sale_price' => $sale]);
        $this->assertSame($regular, $result['regular_price']);
        if ($saleKept) {
            $this->assertSame($sale, $result['sale_price']);
        } else {
            $this->assertArrayNotHasKey('sale_price', $result);
        }
    }

    public function test_validate_prices_valid_data_is_unchanged(): void
    {
        $data = ['regular_price' => '20.00', 'sale_price' => '15.00', 'name' => 'x'];
        $this->assertSame($data, $this->call('validate_prices', $data));
    }

    public function test_validate_prices_without_price_keys_is_unchanged(): void
    {
        $this->assertSame(['name' => 'x'], $this->call('validate_prices', ['name' => 'x']));
        $this->assertSame(['sale_price' => '5'], $this->call('validate_prices', ['sale_price' => '5']));
    }

    // ── through apply_pricing_rules ──────────────────────────────

    public function test_negative_fixed_result_is_clamped_through_public_entry(): void
    {
        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules(
            ['regular_price' => '5.00', 'sale_price' => '3.00'],
            ['enabled' => true, 'type' => 'fixed', 'fixed_amount' => -10]
        );
        // Both clamp to '0'; a zero sale price equal to the zero regular price is removed.
        $this->assertSame('0', $result['regular_price']);
        $this->assertArrayNotHasKey('sale_price', $result);
    }

    public function test_sale_price_dropped_when_adjustment_makes_it_reach_regular(): void
    {
        // Fixed +10 on regular 20 / sale 5 keeps ordering; a rule can never invert it,
        // but a regular price of 0 (untouched) with a sale price still collapses.
        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules(
            ['regular_price' => '0', 'sale_price' => '4'],
            ['enabled' => true, 'type' => 'fixed', 'fixed_amount' => 1]
        );
        $this->assertSame('0', $result['regular_price']);
        $this->assertArrayNotHasKey('sale_price', $result);
    }

    public function test_pricing_rules_zero_multiplier_leaves_prices_unchanged(): void
    {
        $data = ['regular_price' => '100.00', 'sale_price' => '80.00'];
        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules(
            $data,
            ['enabled' => true, 'type' => 'multiplier', 'multiplier' => 0]
        );
        $this->assertSame($data, $result);
    }

    public function test_unknown_type_leaves_prices_but_still_validates(): void
    {
        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules(
            ['regular_price' => '10.00', 'sale_price' => '12.00'],
            ['enabled' => true, 'type' => 'nonsense', 'fixed_amount' => 99]
        );
        $this->assertSame('10.00', $result['regular_price']);
        $this->assertArrayNotHasKey('sale_price', $result);
    }

    public function test_missing_type_key_does_not_apply_adjustment(): void
    {
        $data = ['regular_price' => '10.00'];
        $this->assertSame($data, WC_Multi_Store_Pricing_Rules::apply_pricing_rules($data, ['enabled' => true, 'fixed_amount' => 5]));
    }

    public function test_type_is_case_sensitive(): void
    {
        $data = ['regular_price' => '10.00'];
        $this->assertSame($data, WC_Multi_Store_Pricing_Rules::apply_pricing_rules($data, ['enabled' => true, 'type' => 'FIXED', 'fixed_amount' => 5]));
    }
}
