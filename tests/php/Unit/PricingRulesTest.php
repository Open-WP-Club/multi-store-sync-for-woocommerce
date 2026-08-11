<?php
/**
 * Unit tests for WC_Multi_Store_Pricing_Rules
 */

use Brain\Monkey;

class PricingRulesTest extends WC_Multi_Store_TestCase
{
    /**
     * Test that disabled rules return original data
     */
    public function test_disabled_rules_return_original_data(): void
    {
        $product_data = [
            'regular_price' => '100.00',
            'sale_price' => '80.00',
        ];

        $pricing_rules = [
            'enabled' => false,
            'type' => 'fixed',
            'fixed_amount' => 10,
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals($product_data, $result);
    }

    /**
     * Test that empty rules return original data
     */
    public function test_empty_rules_return_original_data(): void
    {
        $product_data = [
            'regular_price' => '100.00',
            'sale_price' => '80.00',
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, []);

        $this->assertEquals($product_data, $result);
    }

    /**
     * Test fixed positive adjustment
     */
    public function test_fixed_positive_adjustment(): void
    {
        $product_data = [
            'regular_price' => '100.00',
            'sale_price' => '80.00',
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'fixed',
            'fixed_amount' => 10,
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals('110.00', $result['regular_price']);
        $this->assertEquals('90.00', $result['sale_price']);
    }

    /**
     * Test fixed negative adjustment (discount)
     */
    public function test_fixed_negative_adjustment(): void
    {
        $product_data = [
            'regular_price' => '100.00',
            'sale_price' => '80.00',
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'fixed',
            'fixed_amount' => -15,
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals('85.00', $result['regular_price']);
        $this->assertEquals('65.00', $result['sale_price']);
    }

    /**
     * Test percentage positive adjustment
     */
    public function test_percentage_positive_adjustment(): void
    {
        $product_data = [
            'regular_price' => '100.00',
            'sale_price' => '80.00',
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 15,
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals('115.00', $result['regular_price']);
        $this->assertEquals('92.00', $result['sale_price']);
    }

    /**
     * Test percentage negative adjustment (discount)
     */
    public function test_percentage_negative_adjustment(): void
    {
        $product_data = [
            'regular_price' => '100.00',
            'sale_price' => '80.00',
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => -10,
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals('90.00', $result['regular_price']);
        $this->assertEquals('72.00', $result['sale_price']);
    }

    /**
     * Test multiplier adjustment
     */
    public function test_multiplier_adjustment(): void
    {
        $product_data = [
            'regular_price' => '100.00',
            'sale_price' => '80.00',
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'multiplier',
            'multiplier' => 1.25,
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals('125.00', $result['regular_price']);
        $this->assertEquals('100.00', $result['sale_price']);
    }

    /**
     * Test currency conversion
     */
    public function test_currency_conversion(): void
    {
        $product_data = [
            'regular_price' => '100.00',
            'sale_price' => '80.00',
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'currency',
            'exchange_rate' => 1.95, // e.g., USD to BGN
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals('195.00', $result['regular_price']);
        $this->assertEquals('156.00', $result['sale_price']);
    }

    /**
     * Test that negative prices are prevented
     */
    public function test_negative_prices_are_prevented(): void
    {
        $product_data = [
            'regular_price' => '10.00',
            'sale_price' => '8.00',
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'fixed',
            'fixed_amount' => -20, // Would result in negative price
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals('0', $result['regular_price']);
        $this->assertEquals('0', $result['sale_price']);
    }

    /**
     * Test that sale price greater than regular price is removed
     */
    public function test_sale_price_greater_than_regular_is_removed(): void
    {
        $product_data = [
            'regular_price' => '50.00',
            'sale_price' => '80.00', // Invalid: sale > regular
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'fixed',
            'fixed_amount' => 0,
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertArrayNotHasKey('sale_price', $result);
    }

    /**
     * Test default rules structure
     */
    public function test_get_default_rules(): void
    {
        $defaults = WC_Multi_Store_Pricing_Rules::get_default_rules();

        $this->assertArrayHasKey('enabled', $defaults);
        $this->assertArrayHasKey('type', $defaults);
        $this->assertArrayHasKey('fixed_amount', $defaults);
        $this->assertArrayHasKey('percentage', $defaults);
        $this->assertArrayHasKey('multiplier', $defaults);
        $this->assertArrayHasKey('exchange_rate', $defaults);

        $this->assertFalse($defaults['enabled']);
        $this->assertEquals('none', $defaults['type']);
        $this->assertEquals(1, $defaults['multiplier']);
        $this->assertEquals(1, $defaults['exchange_rate']);
    }

    /**
     * Test rules validation with invalid type
     */
    public function test_validate_rules_invalid_type(): void
    {
        $rules = [
            'enabled' => true,
            'type' => 'invalid_type',
        ];

        $validated = WC_Multi_Store_Pricing_Rules::validate_rules($rules);

        $this->assertEquals('none', $validated['type']);
    }

    /**
     * Test rules validation with negative multiplier
     */
    public function test_validate_rules_negative_multiplier(): void
    {
        $rules = [
            'enabled' => true,
            'type' => 'multiplier',
            'multiplier' => -1.5,
        ];

        $validated = WC_Multi_Store_Pricing_Rules::validate_rules($rules);

        $this->assertEquals(1, $validated['multiplier']);
    }

    /**
     * Test rules validation with zero exchange rate
     */
    public function test_validate_rules_zero_exchange_rate(): void
    {
        $rules = [
            'enabled' => true,
            'type' => 'currency',
            'exchange_rate' => 0,
        ];

        $validated = WC_Multi_Store_Pricing_Rules::validate_rules($rules);

        $this->assertEquals(1, $validated['exchange_rate']);
    }

    /**
     * Test price preview functionality
     */
    public function test_preview_price(): void
    {
        $pricing_rules = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 20,
        ];

        $preview = WC_Multi_Store_Pricing_Rules::preview_price(100.00, $pricing_rules);

        $this->assertEquals('100.00', $preview['original']);
        $this->assertEquals('120.00', $preview['adjusted']);
        $this->assertEquals('20.00', $preview['difference']);
        $this->assertEquals(20, $preview['difference_percentage']);
    }

    /**
     * Test zero percentage doesn't modify prices
     */
    public function test_zero_percentage_no_change(): void
    {
        $product_data = [
            'regular_price' => '100.00',
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 0,
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals('100.00', $result['regular_price']);
    }

    /**
     * Test multiplier of 1 doesn't modify prices
     */
    public function test_multiplier_one_no_change(): void
    {
        $product_data = [
            'regular_price' => '100.00',
        ];

        $pricing_rules = [
            'enabled' => true,
            'type' => 'multiplier',
            'multiplier' => 1,
        ];

        $result = WC_Multi_Store_Pricing_Rules::apply_pricing_rules($product_data, $pricing_rules);

        $this->assertEquals('100.00', $result['regular_price']);
    }
}
