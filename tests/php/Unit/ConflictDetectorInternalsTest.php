<?php
/**
 * Tests for WC_Multi_Store_Conflict_Detector private helpers:
 * extract_tracked_fields(), calculate_hash() and send_ajax_error().
 */

use Brain\Monkey\Functions;

class ConflictDetectorInternalsTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_json_encode')->alias(fn($d, $f = 0) => json_encode($d, $f));
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(WC_Multi_Store_Conflict_Detector::class, $method))->invoke(null, ...$args);
    }

    private function full_product(): array
    {
        return [
            'name'              => 'Widget',
            'description'       => '<p>Long</p>',
            'short_description' => 'Short',
            'regular_price'     => '19.99',
            'sale_price'        => '',
            'sku'               => 'W-1',
            'stock_quantity'    => 0,
            'stock_status'      => 'outofstock',
            'status'            => 'publish',
            'weight'            => '0',
            'categories'        => [['id' => 3, 'name' => 'Tools']],
        ];
    }

    public function test_tracked_fields_constant_lists_the_compared_fields(): void
    {
        $this->assertSame(
            ['name', 'description', 'short_description', 'regular_price', 'sale_price', 'sku', 'stock_quantity', 'stock_status', 'status', 'weight', 'categories'],
            WC_Multi_Store_Conflict_Detector::TRACKED_FIELDS
        );
    }

    public function test_extract_tracked_fields_returns_every_tracked_key_in_constant_order(): void
    {
        $result = $this->call('extract_tracked_fields', array_reverse($this->full_product(), true));

        $this->assertSame(WC_Multi_Store_Conflict_Detector::TRACKED_FIELDS, array_keys($result));
        $this->assertSame($this->full_product(), $result);
    }

    public function test_extract_tracked_fields_drops_untracked_keys(): void
    {
        $product = $this->full_product() + ['id' => 99, 'date_modified' => '2024-01-01', 'meta_data' => [['k' => 'v']], 'price' => '19.99'];

        $result = $this->call('extract_tracked_fields', $product);

        foreach (['id', 'date_modified', 'meta_data', 'price'] as $untracked) {
            $this->assertArrayNotHasKey($untracked, $result);
        }
        $this->assertCount(count(WC_Multi_Store_Conflict_Detector::TRACKED_FIELDS), $result);
    }

    public function test_extract_tracked_fields_fills_missing_keys_with_null(): void
    {
        $result = $this->call('extract_tracked_fields', ['name' => 'Only name']);

        $this->assertSame('Only name', $result['name']);
        $this->assertNull($result['sku']);
        $this->assertNull($result['categories']);
        $this->assertCount(11, $result);
    }

    public function test_extract_tracked_fields_of_empty_product_is_all_null(): void
    {
        $result = $this->call('extract_tracked_fields', []);

        $this->assertSame(array_fill_keys(WC_Multi_Store_Conflict_Detector::TRACKED_FIELDS, null), $result);
    }

    public function test_extract_tracked_fields_preserves_falsy_values_instead_of_nulling_them(): void
    {
        $result = $this->call('extract_tracked_fields', ['stock_quantity' => 0, 'weight' => '0', 'sale_price' => '', 'categories' => []]);

        $this->assertSame(0, $result['stock_quantity']);
        $this->assertSame('0', $result['weight']);
        $this->assertSame('', $result['sale_price']);
        $this->assertSame([], $result['categories']);
    }

    public function test_extract_tracked_fields_treats_explicit_null_like_missing(): void
    {
        $result = $this->call('extract_tracked_fields', ['sku' => null]);

        $this->assertNull($result['sku']);
    }

    public function test_calculate_hash_ignores_untracked_changes_but_reacts_to_tracked_ones(): void
    {
        $base = $this->full_product();
        $hash = $this->call('calculate_hash', $base);

        $this->assertSame($hash, $this->call('calculate_hash', $base + ['date_modified' => 'later', 'id' => 5]));
        $this->assertSame($hash, $this->call('calculate_hash', array_reverse($base, true)), 'Key order of the input must not matter');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash);

        foreach (['name' => 'Gadget', 'stock_quantity' => 1, 'categories' => [['id' => 4, 'name' => 'Other']]] as $field => $value) {
            $changed = $this->call('calculate_hash', [$field => $value] + $base);
            $this->assertNotSame($hash, $changed, "Changing $field must change the hash");
        }
    }

    public function test_calculate_hash_distinguishes_missing_from_empty_string(): void
    {
        $this->assertNotSame(
            $this->call('calculate_hash', ['sale_price' => '']),
            $this->call('calculate_hash', [])
        );
    }

    public function test_send_ajax_error_forwards_payload_unchanged_to_json_error(): void
    {
        $sent = [];
        Functions\when('wp_send_json_error')->alias(function (...$args) use (&$sent) {
            $sent[] = $args;
        });

        $this->call('send_ajax_error', ['message' => 'Nope', 'code' => 42, 'nested' => ['a' => 1]]);

        $this->assertSame([[['message' => 'Nope', 'code' => 42, 'nested' => ['a' => 1]]]], $sent);
    }

    public function test_send_ajax_error_with_empty_payload_still_sends_one_error(): void
    {
        $sent = [];
        Functions\when('wp_send_json_error')->alias(function (...$args) use (&$sent) {
            $sent[] = $args;
        });

        $this->call('send_ajax_error', []);

        $this->assertSame([[[]]], $sent);
    }
}
