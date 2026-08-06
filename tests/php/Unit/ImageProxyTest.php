<?php
/**
 * Unit tests for WC_Multi_Store_Image_Proxy
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ImageProxyTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear Settings' static cache to avoid stale data from previous tests
        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
    }

    // ─── Class structure ───────────────────────────

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Multi_Store_Image_Proxy'));
    }

    public function test_has_get_image_data_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Image_Proxy', 'get_image_data'));
    }

    public function test_has_is_enabled_method(): void
    {
        $this->assertTrue(method_exists('WC_Multi_Store_Image_Proxy', 'is_enabled'));
    }

    // ─── is_enabled ────────────────────────────────

    public function test_is_enabled_returns_false_by_default(): void
    {
        // WC_Multi_Store_Settings::get() internally calls get_option
        // With default settings, image_proxy_enabled is false
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') {
                return []; // No image_proxy_enabled key
            }
            return $default;
        });

        $this->assertFalse(WC_Multi_Store_Image_Proxy::is_enabled());
    }

    public function test_is_enabled_returns_true_when_setting_enabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wc_multi_store_sync_settings') {
                return ['image_proxy_enabled' => true];
            }
            return $default;
        });

        $this->assertTrue(WC_Multi_Store_Image_Proxy::is_enabled());
    }

    // ─── get_image_data ────────────────────────────

    public function test_get_image_data_returns_null_when_no_file(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('get_attached_file')->justReturn(false);
        Functions\when('wp_get_attachment_url')->justReturn(false);

        $result = WC_Multi_Store_Image_Proxy::get_image_data(123);

        $this->assertNull($result);
    }

    public function test_get_image_data_returns_null_for_nonexistent_file(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('get_attached_file')->justReturn('/tmp/nonexistent-file.jpg');
        Functions\when('wp_get_attachment_url')->justReturn(false);

        $result = WC_Multi_Store_Image_Proxy::get_image_data(123);

        $this->assertNull($result);
    }

    public function test_get_image_data_returns_null_for_non_image_mime(): void
    {
        // Create a temporary file to pass the file_exists check
        $tmp = tempnam(sys_get_temp_dir(), 'test_');

        Functions\when('get_attached_file')->justReturn($tmp);
        Functions\when('get_post_mime_type')->justReturn('application/pdf');

        $result = WC_Multi_Store_Image_Proxy::get_image_data(123);

        $this->assertNull($result);

        unlink($tmp);
    }

    public function test_get_image_data_returns_null_for_null_mime(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_');

        Functions\when('get_attached_file')->justReturn($tmp);
        Functions\when('get_post_mime_type')->justReturn(null);

        $result = WC_Multi_Store_Image_Proxy::get_image_data(123);

        $this->assertNull($result);

        unlink($tmp);
    }

    public function test_get_image_data_returns_data_for_valid_image(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_img_');
        file_put_contents($tmp, 'fake-image-data');

        Functions\when('get_attached_file')->justReturn($tmp);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');

        $result = WC_Multi_Store_Image_Proxy::get_image_data(123);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('file_path', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('mime_type', $result);
        $this->assertEquals($tmp, $result['file_path']);
        $this->assertEquals(basename($tmp), $result['filename']);
        $this->assertEquals('image/jpeg', $result['mime_type']);

        unlink($tmp);
    }

    public function test_get_image_data_supports_png(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png_');
        file_put_contents($tmp, 'fake-png');

        Functions\when('get_attached_file')->justReturn($tmp);
        Functions\when('get_post_mime_type')->justReturn('image/png');

        $result = WC_Multi_Store_Image_Proxy::get_image_data(456);

        $this->assertNotNull($result);
        $this->assertEquals('image/png', $result['mime_type']);

        unlink($tmp);
    }

    public function test_get_image_data_supports_webp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'webp_');
        file_put_contents($tmp, 'fake-webp');

        Functions\when('get_attached_file')->justReturn($tmp);
        Functions\when('get_post_mime_type')->justReturn('image/webp');

        $result = WC_Multi_Store_Image_Proxy::get_image_data(789);

        $this->assertNotNull($result);
        $this->assertEquals('image/webp', $result['mime_type']);

        unlink($tmp);
    }

    // ─── Static method signatures ──────────────────

    public function test_get_image_data_is_static(): void
    {
        $reflection = new ReflectionMethod('WC_Multi_Store_Image_Proxy', 'get_image_data');
        $this->assertTrue($reflection->isStatic());
    }

    public function test_is_enabled_is_static(): void
    {
        $reflection = new ReflectionMethod('WC_Multi_Store_Image_Proxy', 'is_enabled');
        $this->assertTrue($reflection->isStatic());
    }

    public function test_get_image_data_accepts_int_parameter(): void
    {
        $reflection = new ReflectionMethod('WC_Multi_Store_Image_Proxy', 'get_image_data');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('attachment_id', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());
    }
}
