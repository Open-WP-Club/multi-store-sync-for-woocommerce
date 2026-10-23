<?php
/**
 * Tests for WC_Multi_Store_Attribute_Remapper private matchers
 * (find_name_mapping / find_value_mapping) and set_value_mappings().
 */

use Brain\Monkey\Functions;

class AttributeRemapperMappingsTest extends WC_Multi_Store_TestCase
{
    private const STORE_A = 'https://store-a.example.com';
    private const STORE_B = 'https://store-b.example.com';

    /** @var array<string, mixed> in-memory wp_options */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];

        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        // Unicode-friendly approximation of WordPress sanitize_title().
        Functions\when('sanitize_title')->alias(
            fn($s) => trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower((string) $s)), '-')
        );
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(WC_Multi_Store_Attribute_Remapper::class, $method);
        return $ref->invoke(null, ...$args);
    }

    // ─── find_name_mapping() ────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('nameLookups')]
    public function test_find_name_mapping(string $local, array $mappings, ?string $expected): void
    {
        $this->assertSame($expected, $this->call('find_name_mapping', $local, $mappings));
    }

    public static function nameLookups(): array
    {
        return [
            'exact match' => ['Color', ['Color' => 'Colour'], 'Colour'],
            'case-insensitive latin' => ['COLOR', ['color' => 'Colour'], 'Colour'],
            'case-insensitive cyrillic' => ['ЦВЯТ', ['цвят' => 'Color'], 'Color'],
            'slug match on spaces' => ['product-size', ['Product Size' => 'Size'], 'Size'],
            'slug match on punctuation and case' => ['Product   Size!', ['product-size' => 'Size'], 'Size'],
            'no match' => ['Material', ['Color' => 'Colour'], null],
            'empty mappings' => ['Color', [], null],
            'empty local name never matches real keys' => ['', ['Color' => 'Colour'], null],
            'substring is not a match' => ['Color Family', ['Color' => 'Colour'], null],
            'first matching entry wins' => ['color', ['Color' => 'First', 'COLOR' => 'Second'], 'First'],
            'mapping to empty string is returned, not null' => ['Color', ['Color' => ''], ''],
            'integer-like keys are compared as strings' => ['2024', [2024 => 'Year'], 'Year'],
        ];
    }

    public function test_find_name_mapping_matches_by_slug_even_when_case_differs_after_unicode_normalisation(): void
    {
        $this->assertSame('Size', $this->call('find_name_mapping', 'РАЗМЕР Дрехи', ['размер-дрехи' => 'Size']));
    }

    // ─── find_value_mapping() ───────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('valueLookups')]
    public function test_find_value_mapping(string $value, array $mappings, ?string $expected): void
    {
        $this->assertSame($expected, $this->call('find_value_mapping', $value, $mappings));
    }

    public static function valueLookups(): array
    {
        return [
            'exact' => ['Червен', ['Червен' => 'Red'], 'Red'],
            'case-insensitive cyrillic' => ['ЧЕРВЕН', ['Червен' => 'Red'], 'Red'],
            'case-insensitive latin' => ['xl', ['XL' => 'Extra Large'], 'Extra Large'],
            'no match' => ['Green', ['Червен' => 'Red'], null],
            'empty mappings' => ['Green', [], null],
            'whitespace is significant' => [' Red', ['Red' => 'Rot'], null],
            'slug equivalence is NOT used for values' => ['red-1', ['Red 1' => 'Rot'], null],
            'mapped to empty string' => ['Red', ['Red' => ''], ''],
            'numeric key' => ['42', [42 => 'Forty-two'], 'Forty-two'],
            'first matching entry wins' => ['red', ['RED' => 'A', 'Red' => 'B'], 'A'],
        ];
    }

    // ─── set_value_mappings() ───────────────────────────────────────────────

    public function test_set_value_mappings_stores_under_store_hash_and_sanitized_attribute_key(): void
    {
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Product Colour', ['Червен' => 'Red']);

        $this->assertSame(
            [md5(self::STORE_A) => ['product-colour' => ['Червен' => 'Red']]],
            $this->options[WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY]
        );
    }

    public function test_value_mappings_for_an_attribute_named_zero_do_not_return_the_whole_store_map(): void
    {
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, '0', ['a' => 'b']);
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Color', ['c' => 'd']);

        $this->assertSame(['a' => 'b'], WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_A, '0'));
        $this->assertArrayHasKey('color', WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_A), 'No attribute name still returns the full map');
    }

    public function test_set_value_mappings_replaces_only_the_targeted_attribute(): void
    {
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Color', ['a' => 'b']);
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Size', ['s' => 'S']);
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Color', ['c' => 'd']);

        $this->assertSame(['c' => 'd'], WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_A, 'Color'));
        $this->assertSame(['s' => 'S'], WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_A, 'Size'));
    }

    public function test_set_value_mappings_does_not_touch_other_stores(): void
    {
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_B, 'Color', ['b' => 'B']);
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Color', ['a' => 'A']);

        $this->assertSame(['b' => 'B'], WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_B, 'Color'));
        $this->assertSame(['a' => 'A'], WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_A, 'Color'));
    }

    public function test_set_value_mappings_attribute_name_variants_share_one_bucket(): void
    {
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Shoe Size', ['1' => 'one']);
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'shoe-size', ['2' => 'two']);

        $this->assertSame(['2' => 'two'], WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_A, 'SHOE SIZE'));
        $this->assertCount(1, WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_A));
    }

    public function test_set_value_mappings_with_empty_array_clears_attribute_but_keeps_bucket(): void
    {
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Color', ['a' => 'b']);
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Color', []);

        $this->assertSame([], WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_A, 'Color'));
        $this->assertSame(['color' => []], WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_A));
    }

    public function test_set_value_mappings_does_not_write_to_name_mapping_option(): void
    {
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Color', ['a' => 'b']);

        $this->assertArrayNotHasKey(WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY, $this->options);
    }

    public function test_value_mappings_written_by_set_are_applied_case_insensitively_by_apply_mappings(): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['attribute_remapping_enabled' => true];
        WC_Multi_Store_Attribute_Remapper::set_value_mappings(self::STORE_A, 'Цвят', ['червен' => 'Red']);

        $result = WC_Multi_Store_Attribute_Remapper::apply_variation_mappings(
            ['attributes' => [['id' => 1, 'name' => 'цвят', 'option' => 'ЧЕРВЕН']]],
            self::STORE_A
        );

        $this->assertSame([['id' => 1, 'name' => 'цвят', 'option' => 'Red']], $result['attributes']);
    }
}
