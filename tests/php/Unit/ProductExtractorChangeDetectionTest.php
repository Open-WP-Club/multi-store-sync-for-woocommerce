<?php
/**
 * Stateful tests for the change-detection hashes in WC_Multi_Store_Product_Extractor.
 *
 * Post meta is backed by an in-memory array so the save -> have_changed round trip
 * is exercised end to end, instead of stubbing each get_post_meta() call.
 */

use Brain\Monkey\Functions;

class ProductExtractorChangeDetectionTest extends WC_Multi_Store_TestCase
{
    private const STORE_A = 'https://a.example.com';
    private const STORE_B = 'https://b.example.com';

    private WC_Multi_Store_Product_Extractor $extractor;

    /** @var array<string, mixed> keyed "{id}:{meta_key}" */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->meta = [];
        Functions\when('get_post_meta')->alias(fn($id, $key, $single = false) => $this->meta["$id:$key"] ?? '');
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta["$id:$key"] = $value;
            return true;
        });
        Functions\when('_prime_post_caches')->justReturn(null);
        Functions\when('wp_get_attachment_url')->alias(fn($id) => "https://cdn.example.com/uploads/img-$id.jpg");

        $this->extractor = new WC_Multi_Store_Product_Extractor();
    }

    /**
     * @param array<string, mixed> $o
     */
    private function product(array $o = []): \Mockery\MockInterface
    {
        $o += [
            'id' => 10, 'cats' => [3, 1, 2], 'tags' => [], 'desc' => 'D', 'short' => 'S',
            'image' => 0, 'gallery' => [], 'attrs' => [], 'type' => 'simple', 'defaults' => [],
        ];
        $p = \Mockery::mock('WC_Product');
        $p->shouldReceive('get_id')->andReturn($o['id']);
        $p->shouldReceive('get_category_ids')->andReturn($o['cats']);
        $p->shouldReceive('get_tag_ids')->andReturn($o['tags']);
        $p->shouldReceive('get_description')->andReturn($o['desc']);
        $p->shouldReceive('get_short_description')->andReturn($o['short']);
        $p->shouldReceive('get_image_id')->andReturn($o['image']);
        $p->shouldReceive('get_gallery_image_ids')->andReturn($o['gallery']);
        $p->shouldReceive('get_attributes')->andReturn($o['attrs']);
        $p->shouldReceive('is_type')->andReturnUsing(fn($t) => $t === $o['type']);
        $p->shouldReceive('get_default_attributes')->andReturn($o['defaults']);
        return $p;
    }

    private function attribute(string $name, array $options, bool $visible = true, bool $variation = false): \Mockery\MockInterface
    {
        $a = \Mockery::mock('WC_Product_Attribute');
        $a->shouldReceive('get_name')->andReturn($name);
        $a->shouldReceive('get_options')->andReturn($options);
        $a->shouldReceive('get_visible')->andReturn($visible);
        $a->shouldReceive('get_variation')->andReturn($variation);
        return $a;
    }

    public function test_everything_reports_changed_before_first_sync(): void
    {
        $p = $this->product(['attrs' => ['pa_color' => $this->attribute('pa_color', [1])]]);

        $this->assertTrue($this->extractor->have_categories_changed($p, self::STORE_A));
        $this->assertTrue($this->extractor->have_tags_changed($p, self::STORE_A));
        $this->assertTrue($this->extractor->have_description_changed($p, self::STORE_A));
        $this->assertTrue($this->extractor->have_attributes_changed($p, self::STORE_A));
        $this->assertTrue($this->extractor->have_images_changed($p, self::STORE_A));
    }

    public function test_save_all_then_nothing_changed(): void
    {
        $p = $this->product([
            'image' => 5, 'gallery' => [6], 'tags' => [9],
            'attrs' => ['pa_color' => $this->attribute('pa_color', [1, 2])],
        ]);

        $this->extractor->save_all_sync_hashes($p, self::STORE_A);

        $this->assertFalse($this->extractor->have_categories_changed($p, self::STORE_A));
        $this->assertFalse($this->extractor->have_tags_changed($p, self::STORE_A));
        $this->assertFalse($this->extractor->have_description_changed($p, self::STORE_A));
        $this->assertFalse($this->extractor->have_attributes_changed($p, self::STORE_A));
        $this->assertFalse($this->extractor->have_images_changed($p, self::STORE_A));
    }

    public function test_hashes_are_isolated_per_store(): void
    {
        $p = $this->product();
        $this->extractor->save_all_sync_hashes($p, self::STORE_A);

        $this->assertFalse($this->extractor->have_categories_changed($p, self::STORE_A));
        $this->assertTrue($this->extractor->have_categories_changed($p, self::STORE_B));
    }

    public function test_hashes_are_isolated_per_product(): void
    {
        $this->extractor->save_all_sync_hashes($this->product(['id' => 10]), self::STORE_A);

        $this->assertTrue($this->extractor->have_categories_changed($this->product(['id' => 11]), self::STORE_A));
    }

    public function test_category_order_does_not_count_as_a_change(): void
    {
        $this->extractor->save_synced_categories_hash(10, self::STORE_A, [1, 2, 3]);

        $this->assertFalse($this->extractor->have_categories_changed($this->product(['cats' => [3, 2, 1]]), self::STORE_A));
        $this->assertTrue($this->extractor->have_categories_changed($this->product(['cats' => [3, 2]]), self::STORE_A));
    }

    public function test_tag_order_does_not_count_as_a_change(): void
    {
        $this->extractor->save_synced_tags_hash(10, self::STORE_A, [7, 5]);

        $this->assertFalse($this->extractor->have_tags_changed($this->product(['tags' => [5, 7]]), self::STORE_A));
        $this->assertTrue($this->extractor->have_tags_changed($this->product(['tags' => [5, 7, 8]]), self::STORE_A));
    }

    public function test_description_and_short_description_are_tracked_independently(): void
    {
        $this->extractor->save_synced_description_hash(10, self::STORE_A, 'D', 'S');

        $this->assertFalse($this->extractor->have_description_changed($this->product(), self::STORE_A));
        $this->assertTrue($this->extractor->have_description_changed($this->product(['desc' => 'D2']), self::STORE_A));
        $this->assertTrue($this->extractor->have_description_changed($this->product(['short' => 'S2']), self::STORE_A));
        // Swapping the two fields must not collide with the original.
        $this->assertTrue($this->extractor->have_description_changed($this->product(['desc' => 'S', 'short' => 'D']), self::STORE_A));
    }

    public function test_image_change_is_detected_for_main_gallery_and_order(): void
    {
        $this->extractor->save_synced_images_hash(10, self::STORE_A, ['img-5.jpg', 'img-6.jpg']);

        $this->assertFalse($this->extractor->have_images_changed($this->product(['image' => 5, 'gallery' => [6]]), self::STORE_A));
        $this->assertTrue($this->extractor->have_images_changed($this->product(['image' => 5, 'gallery' => [6, 7]]), self::STORE_A));
        $this->assertTrue($this->extractor->have_images_changed($this->product(['image' => 6, 'gallery' => [5]]), self::STORE_A));
        $this->assertTrue($this->extractor->have_images_changed($this->product(['image' => 0, 'gallery' => []]), self::STORE_A));
    }

    public function test_attribute_signature_detects_option_visibility_and_variation_changes(): void
    {
        $base = $this->product(['attrs' => ['pa_size' => $this->attribute('pa_size', [1, 2])]]);
        $this->extractor->save_synced_attributes_hash($base, self::STORE_A);

        $same = $this->product(['attrs' => ['pa_size' => $this->attribute('pa_size', [1, 2])]]);
        $this->assertFalse($this->extractor->have_attributes_changed($same, self::STORE_A));

        foreach ([
            'options' => $this->attribute('pa_size', [1, 2, 3]),
            'visibility' => $this->attribute('pa_size', [1, 2], false),
            'variation' => $this->attribute('pa_size', [1, 2], true, true),
            'name' => $this->attribute('pa_other', [1, 2]),
        ] as $what => $attr) {
            $changed = $this->product(['attrs' => ['pa_size' => $attr]]);
            $this->assertTrue($this->extractor->have_attributes_changed($changed, self::STORE_A), "Attribute $what change must be detected");
        }

        $removed = $this->product(['attrs' => []]);
        $this->assertTrue($this->extractor->have_attributes_changed($removed, self::STORE_A));
    }

    public function test_default_attributes_only_count_for_variable_products(): void
    {
        $simple = $this->product(['type' => 'simple', 'defaults' => ['pa_size' => 'm']]);
        $simpleOther = $this->product(['type' => 'simple', 'defaults' => ['pa_size' => 'l']]);
        $this->extractor->save_synced_attributes_hash($simple, self::STORE_A);
        $this->assertFalse($this->extractor->have_attributes_changed($simpleOther, self::STORE_A));

        $variable = $this->product(['id' => 20, 'type' => 'variable', 'defaults' => ['pa_size' => 'm']]);
        $variableOther = $this->product(['id' => 20, 'type' => 'variable', 'defaults' => ['pa_size' => 'l']]);
        $this->extractor->save_synced_attributes_hash($variable, self::STORE_A);
        $this->assertTrue($this->extractor->have_attributes_changed($variableOther, self::STORE_A));
    }

    public function test_legacy_array_attributes_are_supported_in_signature(): void
    {
        $withScalar = $this->product(['attrs' => ['color' => 'red']]);
        $this->extractor->save_synced_attributes_hash($withScalar, self::STORE_A);

        $this->assertFalse($this->extractor->have_attributes_changed($withScalar, self::STORE_A));
        $this->assertTrue($this->extractor->have_attributes_changed($this->product(['attrs' => ['color' => 'blue']]), self::STORE_A));
    }

    public function test_meta_keys_use_store_url_hash_and_prefix(): void
    {
        $this->extractor->save_synced_categories_hash(10, self::STORE_A, [1]);

        $this->assertArrayHasKey('10:_mss_cats_hash_' . md5(self::STORE_A), $this->meta);
        $this->assertSame(md5(serialize([1])), $this->meta['10:_mss_cats_hash_' . md5(self::STORE_A)]);
    }
}
