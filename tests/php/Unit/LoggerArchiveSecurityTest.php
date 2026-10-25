<?php
/** Security regressions for archives served independently of WordPress. */
use Brain\Monkey\Functions;

class LoggerArchiveSecurityTest extends WC_Multi_Store_TestCase {
    private string $directory;

    protected function setUp(): void {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/wc-mss-archive-security-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/wc-mss-logs', 0700, true);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->directory]);
        Functions\when('get_option')->alias(fn($key, $default = false) => $default);
        Functions\when('current_time')->justReturn('2026-10-25 12:00:00');
        WC_Multi_Store_Logger::reset_instance();
    }

    protected function tearDown(): void {
        foreach (glob($this->directory . '/wc-mss-logs/{*,.*}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory . '/wc-mss-logs');
        rmdir($this->directory);
        WC_Multi_Store_Logger::reset_instance();
        parent::tearDown();
    }

    private function database(array $records): \Mockery\MockInterface {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->once()->andReturn($records);
        return $wpdb;
    }

    public function test_archive_cannot_output_data_or_execute_embedded_php(): void {
        $record = ['created_at' => '2026-01-01', 'message' => 'PRIVATE ?><?php echo "UNSAFE";'];
        $this->database([$record])->shouldReceive('query')->once()->andReturn(1);
        $result = WC_Multi_Store_Logger::archive_database_history();

        $this->assertTrue($result['success']);
        $this->assertStringEndsWith('.php', $result['file']);
        $contents = file_get_contents($result['file']);
        $this->assertStringStartsWith(WC_Multi_Store_Logger::ARCHIVE_GUARD, $contents);
        $decoded = json_decode(substr($contents, strlen(WC_Multi_Store_Logger::ARCHIVE_GUARD)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([$record], $decoded['records']);
        $process = proc_open([PHP_BINARY, $result['file']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
        $this->assertSame('', $output);
        $this->assertSame('', $errors);
    }

    public function test_legacy_archives_migrate_even_when_database_has_no_old_rows(): void {
        $path = $this->directory . '/wc-mss-logs/webhook-logs-archive-2026-01-01-to-2026-01-01';
        $json = '{"records":[{"sku":"PRIVATE"}]}';
        file_put_contents($path . '.json', $json);
        $this->database([])->shouldNotReceive('query');

        $result = WC_Multi_Store_Logger::archive_database_history();

        $this->assertTrue($result['success']);
        $this->assertFileDoesNotExist($path . '.json');
        $this->assertSame(WC_Multi_Store_Logger::ARCHIVE_GUARD . $json, file_get_contents($path . '-legacy-' . hash('sha256', $json) . '.php'));
    }

    public function test_migration_preserves_existing_archive_with_same_dates(): void {
        $path = $this->directory . '/wc-mss-logs/history-archive-2026-01-01-to-2026-01-01';
        file_put_contents($path . '.json', '{"old":true}');
        file_put_contents($path . '.php', WC_Multi_Store_Logger::ARCHIVE_GUARD . '{"existing":true}');

        $this->assertIsString(WC_Multi_Store_Logger::prepare_archive_directory());
        $this->assertSame(WC_Multi_Store_Logger::ARCHIVE_GUARD . '{"existing":true}', file_get_contents($path . '.php'));
        $this->assertCount(2, glob($path . '*.php'));
        $this->assertFileDoesNotExist($path . '.json');
    }

    public function test_upgrade_migration_runs_once_and_marks_only_success(): void {
        Functions\when('get_option')->justReturn(0);
        Functions\expect('update_option')->once()->with('wc_mss_archive_storage_version', 1, false)->andReturn(true);
        WC_Multi_Store_Logger::migrate_archive_storage();
        $this->assertFileExists($this->directory . '/wc-mss-logs/.htaccess');
    }

    public function test_completed_upgrade_does_not_scan_storage_again(): void {
        Functions\when('get_option')->justReturn(1);
        Functions\expect('wp_upload_dir')->never();
        Functions\expect('update_option')->never();
        WC_Multi_Store_Logger::migrate_archive_storage();
        $this->assertFileDoesNotExist($this->directory . '/wc-mss-logs/.htaccess');
    }

    public function test_failed_upgrade_does_not_mark_migration_complete(): void {
        Functions\when('get_option')->justReturn(0);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->directory . '/unavailable']);
        Functions\when('wp_mkdir_p')->justReturn(false);
        Functions\expect('update_option')->never();
        WC_Multi_Store_Logger::migrate_archive_storage();
        $this->assertDirectoryDoesNotExist($this->directory . '/unavailable');
    }

    public function test_api_cleanup_keeps_rows_when_archive_cannot_encode(): void {
        $this->database([['created_at' => '2026-01-01', 'message' => "\xB1\x31"]])->shouldNotReceive('query');
        $this->assertSame(0, WC_Multi_Store_API_Usage_Tracker::cleanup_old_data());
    }

    public function test_webhook_cleanup_keeps_rows_when_archive_cannot_encode(): void {
        $this->database([['created_at' => '2026-01-01', 'message' => "\xB1\x31"]])->shouldNotReceive('query');
        $this->assertSame(0, WC_Multi_Store_Webhook_Logger::cleanup_old_logs());
    }

    public function test_failed_archive_encoding_does_not_delete_database_rows(): void {
        $this->database([['created_at' => '2026-01-01', 'message' => "\xB1\x31"]])->shouldNotReceive('query');
        $result = WC_Multi_Store_Logger::archive_database_history();
        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['archived']);
        $this->assertSame([], glob($this->directory . '/wc-mss-logs/*.php'));
    }

    public function test_retention_limits_guarded_archives_after_migration(): void {
        for ($day = 1; $day <= 12; $day++) {
            $date = sprintf('2026-01-%02d', $day);
            file_put_contents($this->directory . '/wc-mss-logs/history-archive-' . $date . '-to-' . $date . '.json', '{}');
        }
        $this->database([['created_at' => '2026-02-01']])->shouldReceive('query')->once()->andReturn(1);
        $this->assertTrue(WC_Multi_Store_Logger::archive_database_history()['success']);
        $files = glob($this->directory . '/wc-mss-logs/history-archive-*.php');
        $this->assertCount(WC_Multi_Store_Logger::MAX_ARCHIVE_FILES, $files);
        $this->assertSame([], glob($this->directory . '/wc-mss-logs/*.json'));
    }
}
