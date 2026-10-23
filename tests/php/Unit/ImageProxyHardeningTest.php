<?php
/**
 * Adversarial tests for WC_Multi_Store_Image_Proxy::get_image_data().
 *
 * The result is later read from disk or fetched over HTTP by the API client
 * and pushed to a remote store, so the mime gate, the local-vs-remote
 * decision and the derived filename all matter.
 */

use Brain\Monkey\Functions;

class ImageProxyHardeningTest extends WC_Multi_Store_TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function tempImage(string $suffix = '.jpg'): string
    {
        $path = sys_get_temp_dir() . '/wc-mss-tests/wc-mss-img-' . bin2hex(random_bytes(4)) . $suffix;
        file_put_contents($path, 'x');
        $this->tempFiles[] = $path;
        return $path;
    }

    private function stub(mixed $mime, mixed $file, mixed $url = false): void
    {
        Functions\when('get_post_mime_type')->justReturn($mime);
        Functions\when('get_attached_file')->justReturn($file);
        Functions\when('wp_get_attachment_url')->justReturn($url);
    }

    public static function nonImageMimes(): array
    {
        return [
            'php'               => ['application/x-php'],
            'html'              => ['text/html'],
            'javascript'        => ['application/javascript'],
            'pdf'               => ['application/pdf'],
            'octet stream'      => ['application/octet-stream'],
            'false'             => [false],
            'null'              => [null],
            'empty'             => [''],
            'zero'              => ['0'],
            'prefix only'       => ['image'],
            'no slash'          => ['imagepng'],
            'leading space'     => [' image/png'],
            'leading newline'   => ["\nimage/png"],
            'embedded in other' => ['text/plain; image/png'],
            'nested'            => ['application/x-image/png'],
            'video'             => ['video/mp4'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonImageMimes')]
    public function test_non_image_mime_types_are_refused_even_when_file_and_url_exist(mixed $mime): void
    {
        $this->stub($mime, $this->tempImage(), 'https://cdn.example.com/a.jpg');

        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(5));
    }

    public function test_missing_file_and_missing_url_returns_null(): void
    {
        $this->stub('image/jpeg', '/definitely/not/here.jpg', false);
        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(5));

        $this->stub('image/jpeg', false, '');
        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(5));

        $this->stub('image/jpeg', '', null);
        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(5));
    }

    public function test_local_file_wins_and_result_has_exactly_one_source_key(): void
    {
        $file = $this->tempImage('.png');
        $this->stub('image/png', $file, 'https://cdn.example.com/other.png');

        $data = WC_Multi_Store_Image_Proxy::get_image_data(5);

        $this->assertSame(['file_path', 'filename', 'mime_type'], array_keys($data));
        $this->assertSame($file, $data['file_path']);
        $this->assertSame(basename($file), $data['filename']);
        $this->assertSame('image/png', $data['mime_type']);
    }

    public function test_remote_fallback_has_url_key_and_no_file_path(): void
    {
        $this->stub('image/webp', '/missing.webp', 'https://cdn.example.com/up/photo.webp');

        $data = WC_Multi_Store_Image_Proxy::get_image_data(5);

        $this->assertSame(['url', 'filename', 'mime_type'], array_keys($data));
        $this->assertSame('photo.webp', $data['filename']);
    }

    public static function urlFilenames(): array
    {
        return [
            'query string is dropped'    => ['https://cdn.example.com/a/b.jpg?v=3&x=../../etc/passwd', 'b.jpg'],
            'fragment is dropped'        => ['https://cdn.example.com/a/b.jpg#../../x', 'b.jpg'],
            'dot dot segments collapse'  => ['https://cdn.example.com/a/../../../etc/passwd.png', 'passwd.png'],
            'trailing slash keeps dir'   => ['https://cdn.example.com/a/dir/', 'dir'],
            'userinfo is not in path'    => ['https://user:pw@cdn.example.com/p/img.gif', 'img.gif'],
            'port'                       => ['https://cdn.example.com:8443/p/img.gif', 'img.gif'],
            'backslashes stay one name'  => ['https://cdn.example.com/p/a%5C..%5Cb.png', 'a%5C..%5Cb.png'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urlFilenames')]
    public function test_remote_filename_never_contains_a_directory_component(string $url, string $expected): void
    {
        $this->stub('image/png', false, $url);

        $data = WC_Multi_Store_Image_Proxy::get_image_data(5);

        $this->assertSame($expected, $data['filename']);
        $this->assertStringNotContainsString('/', $data['filename']);
        $this->assertStringNotContainsString('?', $data['filename']);
    }

    public function test_local_filename_is_basename_only_even_for_traversal_style_paths(): void
    {
        $dir  = sys_get_temp_dir() . '/wc-mss-tests';
        $file = $this->tempImage('.jpg');
        $weird = $dir . '/../' . basename($dir) . '/' . basename($file);
        $this->stub('image/jpeg', $weird, false);

        $data = WC_Multi_Store_Image_Proxy::get_image_data(5);

        $this->assertNotNull($data);
        $this->assertSame(basename($file), $data['filename']);
    }

    public function test_attachment_id_is_forwarded_untouched_to_wordpress(): void
    {
        $seen = [];
        Functions\when('get_post_mime_type')->alias(function ($id) use (&$seen) {
            $seen[] = $id;
            return 'image/png';
        });
        Functions\when('get_attached_file')->alias(function ($id) use (&$seen) {
            $seen[] = $id;
            return false;
        });
        Functions\when('wp_get_attachment_url')->alias(function ($id) use (&$seen) {
            $seen[] = $id;
            return 'https://cdn.example.com/x.png';
        });

        WC_Multi_Store_Image_Proxy::get_image_data(-1);
        WC_Multi_Store_Image_Proxy::get_image_data(0);

        $this->assertSame([-1, -1, -1, 0, 0, 0], $seen);
    }

    public function test_is_enabled_only_true_for_truthy_setting(): void
    {
        foreach ([[false, false], [true, true], [0, false], [1, true], ['', false]] as [$stored, $expected]) {
            WC_Multi_Store_Settings::clear_static_cache();
            Functions\when('get_option')->alias(fn($k, $d = false) => $k === 'wc_multi_store_sync_settings' ? ['image_proxy_enabled' => $stored] : $d);
            $this->assertSame($expected, WC_Multi_Store_Image_Proxy::is_enabled(), var_export($stored, true));
        }
    }

    // ── regression: local path containment ─────────────────────────────────

    private function uploadsFile(string $suffix = '.png'): string
    {
        $dir = sys_get_temp_dir() . '/wc-mss-tests';
        $path = tempnam($dir, 'att');
        rename($path, $path . $suffix);
        $this->tempFiles[] = $path . $suffix;
        return $path . $suffix;
    }

    public function test_file_inside_uploads_is_returned_as_local_file(): void
    {
        $file = $this->uploadsFile();
        $this->stub('image/png', $file, 'https://cdn.example.com/x.png');

        $data = WC_Multi_Store_Image_Proxy::get_image_data(1);

        $this->assertSame($file, $data['file_path']);
        $this->assertArrayNotHasKey('url', $data);
    }

    public function test_file_outside_uploads_is_never_returned_and_falls_back_to_url(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'outside');
        $this->tempFiles[] = $outside;
        $this->stub('image/png', $outside, 'https://cdn.example.com/pub.png');

        $data = WC_Multi_Store_Image_Proxy::get_image_data(1);

        $this->assertArrayNotHasKey('file_path', $data);
        $this->assertSame('https://cdn.example.com/pub.png', $data['url']);
    }

    public function test_sensitive_system_file_is_rejected_and_without_url_yields_null(): void
    {
        $this->stub('image/png', '/etc/hosts', false);

        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(1));
    }

    public function test_directory_path_is_rejected(): void
    {
        $this->stub('image/png', sys_get_temp_dir() . '/wc-mss-tests', false);
        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(1));

        $this->stub('image/png', sys_get_temp_dir(), false);
        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(1));
    }

    public function test_dot_dot_escape_out_of_uploads_is_rejected(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'outside');
        $this->tempFiles[] = $outside;
        $escape = sys_get_temp_dir() . '/wc-mss-tests/../' . basename($outside);
        $this->stub('image/png', $escape, false);

        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(1));
    }

    public function test_symlink_inside_uploads_pointing_outside_is_rejected(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'outside');
        $this->tempFiles[] = $target;
        $link = sys_get_temp_dir() . '/wc-mss-tests/link-' . bin2hex(random_bytes(3)) . '.png';
        if (!@symlink($target, $link)) {
            $this->markTestSkipped('symlinks unavailable');
        }
        $this->tempFiles[] = $link;
        $this->stub('image/png', $link, false);

        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(1));
    }

    public function test_uploads_sibling_directory_with_shared_prefix_is_rejected(): void
    {
        $sibling = sys_get_temp_dir() . '/wc-mss-tests-evil';
        @mkdir($sibling);
        $file = $sibling . '/a.png';
        file_put_contents($file, 'x');
        $this->tempFiles[] = $file;
        $this->stub('image/png', $file, false);

        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(1));
        @rmdir($sibling);
    }

    // ── regression: remote URL validation ──────────────────────────────────

    public static function unfetchableUrls(): array
    {
        return [
            'file scheme'        => ['file:///etc/passwd'],
            'ftp'                => ['ftp://cdn.example.com/a.png'],
            'gopher'             => ['gopher://cdn.example.com/a.png'],
            'data uri'           => ['data:image/png;base64,AAAA'],
            'javascript'         => ['javascript:alert(1)'],
            'no scheme'          => ['//cdn.example.com/a.png'],
            'relative'           => ['/uploads/a.png'],
            'cloud metadata'     => ['http://169.254.169.254/latest/meta-data/x.png'],
            'metadata https'     => ['https://169.254.169.254/x.png'],
            'ipv6 link local'    => ['http://[fe80::1]/x.png'],
            'no host'            => ['http:///x.png'],
            'uppercase file'     => ['FILE:///etc/passwd'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unfetchableUrls')]
    public function test_dangerous_or_non_http_urls_are_refused(string $url): void
    {
        Functions\when('wp_parse_url')->alias(fn($u, $c = -1) => parse_url($u, $c));
        $this->stub('image/png', false, $url);

        $this->assertNull(WC_Multi_Store_Image_Proxy::get_image_data(1));
    }

    public function test_plain_http_and_https_hostnames_are_accepted(): void
    {
        Functions\when('wp_parse_url')->alias(fn($u, $c = -1) => parse_url($u, $c));
        foreach (['http://cdn.example.com/a.png', 'HTTPS://cdn.example.com/a.png'] as $url) {
            $this->stub('image/png', false, $url);
            $this->assertSame($url, WC_Multi_Store_Image_Proxy::get_image_data(1)['url']);
        }
    }

    public function test_public_ip_literal_url_is_accepted(): void
    {
        Functions\when('wp_parse_url')->alias(fn($u, $c = -1) => parse_url($u, $c));
        $this->stub('image/png', false, 'https://93.184.216.34/a.png');

        $this->assertSame('a.png', WC_Multi_Store_Image_Proxy::get_image_data(1)['filename']);
    }

    public function test_url_without_path_gets_a_stable_synthetic_filename(): void
    {
        Functions\when('wp_parse_url')->alias(fn($u, $c = -1) => parse_url($u, $c));
        foreach (['https://cdn.example.com', 'https://cdn.example.com/', '', ] as $url) {
            if ($url === '') {
                continue;
            }
            $this->stub('image/png', false, $url);
            $name = WC_Multi_Store_Image_Proxy::get_image_data(42)['filename'];
            $this->assertNotSame('', $name, $url);
        }
        $this->stub('image/png', false, 'https://cdn.example.com');
        $this->assertSame('image-42', WC_Multi_Store_Image_Proxy::get_image_data(42)['filename']);
    }
}
