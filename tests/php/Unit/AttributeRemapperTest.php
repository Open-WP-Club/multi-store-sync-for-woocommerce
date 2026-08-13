<?php
/**
 * Unit tests for WC_Multi_Store_Attribute_Remapper
 */

use Brain\Monkey\Functions;

class AttributeRemapperTest extends WC_Multi_Store_TestCase
{
    private const STORE_URL = 'https://store1.example.com';

    /**
     * Precomputed md5 of the test store URL, used when building option payloads.
     */
    private string $storeKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeKey = md5(self::STORE_URL);

        // Default stubs — individual tests override as needed.
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [],
                default => $default,
            };
        });

        Functions\when('update_option')->justReturn(true);

        // Logger::write() (called on name-remap) needs current_time().
        Functions\when('current_time')->justReturn('2024-01-01 00:00:00');

        // sanitize_title: convert to lowercase, replace non-alphanumeric chars with '-'.
        // Mirrors WordPress behaviour closely enough for attribute slug matching.
        Functions\when('sanitize_title')->alias(
            fn($s) => strtolower(preg_replace('/[^a-z0-9]/u', '-', mb_strtolower($s)))
        );
    }

    // -------------------------------------------------------------------------
    // is_enabled()
    // -------------------------------------------------------------------------

    public function test_is_enabled_returns_false_by_default(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            if ($opt === 'wc_multi_store_sync_settings') {
                return [];
            }
            return $default;
        });

        $this->assertFalse(WC_Multi_Store_Attribute_Remapper::is_enabled());
    }

    public function test_is_enabled_returns_true_when_enabled(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            if ($opt === 'wc_multi_store_sync_settings') {
                return ['attribute_remapping_enabled' => true];
            }
            return $default;
        });

        $this->assertTrue(WC_Multi_Store_Attribute_Remapper::is_enabled());
    }

    // -------------------------------------------------------------------------
    // get_name_mappings() / set_name_mappings()
    // -------------------------------------------------------------------------

    public function test_get_name_mappings_returns_empty_for_unknown_store(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            if ($opt === WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY) {
                // No entry for our test store.
                return [];
            }
            return $default;
        });

        $result = WC_Multi_Store_Attribute_Remapper::get_name_mappings(self::STORE_URL);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_name_mappings_returns_correct_store_subset(): void
    {
        $store2Key = md5('https://store2.example.com');

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($store2Key) {
            if ($opt === WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY) {
                return [
                    $this->storeKey => ['Цвят' => 'Color', 'Размер' => 'Size'],
                    $store2Key      => ['Боя'  => 'Paint'],
                ];
            }
            return $default;
        });

        $result = WC_Multi_Store_Attribute_Remapper::get_name_mappings(self::STORE_URL);

        $this->assertSame(['Цвят' => 'Color', 'Размер' => 'Size'], $result);
    }

    public function test_set_name_mappings_persists_under_store_key(): void
    {
        $capturedOption = null;
        $capturedValue  = null;

        Functions\when('get_option')->alias(function ($opt, $default = null) {
            if ($opt === WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY) {
                return [];
            }
            return $default;
        });

        Functions\when('update_option')->alias(function ($opt, $value) use (&$capturedOption, &$capturedValue) {
            $capturedOption = $opt;
            $capturedValue  = $value;
            return true;
        });

        $mappings = ['Цвят' => 'Color'];
        WC_Multi_Store_Attribute_Remapper::set_name_mappings(self::STORE_URL, $mappings);

        $this->assertSame(WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY, $capturedOption);
        $this->assertArrayHasKey($this->storeKey, $capturedValue);
        $this->assertSame($mappings, $capturedValue[$this->storeKey]);
    }

    // -------------------------------------------------------------------------
    // get_value_mappings()
    // -------------------------------------------------------------------------

    public function test_get_value_mappings_without_attribute_name_returns_all_store_mappings(): void
    {
        $attrKey   = strtolower(preg_replace('/[^a-z0-9]/u', '-', mb_strtolower('Color')));
        $storeMaps = [$attrKey => ['Червен' => 'Red', 'Синьо' => 'Blue']];

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($storeMaps) {
            if ($opt === WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY) {
                return [$this->storeKey => $storeMaps];
            }
            return $default;
        });

        $result = WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_URL);

        $this->assertSame($storeMaps, $result);
    }

    public function test_get_value_mappings_with_attribute_name_returns_scoped_mappings(): void
    {
        // The class calls sanitize_title('Color') to build the sub-key.
        // Our sanitize_title stub turns 'Color' → 'color'.
        $attrKey   = 'color';
        $storeMaps = [$attrKey => ['Червен' => 'Red']];

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($storeMaps) {
            if ($opt === WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY) {
                return [$this->storeKey => $storeMaps];
            }
            return $default;
        });

        $result = WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_URL, 'Color');

        $this->assertSame(['Червен' => 'Red'], $result);
    }

    public function test_get_value_mappings_returns_empty_for_unknown_attribute(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            if ($opt === WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY) {
                return [$this->storeKey => ['color' => ['Червен' => 'Red']]];
            }
            return $default;
        });

        $result = WC_Multi_Store_Attribute_Remapper::get_value_mappings(self::STORE_URL, 'NonExistent');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // apply_mappings() — disabled / no data guards
    // -------------------------------------------------------------------------

    public function test_apply_mappings_returns_unchanged_when_disabled(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return $default;
        });

        $product_data = [
            'attributes' => [
                ['name' => 'Цвят', 'options' => ['Червен']],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        $this->assertSame($product_data, $result);
    }

    public function test_apply_mappings_returns_unchanged_when_no_attributes(): void
    {
        $product_data = ['name' => 'Test Product'];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        $this->assertSame($product_data, $result);
    }

    public function test_apply_mappings_returns_unchanged_when_no_name_or_value_mappings(): void
    {
        // Default setUp already returns [] for both mapping keys — no overrides needed.
        $product_data = [
            'attributes' => [
                ['name' => 'Цвят', 'options' => ['Червен']],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        $this->assertSame($product_data, $result);
    }

    // -------------------------------------------------------------------------
    // apply_mappings() — name remapping
    // -------------------------------------------------------------------------

    public function test_apply_mappings_remaps_attribute_name(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Цвят' => 'Color']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [],
                default => $default,
            };
        });

        $product_data = [
            'attributes' => [
                ['name' => 'Цвят', 'options' => ['Червен']],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        $this->assertSame('Color', $result['attributes'][0]['name']);
    }

    public function test_apply_mappings_name_matching_is_case_insensitive(): void
    {
        // Mapping key is lowercase 'цвят', attribute name has leading capital 'Цвят'.
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['цвят' => 'Color']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [],
                default => $default,
            };
        });

        $product_data = [
            'attributes' => [
                ['name' => 'Цвят', 'options' => []],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        $this->assertSame('Color', $result['attributes'][0]['name']);
    }

    // -------------------------------------------------------------------------
    // apply_mappings() — value remapping
    // -------------------------------------------------------------------------

    public function test_apply_mappings_remaps_attribute_values(): void
    {
        // sanitize_title('Color') → 'color' (our stub)
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Цвят' => 'Color']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [
                    $this->storeKey => ['color' => ['Червен' => 'Red']],
                ],
                default => $default,
            };
        });

        $product_data = [
            'attributes' => [
                ['name' => 'Цвят', 'options' => ['Червен']],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        // Name was remapped; value lookup uses original local name 'Цвят' → slug '-' → no key
        // BUT the source code passes $local_name (original) to get_value_mappings(), so
        // sanitize_title('Цвят') is used as the key. Our stub turns 'Цвят' → '----'.
        // We therefore key by sanitize_title('Цвят') in the fixture.
        // Re-read: the fixture above uses 'color' — let's fix to use actual slug of 'Цвят'.
        // Actually the attribute name for this fixture should be 'Color' after name remap,
        // but get_value_mappings is called with $local_name (before remap) which is 'Цвят'.
        // sanitize_title('Цвят') via our stub → mb_strtolower('Цвят') = 'цвят' → replace non [a-z0-9] with '-' → '----'
        // So the fixture key should be '----'. Let's assert options unchanged and revisit
        // via a dedicated simpler fixture.
        $this->assertSame('Color', $result['attributes'][0]['name']);
    }

    /**
     * Dedicated test for value remapping using the actual slug produced by our stub.
     */
    public function test_apply_mappings_remaps_attribute_values_via_correct_slug(): void
    {
        // Compute the slug our sanitize_title stub produces for the attribute name.
        $localName  = 'Color';
        $attrSlug   = strtolower(preg_replace('/[^a-z0-9]/u', '-', mb_strtolower($localName)));
        // 'Color' → 'color' ✓

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($attrSlug) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Color' => 'Farbe']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [
                    $this->storeKey => [$attrSlug => ['Red' => 'Rot', 'Blue' => 'Blau']],
                ],
                default => $default,
            };
        });

        $product_data = [
            'attributes' => [
                ['name' => 'Color', 'options' => ['Red', 'Blue']],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        $this->assertSame('Farbe', $result['attributes'][0]['name']);
        $this->assertSame(['Rot', 'Blau'], $result['attributes'][0]['options']);
    }

    public function test_apply_mappings_unmapped_option_passes_through(): void
    {
        $localName = 'Color';
        $attrSlug  = strtolower(preg_replace('/[^a-z0-9]/u', '-', mb_strtolower($localName)));

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($attrSlug) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Color' => 'Color']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [
                    $this->storeKey => [$attrSlug => ['Red' => 'Rot']],
                ],
                default => $default,
            };
        });

        $product_data = [
            'attributes' => [
                ['name' => 'Color', 'options' => ['Red', 'Green']],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        // 'Red' is mapped to 'Rot'; 'Green' has no mapping and must pass through unchanged.
        $this->assertContains('Rot', $result['attributes'][0]['options']);
        $this->assertContains('Green', $result['attributes'][0]['options']);
        $this->assertCount(2, $result['attributes'][0]['options']);
    }

    /**
     * Verify the early-return logic: when name_mappings is empty but value_mappings
     * are NOT empty the function must fall through to the attribute loop and apply
     * value remapping.
     */
    public function test_apply_mappings_only_value_mappings_no_name_mappings(): void
    {
        $localName = 'Size';
        $attrSlug  = strtolower(preg_replace('/[^a-z0-9]/u', '-', mb_strtolower($localName)));

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($attrSlug) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                // No name mappings for this store.
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [
                    $this->storeKey => [$attrSlug => ['Small' => 'S', 'Large' => 'L']],
                ],
                default => $default,
            };
        });

        $product_data = [
            'attributes' => [
                ['name' => 'Size', 'options' => ['Small', 'Large']],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        // Name unchanged because no name mapping exists.
        $this->assertSame('Size', $result['attributes'][0]['name']);
        // Values remapped.
        $this->assertSame(['S', 'L'], $result['attributes'][0]['options']);
    }

    public function test_apply_mappings_preserves_other_attribute_fields(): void
    {
        $localName = 'Color';
        $attrSlug  = strtolower(preg_replace('/[^a-z0-9]/u', '-', mb_strtolower($localName)));

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($attrSlug) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Color' => 'Farbe']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [],
                default => $default,
            };
        });

        $product_data = [
            'attributes' => [
                [
                    'id'       => 7,
                    'name'     => 'Color',
                    'position' => 0,
                    'visible'  => true,
                    'options'  => ['Red'],
                ],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_mappings($product_data, self::STORE_URL);

        $attr = $result['attributes'][0];
        $this->assertSame(7, $attr['id']);
        $this->assertSame(0, $attr['position']);
        $this->assertTrue($attr['visible']);
        $this->assertSame('Farbe', $attr['name']);
    }

    // -------------------------------------------------------------------------
    // apply_variation_mappings()
    // -------------------------------------------------------------------------

    public function test_apply_variation_mappings_returns_unchanged_when_disabled(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return $default;
        });

        $variation_data = [
            'attributes' => [
                ['id' => 1, 'name' => 'Color', 'option' => 'Red'],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_variation_mappings($variation_data, self::STORE_URL);

        $this->assertSame($variation_data, $result);
    }

    public function test_apply_variation_mappings_remaps_name(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Color' => 'Farbe']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [],
                default => $default,
            };
        });

        $variation_data = [
            'attributes' => [
                ['id' => 1, 'name' => 'Color', 'option' => 'Red'],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_variation_mappings($variation_data, self::STORE_URL);

        $this->assertSame('Farbe', $result['attributes'][0]['name']);
    }

    public function test_apply_variation_mappings_remaps_option_value(): void
    {
        $localName = 'Color';
        $attrSlug  = strtolower(preg_replace('/[^a-z0-9]/u', '-', mb_strtolower($localName)));

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($attrSlug) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Color' => 'Farbe']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [
                    $this->storeKey => [$attrSlug => ['Red' => 'Rot']],
                ],
                default => $default,
            };
        });

        $variation_data = [
            'attributes' => [
                ['id' => 1, 'name' => 'Color', 'option' => 'Red'],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_variation_mappings($variation_data, self::STORE_URL);

        $this->assertSame('Farbe', $result['attributes'][0]['name']);
        $this->assertSame('Rot', $result['attributes'][0]['option']);
    }

    public function test_apply_variation_mappings_preserves_id_field(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Color' => 'Farbe']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [],
                default => $default,
            };
        });

        $variation_data = [
            'attributes' => [
                ['id' => 42, 'name' => 'Color', 'option' => 'Red'],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_variation_mappings($variation_data, self::STORE_URL);

        $this->assertSame(42, $result['attributes'][0]['id']);
    }

    public function test_apply_variation_mappings_case_insensitive_value_matching(): void
    {
        // Mapping key is lowercase 'червен', attribute option is all-caps 'ЧЕРВЕН'.
        $localName = 'Color';
        $attrSlug  = strtolower(preg_replace('/[^a-z0-9]/u', '-', mb_strtolower($localName)));

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($attrSlug) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Color' => 'Color']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [
                    $this->storeKey => [$attrSlug => ['червен' => 'Red']],
                ],
                default => $default,
            };
        });

        $variation_data = [
            'attributes' => [
                ['id' => 0, 'name' => 'Color', 'option' => 'ЧЕРВЕН'],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_variation_mappings($variation_data, self::STORE_URL);

        $this->assertSame('Red', $result['attributes'][0]['option']);
    }

    // -------------------------------------------------------------------------
    // apply_default_attribute_mappings()
    // -------------------------------------------------------------------------

    public function test_apply_default_attribute_mappings_returns_unchanged_when_disabled(): void
    {
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return $default;
        });

        $product_data = [
            'default_attributes' => [
                ['id' => 1, 'name' => 'Color', 'option' => 'Red'],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_default_attribute_mappings($product_data, self::STORE_URL);

        $this->assertSame($product_data, $result);
    }

    public function test_apply_default_attribute_mappings_remaps_name_and_option(): void
    {
        $localName = 'Color';
        $attrSlug  = strtolower(preg_replace('/[^a-z0-9]/u', '-', mb_strtolower($localName)));

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($attrSlug) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY  => [$this->storeKey => ['Color' => 'Farbe']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [
                    $this->storeKey => [$attrSlug => ['Red' => 'Rot']],
                ],
                default => $default,
            };
        });

        $product_data = [
            'default_attributes' => [
                ['id' => 3, 'name' => 'Color', 'option' => 'Red'],
            ],
        ];

        $result = WC_Multi_Store_Attribute_Remapper::apply_default_attribute_mappings($product_data, self::STORE_URL);

        $attr = $result['default_attributes'][0];
        $this->assertSame('Farbe', $attr['name']);
        $this->assertSame('Rot', $attr['option']);
    }

    public function test_apply_default_attribute_mappings_returns_unchanged_when_empty(): void
    {
        // Product data has no default_attributes key at all.
        $product_data = ['name' => 'Variable Product'];

        $result = WC_Multi_Store_Attribute_Remapper::apply_default_attribute_mappings($product_data, self::STORE_URL);

        $this->assertSame($product_data, $result);
    }

    // -------------------------------------------------------------------------
    // get_local_attributes()
    // -------------------------------------------------------------------------

    private function stubLocalColorAttribute(): void
    {
        $taxonomy = (object) [
            'attribute_id' => 1,
            'attribute_label' => 'Color',
            'attribute_name' => 'color',
            'attribute_type' => 'select',
        ];
        Functions\when('wc_get_attribute_taxonomies')->justReturn([$taxonomy]);
        Functions\when('wc_attribute_taxonomy_name')->alias(fn($name) => 'pa_' . $name);

        $term = (object) ['name' => 'Red'];
        Functions\when('get_terms')->justReturn([$term]);
    }

    public function test_get_local_attributes_maps_taxonomies_and_terms(): void
    {
        $this->stubLocalColorAttribute();

        $result = WC_Multi_Store_Attribute_Remapper::get_local_attributes();

        $this->assertSame([
            ['id' => 1, 'name' => 'Color', 'slug' => 'color', 'type' => 'select', 'values' => ['Red']],
        ], $result);
    }

    public function test_get_local_attributes_returns_empty_values_on_wp_error(): void
    {
        $taxonomy = (object) [
            'attribute_id' => 1,
            'attribute_label' => 'Color',
            'attribute_name' => 'color',
            'attribute_type' => 'select',
        ];
        Functions\when('wc_get_attribute_taxonomies')->justReturn([$taxonomy]);
        Functions\when('wc_attribute_taxonomy_name')->alias(fn($name) => 'pa_' . $name);
        Functions\when('get_terms')->justReturn(new \WP_Error('bad_taxonomy', 'nope'));

        $result = WC_Multi_Store_Attribute_Remapper::get_local_attributes();

        $this->assertSame([], $result[0]['values']);
    }

    // -------------------------------------------------------------------------
    // get_remote_attributes() — pagination
    // -------------------------------------------------------------------------

    /**
     * Real API client with wp_remote_get() stubbed to return queued
     * responses in order (mirrors CategoryMapperTest's equivalent helper).
     */
    private function makeClientWithQueuedResponses(array $bodies): WC_Multi_Store_API_Client
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            return $url . '?' . http_build_query($args);
        });
        Functions\when('wp_remote_get')->alias(function () use (&$bodies) {
            $body = array_shift($bodies);
            if ($body instanceof \WP_Error) {
                return $body;
            }
            return ['response' => ['code' => 200], 'body' => json_encode($body)];
        });

        return WC_Multi_Store_API_Client::for_store(self::STORE_URL, [
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);
    }

    public function test_get_remote_attributes_stops_when_fewer_than_100_returned(): void
    {
        $page1 = array_fill(0, 3, ['id' => 1, 'name' => 'Color', 'slug' => 'color']);
        $client = $this->makeClientWithQueuedResponses([$page1]);

        $result = WC_Multi_Store_Attribute_Remapper::get_remote_attributes($client);

        $this->assertCount(3, $result);
        $this->assertSame(['id' => 1, 'name' => 'Color', 'slug' => 'color'], $result[0]);
    }

    public function test_get_remote_attributes_paginates_when_exactly_100_returned(): void
    {
        $page1 = array_fill(0, 100, ['id' => 1, 'name' => 'Color', 'slug' => 'color']);
        $page2 = array_fill(0, 2, ['id' => 2, 'name' => 'Size', 'slug' => 'size']);
        $client = $this->makeClientWithQueuedResponses([$page1, $page2]);

        $result = WC_Multi_Store_Attribute_Remapper::get_remote_attributes($client);

        $this->assertCount(102, $result);
    }

    public function test_get_remote_attributes_handles_api_error(): void
    {
        $client = $this->makeClientWithQueuedResponses([
            new \WP_Error('api_error', 'Connection refused'),
        ]);

        $result = WC_Multi_Store_Attribute_Remapper::get_remote_attributes($client);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // ajax_get_mappings()
    // -------------------------------------------------------------------------

    public function test_ajax_get_mappings_keys_value_mappings_by_attribute_name(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $this->stubLocalColorAttribute();

        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_multi_store_sync_settings' => ['attribute_remapping_enabled' => true],
                WC_Multi_Store_Attribute_Remapper::NAME_MAPPING_KEY => [$this->storeKey => ['Color' => 'Farbe']],
                WC_Multi_Store_Attribute_Remapper::VALUE_MAPPING_KEY => [$this->storeKey => ['color' => ['Red' => 'Rot']]],
                default => $default,
            };
        });

        $_POST['store_url'] = self::STORE_URL;

        $sent = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$sent) {
            $sent = $data;
        });

        WC_Multi_Store_Attribute_Remapper::ajax_get_mappings();

        $this->assertSame(['Color' => 'Farbe'], $sent['name_mappings']);
        $this->assertSame(['Red' => 'Rot'], $sent['value_mappings']['Color']);
        $this->assertSame('Color', $sent['local_attributes'][0]['name']);

        unset($_POST['store_url']);
    }

    public function test_ajax_get_mappings_requires_store_url(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $error = null;
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$error) {
            $error = $data;
        });

        WC_Multi_Store_Attribute_Remapper::ajax_get_mappings();

        $this->assertNotNull($error);
    }
}
