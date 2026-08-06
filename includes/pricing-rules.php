<?php
/**
 * Pricing Rules for Per-Store Pricing
 *
 * Handles different pricing strategies per remote store:
 * - Fixed markup/markdown (e.g., +$10, -$5)
 * - Percentage adjustment (e.g., +15%, -10%)
 * - Multiplier (e.g., 1.15x, 0.90x)
 * - Currency conversion support
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pricing Rules Class
 */
class WC_Multi_Store_Pricing_Rules {

    /**
     * Apply pricing rules to product data
     *
     * @param array $product_data Product data array
     * @param array $pricing_rules Pricing rules configuration
     * @param WC_Product|null $product Original product object
     * @return array Modified product data with adjusted prices
     */
    public static function apply_pricing_rules(array $product_data, array $pricing_rules, mixed $product = null): array {
        if (empty($pricing_rules) || !is_array($pricing_rules)) {
            return $product_data;
        }

        // Check if pricing rules are enabled
        if (empty($pricing_rules['enabled'])) {
            return $product_data;
        }

        $raw_type = $pricing_rules['type'] ?? 'none';
        $rule_type = is_string($raw_type) ? WC_Multi_Store_Pricing_Rule_Type::tryFrom($raw_type) : null;

        $product_data = match ($rule_type) {
            WC_Multi_Store_Pricing_Rule_Type::FIXED => self::apply_fixed_adjustment($product_data, $pricing_rules),
            WC_Multi_Store_Pricing_Rule_Type::PERCENTAGE => self::apply_percentage_adjustment($product_data, $pricing_rules),
            WC_Multi_Store_Pricing_Rule_Type::MULTIPLIER => self::apply_multiplier_adjustment($product_data, $pricing_rules),
            WC_Multi_Store_Pricing_Rule_Type::CURRENCY => self::apply_currency_conversion($product_data, $pricing_rules),
            WC_Multi_Store_Pricing_Rule_Type::CUSTOM => apply_filters('wc_mss_custom_pricing_rule', $product_data, $pricing_rules, $product),
            default => $product_data,
        };

        // Ensure prices are not negative
        $product_data = self::validate_prices($product_data);

        return $product_data;
    }

    /**
     * Apply fixed adjustment (+$10, -$5)
     *
     * @param array $product_data Product data
     * @param array $pricing_rules Pricing rules
     * @return array Modified product data
     */
    private static function apply_fixed_adjustment(array $product_data, array $pricing_rules): array {
        $adjustment = isset($pricing_rules['fixed_amount']) ? floatval($pricing_rules['fixed_amount']) : 0;

        if ($adjustment == 0) {
            return $product_data;
        }

        // Apply to regular price
        if (isset($product_data['regular_price']) && !empty($product_data['regular_price'])) {
            $product_data['regular_price'] = self::format_price(
                floatval($product_data['regular_price']) + $adjustment
            );
        }

        // Apply to sale price
        if (isset($product_data['sale_price']) && !empty($product_data['sale_price'])) {
            $product_data['sale_price'] = self::format_price(
                floatval($product_data['sale_price']) + $adjustment
            );
        }

        return $product_data;
    }

    /**
     * Apply percentage adjustment (+15%, -10%)
     *
     * @param array $product_data Product data
     * @param array $pricing_rules Pricing rules
     * @return array Modified product data
     */
    private static function apply_percentage_adjustment(array $product_data, array $pricing_rules): array {
        $percentage = isset($pricing_rules['percentage']) ? floatval($pricing_rules['percentage']) : 0;

        if ($percentage == 0) {
            return $product_data;
        }

        $multiplier = 1 + ($percentage / 100);

        // Apply to regular price
        if (isset($product_data['regular_price']) && !empty($product_data['regular_price'])) {
            $product_data['regular_price'] = self::format_price(
                floatval($product_data['regular_price']) * $multiplier
            );
        }

        // Apply to sale price
        if (isset($product_data['sale_price']) && !empty($product_data['sale_price'])) {
            $product_data['sale_price'] = self::format_price(
                floatval($product_data['sale_price']) * $multiplier
            );
        }

        return $product_data;
    }

    /**
     * Apply multiplier adjustment (1.15x, 0.90x)
     *
     * @param array $product_data Product data
     * @param array $pricing_rules Pricing rules
     * @return array Modified product data
     */
    private static function apply_multiplier_adjustment(array $product_data, array $pricing_rules): array {
        $multiplier = isset($pricing_rules['multiplier']) ? floatval($pricing_rules['multiplier']) : 1;

        if ($multiplier == 1 || $multiplier <= 0) {
            return $product_data;
        }

        // Apply to regular price
        if (isset($product_data['regular_price']) && !empty($product_data['regular_price'])) {
            $product_data['regular_price'] = self::format_price(
                floatval($product_data['regular_price']) * $multiplier
            );
        }

        // Apply to sale price
        if (isset($product_data['sale_price']) && !empty($product_data['sale_price'])) {
            $product_data['sale_price'] = self::format_price(
                floatval($product_data['sale_price']) * $multiplier
            );
        }

        return $product_data;
    }

