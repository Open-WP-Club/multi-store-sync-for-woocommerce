<?php
/**
 * Unit tests for the shared traits, enums and small helpers that had no coverage:
 * Csv_Sanitizer, Archive_Before_Delete, Ajax_Auth_Guard, Toggleable_Feature,
 * Email_Shell, Sync_Type_Options and the two label enums.
 */

use Brain\Monkey\Functions;

class SharedTraitsTest extends WC_Multi_Store_TestCase
{
    private string $archiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveDir = sys_get_temp_dir() . '/wc-mss-tests/wc-mss-logs/';
        $this->removeArchives();
    }

    protected function tearDown(): void
    {
        $this->removeArchives();
        parent::tearDown();
    }

    private function removeArchives(): void
    {
        foreach (glob($this->archiveDir . 'unit-*-archive-*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    // ---- Csv_Sanitizer ----------------------------------------------------

    private function csv(string $value): string
    {
        $subject = new class {
            use WC_Multi_Store_Csv_Sanitizer;

            public function run(string $value): string
            {
                return self::csv_cell_sanitize($value);
            }
        };
        return $subject->run($value);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousCsvCells')]
    public function test_csv_sanitizer_prefixes_formula_cells(string $input): void
    {
        $this->assertSame("'" . $input, $this->csv($input));
    }

    public static function dangerousCsvCells(): array
    {
        return [
            'equals' => ['=SUM(A1:A2)'],
            'plus' => ['+1+1'],
            'minus' => ['-2+3'],
            'at' => ['@cmd'],
            'tab' => ["\tcmd"],
            'carriage return' => ["\rcmd"],
        ];
    }

    public function test_csv_sanitizer_leaves_safe_cells_untouched(): void
    {
        $this->assertSame('', $this->csv(''));
        $this->assertSame('plain', $this->csv('plain'));
        $this->assertSame('a=b', $this->csv('a=b'), 'Only the first character matters');
        $this->assertSame('123', $this->csv('123'));
    }

    // ---- Archive_Before_Delete -------------------------------------------

    private function archiver(): object
    {
        return new class {
            use WC_Multi_Store_Archive_Before_Delete;

            public function archive(string $prefix, array $records, string $col): string|false
            {
                return self::archive_records_to_json($prefix, $records, $col);
            }

            public function prune(string $dir, string $prefix, int $max): void
            {
                self::prune_old_archives($dir, $prefix, $max);
            }
        };
    }

    public function test_archive_returns_false_for_empty_records(): void
    {
        $this->assertFalse($this->archiver()->archive('unit-empty', [], 'created_at'));
    }

    public function test_archive_writes_json_with_date_range_and_count(): void
    {
        Functions\when('current_time')->justReturn('2024-02-01 00:00:00');

        $records = [
            ['id' => 1, 'created_at' => '2024-01-05 10:00:00'],
            ['id' => 2, 'created_at' => '2024-01-20 10:00:00'],
        ];

        $path = $this->archiver()->archive('unit-write', $records, 'created_at');

        $this->assertIsString($path);
        $this->assertStringEndsWith('unit-write-archive-2024-01-05-to-2024-01-20.json', $path);

        $data = json_decode((string) file_get_contents($path), true);
        $this->assertSame(2, $data['total_records']);
        $this->assertSame(['from' => '2024-01-05', 'to' => '2024-01-20'], $data['date_range']);
        $this->assertSame($records, $data['records']);
        $this->assertSame('2024-02-01 00:00:00', $data['archived_at']);
    }

    public function test_archive_prunes_oldest_files_beyond_retention(): void
    {
        Functions\when('current_time')->justReturn('2024-02-01 00:00:00');
        $archiver = $this->archiver();

        // archive_max_files() is 10, so writing 12 distinct archives must leave 10.
        for ($day = 1; $day <= 12; $day++) {
            $date = sprintf('2024-01-%02d 00:00:00', $day);
            $archiver->archive('unit-prune', [['created_at' => $date]], 'created_at');
        }

        $files = glob($this->archiveDir . 'unit-prune-archive-*.json') ?: [];
        $this->assertCount(10, $files);
        $this->assertFileDoesNotExist($this->archiveDir . 'unit-prune-archive-2024-01-01-to-2024-01-01.json');
        $this->assertFileExists($this->archiveDir . 'unit-prune-archive-2024-01-12-to-2024-01-12.json');
    }

    public function test_prune_does_nothing_when_under_limit(): void
    {
        file_put_contents($this->archiveDir . 'unit-keep-archive-2024-01-01-to-2024-01-01.json', '{}');

        $this->archiver()->prune($this->archiveDir, 'unit-keep', 5);

        $this->assertFileExists($this->archiveDir . 'unit-keep-archive-2024-01-01-to-2024-01-01.json');
    }

    // ---- Ajax_Auth_Guard --------------------------------------------------

    private function guard(): object
    {
        return new class {
            use WC_Multi_Store_Ajax_Auth_Guard;

            public function verify(string $action = 'wc_mss_admin', ?string $message = null): bool
            {
                return self::verify_admin_request($action, $message);
            }
        };
    }

    public function test_auth_guard_allows_authorized_user(): void
    {
        Functions\expect('check_ajax_referer')->once()->with('wc_mss_admin', 'nonce');
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('wp_send_json_error')->never();

        $this->assertTrue($this->guard()->verify());
    }

    public function test_auth_guard_rejects_unauthorized_user_with_default_message(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\expect('current_user_can')->once()->with('manage_woocommerce')->andReturn(false);
        Functions\expect('wp_send_json_error')->once()->with(['message' => 'Permission denied']);

        $this->assertFalse($this->guard()->verify());
    }

    public function test_auth_guard_uses_custom_nonce_action_and_message(): void
    {
        Functions\expect('check_ajax_referer')->once()->with('custom_action', 'nonce');
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_send_json_error')->once()->with(['message' => 'Nope']);

        $this->assertFalse($this->guard()->verify('custom_action', 'Nope'));
    }

    // ---- Toggleable_Feature ----------------------------------------------

    /** @var array<string, mixed> */
    private array $storedSettings = [];

    private function toggleable(): string
    {
        $this->storedSettings = [];
        Functions\when('get_option')->alias(fn($key, $default = false) => $this->storedSettings ?: $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->storedSettings = $value;
            return true;
        });

        $class = new class {
            use WC_Multi_Store_Toggleable_Feature;

            public static function default_settings(): array
            {
                return ['enabled' => false, 'mode' => 'url'];
            }

            public static function feature_label(): string
            {
                return 'Widget sync';
            }

            public static function central_settings_prefix(): string
            {
                return 'widget_sync';
            }
        };
        return $class::class;
    }

    public function test_toggleable_is_disabled_by_default(): void
    {
        $class = $this->toggleable();
        $this->assertFalse($class::is_enabled());
    }

    public function test_toggleable_update_settings_namespaces_keys(): void
    {
        $class = $this->toggleable();

        $class::update_settings(['enabled' => true, 'mode' => 'file']);

        $this->assertTrue($this->storedSettings['widget_sync_enabled']);
        $this->assertSame('file', $this->storedSettings['widget_sync_mode']);
        $this->assertTrue($class::is_enabled());
        $this->assertSame(['enabled' => true, 'mode' => 'file'], $class::get_settings());
    }

    public function test_toggleable_get_settings_falls_back_to_defaults(): void
    {
        $class = $this->toggleable();
        $this->assertSame(['enabled' => false, 'mode' => 'url'], $class::get_settings());
    }

    public function test_toggle_ajax_rejects_unauthorized(): void
    {
        $class = $this->toggleable();
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_send_json_error')->once()->with(['message' => 'Unauthorized']);
        Functions\expect('wp_send_json_success')->never();

        $class::ajax_toggle();

        $this->assertSame([], $this->storedSettings, 'Nothing may be persisted on failed auth');
    }

    public function test_toggle_ajax_enables_and_disables(): void
    {
        $class = $this->toggleable();
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);

        $responses = [];
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$responses) {
            $responses[] = $data;
        });

        $_POST['enabled'] = '1';
        $class::ajax_toggle();
        $_POST['enabled'] = '0';
        $class::ajax_toggle();
        unset($_POST['enabled']);

        $this->assertSame(['message' => 'Widget sync enabled', 'enabled' => true], $responses[0]);
        $this->assertSame(['message' => 'Widget sync disabled', 'enabled' => false], $responses[1]);
        $this->assertFalse($class::is_enabled());
    }

    public function test_toggle_ajax_treats_missing_or_non_string_value_as_disabled(): void
    {
        $class = $this->toggleable();
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $captured = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$captured) {
            $captured = $data;
        });

        unset($_POST['enabled']);
        $class::ajax_toggle();
        $this->assertFalse($captured['enabled']);

        $_POST['enabled'] = ['1'];
        $class::ajax_toggle();
        unset($_POST['enabled']);
        $this->assertFalse($captured['enabled']);
    }

    // ---- Email_Shell ------------------------------------------------------

    public function test_email_shell_escapes_badge_and_wraps_body(): void
    {
        Functions\when('esc_html')->alias(fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8'));

        $subject = new class {
            use WC_Multi_Store_Email_Shell;

            public function run(string $h, string $label, string $color, string $body): string
            {
                return $this->wrap_email($h, $label, $color, $body);
            }
        };

        $html = $subject->run('Heading', '<b>Alert</b>', '#ff0000"', '<p>Body</p>');

        $this->assertStringContainsString('<h1>Heading</h1>', $html);
        $this->assertStringContainsString('&lt;b&gt;Alert&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Alert</b>', $html);
        $this->assertStringContainsString('background:#ff0000&quot;;', $html);
        $this->assertStringContainsString('<p>Body</p>', $html);
        $this->assertLessThan(strpos($html, '<p>Body</p>'), strpos($html, 'Alert'), 'Badge comes before body');
    }

    // ---- Sync_Type_Options ------------------------------------------------

    public function test_sync_type_options_renders_options_with_selection(): void
    {
        Functions\when('esc_html')->alias(fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8'));
        Functions\when('selected')->alias(fn($sel, $cur, $echo = true) => $sel === $cur ? ' selected="selected"' : '');

        ob_start();
        WC_Multi_Store_Sync_Type_Options::render(['a' => 'Alpha', 'b' => 'B & <C>'], 'b');
        $out = ob_get_clean();

        $this->assertSame(
            '<option value="a">Alpha</option><option value="b" selected="selected">B &amp; &lt;C&gt;</option>',
            $out
        );
    }

    public function test_sync_type_options_with_no_selection_marks_nothing(): void
    {
        Functions\when('esc_html')->returnArg();
        Functions\when('selected')->alias(fn($sel, $cur, $echo = true) => $sel === $cur ? ' selected="selected"' : '');

        ob_start();
        WC_Multi_Store_Sync_Type_Options::render(['a' => 'Alpha']);
        $out = ob_get_clean();

        $this->assertStringNotContainsString('selected', $out);
    }

    // ---- Enums ------------------------------------------------------------

    public function test_pricing_rule_type_values_and_labels(): void
    {
        $this->assertSame(
            ['none', 'fixed', 'percentage', 'multiplier', 'currency', 'custom'],
            array_map(fn($c) => $c->value, WC_Multi_Store_Pricing_Rule_Type::cases())
        );
        foreach (WC_Multi_Store_Pricing_Rule_Type::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
        $this->assertSame(WC_Multi_Store_Pricing_Rule_Type::FIXED, WC_Multi_Store_Pricing_Rule_Type::tryFrom('fixed'));
        $this->assertNull(WC_Multi_Store_Pricing_Rule_Type::tryFrom('bogus'));
    }

    public function test_stock_allocation_type_values_and_labels(): void
    {
        $this->assertSame(
            ['none', 'percentage', 'fixed', 'priority', 'reserve', 'equal'],
            array_map(fn($c) => $c->value, WC_Multi_Store_Stock_Allocation_Type::cases())
        );
        $labels = array_map(fn($c) => $c->label(), WC_Multi_Store_Stock_Allocation_Type::cases());
        $this->assertSame($labels, array_unique($labels), 'Labels must be distinct');
        $this->assertNull(WC_Multi_Store_Stock_Allocation_Type::tryFrom('bogus'));
    }
}
