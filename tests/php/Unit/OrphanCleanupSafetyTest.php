<?php
/**
 * Safety-focused tests for WC_Multi_Store_Orphan_Cleanup.
 *
 * This class deletes products on remote stores, so these tests concentrate on the
 * guards in front of the delete call: payload normalisation, store registration,
 * nonce/capability checks, trash-vs-delete semantics and failure bookkeeping.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class OrphanCleanupSafetyTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> In-memory option store. */
    private array $options = [];

    /** @var array<int, array{0: string, 1: mixed}> Ordered log of update_option writes. */
    private array $writes = [];

    /** @var string[] URLs passed to wp_remote_request (remote DELETE calls). */
    private array $delete_urls = [];

    /** @var array<int, array<string, mixed>> */
    private array $mails = [];

    private mixed $json_error = null;
    private mixed $json_success = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('WC_Multi_Store_Orphan_Cleanup', false)) {
            require_once dirname(__DIR__, 3) . '/includes/orphan-cleanup.php';
        }

        WC_Multi_Store_Settings::clear_static_cache();

        $this->options = [
            'wc_multi_store_sync_settings' => ['enabled' => true, 'orphan_auto_trash_enabled' => true],
            'wc_multi_store_sync_stores' => [
                'https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck1', 'consumer_secret' => 'cs1', 'name' => 'Store 1'],
                'https://store2.com/' => ['status' => 'active', 'consumer_key' => 'ck2', 'consumer_secret' => 'cs2', 'name' => 'Store 2'],
            ],
            'admin_email' => 'admin@example.com',
        ];
        $this->writes = [];
        $this->delete_urls = [];
        $this->mails = [];
        $this->json_error = null;
        $this->json_success = null;

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(fn($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            $this->writes[] = [$name, $value];
            return true;
        });
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('add_query_arg')->alias(function ($args, $url = '') {
            return is_array($args)
                ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args)
                : $url;
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '[]');
        Functions\when('wp_remote_retrieve_headers')->justReturn(new \ArrayObject());
        Functions\when('wp_remote_retrieve_header')->justReturn(0);
        Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'));
        Functions\when('get_bloginfo')->justReturn('My Site');
        Functions\when('wp_mail')->alias(function ($to, $subject, $body, $headers = []) {
            $this->mails[] = compact('to', 'subject', 'body', 'headers');
            return true;
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            $this->json_error = $data;
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->json_success = $data;
        });
    }

    protected function tearDown(): void
    {
        unset($_POST['store_url'], $_POST['orphans'], $_POST['nonce']);
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────

    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);
        return $ref->invoke($obj, ...$args);
    }

    private function stubRemoteDelete(?callable $decider = null): void
    {
        Functions\when('wp_remote_request')->alias(function ($url) use ($decider) {
            $this->delete_urls[] = $url;
            if ($decider) {
                $custom = $decider($url);
                if ($custom !== null) {
                    return $custom;
                }
            }
            return ['response' => ['code' => 200], 'body' => json_encode(['deleted' => true])];
        });
    }

    private function localCatalog(array $skus): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn([], $skus);
    }

    /** Subclass whose background scheduling is forced to a given outcome. */
    private function cleanupWithScheduling(bool $schedules, array &$captured): WC_Multi_Store_Orphan_Cleanup
    {
        return new class($schedules, $captured) extends WC_Multi_Store_Orphan_Cleanup {
            public function __construct(private bool $schedules, private array &$captured)
            {
            }

            public function schedule_background_cleanup(array $orphans): bool
            {
                $this->captured[] = $orphans;
                return $this->schedules;
            }

            public function schedule_background_scan(string $store_url = ''): bool
            {
                $this->captured[] = $store_url;
                return $this->schedules;
            }
        };
    }

    // ── trait configuration ──────────────────────────────────────

    public function test_default_settings_disable_auto_trash(): void
    {
        $this->assertSame(['enabled' => false], WC_Multi_Store_Orphan_Cleanup::default_settings());
    }

    public function test_feature_label_and_prefix(): void
    {
        $this->assertSame('Orphan auto-trash', WC_Multi_Store_Orphan_Cleanup::feature_label());
        $this->assertSame('orphan_auto_trash', WC_Multi_Store_Orphan_Cleanup::central_settings_prefix());
    }

    public function test_is_enabled_reads_prefixed_central_key(): void
    {
        $this->assertTrue(WC_Multi_Store_Orphan_Cleanup::is_enabled());

        $this->options['wc_multi_store_sync_settings'] = ['enabled' => true, 'orphan_auto_trash_enabled' => false];
        WC_Multi_Store_Settings::clear_static_cache();
        $this->assertFalse(WC_Multi_Store_Orphan_Cleanup::is_enabled());

        // The un-prefixed generic key must not enable the destructive job.
        $this->options['wc_multi_store_sync_settings'] = ['enabled' => true];
        WC_Multi_Store_Settings::clear_static_cache();
        $this->assertFalse(WC_Multi_Store_Orphan_Cleanup::is_enabled());
    }

    public function test_constructor_registers_all_ajax_and_background_hooks(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(function ($hook) use (&$hooks) {
            $hooks[] = $hook;
            return true;
        });

        new WC_Multi_Store_Orphan_Cleanup();

        foreach ([
            'wp_ajax_wc_mss_scan_orphans',
            'wp_ajax_wc_mss_cleanup_orphans',
            'wp_ajax_wc_mss_schedule_orphan_scan',
            'wp_ajax_wc_mss_orphan_scan_status',
            'wp_ajax_wc_mss_orphan_cleanup_status',
            WC_Multi_Store_Orphan_Cleanup::BACKGROUND_HOOK,
            WC_Multi_Store_Orphan_Cleanup::AUTO_TRASH_HOOK,
            WC_Multi_Store_Orphan_Cleanup::CLEANUP_HOOK,
        ] as $expected) {
            $this->assertContains($expected, $hooks);
        }
        // No nopriv (logged-out) variants may ever be registered for destructive handlers.
        $this->assertSame([], array_filter($hooks, fn($h) => str_starts_with($h, 'wp_ajax_nopriv_')));
    }

    // ── get_registered_store_url ─────────────────────────────────

    public static function registeredUrlProvider(): array
    {
        return [
            'empty string' => ['', null],
            'exact, no slash' => ['https://store1.com', 'https://store1.com'],
            'registered without slash, given with slash' => ['https://store1.com/', 'https://store1.com'],
            'registered with slash, given exact' => ['https://store2.com/', 'https://store2.com/'],
            'registered with slash, given without' => ['https://store2.com', 'https://store2.com/'],
            'unknown store' => ['https://evil.example', null],
            'unknown store with slash' => ['https://evil.example/', null],
            'only slash' => ['/', null],
            'different scheme is not a match' => ['http://store1.com', null],
            'case differs is not a match' => ['https://STORE1.com', null],
            'subpath is not a match' => ['https://store1.com/sub', null],
        ];
    }

    #[DataProvider('registeredUrlProvider')]
    public function test_get_registered_store_url(string $input, ?string $expected): void
    {
        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $this->assertSame($expected, $this->invoke($cleanup, 'get_registered_store_url', $input));
    }

    // ── normalize_cleanup_orphans ────────────────────────────────

    public static function invalidPayloadProvider(): array
    {
        $s1 = 'https://store1.com';
        return [
            'not json' => ['not json'],
            'empty string' => [''],
            'json null' => ['null'],
            'json scalar number' => ['5'],
            'json scalar string' => ['"https://store1.com"'],
            'json true' => ['true'],
            'associative object, not list' => ['{"store_url":"https://store1.com","product_id":5}'],
            'record is scalar' => ['[5]'],
            'record is null' => ['[null]'],
            'missing product_id' => ['[{"store_url":"' . $s1 . '"}]'],
            'missing store_url' => ['[{"product_id":5}]'],
            'null product_id' => ['[{"store_url":"' . $s1 . '","product_id":null}]'],
            'null store_url' => ['[{"store_url":null,"product_id":5}]'],
            'store_url is int' => ['[{"store_url":1,"product_id":5}]'],
            'store_url is array' => ['[{"store_url":["https://store1.com"],"product_id":5}]'],
            'unregistered store' => ['[{"store_url":"https://evil.example","product_id":5}]'],
            'empty store_url' => ['[{"store_url":"","product_id":5}]'],
            'product_id zero' => ['[{"store_url":"' . $s1 . '","product_id":0}]'],
            'product_id negative' => ['[{"store_url":"' . $s1 . '","product_id":-5}]'],
            'product_id string zero' => ['[{"store_url":"' . $s1 . '","product_id":"0"}]'],
            'product_id string zeros' => ['[{"store_url":"' . $s1 . '","product_id":"000"}]'],
            'product_id string negative' => ['[{"store_url":"' . $s1 . '","product_id":"-5"}]'],
            'product_id trailing junk' => ['[{"store_url":"' . $s1 . '","product_id":"12abc"}]'],
            'product_id padded with space' => ['[{"store_url":"' . $s1 . '","product_id":" 5"}]'],
            'product_id decimal string' => ['[{"store_url":"' . $s1 . '","product_id":"5.5"}]'],
            'product_id float' => ['[{"store_url":"' . $s1 . '","product_id":5.5}]'],
            'product_id whole float' => ['[{"store_url":"' . $s1 . '","product_id":5.0}]'],
            'product_id bool true' => ['[{"store_url":"' . $s1 . '","product_id":true}]'],
            'product_id array' => ['[{"store_url":"' . $s1 . '","product_id":[5]}]'],
            'product_id sql-ish' => ['[{"store_url":"' . $s1 . '","product_id":"5 OR 1=1"}]'],
            'one bad record poisons the whole batch' => [
                '[{"store_url":"' . $s1 . '","product_id":5},{"store_url":"' . $s1 . '","product_id":0}]',
            ],
            'one unregistered store poisons the whole batch' => [
                '[{"store_url":"' . $s1 . '","product_id":5},{"store_url":"https://evil.example","product_id":6}]',
            ],
            'truncated json' => ['[{"store_url":"https://store1.com","product_id":5}'],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_normalize_rejects_invalid_payload(string $payload): void
    {
        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $this->assertNull($this->invoke($cleanup, 'normalize_cleanup_orphans', $payload));
    }

    public function test_normalize_accepts_empty_list_as_empty_array(): void
    {
        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $this->assertSame([], $this->invoke($cleanup, 'normalize_cleanup_orphans', '[]'));
    }

    public function test_normalize_casts_ids_and_strips_unknown_fields(): void
    {
        $payload = json_encode([
            ['store_url' => 'https://store1.com', 'product_id' => '7', 'force' => true, 'name' => 'x', 'sku' => 'S'],
            ['store_url' => 'https://store1.com', 'product_id' => 8],
            ['store_url' => 'https://store1.com', 'product_id' => '007'],
        ]);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $this->invoke($cleanup, 'normalize_cleanup_orphans', $payload);

        $this->assertSame([
            ['store_url' => 'https://store1.com', 'product_id' => 7],
            ['store_url' => 'https://store1.com', 'product_id' => 8],
            ['store_url' => 'https://store1.com', 'product_id' => 7],
        ], $result);
    }

    public function test_normalize_rewrites_url_to_the_registered_slash_variant(): void
    {
        $payload = json_encode([
            ['store_url' => 'https://store2.com', 'product_id' => 1],
            ['store_url' => 'https://store1.com/', 'product_id' => 2],
        ]);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $this->invoke($cleanup, 'normalize_cleanup_orphans', $payload);

        $this->assertSame('https://store2.com/', $result[0]['store_url']);
        $this->assertSame('https://store1.com', $result[1]['store_url']);
    }

    public function test_normalize_sanitizes_store_url_before_matching(): void
    {
        $payload = json_encode([['store_url' => '  https://store1.com<script>', 'product_id' => 3]]);

        $cleanup = new WC_Multi_Store_Orphan_Cleanup();
        $result = $this->invoke($cleanup, 'normalize_cleanup_orphans', $payload);

        $this->assertSame([['store_url' => 'https://store1.com', 'product_id' => 3]], $result);
    }

    // ── ajax response helpers ────────────────────────────────────

    public function test_send_ajax_error_and_success_delegate_to_wp_json(): void
    {
        $cleanup = new WC_Multi_Store_Orphan_Cleanup();

        $this->invoke($cleanup, 'send_ajax_error', ['message' => 'boom']);
        $this->invoke($cleanup, 'send_ajax_success', ['ok' => 1]);

        $this->assertSame(['message' => 'boom'], $this->json_error);
        $this->assertSame(['ok' => 1], $this->json_success);
    }

    // ── with_result_tables ───────────────────────────────────────

    public function test_with_result_tables_skips_stores_without_orphans_and_keeps_input_intact(): void
    {
        $scan = ['success' => true, 'results' => [
            ['store_url' => 'https://store1.com', 'orphans' => []],
            ['store_url' => 'https://store2.com/', 'orphans' => [
                ['id' => 5, 'name' => 'Widget', 'sku' => 'W-1', 'price' => '9', 'stock' => 2],
            ]],
            ['store_url' => 'https://store3.com'],
        ]];
        $original = $scan;

        $out = $this->invoke(new WC_Multi_Store_Orphan_Cleanup(), 'with_result_tables', $scan);

        $this->assertArrayNotHasKey('table_html', $out['results'][0]);
        $this->assertArrayNotHasKey('table_html', $out['results'][2]);
        $this->assertArrayHasKey('table_html', $out['results'][1]);
        $this->assertStringContainsString('orphan-cleanup-1', $out['results'][1]['table_html']);
        $this->assertSame($original, $scan);
        $this->assertSame($original['results'][1]['orphans'], $out['results'][1]['orphans']);
    }

    public function test_with_result_tables_handles_missing_results_key(): void
    {
        $out = $this->invoke(new WC_Multi_Store_Orphan_Cleanup(), 'with_result_tables', ['success' => false, 'message' => 'x']);
        $this->assertSame(['success' => false, 'message' => 'x'], $out);
    }

    // ── AJAX auth / nonce (all five handlers) ────────────────────

    public static function handlerProvider(): array
    {
        return [
            ['ajax_scan_orphans'],
            ['ajax_cleanup_orphans'],
            ['ajax_schedule_scan'],
            ['ajax_get_scan_status'],
            ['ajax_get_cleanup_status'],
        ];
    }

    #[DataProvider('handlerProvider')]
    public function test_handler_verifies_nonce_with_expected_action_and_field(string $handler): void
    {
        $seen = null;
        Functions\when('check_ajax_referer')->alias(function ($action, $field = false) use (&$seen) {
            $seen = [$action, $field];
            throw new \RuntimeException('nonce failed');
        });

        try {
            (new WC_Multi_Store_Orphan_Cleanup())->$handler();
            $this->fail('Handler continued after failed nonce check');
        } catch (\RuntimeException $e) {
            $this->assertSame('nonce failed', $e->getMessage());
        }

        $this->assertSame(['wc_mss_orphan_cleanup', 'nonce'], $seen);
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->delete_urls);
        $this->assertNull($this->json_success);
        $this->assertNull($this->json_error);
    }

    #[DataProvider('handlerProvider')]
    public function test_handler_requires_manage_woocommerce_and_does_nothing_otherwise(string $handler): void
    {
        $capability = null;
        Functions\when('current_user_can')->alias(function ($cap) use (&$capability) {
            $capability = $cap;
            return false;
        });
        $this->stubRemoteDelete();
        $_POST['store_url'] = 'https://store1.com';
        $_POST['orphans'] = json_encode([['store_url' => 'https://store1.com', 'product_id' => 5]]);

        (new WC_Multi_Store_Orphan_Cleanup())->$handler();

        $this->assertSame('manage_woocommerce', $capability);
        $this->assertNull($this->json_success);
        $this->assertIsArray($this->json_error);
        $this->assertNotEmpty($this->json_error['message']);
        $this->assertSame([], $this->delete_urls, 'No remote request may be made without capability');
        $this->assertSame([], $this->writes, 'No state may be written without capability');
    }

    // ── ajax_cleanup_orphans validation ──────────────────────────

    public function test_ajax_cleanup_rejects_missing_orphans_param(): void
    {
        $this->stubRemoteDelete();

        (new WC_Multi_Store_Orphan_Cleanup())->ajax_cleanup_orphans();

        $this->assertSame('Invalid orphan data format.', $this->json_error['message']);
        $this->assertSame([], $this->delete_urls);
    }

    public function test_ajax_cleanup_rejects_array_orphans_param(): void
    {
        $this->stubRemoteDelete();
        $_POST['orphans'] = [['store_url' => 'https://store1.com', 'product_id' => 5]];

        (new WC_Multi_Store_Orphan_Cleanup())->ajax_cleanup_orphans();

        $this->assertSame('Invalid orphan data format.', $this->json_error['message']);
        $this->assertSame([], $this->delete_urls);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_ajax_cleanup_never_deletes_for_invalid_payload(string $payload): void
    {
        $this->stubRemoteDelete();
        $captured = [];
        $_POST['orphans'] = $payload;

        $this->cleanupWithScheduling(true, $captured)->ajax_cleanup_orphans();

        $this->assertSame('Invalid orphan data format.', $this->json_error['message']);
        $this->assertSame([], $this->delete_urls);
        $this->assertSame([], $captured, 'Nothing may be queued for an invalid payload');
        $this->assertNull($this->json_success);
    }

    public function test_ajax_cleanup_empty_list_reports_nothing_specified(): void
    {
        $this->stubRemoteDelete();
        $captured = [];
        $_POST['orphans'] = '[]';

        $this->cleanupWithScheduling(true, $captured)->ajax_cleanup_orphans();

        $this->assertSame('No orphan products specified.', $this->json_error['message']);
        $this->assertSame([], $captured);
        $this->assertSame([], $this->delete_urls);
    }

    public function test_ajax_cleanup_queues_only_normalized_records(): void
    {
        $captured = [];
        $_POST['orphans'] = json_encode([
            ['store_url' => 'https://store2.com', 'product_id' => '12', 'extra' => 'ignored'],
        ]);

        $this->cleanupWithScheduling(true, $captured)->ajax_cleanup_orphans();

        $this->assertSame([[['store_url' => 'https://store2.com/', 'product_id' => 12]]], $captured);
        $this->assertTrue($this->json_success['scheduled']);
        $this->assertNull($this->json_error);
    }

    public function test_ajax_cleanup_accepts_wordpress_slashed_post_data(): void
    {
        $captured = [];
        // WordPress adds slashes to superglobals; the handler must unslash before json_decode.
        $_POST['orphans'] = addslashes(json_encode([['store_url' => 'https://store1.com', 'product_id' => 5]]));

        $this->cleanupWithScheduling(true, $captured)->ajax_cleanup_orphans();

        $this->assertSame([[['store_url' => 'https://store1.com', 'product_id' => 5]]], $captured);
        $this->assertNull($this->json_error);
    }

    public function test_ajax_cleanup_inline_fallback_force_deletes_and_reports_counts(): void
    {
        $this->stubRemoteDelete(fn($url) => str_contains($url, 'products/6')
            ? new \WP_Error('http_fail', 'Timeout')
            : null);
        $captured = [];
        $_POST['orphans'] = json_encode([
            ['store_url' => 'https://store1.com', 'product_id' => 5],
            ['store_url' => 'https://store1.com', 'product_id' => 6],
        ]);

        $this->cleanupWithScheduling(false, $captured)->ajax_cleanup_orphans();

        $this->assertCount(2, $this->delete_urls);
        $this->assertStringContainsString('force=1', $this->delete_urls[0]);
        $this->assertSame('Cleanup complete: 1 deleted, 1 failed.', $this->json_success['message']);
        $this->assertSame(1, $this->json_success['results']['deleted']);
        $this->assertSame(1, $this->json_success['results']['failed']);
        $this->assertStringContainsString('Timeout', $this->json_success['results']['errors'][0]);
    }

    public function test_ajax_cleanup_exception_returns_generic_error_without_leaking_details(): void
    {
        Functions\when('wp_remote_request')->alias(function () {
            throw new \RuntimeException('secret internal detail');
        });
        $captured = [];
        $_POST['orphans'] = json_encode([['store_url' => 'https://store1.com', 'product_id' => 5]]);

        $this->cleanupWithScheduling(false, $captured)->ajax_cleanup_orphans();

        $this->assertSame('An error occurred while cleaning up orphans', $this->json_error['message']);
        $this->assertNull($this->json_error['error'], 'Exception text must not leak when WP_DEBUG is off');
    }

    // ── ajax_scan_orphans ────────────────────────────────────────

    public function test_ajax_scan_rejects_unregistered_store_without_scanning(): void
    {
        $requests = 0;
        Functions\when('wp_remote_get')->alias(function () use (&$requests) {
            $requests++;
            return ['body' => '[]', 'response' => ['code' => 200]];
        });
        $_POST['store_url'] = 'https://evil.example';

        (new WC_Multi_Store_Orphan_Cleanup())->ajax_scan_orphans();

        $this->assertSame('Store not found.', $this->json_error['message']);
        $this->assertSame(0, $requests);
    }

    public function test_scan_aborts_instead_of_flagging_everything_when_local_catalog_is_empty(): void
    {
        $this->localCatalog([]);
        $requests = 0;
        Functions\when('wp_remote_get')->alias(function () use (&$requests) {
            $requests++;
            return ['body' => json_encode([['id' => 1, 'sku' => 'REMOTE-1', 'name' => 'Valid remote product']]), 'response' => ['code' => 200]];
        });

        $result = (new WC_Multi_Store_Orphan_Cleanup())->scan_store_for_orphans();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('aborted', $result['message']);
        $this->assertSame(0, $requests, 'No remote store may be contacted without a trustworthy local catalogue');
    }

    public function test_ajax_scan_resolves_slash_variant_and_scans_only_that_store(): void
    {
        $this->localCatalog(['LOCAL-ONLY']);
        $urls = [];
        Functions\when('wp_remote_get')->alias(function ($url) use (&$urls) {
            $urls[] = $url;
            return ['body' => '[]', 'response' => ['code' => 200]];
        });
        $_POST['store_url'] = 'https://store2.com';

        (new WC_Multi_Store_Orphan_Cleanup())->ajax_scan_orphans();

        $this->assertNull($this->json_error);
        $this->assertSame(1, $this->json_success['total_stores']);
        $this->assertSame('https://store2.com/', $this->json_success['results'][0]['store_url']);
        foreach ($urls as $url) {
            $this->assertStringContainsString('store2.com', $url);
            $this->assertStringNotContainsString('store1.com', $url);
        }
    }

    public function test_ajax_scan_failure_is_reported_as_error_with_scan_payload(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [];
        WC_Multi_Store_Settings::clear_static_cache();

        (new WC_Multi_Store_Orphan_Cleanup())->ajax_scan_orphans();

        $this->assertNull($this->json_success);
        $this->assertFalse($this->json_error['success']);
        $this->assertSame('No active stores configured.', $this->json_error['message']);
    }

    public function test_ajax_scan_exception_returns_generic_message(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andThrow(new \RuntimeException('db down'));

        (new WC_Multi_Store_Orphan_Cleanup())->ajax_scan_orphans();

        $this->assertSame('An error occurred while scanning for orphans', $this->json_error['message']);
        $this->assertNull($this->json_error['error']);
    }

    // ── ajax_schedule_scan ───────────────────────────────────────

    public function test_ajax_schedule_scan_rejects_non_string_store_url(): void
    {
        $captured = [];
        $_POST['store_url'] = ['https://store1.com'];

        $this->cleanupWithScheduling(true, $captured)->ajax_schedule_scan();

        $this->assertSame('Invalid store URL.', $this->json_error['message']);
        $this->assertSame([], $captured);
    }

    public function test_ajax_schedule_scan_rejects_unknown_store(): void
    {
        $captured = [];
        $_POST['store_url'] = 'https://evil.example';

        $this->cleanupWithScheduling(true, $captured)->ajax_schedule_scan();

        $this->assertSame('Store not found.', $this->json_error['message']);
        $this->assertSame([], $captured);
    }

    public function test_ajax_schedule_scan_schedules_registered_variant_and_falls_back_to_admin_email(): void
    {
        $captured = [];
        $_POST['store_url'] = 'https://store2.com';

        $this->cleanupWithScheduling(true, $captured)->ajax_schedule_scan();

        $this->assertSame(['https://store2.com/'], $captured);
        $this->assertSame('admin@example.com', $this->json_success['recipient']);
    }

    public function test_ajax_schedule_scan_without_store_schedules_all_stores_and_uses_configured_recipient(): void
    {
        $this->options['wc_multi_store_sync_email_settings'] = ['recipient_email' => 'ops@example.com'];
        $captured = [];

        $this->cleanupWithScheduling(true, $captured)->ajax_schedule_scan();

        $this->assertSame([''], $captured);
        $this->assertSame('ops@example.com', $this->json_success['recipient']);
    }

    public function test_ajax_schedule_scan_reports_missing_action_scheduler(): void
    {
        $captured = [];

        $this->cleanupWithScheduling(false, $captured)->ajax_schedule_scan();

        $this->assertNull($this->json_success);
        $this->assertStringContainsString('Action Scheduler is not available', $this->json_error['message']);
    }

    // ── status endpoints ─────────────────────────────────────────

    public function test_scan_status_does_not_render_tables_unless_scan_is_done(): void
    {
        $results = ['success' => true, 'results' => [['orphans' => [['id' => 1, 'name' => 'n', 'sku' => 's']]]]];
        $this->options[WC_Multi_Store_Orphan_Cleanup::STATUS_OPTION] = ['status' => 'running'];
        $this->options[WC_Multi_Store_Orphan_Cleanup::RESULTS_OPTION] = $results;

        (new WC_Multi_Store_Orphan_Cleanup())->ajax_get_scan_status();

        $this->assertSame('running', $this->json_success['status']['status']);
        $this->assertSame($results, $this->json_success['results']);
    }

    public function test_scan_status_defaults_to_idle_with_null_results(): void
    {
        (new WC_Multi_Store_Orphan_Cleanup())->ajax_get_scan_status();

        $this->assertSame(['status' => 'idle'], $this->json_success['status']);
        $this->assertNull($this->json_success['results']);
    }

    public function test_cleanup_status_returns_stored_status(): void
    {
        $this->options[WC_Multi_Store_Orphan_Cleanup::CLEANUP_STATUS_OPTION] = ['status' => 'running', 'processed' => 3, 'total' => 9];

        (new WC_Multi_Store_Orphan_Cleanup())->ajax_get_cleanup_status();

        $this->assertSame(['status' => ['status' => 'running', 'processed' => 3, 'total' => 9]], $this->json_success);
    }

    // ── cleanup_orphans ──────────────────────────────────────────

    public function test_cleanup_orphans_targets_the_orphans_own_store(): void
    {
        $this->stubRemoteDelete();

        $result = (new WC_Multi_Store_Orphan_Cleanup())->cleanup_orphans([
            ['store_url' => 'https://store1.com', 'product_id' => 11],
            ['store_url' => 'https://store2.com/', 'product_id' => 22],
        ]);

        $this->assertSame(2, $result['deleted']);
        $this->assertStringContainsString('store1.com', $this->delete_urls[0]);
        $this->assertStringContainsString('products/11', $this->delete_urls[0]);
        $this->assertStringContainsString('store2.com', $this->delete_urls[1]);
        $this->assertStringContainsString('products/22', $this->delete_urls[1]);
        $this->assertStringNotContainsString('store2.com', $this->delete_urls[0]);
    }

    public function test_cleanup_orphans_unknown_store_never_reaches_network_and_does_not_stop_batch(): void
    {
        $this->stubRemoteDelete();

        $result = (new WC_Multi_Store_Orphan_Cleanup())->cleanup_orphans([
            ['store_url' => 'https://evil.example', 'product_id' => 1],
            ['store_url' => 'https://store1.com', 'product_id' => 2],
        ]);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $this->delete_urls);
        $this->assertStringContainsString('store1.com', $this->delete_urls[0]);
        $this->assertSame('Store not found: https://evil.example', $result['errors'][0]);
    }

    public function test_cleanup_orphans_error_message_names_product_store_and_cause(): void
    {
        $this->stubRemoteDelete(fn() => new \WP_Error('x', 'Forbidden'));

        $result = (new WC_Multi_Store_Orphan_Cleanup())->cleanup_orphans([
            ['store_url' => 'https://store1.com', 'product_id' => 77],
        ]);

        $this->assertSame(
            ['Failed to delete product ID 77 from https://store1.com: Forbidden'],
            $result['errors']
        );
        $this->assertSame(0, $result['deleted']);
    }

    public function test_cleanup_orphans_reports_progress_after_each_remote_call(): void
    {
        $this->stubRemoteDelete(fn($url) => str_contains($url, 'products/2')
            ? new \WP_Error('x', 'fail')
            : null);
        $calls = [];

        (new WC_Multi_Store_Orphan_Cleanup())->cleanup_orphans([
            ['store_url' => 'https://store1.com', 'product_id' => 1],
            ['store_url' => 'https://store1.com', 'product_id' => 2],
            ['store_url' => 'https://store1.com', 'product_id' => 3],
        ], true, function (int $deleted, int $failed, int $total) use (&$calls) {
            $calls[] = [$deleted, $failed, $total];
        });

        $this->assertSame([[1, 0, 3], [1, 1, 3], [2, 1, 3]], $calls);
    }

    public function test_cleanup_orphans_trash_mode_never_sends_force_true(): void
    {
        $this->stubRemoteDelete();

        (new WC_Multi_Store_Orphan_Cleanup())->cleanup_orphans([
            ['store_url' => 'https://store1.com', 'product_id' => 5],
        ], false);

        $this->assertStringContainsString('force=0', $this->delete_urls[0]);
        $this->assertStringNotContainsString('force=1', $this->delete_urls[0]);
    }

    public function test_cleanup_orphans_defaults_to_permanent_delete(): void
    {
        $this->stubRemoteDelete();

        (new WC_Multi_Store_Orphan_Cleanup())->cleanup_orphans([
            ['store_url' => 'https://store1.com', 'product_id' => 5],
        ]);

        $this->assertStringContainsString('force=1', $this->delete_urls[0]);
    }

    // ── run_background_cleanup ───────────────────────────────────

    public function test_run_background_cleanup_throttles_progress_writes(): void
    {
        $this->stubRemoteDelete();
        $orphans = [];
        for ($i = 1; $i <= 7; $i++) {
            $orphans[] = ['store_url' => 'https://store1.com', 'product_id' => $i];
        }

        (new WC_Multi_Store_Orphan_Cleanup())->run_background_cleanup($orphans);

        $processed = [];
        foreach ($this->writes as [$name, $value]) {
            if ($name === WC_Multi_Store_Orphan_Cleanup::CLEANUP_STATUS_OPTION) {
                $processed[] = [$value['status'], $value['processed']];
            }
        }

        // start (0), every 5th (5), the last item (7), final summary (7).
        $this->assertSame([['running', 0], ['running', 5], ['running', 7], ['done', 7]], $processed);
    }

    public function test_run_background_cleanup_records_summary_and_errors(): void
    {
        $this->stubRemoteDelete(fn($url) => str_contains($url, 'products/2')
            ? new \WP_Error('x', 'nope')
            : null);

        (new WC_Multi_Store_Orphan_Cleanup())->run_background_cleanup([
            ['store_url' => 'https://store1.com', 'product_id' => 1],
            ['store_url' => 'https://store1.com', 'product_id' => 2],
        ]);

        $status = $this->options[WC_Multi_Store_Orphan_Cleanup::CLEANUP_STATUS_OPTION];
        $this->assertSame('done', $status['status']);
        $this->assertSame(1, $status['deleted']);
        $this->assertSame(1, $status['failed']);
        $this->assertSame(2, $status['processed']);
        $this->assertSame('Cleanup complete: 1 deleted, 1 failed.', $status['message']);
        $this->assertCount(1, $status['errors']);
        $this->assertSame('2024-01-15 12:00:00', $status['finished_at']);
    }

    public function test_run_background_cleanup_with_no_orphans_is_marked_failed(): void
    {
        (new WC_Multi_Store_Orphan_Cleanup())->run_background_cleanup([]);

        $status = $this->options[WC_Multi_Store_Orphan_Cleanup::CLEANUP_STATUS_OPTION];
        $this->assertSame('failed', $status['status']);
        $this->assertSame('No orphan products specified.', $status['message']);
        $this->assertSame(0, $status['processed']);
    }

    public function test_run_background_cleanup_uses_permanent_delete(): void
    {
        $this->stubRemoteDelete();

        (new WC_Multi_Store_Orphan_Cleanup())->run_background_cleanup([
            ['store_url' => 'https://store1.com', 'product_id' => 9],
        ]);

        $this->assertStringContainsString('force=1', $this->delete_urls[0]);
    }

    // ── run_auto_trash ───────────────────────────────────────────

    public function test_run_auto_trash_aborts_without_trashing_when_scan_fails(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [];
        WC_Multi_Store_Settings::clear_static_cache();
        $this->stubRemoteDelete();

        (new WC_Multi_Store_Orphan_Cleanup())->run_auto_trash();

        $this->assertSame([], $this->delete_urls);
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->mails);
    }

    public function test_run_auto_trash_only_trashes_skuless_free_orphans_with_unmatched_sku(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [
            'https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'name' => 'Store 1'],
        ];
        WC_Multi_Store_Settings::clear_static_cache();
        $this->localCatalog(['KEEP-1']);
        $remote = [
            ['id' => 1, 'name' => 'Matches local', 'sku' => 'KEEP-1'],
            ['id' => 2, 'name' => 'No SKU at all', 'sku' => ''],
            ['id' => 3, 'name' => 'Case differs', 'sku' => 'keep-1'],
            ['id' => 4, 'name' => 'True orphan', 'sku' => 'GONE-1', 'price' => '5', 'stock_quantity' => 1],
        ];
        Functions\when('wp_remote_get')->justReturn(['body' => json_encode($remote), 'response' => ['code' => 200]]);
        $this->stubRemoteDelete();

        (new WC_Multi_Store_Orphan_Cleanup())->run_auto_trash();

        sort($this->delete_urls);
        $this->assertCount(2, $this->delete_urls);
        $this->assertStringContainsString('products/3', $this->delete_urls[0]);
        $this->assertStringContainsString('products/4', $this->delete_urls[1]);
        foreach ($this->delete_urls as $url) {
            $this->assertStringContainsString('force=0', $url);
        }
        $status = $this->options[WC_Multi_Store_Orphan_Cleanup::AUTO_TRASH_STATUS_OPTION];
        $this->assertSame(2, $status['trashed']);
        $this->assertSame(0, $status['failed']);
        $this->assertSame('2024-01-15 12:00:00', $status['last_run_at']);
    }

    public function test_run_auto_trash_records_failures_and_emails_the_summary(): void
    {
        $this->options['wc_multi_store_sync_stores'] = [
            'https://store1.com' => ['status' => 'active', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'name' => 'Store 1'],
        ];
        $this->options['wc_multi_store_sync_email_settings'] = ['recipient_email' => 'ops@example.com'];
        WC_Multi_Store_Settings::clear_static_cache();
        $this->localCatalog(['LOCAL-ONLY']);
        $remote = [
            ['id' => 10, 'name' => 'A', 'sku' => 'A'],
            ['id' => 11, 'name' => 'B', 'sku' => 'B'],
        ];
        Functions\when('wp_remote_get')->justReturn(['body' => json_encode($remote), 'response' => ['code' => 200]]);
        $this->stubRemoteDelete(fn($url) => str_contains($url, 'products/11')
            ? new \WP_Error('x', 'denied')
            : null);

        (new WC_Multi_Store_Orphan_Cleanup())->run_auto_trash();

        $status = $this->options[WC_Multi_Store_Orphan_Cleanup::AUTO_TRASH_STATUS_OPTION];
        $this->assertSame(1, $status['trashed']);
        $this->assertSame(1, $status['failed']);
        $this->assertCount(1, $this->mails);
        $this->assertSame('ops@example.com', $this->mails[0]['to']);
        $this->assertSame('[My Site] Orphan Auto-Trash — 1 trashed, 1 failed', $this->mails[0]['subject']);
    }

    // ── send_auto_trash_email ────────────────────────────────────

    private function sendAutoTrashEmail(array $scan, array $cleanup): void
    {
        $this->invoke(new WC_Multi_Store_Orphan_Cleanup(), 'send_auto_trash_email', $scan, $cleanup);
    }

    private function scanWith(array ...$stores): array
    {
        return ['success' => true, 'results' => $stores];
    }

    public function test_auto_trash_email_not_sent_when_nothing_trashed_or_failed(): void
    {
        $this->sendAutoTrashEmail($this->scanWith(['store_url' => 'u', 'total_orphans' => 3]), ['deleted' => 0, 'failed' => 0]);

        $this->assertSame([], $this->mails);
    }

    public function test_auto_trash_email_not_sent_without_any_recipient(): void
    {
        $this->options['admin_email'] = '';

        $this->sendAutoTrashEmail($this->scanWith(), ['deleted' => 2, 'failed' => 0]);

        $this->assertSame([], $this->mails);
    }

    public function test_auto_trash_email_success_uses_green_accent_and_no_failure_note(): void
    {
        $this->sendAutoTrashEmail(
            $this->scanWith(['store_url' => 'https://store1.com', 'store_name' => 'Store 1', 'total_orphans' => 2]),
            ['deleted' => 2, 'failed' => 0]
        );

        $this->assertCount(1, $this->mails);
        $mail = $this->mails[0];
        $this->assertSame('admin@example.com', $mail['to']);
        $this->assertSame('[My Site] Orphan Auto-Trash — 2 trashed, 0 failed', $mail['subject']);
        $this->assertStringContainsString('<h1>2 Product(s) Moved to Trash</h1>', $mail['body']);
        $this->assertStringContainsString('Store 1', $mail['body']);
        $this->assertStringNotContainsString('failed to trash', $mail['body']);
        $this->assertContains('Content-Type: text/html; charset=UTF-8', $mail['headers']);
        $this->assertContains('From: My Site <admin@example.com>', $mail['headers']);
    }

    public function test_auto_trash_email_sent_when_everything_failed_and_notes_failures(): void
    {
        $this->sendAutoTrashEmail(
            $this->scanWith(['store_url' => 'https://store1.com', 'total_orphans' => 3]),
            ['deleted' => 0, 'failed' => 3]
        );

        $this->assertCount(1, $this->mails);
        $this->assertSame('[My Site] Orphan Auto-Trash — 0 trashed, 3 failed', $this->mails[0]['subject']);
        $this->assertStringContainsString('3 product(s) failed to trash', $this->mails[0]['body']);
        $this->assertStringContainsString('<h1>0 Product(s) Moved to Trash</h1>', $this->mails[0]['body']);
    }

    public function test_auto_trash_email_prefers_configured_recipient_over_admin_email(): void
    {
        $this->options['wc_multi_store_sync_email_settings'] = ['recipient_email' => 'ops@example.com'];

        $this->sendAutoTrashEmail($this->scanWith(), ['deleted' => 1, 'failed' => 0]);

        $this->assertSame('ops@example.com', $this->mails[0]['to']);
    }

    public function test_auto_trash_email_skips_zero_orphan_stores_falls_back_to_url_and_escapes_names(): void
    {
        $this->sendAutoTrashEmail(
            $this->scanWith(
                ['store_url' => 'https://clean.example', 'store_name' => 'Clean Store', 'total_orphans' => 0],
                ['store_url' => 'https://nameless.example', 'total_orphans' => 4],
                ['store_url' => 'https://x.example', 'store_name' => '<b>Evil</b>', 'total_orphans' => 1]
            ),
            ['deleted' => 5, 'failed' => 0]
        );

        $body = $this->mails[0]['body'];
        $this->assertStringNotContainsString('Clean Store', $body);
        $this->assertStringContainsString('https://nameless.example', $body);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('<b>Evil</b>', $body);
        $this->assertStringContainsString('>4</td>', $body);
    }
}