    /**
     * Apply currency conversion
     *
     * @param array $product_data Product data
     * @param array $pricing_rules Pricing rules
     * @return array Modified product data
     */
    private static function apply_currency_conversion(array $product_data, array $pricing_rules): array {
        $exchange_rate = isset($pricing_rules['exchange_rate']) ? floatval($pricing_rules['exchange_rate']) : 1;

        if ($exchange_rate == 1 || $exchange_rate <= 0) {
            return $product_data;
        }

        // Apply to regular price
        if (isset($product_data['regular_price']) && !empty($product_data['regular_price'])) {
            $product_data['regular_price'] = self::format_price(
                floatval($product_data['regular_price']) * $exchange_rate
            );
        }

        // Apply to sale price
        if (isset($product_data['sale_price']) && !empty($product_data['sale_price'])) {
            $product_data['sale_price'] = self::format_price(
                floatval($product_data['sale_price']) * $exchange_rate
            );
        }

        return $product_data;
    }

    /**
     * Apply pricing rules to variation data
     *
     * @param array $variation_data Variation data
     * @param array $pricing_rules Pricing rules configuration
     * @return array Modified variation data
     */
    public static function apply_to_variation(array $variation_data, array $pricing_rules): array {
        // Use the same logic as main product
        return self::apply_pricing_rules($variation_data, $pricing_rules);
    }

    /**
     * Validate prices (ensure not negative and proper format)
     *
     * @param array $product_data Product data
     * @return array Validated product data
     */
    private static function validate_prices(array $product_data): array {
        // Ensure regular price is not negative
        if (isset($product_data['regular_price']) && floatval($product_data['regular_price']) < 0) {
            $product_data['regular_price'] = '0';
        }

        // Ensure sale price is not negative
        if (isset($product_data['sale_price']) && floatval($product_data['sale_price']) < 0) {
            $product_data['sale_price'] = '0';
        }

        // Ensure sale price is less than regular price
        if (
            isset($product_data['regular_price']) &&
            isset($product_data['sale_price']) &&
            !empty($product_data['sale_price']) &&
            floatval($product_data['sale_price']) >= floatval($product_data['regular_price'])
        ) {
            // Sale price should be less than regular price, so remove it
            unset($product_data['sale_price']);
        }

        return $product_data;
    }

    /**
     * Format price to 2 decimal places
     *
     * @param float $price Price value
     * @return string Formatted price
     */
    private static function format_price(float $price): string {
        return number_format($price, 2, '.', '');
    }

    /**
     * Get default pricing rules structure
     *
     * @return array Default pricing rules
     */
    public static function get_default_rules(): array {
        return [
            'enabled' => false,
            'type' => WC_Multi_Store_Pricing_Rule_Type::NONE->value,
            'fixed_amount' => 0,
            'percentage' => 0,
            'multiplier' => 1,
            'exchange_rate' => 1,
            'currency_from' => '',
            'currency_to' => '',
        ];
    }

    /**
     * Validate pricing rules
     *
     * @param array $rules Pricing rules to validate
     * @return array Validated rules
     */
    public static function validate_rules(mixed $rules): array {
        if (!is_array($rules)) {
            return self::get_default_rules();
        }

        $defaults = self::get_default_rules();
        $validated = wp_parse_args($rules, $defaults);

        // Validate type
        $type_value = is_string($validated['type'] ?? null) ? WC_Multi_Store_Pricing_Rule_Type::tryFrom($validated['type']) : null;
        $validated['type'] = ($type_value ?? WC_Multi_Store_Pricing_Rule_Type::NONE)->value;

        // Validate numeric values
        $validated['fixed_amount'] = floatval($validated['fixed_amount']);
        $validated['percentage'] = floatval($validated['percentage']);
        $validated['multiplier'] = floatval($validated['multiplier']);
        $validated['exchange_rate'] = floatval($validated['exchange_rate']);

        // Ensure multiplier and exchange rate are positive
        if ($validated['multiplier'] <= 0) {
            $validated['multiplier'] = 1;
        }
        if ($validated['exchange_rate'] <= 0) {
            $validated['exchange_rate'] = 1;
        }

        // Sanitize currency codes
        $validated['currency_from'] = sanitize_text_field($validated['currency_from']);
        $validated['currency_to'] = sanitize_text_field($validated['currency_to']);

        return $validated;
    }

    /**
     * Get pricing rule types with labels
     *
     * @return array Rule types
     */
    public static function get_rule_types(): array {
        $types = [];
        foreach (WC_Multi_Store_Pricing_Rule_Type::cases() as $type) {
            $types[$type->value] = $type->label();
        }
        return $types;
    }

    /**
     * Preview pricing changes
     *
     * @param float $original_price Original price
     * @param array $pricing_rules Pricing rules
     * @return array Preview with original and new price
     */
    public static function preview_price(float $original_price, array $pricing_rules): array {
        $product_data = ['regular_price' => $original_price];
        $modified_data = self::apply_pricing_rules($product_data, $pricing_rules);

        return [
            'original' => self::format_price($original_price),
            'adjusted' => $modified_data['regular_price'] ?? self::format_price($original_price),
            'difference' => self::format_price(
                floatval($modified_data['regular_price']) - floatval($original_price)
            ),
            'difference_percentage' => $original_price > 0
                ? round((floatval($modified_data['regular_price']) - floatval($original_price)) / floatval($original_price) * 100, 2)
                : 0,
        ];
    }
}
