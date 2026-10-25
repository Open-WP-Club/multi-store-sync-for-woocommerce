<?php

use Brain\Monkey\Functions;

class TransferSecurityRegressionTest extends WC_Multi_Store_TestCase
{
    public function test_image_fetch_rejection_never_uploads_a_response(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\expect('wp_safe_remote_get')->once()
            ->with('http://metadata.google.internal/private.png', \Mockery::on(
                fn($args) => $args['limit_response_size'] === WC_Multi_Store_API_Client::MAX_IMAGE_BYTES + 1
            ))
            ->andReturn(new WP_Error('http_request_failed', 'Unsafe destination'));
        Functions\expect('wp_remote_get')->never();
        Functions\expect('wp_remote_post')->never();

        $client = new WC_Multi_Store_API_Client('https://remote.example');
        $result = $client->upload_image([
            'url' => 'http://metadata.google.internal/private.png',
            'filename' => 'private.png',
            'mime_type' => 'image/png',
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('image_fetch_error', $result->get_error_code());
    }

    public function test_public_image_is_uploaded_after_safe_fetch(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\expect('wp_safe_remote_get')->once()->andReturn([
            'body' => 'public image bytes',
            'headers' => ['content-type' => 'image/png'],
        ]);
        Functions\when('wp_remote_retrieve_header')->justReturn('image/png');
        Functions\when('wp_remote_retrieve_body')->alias(fn($response) => $response['body']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(201);
        Functions\expect('wp_remote_post')->once()
            ->with('https://remote.example/wp-json/wp/v2/media', \Mockery::on(
                fn($args) => $args['body'] === 'public image bytes' && $args['sslverify'] === true
            ))
            ->andReturn(['body' => '{"id":42}']);

        $client = new WC_Multi_Store_API_Client('https://remote.example');
        $this->assertSame(['id' => 42], $client->upload_image([
            'url' => 'https://cdn.example/image.png',
            'filename' => 'image.png',
            'mime_type' => 'image/png',
        ]));
    }

    public function test_legacy_api_mode_neither_reads_local_secrets_nor_uploads_downloads(): void
    {
        WC_Multi_Store_Settings::clear_static_cache();
        Functions\when('get_option')->alias(fn($name, $default = false) =>
            $name === 'wc_multi_store_sync_settings'
                ? ['downloadable_files_sync_enabled' => true, 'downloadable_files_sync_transfer_mode' => 'api']
                : $default
        );
        Functions\expect('wp_tempnam')->never();
        Functions\expect('wp_remote_post')->never();
        $secret = tempnam(sys_get_temp_dir(), 'wc-mss-secret-');
        file_put_contents($secret, 'dummy confidential content');
        try {
            $download = \Mockery::mock('WC_Product_Download');
            $download->shouldReceive('get_name')->andReturn('Manual');
            $download->shouldReceive('get_file')->andReturn($secret);
            $product = \Mockery::mock('WC_Product');
            $product->shouldReceive('is_downloadable')->andReturn(true);
            $product->shouldReceive('get_downloads')->andReturn([$download]);
            $product->shouldReceive('get_download_limit')->andReturn(2);
            $product->shouldReceive('get_download_expiry')->andReturn(7);
            $client = \Mockery::mock(WC_Multi_Store_API_Client::class);
            $client->shouldNotReceive('upload_image');

            $this->assertSame([['name' => 'Manual', 'file' => $secret]],
                WC_Multi_Store_Downloadable_Files_Sync::extract_downloads($product));
            $result = WC_Multi_Store_Downloadable_Files_Sync::sync_downloads(
                $client, $product, [], 'https://remote.example'
            );
            $this->assertSame([['name' => 'Manual', 'file' => $secret]], $result['downloads']);
            $this->assertSame(2, $result['download_limit']);
            $this->assertSame(7, $result['download_expiry']);
        } finally {
            unlink($secret);
        }
    }
}
