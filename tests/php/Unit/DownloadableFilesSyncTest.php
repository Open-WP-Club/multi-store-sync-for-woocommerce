<?php
/**
 * Unit tests for WC_Multi_Store_Downloadable_Files_Sync
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

if (!defined('MB_IN_BYTES')) {
    define('MB_IN_BYTES', 1048576);
}

if (!class_exists('WC_Product_Download')) {
    class WC_Product_Download {
        private string $name = '';
        private string $file = '';
        public function get_name(): string { return $this->name; }
        public function get_file(): string { return $this->file; }
        public function set_name(string $n): void { $this->name = $n; }
        public function set_file(string $f): void { $this->file = $f; }
    }
}

class DownloadableFilesSyncTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WC_Multi_Store_Settings::clear_static_cache();

        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_mss_downloadable_files_sync_settings' => ['enabled' => true, 'transfer_mode' => 'url'],
                'wc_multi_store_sync_settings'            => ['enabled' => true, 'auth_method' => 'basic_auth'],
                'wc_multi_store_sync_stores'              => ['https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'store_url' => 'https://store1.com']],
                default                                   => $default,
            };
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('site_url')->justReturn('http://test.com');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function makeDownload(string $name, string $file): WC_Product_Download
    {
        $d = new WC_Product_Download();
        $d->set_name($name);
        $d->set_file($file);
        return $d;
    }

    private function makeDownloadableProduct(array $downloads = [], int $downloadLimit = -1, int $downloadExpiry = -1): \Mockery\MockInterface
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_downloadable')->andReturn(true);
        $product->shouldReceive('get_downloads')->andReturn($downloads);
        $product->shouldReceive('get_download_limit')->andReturn($downloadLimit);
        $product->shouldReceive('get_download_expiry')->andReturn($downloadExpiry);
        return $product;
    }

    /**
     * Returns the WC_MSS_Test_API_Client_Stub defined in CouponSyncTest.
     * Both test files load in the same PHP process, so the class is already available.
     * If for any reason it is not yet loaded (e.g. isolated run), we fall back to
     * a minimal anonymous stub.
     */
    private function makeClient(): object
    {
        if (class_exists('WC_MSS_Test_API_Client_Stub', false)) {
            return new WC_MSS_Test_API_Client_Stub();
        }

        // Minimal inline stub — identical interface, used when running this file alone.
        return new class extends WC_Multi_Store_API_Client {
            public ?\Closure $get_handler    = null;
            public ?\Closure $post_handler   = null;
            public ?\Closure $put_handler    = null;
            public ?\Closure $delete_handler = null;

            public function __construct() {}

            public function get(string $endpoint, array $params = []): array|\WP_Error
            {
                return ($this->get_handler)($endpoint, $params);
            }

            public function post(string $endpoint, array $data = []): array|\WP_Error
            {
                return ($this->post_handler)($endpoint, $data);
            }

            public function put(string $endpoint, array $data = []): array|\WP_Error
            {
                return ($this->put_handler)($endpoint, $data);
            }

            public function delete(string $endpoint, array $params = []): array|\WP_Error
            {
                return ($this->delete_handler)($endpoint, $params);
            }
        };
    }

    // ─── is_enabled() ─────────────────────────────────────────────────────────

    public function test_is_enabled_returns_false_by_default(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return $opt === 'wc_mss_downloadable_files_sync_settings' ? ['enabled' => false] : $default;
        });

        $this->assertFalse(WC_Multi_Store_Downloadable_Files_Sync::is_enabled());
    }

    public function test_is_enabled_returns_true_when_enabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return $opt === 'wc_mss_downloadable_files_sync_settings' ? ['enabled' => true] : $default;
        });

        $this->assertTrue(WC_Multi_Store_Downloadable_Files_Sync::is_enabled());
    }

    // ─── extract_downloads() ──────────────────────────────────────────────────

    public function test_extract_returns_empty_when_disabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return $opt === 'wc_mss_downloadable_files_sync_settings' ? ['enabled' => false] : $default;
        });

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_downloadable')->never();

        $result = WC_Multi_Store_Downloadable_Files_Sync::extract_downloads($product);

        $this->assertSame([], $result);
    }

    public function test_extract_returns_empty_when_product_not_downloadable(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_downloadable')->andReturn(false);

        $result = WC_Multi_Store_Downloadable_Files_Sync::extract_downloads($product);

        $this->assertSame([], $result);
    }

    public function test_extract_returns_empty_when_no_downloads(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_downloadable')->andReturn(true);
        $product->shouldReceive('get_downloads')->andReturn([]);

        $result = WC_Multi_Store_Downloadable_Files_Sync::extract_downloads($product);

        $this->assertSame([], $result);
    }

    public function test_extract_returns_basic_file_data_in_url_mode(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return $opt === 'wc_mss_downloadable_files_sync_settings'
                ? ['enabled' => true, 'transfer_mode' => 'url']
                : $default;
        });

        $dl      = $this->makeDownload('My eBook', 'https://example.com/uploads/ebook.pdf');
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_downloadable')->andReturn(true);
        $product->shouldReceive('get_downloads')->andReturn(['abc123' => $dl]);

        $result = WC_Multi_Store_Downloadable_Files_Sync::extract_downloads($product);

        $this->assertCount(1, $result);
        $this->assertSame('My eBook', $result[0]['name']);
        $this->assertSame('https://example.com/uploads/ebook.pdf', $result[0]['file']);
        $this->assertArrayNotHasKey('_wc_mss_file_content', $result[0]);
    }

    public function test_extract_skips_files_over_10mb_in_api_mode(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return $opt === 'wc_mss_downloadable_files_sync_settings'
                ? ['enabled' => true, 'transfer_mode' => 'api']
                : $default;
        });

        // Create a real file that is > 10 MB so filesize() returns the correct value
        // without needing to stub a PHP built-in that Patchwork cannot intercept in
        // production code from a test body.
        $localPath = tempnam(sys_get_temp_dir(), 'wc_mss_large_');
        $this->assertNotFalse($localPath);
        // Write just over 10 MB of content (10 * 1024 * 1024 + 1 bytes)
        $fp = fopen($localPath, 'wb');
        fseek($fp, 10 * MB_IN_BYTES);
        fwrite($fp, "\0");
        fclose($fp);

        $dl = $this->makeDownload('Big Archive', $localPath);

        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_downloadable')->andReturn(true);
        $product->shouldReceive('get_downloads')->andReturn(['def456' => $dl]);

        $result = WC_Multi_Store_Downloadable_Files_Sync::extract_downloads($product);

        @unlink($localPath);

        // File is too large — the entry must be skipped entirely
        $this->assertSame([], $result);
    }

    // ─── sync_downloads() ─────────────────────────────────────────────────────

    public function test_sync_downloads_returns_unchanged_when_not_downloadable(): void
    {
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_downloadable')->andReturn(false);

        $client      = $this->makeClient();
        $productData = ['sku' => 'PROD-001', 'regular_price' => '9.99'];

        $result = WC_Multi_Store_Downloadable_Files_Sync::sync_downloads(
            $client,
            $product,
            $productData,
            'https://store1.com'
        );

        $this->assertSame($productData, $result);
    }

    public function test_sync_downloads_adds_download_fields_to_product_data(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_mss_downloadable_files_sync_settings' => ['enabled' => true, 'transfer_mode' => 'url'],
                'wc_multi_store_sync_settings'            => ['enabled' => true, 'auth_method' => 'basic_auth'],
                default                                   => $default,
            };
        });

        $dl      = $this->makeDownload('Guide PDF', 'https://example.com/uploads/guide.pdf');
        $product = $this->makeDownloadableProduct(['abc' => $dl], 3, 30);

        $client = $this->makeClient();

        $result = WC_Multi_Store_Downloadable_Files_Sync::sync_downloads(
            $client,
            $product,
            ['sku' => 'GUIDE-001'],
            'https://store1.com'
        );

        $this->assertTrue($result['downloadable']);
        $this->assertArrayHasKey('downloads', $result);
        $this->assertArrayHasKey('download_limit', $result);
        $this->assertArrayHasKey('download_expiry', $result);
        $this->assertSame(3, $result['download_limit']);
        $this->assertSame(30, $result['download_expiry']);
    }

    public function test_sync_downloads_uses_original_url_when_transfer_disabled(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_mss_downloadable_files_sync_settings' => ['enabled' => true, 'transfer_mode' => 'url'],
                // download_files_transfer_enabled NOT present → transfer disabled
                'wc_multi_store_sync_settings'            => ['enabled' => true, 'auth_method' => 'basic_auth'],
                default                                   => $default,
            };
        });

        $originalUrl = 'https://example.com/uploads/ebook.pdf';
        $dl          = $this->makeDownload('eBook', $originalUrl);
        $product     = $this->makeDownloadableProduct(['dl1' => $dl]);

        $client = $this->makeClient();

        $result = WC_Multi_Store_Downloadable_Files_Sync::sync_downloads(
            $client,
            $product,
            ['sku' => 'EBOOK-001'],
            'https://store1.com'
        );

        $this->assertArrayHasKey('downloads', $result);
        $this->assertSame($originalUrl, $result['downloads'][0]['file']);
    }

    public function test_sync_downloads_falls_back_to_url_when_upload_fails(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                'wc_mss_downloadable_files_sync_settings' => ['enabled' => true, 'transfer_mode' => 'api'],
                // transfer enabled but upload_file_to_remote will effectively return null
                // because _wc_mss_file_content won't be populated (file_exists = false)
                'wc_multi_store_sync_settings'            => ['enabled' => true, 'auth_method' => 'basic_auth', 'download_files_transfer_enabled' => true],
                default                                   => $default,
            };
        });

        $originalUrl = 'https://example.com/uploads/report.pdf';
        $dl          = $this->makeDownload('Report', $originalUrl);
        $product     = $this->makeDownloadableProduct(['dl2' => $dl]);

        // file_exists returns false → resolve_download_path returns null
        // → _wc_mss_file_content is never set → transfer branch skipped → original URL used
        Functions\when('file_exists')->alias(fn($p) => false);

        $client = $this->makeClient();

        $result = WC_Multi_Store_Downloadable_Files_Sync::sync_downloads(
            $client,
            $product,
            ['sku' => 'RPT-001'],
            'https://store1.com'
        );

        $this->assertArrayHasKey('downloads', $result);
        $this->assertSame($originalUrl, $result['downloads'][0]['file']);
    }

    // ─── resolve_download_path() tested indirectly via extract_downloads() ─────

    public function test_resolve_download_path_via_extract_in_api_mode(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();

        $uploadBase    = sys_get_temp_dir() . '/wc-mss-tests';
        $uploadBaseUrl = 'http://test.com/uploads';
        $relPath       = '/2024/01/sample.pdf';
        $localFullPath = $uploadBase . $relPath;
        $fileUrl       = $uploadBaseUrl . $relPath;

        Functions\when('get_option')->alias(function ($opt, $default = null) use ($uploadBase, $uploadBaseUrl) {
            return $opt === 'wc_mss_downloadable_files_sync_settings'
                ? ['enabled' => true, 'transfer_mode' => 'api']
                : $default;
        });

        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $uploadBase,
            'baseurl' => $uploadBaseUrl,
        ]);

        // resolve_download_path: file_exists($fileUrl) → false, then file_exists($localFullPath) → true
        // filesize is within the 10 MB limit → entry reaches the file-read branch
        Functions\when('file_exists')->alias(fn($p) => $p === $localFullPath);
        Functions\when('filesize')->alias(fn($p) => 512);
        Functions\when('wp_check_filetype')->justReturn(['type' => 'application/pdf']);

        $dl      = $this->makeDownload('Report', $fileUrl);
        $product = \Mockery::mock('WC_Product');
        $product->shouldReceive('is_downloadable')->andReturn(true);
        $product->shouldReceive('get_downloads')->andReturn(['doc1' => $dl]);

        // file_get_contents is a PHP built-in that Brain Monkey cannot stub.
        // The local path doesn't exist on disk so it returns false, which
        // causes the content branch to be skipped — but the entry itself
        // is still present in the output (just without embedded content).
        $result = WC_Multi_Store_Downloadable_Files_Sync::extract_downloads($product);

        // The file was resolved from the upload URL to a local path. Even if the
        // actual file read fails, the entry should appear (without _wc_mss_file_content).
        $this->assertCount(1, $result);
        $this->assertSame('Report', $result[0]['name']);
        $this->assertSame($fileUrl, $result[0]['file']);
        $this->assertArrayNotHasKey('_wc_mss_file_content', $result[0]);
    }
}
