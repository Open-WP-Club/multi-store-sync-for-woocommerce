<?php
/**
 * Concrete Toggleable_Feature bits for the category mapper and attribute
 * remapper: metadata, prefixed central settings keys and AJAX toggle output.
 */

use Brain\Monkey\Functions;

class FeatureModulesToggleTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
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
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public static function modules(): array
    {
        return [
            'category mapper' => [WC_Multi_Store_Category_Mapper::class, 'category_mapper', 'Category mapping'],
            'attribute remapper' => [WC_Multi_Store_Attribute_Remapper::class, 'attribute_remapping', 'Attribute remapping'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modules')]
    public function test_metadata(string $class, string $prefix, string $label): void
    {
        $this->assertSame(['enabled' => false], $class::default_settings());
        $this->assertSame($prefix, $class::central_settings_prefix());
        $this->assertSame($label, $class::feature_label());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modules')]
    public function test_disabled_by_default_and_get_settings_returns_default(string $class, string $prefix, string $label): void
    {
        $this->assertFalse($class::is_enabled());
        $this->assertSame(['enabled' => false], $class::get_settings());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modules')]
    public function test_update_settings_writes_prefixed_key_and_keeps_unrelated_settings(string $class, string $prefix, string $label): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['unrelated' => 'kept'];

        $class::update_settings(['enabled' => true]);

        $this->assertTrue($this->options['wc_multi_store_sync_settings'][$prefix . '_enabled']);
        $this->assertSame('kept', $this->options['wc_multi_store_sync_settings']['unrelated']);
        $this->assertTrue($class::is_enabled());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modules')]
    public function test_ajax_toggle_enable_and_disable_messages(string $class, string $prefix, string $label): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $payloads = [];
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$payloads) {
            $payloads[] = $data;
        });

        $_POST = ['enabled' => '1'];
        $class::ajax_toggle();
        $_POST = ['enabled' => '0'];
        $class::ajax_toggle();

        $this->assertSame([
            ['message' => $label . ' enabled', 'enabled' => true],
            ['message' => $label . ' disabled', 'enabled' => false],
        ], $payloads);
        $this->assertFalse($this->options['wc_multi_store_sync_settings'][$prefix . '_enabled']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modules')]
    public function test_ajax_toggle_requires_manage_woocommerce_and_changes_nothing(string $class, string $prefix, string $label): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        $capability = null;
        Functions\when('current_user_can')->alias(function ($cap) use (&$capability) {
            $capability = $cap;
            return false;
        });
        $errors = [];
        Functions\when('wp_send_json_error')->alias(function ($data) use (&$errors) {
            $errors[] = $data;
        });
        Functions\when('wp_send_json_success')->alias(function () {
            $this->fail('Must not report success for an unauthorized user');
        });

        $_POST = ['enabled' => '1'];
        $class::ajax_toggle();

        $this->assertSame('manage_woocommerce', $capability);
        $this->assertSame([['message' => 'Unauthorized']], $errors);
        $this->assertSame([], $this->options);
    }
}
