<?php
/**
 * Hardening tests for WC_Multi_Store_Config_Manager export/import.
 *
 * ConfigManagerTest covers the happy path. This file feeds the importer the
 * nastiest realistic inputs (hostile store URLs, option-name keys, wrong
 * types, redaction markers in odd positions, malformed AJAX payloads) and
 * asserts that nothing outside the plugin's own options is ever written,
 * that credentials only travel when explicitly requested, and that redaction
 * markers are never persisted as real credentials.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class ConfigManagerImportHardeningTest extends WC_Multi_Store_TestCase
{
    private const REDACTED = '***REDACTED***';

    /** @var array<string, mixed> In-memory wp_options. */
    private array $options = [];

    /** @var array<int, array{0: string, 1: mixed}> Every update_option() call in order. */
    private array $writes = [];

    /** Options the importer is allowed to touch. */
    private const ALLOWED_OPTIONS = [
        'wc_multi_store_sync_settings',
        'wc_multi_store_sync_scheduled',
        'wc_multi_store_sync_stores',
        'wc_multi_store_sync_email_settings',
        'wc_multi_store_sync_webhook_settings',
        'wc_multi_store_sync_weekly_verification',
        'wc_multi_store_sync_orders',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->writes = [];

        Functions\when('current_time')->justReturn('2026-01-01 12:00:00');
        Functions\when('wp_parse_url')->alias(fn($url, $c = -1) => $c === -1 ? parse_url($url) : parse_url($url, $c));
        // Mimic WordPress: unknown/dangerous protocols and non-URLs are emptied.
        Functions\when('esc_url_raw')->alias(function ($url) {
            $url = trim((string) $url);
            if ($url === '' || preg_match('#^(https?|ftps?)://#i', $url) !== 1) {
                return '';
            }
            return $url;
        });
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = null) {
            $this->writes[] = [$key, $value];
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('wp_generate_password')->alias(fn($len = 12) => str_repeat('g', (int) $len));
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        WC_Multi_Store_Settings::clear_static_cache();
    }

    private function writtenOptionNames(): array
    {
        $names = array_map(fn($w) => $w[0], $this->writes);
        // Cache-generation bumps are internal bookkeeping, not imported configuration.
        $names = array_filter($names, fn($n) => !str_starts_with($n, 'wc_mss_cache_'));
        return array_values(array_unique($names));
    }

    private function storeWrites(): array
    {
        return array_values(array_filter($this->writes, fn($w) => $w[0] === 'wc_multi_store_sync_stores'));
    }

    private function validStore(array $overrides = []): array
    {
        return array_merge([
            'consumer_key' => 'ck_real',
            'consumer_secret' => 'cs_real',
            'status' => 'active',
        ], $overrides);
    }

    // ── structure validation ──────────────────────────────────────

    public static function missingVersionProvider(): array
    {
        return [
            'absent' => [[]],
            'null' => [['plugin_version' => null]],
            'empty string' => [['plugin_version' => '']],
            'zero int' => [['plugin_version' => 0]],
            'zero string' => [['plugin_version' => '0']],
            'false' => [['plugin_version' => false]],
            'empty array' => [['plugin_version' => []]],
        ];
    }

    #[DataProvider('missingVersionProvider')]
    public function test_import_without_a_usable_version_is_rejected_and_writes_nothing(array $version): void
    {
        $config = $version + [
            'settings' => ['enabled' => true],
            'stores' => ['https://shop.example.com' => $this->validStore()],
            'webhook_settings' => ['webhook_secret' => 'x'],
        ];

        $result = WC_Multi_Store_Config_Manager::import($config);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_config', $result->get_error_code());
        $this->assertSame([], $this->writes, 'a rejected file must not touch any option');
    }

    public function test_unknown_top_level_keys_are_ignored_and_never_become_options(): void
    {
        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'siteurl' => 'https://evil.example',
            'default_role' => 'administrator',
            'users_can_register' => 1,
            'active_plugins' => ['evil/evil.php'],
            'admin_email' => 'attacker@example.com',
            '__proto__' => ['polluted' => true],
            'wc_multi_store_sync_settings' => ['enabled' => true],
            'settings' => ['enabled' => true],
        ]);

        $this->assertTrue($result);
        $this->assertSame(['wc_multi_store_sync_settings'], $this->writtenOptionNames());
        $this->assertSame(['enabled' => true], $this->options['wc_multi_store_sync_settings']);
        $this->assertArrayNotHasKey('siteurl', $this->options);
        $this->assertArrayNotHasKey('default_role', $this->options);
        $this->assertArrayNotHasKey('active_plugins', $this->options);
    }

    public function test_a_fully_populated_import_only_writes_plugin_options(): void
    {
        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'settings' => ['enabled' => true],
            'scheduled_settings' => ['scheduled_sync_enabled' => true],
            'stores' => ['https://shop.example.com' => $this->validStore()],
            'email_settings' => ['notifications_enabled' => true],
            'webhook_settings' => ['enabled' => true, 'webhook_secret' => 'abc'],
            'weekly_verification' => ['enabled' => true],
            'order_settings' => ['auto_sync_enabled' => true],
        ]);

        $this->assertTrue($result);
        foreach ($this->writtenOptionNames() as $name) {
            $this->assertContains($name, self::ALLOWED_OPTIONS, "unexpected option written: $name");
        }
    }

    public static function nonArraySectionProvider(): array
    {
        $sections = ['settings', 'scheduled_settings', 'stores', 'email_settings', 'webhook_settings', 'weekly_verification', 'order_settings'];
        $values = ['string' => 'evil', 'int' => 7, 'true' => true, 'float' => 1.5];
        $cases = [];
        foreach ($sections as $section) {
            foreach ($values as $label => $value) {
                $cases["$section as $label"] = [$section, $value];
            }
        }
        return $cases;
    }

    #[DataProvider('nonArraySectionProvider')]
    public function test_scalar_sections_are_ignored_not_stored(string $section, mixed $value): void
    {
        $result = WC_Multi_Store_Config_Manager::import(['plugin_version' => '3.1.0', $section => $value]);

        $this->assertTrue($result);
        $this->assertSame([], $this->writtenOptionNames(), 'only the cache-generation bump may be written');
    }

    // ── store URLs ────────────────────────────────────────────────

    public static function hostileStoreUrlProvider(): array
    {
        return [
            'plain http to a public host' => ['http://shop.example.com', 'insecure_url'],
            'http to the metadata ip' => ['http://169.254.169.254/latest/meta-data', 'insecure_url'],
            'https to the metadata ip' => ['https://169.254.169.254', 'unsafe_url'],
            'https to the metadata ip with path' => ['https://169.254.169.254/latest/meta-data/iam', 'unsafe_url'],
            'https to link-local range edge' => ['https://169.254.0.1', 'unsafe_url'],
            'https to bracketed ipv6 link-local' => ['https://[fe80::1]/', 'unsafe_url'],
            'javascript scheme' => ['javascript:alert(1)', 'invalid_url'],
            'data scheme' => ['data:text/html;base64,PHNjcmlwdD4=', 'invalid_url'],
            'file scheme' => ['file:///etc/passwd', 'invalid_url'],
            'gopher scheme' => ['gopher://127.0.0.1:25/_HELO', 'invalid_url'],
            'ftp scheme' => ['ftp://shop.example.com', 'insecure_url'],
            'no scheme' => ['shop.example.com', 'invalid_url'],
            'empty key' => ['', 'invalid_url'],
            'whitespace key' => ['   ', 'invalid_url'],
            'option name as key' => ['wc_multi_store_sync_settings', 'invalid_url'],
            'path traversal as key' => ['../../wp-config.php', 'invalid_url'],
            'numeric list index' => ['0', 'invalid_url'],
        ];
    }

    #[DataProvider('hostileStoreUrlProvider')]
    public function test_hostile_store_urls_are_rejected_and_not_persisted(string $url, string $expectedCode): void
    {
        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'stores' => [$url => $this->validStore()],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame($expectedCode, $result->get_error_code());
        $this->assertSame([], $this->storeWrites());
        $this->assertArrayNotHasKey($url === '' ? 'x' : $url, $this->options);
    }

    public static function acceptedLocalUrlProvider(): array
    {
        return [
            'localhost over http' => ['http://localhost', 'http://localhost'],
            'loopback over http' => ['http://127.0.0.1:8080', 'http://127.0.0.1:8080'],
            '.test dev domain' => ['http://shop.test', 'http://shop.test'],
            '.local dev domain' => ['http://shop.local', 'http://shop.local'],
            'https public host' => ['https://shop.example.com', 'https://shop.example.com'],
            'trailing slash is normalised' => ['https://shop.example.com/', 'https://shop.example.com'],
        ];
    }

    #[DataProvider('acceptedLocalUrlProvider')]
    public function test_legitimate_urls_are_stored_under_their_normalised_key(string $given, string $expectedKey): void
    {
        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'stores' => [$given => $this->validStore()],
        ]);

        $this->assertTrue($result);
        $this->assertArrayHasKey($expectedKey, $this->options['wc_multi_store_sync_stores']);
    }

    public function test_a_bad_store_stops_the_import_before_later_sections_are_written(): void
    {
        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'stores' => [
                'https://good.example.com' => $this->validStore(),
                'file:///etc/passwd' => $this->validStore(),
                'https://never-reached.example.com' => $this->validStore(),
            ],
            'email_settings' => ['notifications_enabled' => true],
            'webhook_settings' => ['webhook_secret' => 'attacker'],
            'order_settings' => ['auto_sync_enabled' => true],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertArrayNotHasKey('wc_multi_store_sync_email_settings', $this->options);
        $this->assertArrayNotHasKey('wc_multi_store_sync_webhook_settings', $this->options);
        $this->assertArrayNotHasKey('wc_multi_store_sync_orders', $this->options);
        $this->assertArrayNotHasKey('https://never-reached.example.com', $this->options['wc_multi_store_sync_stores'] ?? []);
    }

    public function test_store_save_failure_is_reported_as_an_error_not_success(): void
    {
        Functions\when('update_option')->alias(function ($key, $value) {
            return $key === 'wc_multi_store_sync_stores' ? false : true;
        });

        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'stores' => ['https://shop.example.com' => $this->validStore()],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('store_import_failed', $result->get_error_code());
    }

    public function test_cache_invalidation_failure_after_saving_is_surfaced(): void
    {
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->writes[] = [$key, $value];
            return !str_contains((string) $key, 'generation');
        });

        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'settings' => ['enabled' => true],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('cache_invalidation_failed', $result->get_error_code());
    }

    // ── redaction handling ────────────────────────────────────────

    public function test_redacted_credentials_restore_the_existing_store_credentials(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [
            'https://shop.example.com' => $this->validStore(['consumer_key' => 'ck_keep', 'consumer_secret' => 'cs_keep', 'status' => 'inactive']),
        ];

        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'stores' => ['https://shop.example.com' => [
                'consumer_key' => self::REDACTED,
                'consumer_secret' => self::REDACTED,
                'status' => 'active',
            ]],
        ]);

        $this->assertTrue($result);
        $store = $this->options['wc_multi_store_sync_stores']['https://shop.example.com'];
        $this->assertSame('ck_keep', $store['consumer_key']);
        $this->assertSame('cs_keep', $store['consumer_secret']);
        $this->assertSame('active', $store['status'], 'non-secret fields still come from the file');
    }

    public function test_redacted_credentials_match_the_stored_store_despite_a_trailing_slash(): void
    {
        Functions\when('untrailingslashit')->alias(fn($v) => rtrim($v, '/'));
        Functions\when('trailingslashit')->alias(fn($v) => rtrim($v, '/') . '/');
        $this->options['wc_multi_store_sync_stores'] = [
            'https://shop.example.com' => $this->validStore(['consumer_key' => 'ck_keep', 'consumer_secret' => 'cs_keep']),
        ];

        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'stores' => ['https://shop.example.com/' => ['consumer_key' => self::REDACTED, 'consumer_secret' => self::REDACTED]],
        ]);

        $this->assertTrue($result);
        $saved = $this->options['wc_multi_store_sync_stores'];
        $this->assertContains('ck_keep', array_column($saved, 'consumer_key'));
        $this->assertNotContains(self::REDACTED, array_column($saved, 'consumer_key'));
    }

    public function test_a_section_with_only_unknown_keys_does_not_wipe_the_stored_settings(): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['enabled' => true, 'sync_type_default' => 'quantity'];
        WC_Multi_Store_Settings::clear_static_cache();

        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'settings' => ['evil' => 1, 'another' => 'x'],
        ]);

        $this->assertTrue($result);
        $this->assertSame('quantity', $this->options['wc_multi_store_sync_settings']['sync_type_default']);
        $this->assertTrue($this->options['wc_multi_store_sync_settings']['enabled']);
    }

    public function test_redacted_credentials_for_an_unknown_store_are_skipped_but_other_stores_import(): void
    {
        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'stores' => [
                'https://unknown.example.com' => ['consumer_key' => self::REDACTED, 'consumer_secret' => self::REDACTED],
                'https://known.example.com' => $this->validStore(),
            ],
        ]);

        $this->assertTrue($result);
        $stores = $this->options['wc_multi_store_sync_stores'];
        $this->assertArrayNotHasKey('https://unknown.example.com', $stores);
        $this->assertSame('ck_real', $stores['https://known.example.com']['consumer_key']);
    }

    public function test_redaction_marker_is_never_persisted_when_url_differs_only_by_trailing_slash(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [
            'https://shop.example.com' => $this->validStore(['consumer_key' => 'ck_keep']),
        ];

        WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'stores' => ['https://shop.example.com/' => [
                'consumer_key' => self::REDACTED,
                'consumer_secret' => self::REDACTED,
            ]],
        ]);

        $this->assertStringNotContainsString(self::REDACTED, json_encode($this->options));
        $this->assertSame('ck_keep', $this->options['wc_multi_store_sync_stores']['https://shop.example.com']['consumer_key']);
    }

    public function test_real_credentials_in_the_file_are_stored_verbatim(): void
    {
        WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'stores' => ['https://shop.example.com' => $this->validStore(['consumer_key' => 'ck_new', 'consumer_secret' => 'cs_new'])],
        ]);

        $store = $this->options['wc_multi_store_sync_stores']['https://shop.example.com'];
        $this->assertSame('ck_new', $store['consumer_key']);
        $this->assertSame('cs_new', $store['consumer_secret']);
    }

    public function test_redacted_webhook_secret_keeps_the_existing_secret(): void
    {
        $this->options['wc_multi_store_sync_webhook_settings'] = ['webhook_secret' => 'existing-secret', 'enabled' => false];

        WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'webhook_settings' => ['enabled' => true, 'webhook_secret' => self::REDACTED],
        ]);

        $this->assertSame('existing-secret', $this->options['wc_multi_store_sync_webhook_settings']['webhook_secret']);
        $this->assertTrue($this->options['wc_multi_store_sync_webhook_settings']['enabled']);
    }

    public function test_redacted_webhook_secret_without_existing_one_is_replaced_by_a_random_secret(): void
    {
        WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'webhook_settings' => ['enabled' => true, 'webhook_secret' => self::REDACTED],
        ]);

        $secret = $this->options['wc_multi_store_sync_webhook_settings']['webhook_secret'];
        $this->assertNotSame(self::REDACTED, $secret, 'the literal marker would be a guessable webhook secret');
        $this->assertSame(32, strlen($secret));
    }

    public function test_explicit_webhook_secret_in_the_file_is_kept(): void
    {
        WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'webhook_settings' => ['webhook_secret' => 'from-file'],
        ]);

        $this->assertSame('from-file', $this->options['wc_multi_store_sync_webhook_settings']['webhook_secret']);
    }

    // ── export ────────────────────────────────────────────────────

    public function test_default_export_never_contains_the_real_consumer_credentials_or_webhook_secret(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [
            'https://a.example.com' => $this->validStore(['consumer_key' => 'ck_AAA', 'consumer_secret' => 'cs_AAA']),
            'https://b.example.com' => $this->validStore(['consumer_key' => 'ck_BBB', 'consumer_secret' => 'cs_BBB']),
        ];
        $this->options['wc_multi_store_sync_webhook_settings'] = ['webhook_secret' => 'whsec_TOPSECRET'];

        $json = json_encode(WC_Multi_Store_Config_Manager::export());

        foreach (['ck_AAA', 'cs_AAA', 'ck_BBB', 'cs_BBB', 'whsec_TOPSECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_export_with_keys_contains_real_credentials(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [
            'https://a.example.com' => $this->validStore(['consumer_key' => 'ck_AAA', 'consumer_secret' => 'cs_AAA']),
        ];

        $config = WC_Multi_Store_Config_Manager::export(true);

        $this->assertSame('ck_AAA', $config['stores']['https://a.example.com']['consumer_key']);
        $this->assertSame('cs_AAA', $config['stores']['https://a.example.com']['consumer_secret']);
    }

    public function test_export_does_not_mutate_stored_options(): void
    {
        $this->options['wc_multi_store_sync_stores'] = ['https://a.example.com' => $this->validStore()];
        $this->options['wc_multi_store_sync_webhook_settings'] = ['webhook_secret' => 'keep'];

        WC_Multi_Store_Config_Manager::export(false);

        $this->assertSame('ck_real', $this->options['wc_multi_store_sync_stores']['https://a.example.com']['consumer_key']);
        $this->assertSame('keep', $this->options['wc_multi_store_sync_webhook_settings']['webhook_secret']);
        $this->assertSame([], $this->writes);
    }

    public static function corruptedOptionProvider(): array
    {
        return [
            'string' => ['not-an-array'],
            'int' => [42],
            'null' => [null],
            'false' => [false],
        ];
    }

    #[DataProvider('corruptedOptionProvider')]
    public function test_export_survives_a_corrupted_stores_option(mixed $corrupted): void
    {
        $this->options['wc_multi_store_sync_stores'] = $corrupted;

        $config = WC_Multi_Store_Config_Manager::export();

        $this->assertSame([], $config['stores']);
    }

    public function test_export_skips_store_rows_with_non_string_keys_or_non_array_configs(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [
            0 => $this->validStore(),
            '' => $this->validStore(),
            'https://bad.example.com' => 'oops',
            'https://ok.example.com' => $this->validStore(),
        ];

        $config = WC_Multi_Store_Config_Manager::export();

        $this->assertSame(['https://ok.example.com'], array_keys($config['stores']));
    }

    public function test_export_then_import_round_trip_keeps_existing_credentials(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [
            'https://a.example.com' => $this->validStore(['consumer_key' => 'ck_AAA', 'consumer_secret' => 'cs_AAA']),
        ];
        $this->options['wc_multi_store_sync_webhook_settings'] = ['webhook_secret' => 'whsec', 'enabled' => true];

        $exported = WC_Multi_Store_Config_Manager::export(false);
        $roundTripped = json_decode(json_encode($exported), true);

        $this->assertTrue(WC_Multi_Store_Config_Manager::import($roundTripped));
        $this->assertSame('ck_AAA', $this->options['wc_multi_store_sync_stores']['https://a.example.com']['consumer_key']);
        $this->assertSame('cs_AAA', $this->options['wc_multi_store_sync_stores']['https://a.example.com']['consumer_secret']);
        $this->assertSame('whsec', $this->options['wc_multi_store_sync_webhook_settings']['webhook_secret']);
    }

    // ── AJAX entry points ─────────────────────────────────────────

    private function captureAjax(): \ArrayObject
    {
        $out = new \ArrayObject(['success' => null, 'error' => null]);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_send_json_success')->alias(function ($data = null) use ($out) {
            $out['success'] = $data;
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) use ($out) {
            $out['error'] = $data;
        });
        return $out;
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public function test_ajax_import_without_capability_does_nothing(): void
    {
        $out = $this->captureAjax();
        Functions\when('current_user_can')->justReturn(false);
        $_POST = ['config' => json_encode(['plugin_version' => '3.1.0', 'settings' => ['enabled' => true]])];

        WC_Multi_Store_Config_Manager::ajax_import();

        $this->assertNotNull($out['error']);
        $this->assertNull($out['success']);
        $this->assertSame([], $this->writes);
    }

    public function test_ajax_export_without_capability_leaks_nothing(): void
    {
        $out = $this->captureAjax();
        Functions\when('current_user_can')->justReturn(false);
        $this->options['wc_multi_store_sync_stores'] = ['https://a.example.com' => $this->validStore()];
        $_POST = ['include_keys' => '1'];

        WC_Multi_Store_Config_Manager::ajax_export();

        $this->assertNull($out['success']);
        $this->assertNotNull($out['error']);
        $this->assertStringNotContainsString('ck_real', json_encode($out['error']));
    }

    public static function malformedPayloadProvider(): array
    {
        return [
            'missing' => [null],
            'empty string' => [''],
            'array instead of string' => [['plugin_version' => '3.1.0']],
            'truncated json' => ['{"plugin_version":"3.1.0","settings":{'],
            'json scalar number' => ['123'],
            'json scalar true' => ['true'],
            'json null' => ['null'],
            'json string' => ['"plugin_version"'],
            'plain text' => ['hello'],
            'invalid utf-8' => ["{\"plugin_version\":\"\xB1\xB2\"}"],
            'nesting beyond depth limit' => [str_repeat('[', 600) . str_repeat(']', 600)],
        ];
    }

    #[DataProvider('malformedPayloadProvider')]
    public function test_ajax_import_rejects_malformed_payloads_without_writing(mixed $payload): void
    {
        $out = $this->captureAjax();
        Functions\when('current_user_can')->justReturn(true);
        $_POST = $payload === null ? [] : ['config' => $payload];

        WC_Multi_Store_Config_Manager::ajax_import();

        $this->assertNull($out['success']);
        $this->assertIsArray($out['error']);
        $this->assertSame([], $this->writes);
    }

    public function test_ajax_import_json_list_without_version_is_rejected(): void
    {
        $out = $this->captureAjax();
        Functions\when('current_user_can')->justReturn(true);
        $_POST = ['config' => '[1,2,3]'];

        WC_Multi_Store_Config_Manager::ajax_import();

        $this->assertNull($out['success']);
        $this->assertNotEmpty($out['error']['message']);
        $this->assertSame([], $this->writes);
    }

    public function test_ajax_import_handles_slashed_post_data_like_wordpress_delivers_it(): void
    {
        $out = $this->captureAjax();
        Functions\when('current_user_can')->justReturn(true);
        $config = ['plugin_version' => '3.1.0', 'settings' => ['sync_type_default' => 'He said "hi" \\ bye']];
        $_POST = ['config' => addslashes(json_encode($config))];

        WC_Multi_Store_Config_Manager::ajax_import();

        $this->assertNotNull($out['success']);
        $this->assertSame('He said "hi" \\ bye', $this->options['wc_multi_store_sync_settings']['sync_type_default']);
    }

    public function test_ajax_import_error_message_does_not_echo_the_payload(): void
    {
        $out = $this->captureAjax();
        Functions\when('current_user_can')->justReturn(true);
        $_POST = ['config' => json_encode([
            'plugin_version' => '3.1.0',
            'stores' => ['https://169.254.169.254/<script>alert(1)</script>' => $this->validStore()],
        ])];

        WC_Multi_Store_Config_Manager::ajax_import();

        $this->assertNotNull($out['error']);
        $this->assertStringNotContainsString('<script>', $out['error']['message']);
    }

    public static function includeKeysFlagProvider(): array
    {
        return [
            'absent' => [null, false],
            'zero string' => ['0', false],
            'empty string' => ['', false],
            'one string' => ['1', true],
        ];
    }

    #[DataProvider('includeKeysFlagProvider')]
    public function test_ajax_export_only_includes_keys_for_an_explicit_truthy_flag(?string $flag, bool $expectKeys): void
    {
        $out = $this->captureAjax();
        Functions\when('current_user_can')->justReturn(true);
        $this->options['wc_multi_store_sync_stores'] = ['https://a.example.com' => $this->validStore(['consumer_key' => 'ck_AAA'])];
        $_POST = $flag === null ? [] : ['include_keys' => $flag];

        WC_Multi_Store_Config_Manager::ajax_export();

        $this->assertNotNull($out['success']);
        $this->assertSame($expectKeys, str_contains(json_encode($out['success']['config']), 'ck_AAA'));
        $this->assertMatchesRegularExpression('/^wc-mss-config-\d{4}-\d{2}-\d{2}-\d{6}\.json$/', $out['success']['filename']);
    }

    // ── regressions: atomic validation, per-field redaction, conformed sections ──

    public function test_export_redacts_the_application_password_but_keeps_the_username(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [
            'https://a.example.com' => $this->validStore(['wp_username' => 'editor', 'wp_app_password' => 'abcd efgh ijkl']),
            'https://b.example.com' => $this->validStore(),
        ];

        $config = WC_Multi_Store_Config_Manager::export(false);

        $a = $config['stores']['https://a.example.com'];
        $this->assertSame(self::REDACTED, $a['wp_app_password']);
        $this->assertSame('editor', $a['wp_username']);
        $this->assertStringNotContainsString('abcd efgh', json_encode($config));
        $this->assertArrayNotHasKey('wp_app_password', $config['stores']['https://b.example.com'], 'no phantom password is invented');
    }

    public function test_export_with_keys_includes_the_application_password(): void
    {
        $this->options['wc_multi_store_sync_stores'] = ['https://a.example.com' => $this->validStore(['wp_app_password' => 'abcd efgh'])];

        $this->assertSame('abcd efgh', WC_Multi_Store_Config_Manager::export(true)['stores']['https://a.example.com']['wp_app_password']);
    }

    public static function partialRedactionProvider(): array
    {
        $r = '***REDACTED***';
        return [
            'only key redacted' => [['consumer_key' => $r, 'consumer_secret' => 'cs_new'], 'ck_old', 'cs_new', null],
            'only secret redacted' => [['consumer_key' => 'ck_new', 'consumer_secret' => $r], 'ck_new', 'cs_old', null],
            'only app password redacted' => [['consumer_key' => 'ck_new', 'consumer_secret' => 'cs_new', 'wp_app_password' => $r], 'ck_new', 'cs_new', 'pw_old'],
            'everything redacted' => [['consumer_key' => $r, 'consumer_secret' => $r, 'wp_app_password' => $r], 'ck_old', 'cs_old', 'pw_old'],
            'app password real, rest redacted' => [['consumer_key' => $r, 'consumer_secret' => $r, 'wp_app_password' => 'pw_new'], 'ck_old', 'cs_old', 'pw_new'],
        ];
    }

    #[DataProvider('partialRedactionProvider')]
    public function test_each_redacted_credential_is_restored_independently(array $incoming, string $key, string $secret, ?string $password): void
    {
        $this->options['wc_multi_store_sync_stores'] = ['https://shop.example.com' => [
            'consumer_key' => 'ck_old', 'consumer_secret' => 'cs_old', 'wp_app_password' => 'pw_old', 'status' => 'active',
        ]];

        $this->assertTrue(WC_Multi_Store_Config_Manager::import(['plugin_version' => '3.1.0', 'stores' => ['https://shop.example.com' => $incoming]]));

        $store = $this->options['wc_multi_store_sync_stores']['https://shop.example.com'];
        $this->assertSame($key, $store['consumer_key']);
        $this->assertSame($secret, $store['consumer_secret']);
        $this->assertSame($password, $store['wp_app_password'] ?? null, 'fields the file omits are not carried over from the old store');
        $this->assertStringNotContainsString(self::REDACTED, json_encode($store));
    }

    public function test_redacted_field_the_existing_store_never_had_is_dropped_not_stored_as_the_marker(): void
    {
        $this->options['wc_multi_store_sync_stores'] = ['https://shop.example.com' => $this->validStore()];

        WC_Multi_Store_Config_Manager::import(['plugin_version' => '3.1.0', 'stores' => ['https://shop.example.com' => [
            'consumer_key' => 'ck_real', 'consumer_secret' => 'cs_real', 'wp_app_password' => self::REDACTED,
        ]]]);

        $this->assertStringNotContainsString(self::REDACTED, json_encode($this->options['wc_multi_store_sync_stores']));
    }

    public static function wrongTypeStoreProvider(): array
    {
        return [
            'store is a string' => ['junk'],
            'store is an int' => [5],
            'store is null' => [null],
            'key is an array' => [['consumer_key' => ['x'], 'consumer_secret' => 's']],
            'secret is an int' => [['consumer_key' => 'k', 'consumer_secret' => 5]],
            'app password is an object' => [['consumer_key' => 'k', 'consumer_secret' => 's', 'wp_app_password' => ['a' => 1]]],
            'username is bool' => [['consumer_key' => 'k', 'consumer_secret' => 's', 'wp_username' => true]],
            'status is an array' => [['consumer_key' => 'k', 'consumer_secret' => 's', 'status' => ['active']]],
            'name is an array' => [['consumer_key' => 'k', 'consumer_secret' => 's', 'name' => ['x']]],
        ];
    }

    #[DataProvider('wrongTypeStoreProvider')]
    public function test_wrongly_typed_stores_are_rejected_before_anything_is_written(mixed $store): void
    {
        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'settings' => ['enabled' => true],
            'scheduled_settings' => ['batch_size_peak' => 10],
            'stores' => ['https://good.example.com' => $this->validStore(), 'https://bad.example.com' => $store],
            'email_settings' => ['enabled' => true],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_store', $result->get_error_code());
        $this->assertSame([], $this->writes, 'a rejected file must leave the site untouched');
    }

    public function test_a_hostile_store_url_after_valid_sections_writes_nothing_at_all(): void
    {
        $result = WC_Multi_Store_Config_Manager::import([
            'plugin_version' => '3.1.0',
            'settings' => ['enabled' => true],
            'order_settings' => ['auto_sync_enabled' => true],
            'stores' => ['https://good.example.com' => $this->validStore(), 'https://169.254.169.254' => $this->validStore()],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unsafe_url', $result->get_error_code());
        $this->assertSame([], $this->writes);
    }

    public function test_unknown_setting_keys_are_dropped_and_known_ones_are_coerced_to_the_default_type(): void
    {
        WC_Multi_Store_Config_Manager::import(['plugin_version' => '3.1.0', 'settings' => [
            'enabled' => 'true',
            'circuit_breaker_threshold' => '25',
            'sync_type_default' => 'price_quantity',
            'evil_option' => 'x',
            'cleanup_on_uninstall' => ['nested'],
            'stock_sync_enabled' => 'false',
        ]]);

        $saved = $this->options['wc_multi_store_sync_settings'];
        $this->assertTrue($saved['enabled']);
        $this->assertSame(25, $saved['circuit_breaker_threshold']);
        $this->assertSame('price_quantity', $saved['sync_type_default']);
        $this->assertFalse($saved['stock_sync_enabled']);
        $this->assertArrayNotHasKey('evil_option', $saved);
        $this->assertArrayNotHasKey('cleanup_on_uninstall', $saved, 'an array cannot become a flag');
    }

    public function test_scheduled_settings_are_clamped_to_the_same_limits_as_the_settings_screen(): void
    {
        WC_Multi_Store_Config_Manager::import(['plugin_version' => '3.1.0', 'scheduled_settings' => [
            'batch_size_peak' => 0,
            'batch_size_offpeak' => 100000,
            'peak_start_hour' => -4,
            'peak_end_hour' => 99,
            'sync_modified_hours' => 100000,
            'scheduled_sync_interval' => 'every-second',
            'scheduled_sync_type' => '<script>',
            'evil' => 1,
        ]]);

        $saved = $this->options['wc_multi_store_sync_scheduled'];
        $this->assertSame(1, $saved['batch_size_peak']);
        $this->assertSame(500, $saved['batch_size_offpeak']);
        $this->assertSame(0, $saved['peak_start_hour']);
        $this->assertSame(23, $saved['peak_end_hour']);
        $this->assertSame(168, $saved['sync_modified_hours']);
        $this->assertSame('10min', $saved['scheduled_sync_interval']);
        $this->assertSame('use_default', $saved['scheduled_sync_type']);
        $this->assertArrayNotHasKey('evil', $saved);
    }

    public function test_valid_scheduled_settings_pass_through(): void
    {
        WC_Multi_Store_Config_Manager::import(['plugin_version' => '3.1.0', 'scheduled_settings' => [
            'batch_size_peak' => '25', 'batch_size_offpeak' => 500, 'peak_start_hour' => '6', 'peak_end_hour' => 23, 'scheduled_sync_interval' => 'hourly',
        ]]);

        $saved = $this->options['wc_multi_store_sync_scheduled'];
        $this->assertSame([25, 500, 6, 23, 'hourly'], [$saved['batch_size_peak'], $saved['batch_size_offpeak'], $saved['peak_start_hour'], $saved['peak_end_hour'], $saved['scheduled_sync_interval']]);
    }

    public function test_webhook_retention_is_clamped_and_the_secret_survives_a_file_that_omits_it(): void
    {
        $this->options['wc_multi_store_sync_webhook_settings'] = ['webhook_secret' => 'keep-me', 'enabled' => false];

        WC_Multi_Store_Config_Manager::import(['plugin_version' => '3.1.0', 'webhook_settings' => [
            'enabled' => true, 'webhook_log_retention_days' => 100000, 'evil' => 'x',
        ]]);

        $saved = $this->options['wc_multi_store_sync_webhook_settings'];
        $this->assertSame(180, $saved['webhook_log_retention_days']);
        $this->assertSame('keep-me', $saved['webhook_secret']);
        $this->assertTrue($saved['enabled']);
        $this->assertArrayNotHasKey('evil', $saved);

        WC_Multi_Store_Config_Manager::import(['plugin_version' => '3.1.0', 'webhook_settings' => ['enabled' => true, 'webhook_log_retention_days' => 1]]);
        $this->assertSame(30, $this->options['wc_multi_store_sync_webhook_settings']['webhook_log_retention_days']);
    }

    public function test_order_settings_cannot_introduce_new_keys_or_change_types(): void
    {
        $this->options['wc_multi_store_sync_orders'] = ['auto_sync_enabled' => false, 'sync_statuses' => ['processing'], 'debounce_timeout' => 30];

        WC_Multi_Store_Config_Manager::import(['plugin_version' => '3.1.0', 'order_settings' => [
            'auto_sync_enabled' => '1', 'sync_statuses' => 'completed', 'debounce_timeout' => '45', 'evil' => 1,
        ]]);

        $saved = $this->options['wc_multi_store_sync_orders'];
        $this->assertTrue($saved['auto_sync_enabled']);
        $this->assertSame(45, $saved['debounce_timeout']);
        $this->assertSame(['processing'], $saved['sync_statuses'] ?? ['processing'], 'a string cannot replace a list');
        $this->assertArrayNotHasKey('evil', $saved);
    }

    public static function falsyIncludeKeysProvider(): array
    {
        return [['false'], ['0'], [''], ['no'], ['off']];
    }

    #[DataProvider('falsyIncludeKeysProvider')]
    public function test_ajax_export_treats_textual_false_as_no_keys(string $flag): void
    {
        $out = $this->captureAjax();
        Functions\when('current_user_can')->justReturn(true);
        $this->options['wc_multi_store_sync_stores'] = ['https://a.example.com' => $this->validStore(['consumer_key' => 'ck_AAA'])];
        $_POST = ['include_keys' => $flag];

        WC_Multi_Store_Config_Manager::ajax_export();

        $this->assertStringNotContainsString('ck_AAA', json_encode($out['success']['config']));
    }

    public static function truthyIncludeKeysProvider(): array
    {
        return [['1'], ['true'], ['on'], ['yes']];
    }

    #[DataProvider('truthyIncludeKeysProvider')]
    public function test_ajax_export_includes_keys_only_for_an_explicit_true(string $flag): void
    {
        $out = $this->captureAjax();
        Functions\when('current_user_can')->justReturn(true);
        $this->options['wc_multi_store_sync_stores'] = ['https://a.example.com' => $this->validStore(['consumer_key' => 'ck_AAA'])];
        $_POST = ['include_keys' => $flag];

        WC_Multi_Store_Config_Manager::ajax_export();

        $this->assertStringContainsString('ck_AAA', json_encode($out['success']['config']));
    }
}
