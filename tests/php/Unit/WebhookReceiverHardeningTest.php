<?php
/**
 * Adversarial / end-to-end tests for WC_Multi_Store_Webhook_Receiver.
 *
 * These drive the public entry points (permission callback + REST handler)
 * with hostile or malformed input and assert the safe outcome: no stock is
 * touched, nothing sensitive is echoed back, the loop-prevention flag is
 * always cleared, and replays are suppressed.
 */

use Brain\Monkey\Functions;

class WebhookReceiverHardeningTest extends WC_Multi_Store_TestCase
{
    private const SECRET = 'S3cr3t-value-that-must-never-leak';
    private const STORE  = 'https://store1.example.com';

    private WC_Multi_Store_Webhook_Receiver $receiver;

    /** @var array<string, mixed> */
    private mixed $webhookSettings = [];

    /** @var array<string, mixed> */
    private array $stores = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    private array $receipts = [];

    /** @var array<int, int> product id => stock */
    private array $stock = [];

    /** @var array<string, int> sku => product id */
    private array $skus = [];

    /** @var array<int, bool> product id => manages stock */
    private array $managed = [];

    /** @var list<string> */
    private array $sql = [];

    /** @var list<string> */
    private array $logMessages = [];

    /** @var list<array{0:int,1:string,2:int}> */
    private array $queued = [];

    /** @var list<array{0:int,1:int,2:string}> wc_update_product_stock calls */
    private array $wcStockCalls = [];

    /** @var list<bool> value of the loop flag each time stock is written */
    private array $flagDuringWrite = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->webhookSettings = [
            'enabled'              => true,
            'webhook_secret'       => self::SECRET,
            'trigger_statuses'     => ['processing', 'completed'],
            'allow_negative_stock' => false,
        ];
        $this->stores          = [self::STORE => ['status' => 'active']];
        $this->transients      = [];
        $this->receipts        = [];
        $this->stock           = [];
        $this->skus            = [];
        $this->managed         = [];
        $this->sql             = [];
        $this->logMessages     = [];
        $this->queued          = [];
        $this->wcStockCalls    = [];
        $this->flagDuringWrite = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        unset($GLOBALS['wc_mss_test_wp_cache_incr_return'], $GLOBALS['wc_mss_test_use_object_cache']);

        Functions\when('add_action')->justReturn(true);
        Functions\when('register_rest_route')->justReturn(true);
        Functions\when('get_option')->alias(function (string $opt, mixed $default = false): mixed {
            return match ($opt) {
                'wc_multi_store_sync_webhook_settings' => $this->webhookSettings,
                'wc_multi_store_sync_stores'           => $this->stores,
                default                                => $this->receipts[$opt] ?? $default,
            };
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = null): bool {
            $this->assertFalse($autoload);
            $this->receipts[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key): bool {
            unset($this->receipts[$key]);
            return true;
        });
        Functions\when('get_transient')->alias(fn(string $k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function (string $k, mixed $v, int $ttl = 0): bool {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('untrailingslashit')->alias(fn($s) => rtrim($s, '/\\'));
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wc_delete_product_transients')->justReturn(null);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));

        Functions\when('wc_get_product_id_by_sku')->alias(fn(string $sku) => $this->skus[$sku] ?? 0);
        Functions\when('wc_get_product')->alias(fn($id) => isset($this->stock[$id]) ? $this->makeProduct((int) $id) : false);
        Functions\when('wc_update_product_stock')->alias(function (int $id, int $qty, string $op) {
            $this->wcStockCalls[]    = [$id, $qty, $op];
            $this->flagDuringWrite[] = WC_Multi_Store_Webhook_Receiver::$is_processing_webhook;
            $this->stock[$id]       -= $qty;
            return $this->stock[$id];
        });
        Functions\when('wc_get_logger')->justReturn(new class ($this->logMessages) {
            /** @param list<string> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function log(string $level, string $message, array $context = []): void
            {
                $this->sink[] = $message;
            }
        });

        $test = $this;
        global $wpdb;
        $wpdb = new class ($test) extends wpdb {
            public function __construct(private object $test)
            {
            }
            public function prepare(string $query, mixed ...$args): string
            {
                $i = 0;
                return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
                    $v = $args[$i++] ?? '';
                    return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
                }, $query);
            }
            public function query(string $query): int|false
            {
                return $this->test->handleQuery($query);
            }
        };

        $queue = Mockery::mock('WC_Multi_Store_Queue_Manager');
        $queue->shouldReceive('add_product')->andReturnUsing(function (int $id, string $trigger, int $prio = 0) {
            $this->queued[] = [$id, $trigger, $prio];
            return 1;
        });
        WC_Multi_Store_Sync::reset_instance();
        WC_MSS()->queue_manager = $queue;

        WC_Multi_Store_Settings::clear_static_cache();
        $this->receiver = new WC_Multi_Store_Webhook_Receiver();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $GLOBALS['wc_mss_test_wp_cache_incr_return'], $GLOBALS['wc_mss_test_use_object_cache']);
        WC_Multi_Store_Sync::reset_instance();
        parent::tearDown();
    }

    /** Called by the anonymous wpdb: emulates the guarded atomic UPDATE. */
    public function handleQuery(string $query): int
    {
        if (str_starts_with($query, 'INSERT IGNORE INTO wp_options')) {
            $this->assertStringContainsString("'no')", $query, 'durable receipts must never autoload');
            preg_match("/VALUES \(\x27([^\x27]+)\x27, /", $query, $matches);
            $key = $matches[1];
            if (isset($this->receipts[$key])) {
                return 0;
            }
            $this->receipts[$key] = ['state' => 'pending'];
            return 1;
        }
        $this->sql[] = $query;
        if (preg_match('/- (\d+)\s+WHERE post_id = (\d+).*>= (\d+)/s', $query, $m)) {
            [, $qty, $id, $min] = $m;
            $id = (int) $id;
            if (isset($this->stock[$id]) && $this->stock[$id] >= (int) $min) {
                $this->stock[$id] -= (int) $qty;
                return 1;
            }
            return 0;
        }
        return 1;
    }

    private function makeProduct(int $id): WC_Product
    {
        $test = $this;
        return new class ($id, $test) extends WC_Product {
            public function __construct(private int $id, private object $test)
            {
            }
            public function get_id(): int
            {
                return $this->id;
            }
            public function get_sku(): string
            {
                return (string) (array_search($this->id, $this->test->skuMap(), true) ?: '');
            }
            public function get_stock_quantity(): ?int
            {
                return $this->test->stockOf($this->id);
            }
            public function managing_stock(): bool
            {
                return $this->test->isManaged($this->id);
            }
            public function set_stock_status(string $s): void
            {
            }
            public function save(): int
            {
                return $this->id;
            }
        };
    }

    public function skuMap(): array
    {
        return $this->skus;
    }

    public function stockOf(int $id): int
    {
        return $this->stock[$id];
    }

    public function isManaged(int $id): bool
    {
        return $this->managed[$id] ?? true;
    }

    private function addProduct(int $id, string $sku, int $stock, bool $managed = true): void
    {
        $this->stock[$id]   = $stock;
        $this->skus[$sku]   = $id;
        $this->managed[$id] = $managed;
    }

    private function request(array $order, ?string $storeUrl = self::STORE): WP_REST_Request
    {
        $r = new WP_REST_Request();
        $r->set_body(json_encode($order));
        if ($storeUrl !== null) {
            $r->set_param('store_url', $storeUrl);
        }
        return $r;
    }

    private function order(array $items, int $id = 500, string $status = 'processing'): array
    {
        return ['id' => $id, 'status' => $status, 'line_items' => $items];
    }

    private function signed(string $body, ?string $secret = null): WP_REST_Request
    {
        $r = new WP_REST_Request();
        $r->set_body($body);
        $r->set_header('x-wc-webhook-signature', base64_encode(hash_hmac('sha256', $body, $secret ?? self::SECRET, true)));
        return $r;
    }

    // ── authentication ─────────────────────────────────────────────────────

    public function test_signature_over_a_different_body_is_rejected(): void
    {
        $good = '{"id":1,"status":"processing","line_items":[{"sku":"A","quantity":1}]}';
        $r    = $this->signed($good);
        $r->set_body('{"id":1,"status":"processing","line_items":[{"sku":"A","quantity":999}]}');

        $result = $this->receiver->verify_webhook_signature($r);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_signature', $result->get_error_code());
    }

    public function test_signature_made_with_wrong_secret_is_rejected(): void
    {
        $result = $this->receiver->verify_webhook_signature($this->signed('{"a":1}', 'another-secret'));

        $this->assertSame('invalid_signature', $result->get_error_code());
    }

    public function test_hex_encoded_hmac_is_not_accepted_in_place_of_base64(): void
    {
        $body = '{"a":1}';
        $r    = new WP_REST_Request();
        $r->set_body($body);
        $r->set_header('x-wc-webhook-signature', hash_hmac('sha256', $body, self::SECRET));

        $this->assertSame('invalid_signature', $this->receiver->verify_webhook_signature($r)->get_error_code());
    }

    public function test_base64_signature_must_match_exactly_no_padding_or_case_tolerance(): void
    {
        $body = '{"a":1}';
        $sig  = base64_encode(hash_hmac('sha256', $body, self::SECRET, true));

        foreach ([rtrim($sig, '='), strtolower($sig), $sig . ' ', ' ' . $sig, $sig . "\n", substr($sig, 0, -2)] as $variant) {
            $r = new WP_REST_Request();
            $r->set_body($body);
            $r->set_header('x-wc-webhook-signature', $variant);
            $res = $this->receiver->verify_webhook_signature($r);
            $this->assertInstanceOf(WP_Error::class, $res, 'variant accepted: ' . var_export($variant, true));
        }
    }

    public function test_bad_signature_is_not_rescued_by_a_valid_custom_secret_header(): void
    {
        // The signature branch takes precedence; a fallback to the secret
        // header after a failed signature would let a replayed secret win.
        $r = $this->signed('{"a":1}', 'wrong');
        $r->set_header('x-wc-mss-secret', self::SECRET);

        $this->assertSame('invalid_signature', $this->receiver->verify_webhook_signature($r)->get_error_code());
    }

    public function test_secret_header_must_match_exactly(): void
    {
        foreach ([self::SECRET . ' ', ' ' . self::SECRET, strtoupper(self::SECRET), substr(self::SECRET, 0, -1), self::SECRET . self::SECRET] as $v) {
            $r = new WP_REST_Request();
            $r->set_header('x-wc-mss-secret', $v);
            $res = $this->receiver->verify_webhook_signature($r);
            $this->assertInstanceOf(WP_Error::class, $res, 'accepted: ' . $v);
            $this->assertSame('invalid_secret', $res->get_error_code());
        }
    }

    public function test_zero_string_credentials_do_not_authenticate(): void
    {
        foreach (['x-wc-webhook-signature', 'x-wc-mss-secret'] as $header) {
            $r = new WP_REST_Request();
            $r->set_header($header, '0');
            $res = $this->receiver->verify_webhook_signature($r);
            $this->assertInstanceOf(WP_Error::class, $res);
            $this->assertContains($res->get_error_code(), ['missing_auth', 'invalid_secret', 'invalid_signature']);
        }
    }

    public function test_empty_header_values_do_not_authenticate_against_empty_secret_or_real_secret(): void
    {
        $r = new WP_REST_Request();
        $r->set_header('x-wc-mss-secret', '');
        $r->set_header('x-wc-webhook-signature', '');

        $res = $this->receiver->verify_webhook_signature($r);

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('missing_auth', $res->get_error_code());
    }

    public function test_missing_secret_in_settings_rejects_even_a_matching_empty_credential(): void
    {
        foreach ([null, '', false, 0, '0'] as $configured) {
            $this->webhookSettings['webhook_secret'] = $configured;
            WC_Multi_Store_Settings::clear_static_cache();

            // The attacker "knows" the empty secret and sends it HMAC'd with ''.
            $res = $this->receiver->verify_webhook_signature($this->signed('{}', ''));

            $this->assertInstanceOf(WP_Error::class, $res, 'secret=' . var_export($configured, true));
            $this->assertSame('webhook_not_configured', $res->get_error_code());
            $this->assertSame(503, $res->get_error_data()['status'] ?? null);
        }
    }

    public function test_secret_key_absent_from_settings_array_is_rejected(): void
    {
        unset($this->webhookSettings['webhook_secret']);
        WC_Multi_Store_Settings::clear_static_cache();

        $r = new WP_REST_Request();
        $r->set_header('x-wc-mss-secret', 'anything');

        $this->assertSame('webhook_not_configured', $this->receiver->verify_webhook_signature($r)->get_error_code());
    }

    public function test_corrupt_non_array_settings_option_is_rejected_not_fatal(): void
    {
        $this->webhookSettings = 'corrupted';
        WC_Multi_Store_Settings::clear_static_cache();

        $r = new WP_REST_Request();
        $r->set_header('x-wc-mss-secret', 'x');

        $this->assertSame('webhook_not_configured', $this->receiver->verify_webhook_signature($r)->get_error_code());
    }

    public function test_auth_errors_never_reveal_secret_or_expected_signature(): void
    {
        $body     = '{"id":1}';
        $expected = base64_encode(hash_hmac('sha256', $body, self::SECRET, true));

        $bad = $this->signed($body, 'wrong');
        $r1  = $this->receiver->verify_webhook_signature($bad);
        $r2  = $this->receiver->verify_webhook_signature((function () {
            $r = new WP_REST_Request();
            $r->set_header('x-wc-mss-secret', 'nope');
            return $r;
        })());
        $r3 = $this->receiver->verify_webhook_signature(new WP_REST_Request());

        foreach ([$r1, $r2, $r3] as $err) {
            $dump = $err->get_error_message() . json_encode($err->get_error_data()) . $err->get_error_code();
            $this->assertStringNotContainsString(self::SECRET, $dump);
            $this->assertStringNotContainsString($expected, $dump);
            $this->assertSame(401, $err->get_error_data()['status']);
        }
        foreach ($this->logMessages as $line) {
            $this->assertStringNotContainsString(self::SECRET, $line);
            $this->assertStringNotContainsString($expected, $line);
        }
    }

    public function test_auth_failures_are_audited_with_the_caller_ip(): void
    {
        $rows = [];
        global $wpdb;
        $wpdb = new class ($rows) extends wpdb {
            public function __construct(private array &$rows)
            {
            }
            public function insert(string $table, array $data, ?array $format = null): int|false
            {
                $this->rows[] = $data;
                return 1;
            }
        };

        $this->receiver->verify_webhook_signature(new WP_REST_Request());

        $this->assertCount(1, $rows);
        $this->assertSame('auth_failed', $rows[0]['log_type']);
        $this->assertSame('203.0.113.9', $rows[0]['request_ip']);
        $this->assertSame('failed', $rows[0]['status']);
    }

    // ── rate limiting ──────────────────────────────────────────────────────

    public function test_rate_limit_boundary_100_allowed_101_blocked_via_object_cache(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache'] = true;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = 100;
        $this->assertTrue($this->receiver->verify_webhook_signature($this->signed('{}')));

        $GLOBALS['wc_mss_test_use_object_cache'] = true;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = 101;
        $res = $this->receiver->verify_webhook_signature($this->signed('{}'));

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('rate_limit_exceeded', $res->get_error_code());
        $this->assertSame(429, $res->get_error_data()['status']);
    }

    public function test_rate_limit_applies_before_auth_so_valid_creds_cannot_bypass_it(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache'] = true;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = 5000;

        $res = $this->receiver->verify_webhook_signature($this->signed('{}'));

        $this->assertSame('rate_limit_exceeded', $res->get_error_code());
    }

    public function test_rate_limit_key_is_per_ip_and_ignores_spoofed_forwarding_headers(): void
    {
        $keys = [];
        $GLOBALS['wc_mss_test_use_object_cache'] = true;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = function (string $key) use (&$keys) {
            $keys[] = $key;
            return 1;
        };

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.78';
        $this->receiver->verify_webhook_signature($this->signed('{}'));
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
        $this->receiver->verify_webhook_signature($this->signed('{}'));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $this->receiver->verify_webhook_signature($this->signed('{}'));
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);

        $this->assertCount(3, $keys);
        $this->assertSame($keys[0], $keys[1], 'rotating X-Forwarded-For must not mint new buckets');
        $this->assertNotSame($keys[0], $keys[2]);
        $this->assertSame('wc_mss_webhook_rate_' . md5('203.0.113.9'), $keys[0]);
    }

    public function test_rate_limit_transient_fallback_increments_with_window_ttl(): void
    {
        $captured = [];
        Functions\when('set_transient')->alias(function (string $k, mixed $v, int $ttl = 0) use (&$captured): bool {
            $captured[$k] = [$v, $ttl];
            return true;
        });
        Functions\when('get_transient')->alias(fn(string $k) => str_contains($k, 'rate') ? 99 : false);

        $this->assertTrue($this->receiver->verify_webhook_signature($this->signed('{}')));

        $key = 'wc_mss_webhook_rate_' . md5('203.0.113.9');
        $this->assertSame([100, WC_Multi_Store_Webhook_Receiver::RATE_LIMIT_WINDOW], $captured[$key]);
    }

    public function test_rate_limited_requests_are_logged_without_auth_failure_noise(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache'] = true;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = 101;
        $types = [];
        global $wpdb;
        $wpdb = new class ($types) extends wpdb {
            public function __construct(private array &$types)
            {
            }
            public function insert(string $table, array $data, ?array $format = null): int|false
            {
                $this->types[] = $data['log_type'];
                return 1;
            }
        };

        $this->receiver->verify_webhook_signature(new WP_REST_Request());

        $this->assertSame(['rate_limited'], $types);
    }

    // ── payload validation ─────────────────────────────────────────────────

    public static function malformedBodies(): array
    {
        return [
            'empty string'        => [''],
            'invalid json'        => ['{"id": 1, "status": '],
            'json null'           => ['null'],
            'empty object'        => ['{}'],
            'empty array'         => ['[]'],
            'truncated unicode'   => ["{\"id\":1,\"status\":\"proc\xC3"],
            'binary garbage'      => ["\x00\x01\x02\xFF\xFE"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedBodies')]
    public function test_malformed_json_bodies_are_rejected_without_touching_stock(string $body): void
    {
        $this->addProduct(10, 'SKU-10', 50);
        $r = new WP_REST_Request();
        $r->set_body($body);
        $r->set_param('store_url', self::STORE);

        $res = $this->receiver->handle_order_webhook($r);

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertContains($res->get_error_code(), ['empty_data', 'invalid_order_data']);
        $this->assertSame(400, $res->get_error_data()['status']);
        $this->assertSame(50, $this->stock[10]);
        $this->assertSame([], $this->sql);
    }

    public static function invalidOrderShapes(): array
    {
        $items = [['sku' => 'SKU-10', 'quantity' => 1]];
        return [
            'missing id'          => [['status' => 'processing', 'line_items' => $items]],
            'id zero'             => [['id' => 0, 'status' => 'processing', 'line_items' => $items]],
            'id non numeric str'  => [['id' => 'abc', 'status' => 'processing', 'line_items' => $items]],
            'id array'            => [['id' => [1], 'status' => 'processing', 'line_items' => $items]],
            'id null'             => [['id' => null, 'status' => 'processing', 'line_items' => $items]],
            'missing status'      => [['id' => 5, 'line_items' => $items]],
            'empty status'        => [['id' => 5, 'status' => '', 'line_items' => $items]],
            'status array'        => [['id' => 5, 'status' => ['processing'], 'line_items' => $items]],
            'status int'          => [['id' => 5, 'status' => 1, 'line_items' => $items]],
            'status only tags'    => [['id' => 5, 'status' => '<b></b>', 'line_items' => $items]],
            'line_items string'   => [['id' => 5, 'status' => 'processing', 'line_items' => 'SKU-10']],
            'line_items int'      => [['id' => 5, 'status' => 'processing', 'line_items' => 7]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrderShapes')]
    public function test_structurally_invalid_orders_get_400_and_no_side_effects(array $order): void
    {
        $this->addProduct(10, 'SKU-10', 50);

        $res = $this->receiver->handle_order_webhook($this->request($order));

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('invalid_order_data', $res->get_error_code());
        $this->assertSame(400, $res->get_error_data()['status']);
        $this->assertSame(50, $this->stock[10]);
        $this->assertSame([], $this->transients, 'no dedup marker for rejected payloads');
    }

    public function test_store_url_must_be_a_non_empty_string(): void
    {
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 1]]);
        foreach ([[['https://store1.example.com']], '', null] as $bad) {
            $r = new WP_REST_Request();
            $r->set_body(json_encode($order));
            if ($bad !== null) {
                $r->set_param('store_url', $bad);
            }
            $res = $this->receiver->handle_order_webhook($r);
            $this->assertInstanceOf(WP_Error::class, $res);
            $this->assertSame('missing_store_url', $res->get_error_code());
        }
    }

    public static function unregisteredStoreUrls(): array
    {
        return [
            'different host'         => ['https://evil.example.com'],
            'scheme downgrade'       => ['http://store1.example.com'],
            'subdomain'              => ['https://sub.store1.example.com'],
            'suffix trick'           => ['https://store1.example.com.evil.test'],
            'userinfo trick'         => ['https://store1.example.com@evil.test'],
            'path appended'          => ['https://store1.example.com/shop'],
            'different port'         => ['https://store1.example.com:8443'],
            'whitespace padded'      => [' https://store1.example.com'],
            'null byte'              => ["https://store1.example.com\0.evil.test"],
            'sql injection'          => ["https://x' OR '1'='1"],
            'script tag'             => ['<script>alert(1)</script>'],
            'prefix only'            => ['https://store1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unregisteredStoreUrls')]
    public function test_only_exact_registered_active_store_urls_are_accepted(string $url): void
    {
        $this->addProduct(10, 'SKU-10', 50);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]]), $url));

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('unknown_store', $res->get_error_code());
        $this->assertSame(403, $res->get_error_data()['status']);
        $this->assertSame(50, $this->stock[10]);
        // The error body must not reflect the attacker-controlled URL back.
        $this->assertStringNotContainsString($url, $res->get_error_message());
    }

    public function test_inactive_or_status_less_store_entries_are_not_registered(): void
    {
        $this->stores = [
            self::STORE => ['status' => 'inactive'],
            'https://store2.example.com' => ['consumer_key' => 'x'],
            'https://store3.example.com' => 'active',
            'https://store4.example.com' => ['status' => 'ACTIVE'],
        ];
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 1]]);

        foreach (array_keys($this->stores) as $url) {
            $res = $this->receiver->handle_order_webhook($this->request($order, $url));
            $this->assertInstanceOf(WP_Error::class, $res, $url);
            $this->assertSame('unknown_store', $res->get_error_code(), $url);
        }
    }

    public function test_trailing_slashes_on_registered_url_are_tolerated_but_nothing_else(): void
    {
        $this->addProduct(10, 'SKU-10', 50);
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 1]]);

        $res = $this->receiver->handle_order_webhook($this->request($order, self::STORE . '/'));

        $this->assertInstanceOf(WP_REST_Response::class, $res);
        $this->assertSame(49, $this->stock[10]);
    }

    public function test_disabled_sync_returns_200_without_touching_stock_or_marking_dedup(): void
    {
        $this->webhookSettings['enabled'] = false;
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 50);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]])));

        $this->assertSame(200, $res->get_status());
        $this->assertFalse($res->get_data()['success']);
        $this->assertSame(50, $this->stock[10]);
        $this->assertSame([], $this->transients);
    }

    // ── status filtering ───────────────────────────────────────────────────

    public static function nonTriggerStatuses(): array
    {
        return [
            'pending'     => ['pending'],
            'on-hold'     => ['on-hold'],
            'cancelled'   => ['cancelled'],
            'refunded'    => ['refunded'],
            'failed'      => ['failed'],
            'trash'       => ['trash'],
            'wrong case'  => ['Processing'],
            'substring'   => ['process'],
            'unknown'     => ['totally-made-up'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonTriggerStatuses')]
    public function test_statuses_outside_the_trigger_list_never_deduct_stock(string $status): void
    {
        $this->addProduct(10, 'SKU-10', 50);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 5]], 77, $status)));

        $this->assertInstanceOf(WP_REST_Response::class, $res);
        $this->assertSame(200, $res->get_status());
        $this->assertSame(50, $this->stock[10]);
        $this->assertSame([], $this->sql);
        $this->assertSame([], $this->wcStockCalls);
        $this->assertSame([], $this->transients);
    }

    public function test_missing_trigger_statuses_setting_falls_back_to_processing_and_completed_only(): void
    {
        unset($this->webhookSettings['trigger_statuses']);
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 50);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 1, 'on-hold')));
        $this->assertSame(50, $this->stock[10]);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 2, 'completed')));
        $this->assertSame(49, $this->stock[10]);
    }

    // ── quantities ─────────────────────────────────────────────────────────

    public static function ignorableQuantities(): array
    {
        return [
            'zero'          => [0],
            'zero string'   => ['0'],
            'text'          => ['lots'],
            'null'          => [null],
            'empty string'  => [''],
            'tiny fraction' => [0.4],
            'bool false'    => [false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ignorableQuantities')]
    public function test_quantities_that_resolve_to_zero_are_skipped_silently(mixed $qty): void
    {
        $this->addProduct(10, 'SKU-10', 50);
        $item = ['sku' => 'SKU-10', 'quantity' => $qty];
        if ($qty === null) {
            unset($item['quantity']);
        }

        $res = $this->receiver->handle_order_webhook($this->request($this->order([$item])));

        $this->assertSame(200, $res->get_status());
        $this->assertSame(0, $res->get_data()['products_processed']);
        $this->assertSame(0, $res->get_data()['products_failed']);
        $this->assertSame(50, $this->stock[10]);
        $this->assertSame([], $this->queued, 'nothing deducted, nothing to propagate');
    }

    public function test_fractional_quantity_truncates_instead_of_rounding_up(): void
    {
        $this->addProduct(10, 'SKU-10', 50);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 2.99]])));

        $this->assertSame(48, $this->stock[10]);
    }

    public static function oversizedQuantities(): array
    {
        return [
            'just over stock'      => [51],
            'million'              => [1000000],
            'int max'              => [PHP_INT_MAX],
            'int max as string'    => ['9223372036854775807'],
            'beyond int max'       => ['99999999999999999999999'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('oversizedQuantities')]
    public function test_quantities_exceeding_stock_never_go_negative_when_not_allowed(mixed $qty): void
    {
        $this->addProduct(10, 'SKU-10', 50);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => $qty]])));

        $this->assertInstanceOf(WP_REST_Response::class, $res);
        $this->assertSame(50, $this->stock[10], 'stock must be untouched');
        $this->assertSame([], $this->sql, 'guarded UPDATE must not even be attempted');
        $data = $res->get_data();
        $this->assertSame(1, $data['products_failed']);
        $this->assertSame(0, $data['products_processed']);
        $this->assertStringContainsString('Insufficient stock', $data['errors'][0]);
        $this->assertArrayNotHasKey('deducted_products', $data);
    }

    public function test_exact_remaining_stock_can_be_deducted_down_to_zero(): void
    {
        $this->addProduct(10, 'SKU-10', 4);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 4]])));

        $this->assertSame(0, $this->stock[10]);
        $this->assertSame(1, $res->get_data()['products_processed']);
        $this->assertSame(0, $res->get_data()['deducted_products'][0]['new_stock']);
    }

    public function test_atomic_update_sql_guards_against_going_below_zero_and_binds_ints(): void
    {
        $this->addProduct(10, 'SKU-10', 20);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => '7']])));

        $this->assertCount(1, $this->sql);
        $this->assertMatchesRegularExpression("/meta_value AS SIGNED\\) - 7\\s+WHERE post_id = 10 AND meta_key = '_stock'\\s+AND CAST\\(meta_value AS SIGNED\\) >= 7/", $this->sql[0]);
        $this->assertSame(13, $this->stock[10]);
    }

    public function test_non_numeric_quantity_is_skipped_instead_of_guessed(): void
    {
        $this->addProduct(10, 'SKU-10', 20);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => '7abc']])));

        $this->assertSame([], $this->sql);
        $this->assertSame(20, $this->stock[10]);
    }

    public function test_negative_quantity_never_deducts(): void
    {
        $this->addProduct(10, 'SKU-10', 10);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => -4]])));

        $this->assertSame(10, $this->stock[10]);
        $this->assertSame([], $this->sql);
    }

    public function test_duplicate_sku_lines_deduct_cumulatively_and_stop_at_zero(): void
    {
        $this->addProduct(10, 'SKU-10', 5);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([
            ['sku' => 'SKU-10', 'quantity' => 3],
            ['sku' => 'SKU-10', 'quantity' => 3],
        ])));

        $data = $res->get_data();
        $this->assertSame(2, $this->stock[10], 'first line deducted, second refused');
        $this->assertSame(1, $data['products_processed']);
        $this->assertSame(1, $data['products_failed']);
    }

    // ── allow_negative_stock ───────────────────────────────────────────────

    public function test_allow_negative_stock_uses_wc_api_and_may_go_below_zero(): void
    {
        $this->webhookSettings['allow_negative_stock'] = true;
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 2);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 5]])));

        $this->assertSame(-3, $this->stock[10]);
        $this->assertSame([[10, 5, 'decrease']], $this->wcStockCalls);
        $this->assertSame([], $this->sql, 'raw SQL path is only for the guarded mode');
        $this->assertSame(-3, $res->get_data()['deducted_products'][0]['new_stock']);
    }

    public static function negativeStockFlagValues(): array
    {
        return [
            'false'        => [false],
            'null'         => [null],
            'zero'         => [0],
            'empty string' => [''],
            'absent'       => ['__absent__'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('negativeStockFlagValues')]
    public function test_falsy_allow_negative_values_keep_the_floor_at_zero(mixed $flag): void
    {
        if ($flag === '__absent__') {
            unset($this->webhookSettings['allow_negative_stock']);
        } else {
            $this->webhookSettings['allow_negative_stock'] = $flag;
        }
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 2);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 5]])));

        $this->assertSame(2, $this->stock[10]);
    }

    // ── product resolution ─────────────────────────────────────────────────

    public function test_unknown_or_blank_sku_is_reported_not_guessed_from_product_id(): void
    {
        $this->addProduct(10, 'SKU-10', 50);
        $items = [
            ['sku' => 'NOPE', 'quantity' => 1, 'product_id' => 10],
            ['sku' => '', 'quantity' => 1, 'product_id' => 10, 'variation_id' => 10],
            ['quantity' => 1, 'product_id' => 10],
        ];

        $res = $this->receiver->handle_order_webhook($this->request($this->order($items)));

        $this->assertSame(50, $this->stock[10], 'an attacker-supplied product_id must never be used to pick a product');
        $this->assertSame(3, $res->get_data()['products_failed']);
        $this->assertSame([], $this->queued);
    }

    public function test_sku_with_sql_and_markup_is_only_ever_passed_to_the_lookup_function(): void
    {
        $this->addProduct(10, 'SKU-10', 50);
        $seen = [];
        Functions\when('wc_get_product_id_by_sku')->alias(function (string $sku) use (&$seen) {
            $seen[] = $sku;
            return 0;
        });

        $res = $this->receiver->handle_order_webhook($this->request($this->order([
            ['sku' => "x'; DROP TABLE wp_postmeta;--", 'quantity' => 1],
            ['sku' => '<script>alert(1)</script>SKU-10', 'quantity' => 1],
        ])));

        $this->assertSame([], $this->sql);
        $this->assertSame("x'; DROP TABLE wp_postmeta;--", $seen[0]);
        $this->assertStringNotContainsString('<script>', $seen[1]);
        $this->assertSame(2, $res->get_data()['products_failed']);
    }

    public function test_non_array_line_item_entries_are_ignored_without_errors(): void
    {
        $this->addProduct(10, 'SKU-10', 50);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([5, 'SKU-10', null, true, [], ['sku' => 'SKU-10', 'quantity' => 2]])));

        $this->assertSame(200, $res->get_status());
        $this->assertSame(48, $this->stock[10]);
        $this->assertSame(1, $res->get_data()['products_processed']);
        $this->assertSame(0, $res->get_data()['products_failed']);
    }

    public function test_product_that_does_not_manage_stock_is_never_modified(): void
    {
        $this->addProduct(10, 'SKU-10', 50, false);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]])));

        $this->assertSame(50, $this->stock[10]);
        $this->assertSame([], $this->sql);
        $this->assertSame([], $this->wcStockCalls);
        $this->assertSame(1, $res->get_data()['products_failed']);
        $this->assertSame([], $this->queued);
    }

    public function test_concurrent_depletion_detected_by_guarded_update_is_reported_as_failure(): void
    {
        $this->addProduct(10, 'SKU-10', 9);
        // Another request drains stock after our pre-check but before the UPDATE.
        global $wpdb;
        $wpdb = new class extends wpdb {
            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }
            public function query(string $query): int|false
            {
                return str_starts_with($query, 'INSERT IGNORE') ? 1 : 0;
            }
        };

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 5]])));

        $data = $res->get_data();
        $this->assertSame(1, $data['products_failed']);
        $this->assertStringContainsString('concurrent deduction', $data['errors'][0]);
        $this->assertSame([], $this->queued, 'a failed deduction must not trigger a sync');
    }

    // ── happy path / propagation ───────────────────────────────────────────

    public function test_successful_order_deducts_queues_high_priority_and_marks_dedup(): void
    {
        $this->addProduct(10, 'SKU-10', 30);
        $this->addProduct(11, 'SKU-11', 8);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([
            ['sku' => 'SKU-10', 'quantity' => 4],
            ['sku' => 'SKU-11', 'quantity' => 8],
        ], 900)));

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame(2, $data['products_processed']);
        $this->assertSame(0, $data['products_failed']);
        $this->assertSame(26, $this->stock[10]);
        $this->assertSame(0, $this->stock[11]);
        $this->assertSame([[10, 'webhook_stock_change', 1], [11, 'webhook_stock_change', 1]], $this->queued);

        $key = 'wc_mss_wh_seen_' . md5(self::STORE . '|900|processing');
        $this->assertArrayHasKey($key, $this->transients);
    }

    public function test_response_body_does_not_leak_internals(): void
    {
        $this->addProduct(10, 'SKU-10', 3);

        $res = $this->receiver->handle_order_webhook($this->request($this->order([
            ['sku' => 'SKU-10', 'quantity' => 99],
            ['sku' => 'MISSING', 'quantity' => 1],
        ])));

        $dump = json_encode($res->get_data());
        foreach ([self::SECRET, 'wp_postmeta', 'UPDATE', 'SELECT', '_stock', 'consumer_key', 'consumer_secret', ABSPATH, 'Stack trace'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump, $needle);
        }
    }

    // ── replay / duplicates ────────────────────────────────────────────────

    public function test_replaying_the_same_webhook_deducts_exactly_once(): void
    {
        $this->addProduct(10, 'SKU-10', 30);
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 4]], 321);

        $first  = $this->receiver->handle_order_webhook($this->request($order));
        $second = $this->receiver->handle_order_webhook($this->request($order));
        $third  = $this->receiver->handle_order_webhook($this->request($order));

        $this->assertSame(26, $this->stock[10]);
        $this->assertArrayNotHasKey('duplicate', $first->get_data());
        foreach ([$second, $third] as $dupe) {
            $this->assertSame(200, $dupe->get_status());
            $this->assertTrue($dupe->get_data()['duplicate']);
            $this->assertSame(321, $dupe->get_data()['order_id']);
        }
        $this->assertCount(1, $this->queued);
    }

    public function test_replay_with_inflated_quantities_is_still_suppressed(): void
    {
        $this->addProduct(10, 'SKU-10', 30);
        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 321)));

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 20]], 321)));

        $this->assertTrue($res->get_data()['duplicate']);
        $this->assertSame(29, $this->stock[10]);
    }

    public function test_same_order_id_with_a_new_trigger_status_is_not_deducted_again(): void
    {
        $this->addProduct(10, 'SKU-10', 30);
        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 321, 'processing')));
        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 321, 'completed')));

        $this->assertSame(29, $this->stock[10]);
        $this->assertCount(1, $this->receipts);
    }

    public function test_same_order_id_from_different_stores_does_not_collide(): void
    {
        $this->stores['https://store2.example.com'] = ['status' => 'active'];
        $this->addProduct(10, 'SKU-10', 30);
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 1]], 321);

        $this->receiver->handle_order_webhook($this->request($order, self::STORE));
        $res = $this->receiver->handle_order_webhook($this->request($order, 'https://store2.example.com'));

        $this->assertArrayNotHasKey('duplicate', $res->get_data());
        $this->assertSame(28, $this->stock[10]);
    }

    public function test_dedup_key_cannot_be_forged_by_shifting_separator_characters(): void
    {
        $this->stores['https://store1.example.com|12'] = ['status' => 'active'];
        $this->addProduct(10, 'SKU-10', 30);

        // (store1, order 12|3?) cannot exist because ids are absint-ed, but
        // (store "…|12", order 3) vs (store1, order 123) must stay distinct.
        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 123), self::STORE));
        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 3), 'https://store1.example.com|12'));

        $this->assertArrayNotHasKey('duplicate', $res->get_data());
    }

    public function test_dedup_marker_is_stored_for_one_hour(): void
    {
        $ttl = null;
        Functions\when('set_transient')->alias(function (string $k, mixed $v, int $t = 0) use (&$ttl): bool {
            if (str_starts_with($k, 'wc_mss_wh_seen_')) {
                $ttl = $t;
            }
            return true;
        });
        $this->addProduct(10, 'SKU-10', 30);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]])));

        $this->assertSame(HOUR_IN_SECONDS, $ttl);
    }

    public function test_no_dedup_marker_when_order_has_no_line_items(): void
    {
        $res = $this->receiver->handle_order_webhook($this->request($this->order([])));

        $this->assertSame(200, $res->get_status());
        $this->assertSame([], $this->transients);
    }

    // ── loop prevention flag ───────────────────────────────────────────────

    public function test_loop_flag_is_raised_only_while_stock_is_written_and_cleared_after(): void
    {
        $this->webhookSettings['allow_negative_stock'] = true;
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 30);

        $this->assertFalse(WC_Multi_Store_Webhook_Receiver::$is_processing_webhook);
        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]])));

        $this->assertSame([true], $this->flagDuringWrite, 'stock hooks must see the flag during the write');
        $this->assertFalse(WC_Multi_Store_Webhook_Receiver::$is_processing_webhook);
    }

    public function test_loop_flag_is_cleared_when_a_downstream_exception_escapes(): void
    {
        $this->webhookSettings['allow_negative_stock'] = true;
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 30);
        Functions\when('wc_update_product_stock')->alias(function (): never {
            throw new RuntimeException('db gone');
        });

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]])));

        $this->assertFalse(WC_Multi_Store_Webhook_Receiver::$is_processing_webhook);
        $this->assertCount(1, $this->receipts, 'uncertain writes must remain claimed');
    }

    public function test_loop_flag_stays_down_when_the_precheck_refuses_the_deduction(): void
    {
        $this->addProduct(10, 'SKU-10', 1);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 5]])));

        $this->assertFalse(WC_Multi_Store_Webhook_Receiver::$is_processing_webhook);
    }

    public function test_loop_flag_is_cleared_after_rejected_requests_even_if_left_set_by_a_prior_call(): void
    {
        // Documents the contract relied on by the hook layer: the handler
        // always leaves the flag low after a *processed* order.
        WC_Multi_Store_Webhook_Receiver::$is_processing_webhook = true;
        $this->addProduct(10, 'SKU-10', 30);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]])));

        $this->assertFalse(WC_Multi_Store_Webhook_Receiver::$is_processing_webhook);
    }

    // ── test endpoint / url builder ────────────────────────────────────────

    public function test_test_endpoint_response_does_not_echo_request_data(): void
    {
        $r = new WP_REST_Request();
        $r->set_param('store_url', '<script>alert(1)</script>');

        $res = $this->receiver->handle_test_webhook($r);

        $this->assertSame(200, $res->get_status());
        $this->assertStringNotContainsString('script', json_encode($res->get_data()));
    }

    public function test_webhook_url_encodes_the_store_url_parameter(): void
    {
        Functions\when('rest_url')->alias(fn($p = '') => 'https://main.example.com/wp-json/' . ltrim($p, '/'));
        Functions\when('add_query_arg')->alias(fn(array $args, string $url) => $url . '?' . http_build_query($args));

        $url = WC_Multi_Store_Webhook_Receiver::get_webhook_url('https://s.example.com/?a=1&b=2#frag');

        $this->assertStringContainsString('/webhook/order?', $url);
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $parsed);
        $this->assertSame(['store_url'], array_keys($parsed));
        $this->assertSame('https://s.example.com/?a=1&b=2#frag', urldecode($parsed['store_url']));
    }

    // ── regression: replay marker semantics ────────────────────────────────

    private function dedupKey(int $order, string $status = 'processing'): string
    {
        return 'wc_mss_wh_seen_' . md5(self::STORE . '|' . $order . '|' . $status);
    }

    public function test_all_failed_order_sets_no_marker_so_a_retry_after_restock_succeeds(): void
    {
        $this->addProduct(10, 'SKU-10', 1);
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 5]], 600);

        $first = $this->receiver->handle_order_webhook($this->request($order));
        $this->assertSame(1, $first->get_data()['products_failed']);
        $this->assertArrayNotHasKey($this->dedupKey(600), $this->transients);

        $this->stock[10] = 20; // restocked
        $retry = $this->receiver->handle_order_webhook($this->request($order));

        $this->assertArrayNotHasKey('duplicate', $retry->get_data());
        $this->assertSame(15, $this->stock[10]);
        $this->assertArrayHasKey($this->dedupKey(600), $this->transients);
    }

    public function test_order_with_only_unknown_skus_sets_no_marker(): void
    {
        $this->receiver->handle_order_webhook($this->request($this->order([
            ['sku' => 'GHOST-1', 'quantity' => 1],
            ['sku' => 'GHOST-2', 'quantity' => 1],
        ], 601)));

        $this->assertSame([], $this->transients);
    }

    public function test_partial_success_sets_marker_so_deducted_lines_are_not_deducted_twice(): void
    {
        $this->addProduct(10, 'SKU-10', 10);
        $order = $this->order([
            ['sku' => 'SKU-10', 'quantity' => 3],
            ['sku' => 'GHOST', 'quantity' => 1],
        ], 602);

        $this->receiver->handle_order_webhook($this->request($order));
        $replay = $this->receiver->handle_order_webhook($this->request($order));

        $this->assertArrayHasKey($this->dedupKey(602), $this->transients);
        $this->assertTrue($replay->get_data()['duplicate']);
        $this->assertSame(7, $this->stock[10]);
    }

    public function test_order_whose_lines_are_all_skipped_without_failures_still_sets_marker(): void
    {
        $this->addProduct(10, 'SKU-10', 10);

        $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 0]], 603)));

        $this->assertArrayHasKey($this->dedupKey(603), $this->transients);
    }

    // ── regression: quantity sanitising ────────────────────────────────────

    public static function skippedQuantities(): array
    {
        return [
            'negative'            => [-4],
            'negative string'     => ['-4'],
            'trailing junk'       => ['7abc'],
            'hex text'            => ['0x1A'],
            'int min'             => [PHP_INT_MIN],
            'negative fraction'   => [-0.5],
            'array'               => [[5]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('skippedQuantities')]
    public function test_invalid_quantities_are_skipped_not_failed_and_never_error_out(mixed $qty): void
    {
        $this->addProduct(10, 'SKU-10', 10);
        $r = new WP_REST_Request();
        $r->set_body(json_encode($this->order([['sku' => 'SKU-10', 'quantity' => $qty]])));
        $r->set_param('store_url', self::STORE);

        $res = $this->receiver->handle_order_webhook($r);

        $this->assertInstanceOf(WP_REST_Response::class, $res);
        $this->assertSame(200, $res->get_status());
        $this->assertSame(10, $this->stock[10]);
        $this->assertSame(0, $res->get_data()['products_processed']);
        $this->assertSame([], $this->sql);
    }

    public function test_raw_int_min_json_body_does_not_throw(): void
    {
        $this->addProduct(10, 'SKU-10', 10);
        $r = new WP_REST_Request();
        $r->set_body('{"id":5,"status":"processing","line_items":[{"sku":"SKU-10","quantity":-9223372036854775808}]}');
        $r->set_param('store_url', self::STORE);

        $res = $this->receiver->handle_order_webhook($r);

        $this->assertSame(200, $res->get_status());
        $this->assertSame(10, $this->stock[10]);
    }

    // ── regression: per-line exception isolation ───────────────────────────

    public function test_exception_on_one_line_becomes_an_error_entry_and_later_lines_still_run(): void
    {
        $this->webhookSettings['allow_negative_stock'] = true;
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 10);
        $this->addProduct(11, 'SKU-11', 10);
        $this->addProduct(12, 'SKU-12', 10);
        Functions\when('wc_update_product_stock')->alias(function (int $id, int $qty) {
            if ($id === 11) {
                throw new RuntimeException("SQLSTATE[HY000]: secret internals at /var/www/wp-config.php");
            }
            $this->stock[$id] -= $qty;
            return $this->stock[$id];
        });

        $res = $this->receiver->handle_order_webhook($this->request($this->order([
            ['sku' => 'SKU-10', 'quantity' => 1],
            ['sku' => 'SKU-11', 'quantity' => 1],
            ['sku' => 'SKU-12', 'quantity' => 1],
        ], 610)));

        $data = $res->get_data();
        $this->assertSame(200, $res->get_status());
        $this->assertSame(2, $data['products_processed']);
        $this->assertSame(1, $data['products_failed']);
        $this->assertSame(9, $this->stock[10]);
        $this->assertSame(10, $this->stock[11]);
        $this->assertSame(9, $this->stock[12]);
        $this->assertSame(['SKU SKU-11: Stock deduction failed unexpectedly'], $data['errors']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($data), 'exception text must not reach the response');
        $this->assertFalse(WC_Multi_Store_Webhook_Receiver::$is_processing_webhook);
        $this->assertSame([[10, 'webhook_stock_change', 1], [12, 'webhook_stock_change', 1]], $this->queued);
        $this->assertArrayHasKey($this->dedupKey(610), $this->transients, 'partial success must be marked as handled');
    }

    public function test_exception_on_the_only_line_retains_receipt_until_reconciled(): void
    {
        $this->webhookSettings['allow_negative_stock'] = true;
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 10);
        Functions\when('wc_update_product_stock')->alias(function (): never {
            throw new RuntimeException('db gone');
        });

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 611)));

        $this->assertSame(1, $res->get_data()['products_failed']);
        $this->assertTrue(array_values($this->receipts)[0]['deductions_uncertain']);
        $this->assertFalse(WC_Multi_Store_Webhook_Receiver::$is_processing_webhook);
    }

    public function test_product_deleted_during_concurrent_deduction_is_reported_with_its_id(): void
    {
        $this->addProduct(10, 'SKU-10', 9);
        global $wpdb;
        $t = $this;
        $wpdb = new class ($t) extends wpdb {
            public function __construct(private object $t)
            {
            }
            public function prepare(string $q, mixed ...$a): string
            {
                return $q;
            }
            public function query(string $q): int|false
            {
                if (str_starts_with($q, 'INSERT IGNORE')) {
                    return 1;
                }
                $this->t->deleteProduct(10);
                return 0;
            }
        };

        $res = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 5]], 612)));

        $data = $res->get_data();
        $this->assertSame(1, $data['products_failed']);
        $this->assertStringContainsString('#10', $data['errors'][0]);
        $this->assertStringContainsString('concurrent deduction', $data['errors'][0]);
    }

    public function deleteProduct(int $id): void
    {
        unset($this->stock[$id]);
    }

    // ── regression: option type tolerance ──────────────────────────────────

    public function test_non_array_trigger_statuses_falls_back_to_defaults(): void
    {
        foreach (['processing', null, 5, ''] as $bad) {
            $this->webhookSettings['trigger_statuses'] = $bad;
            WC_Multi_Store_Settings::clear_static_cache();
            $this->addProduct(10, 'SKU-10', 10);

            $ok  = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 620, 'completed')));
            $off = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 1]], 621, 'on-hold')));

            $this->assertSame(200, $ok->get_status());
            $this->assertSame(9, $this->stock[10], var_export($bad, true));
            $this->assertSame(9, $this->stock[10]);
            $this->assertStringContainsString('not configured', $off->get_data()['message']);
            $this->transients = [];
            $this->receipts = [];
        }
    }
    public function test_request_local_cache_never_bypasses_the_persistent_rate_bucket(): void
    {
        $GLOBALS['wc_mss_test_use_object_cache'] = false;
        $GLOBALS['wc_mss_test_wp_cache_incr_return'] = 1; // Request-local cache would always allow.
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($this->receiver->verify_webhook_signature($this->signed('{}')));
        }
        $result = $this->receiver->verify_webhook_signature($this->signed('{}'));
        $this->assertSame('rate_limit_exceeded', $result->get_error_code());
    }

    public function test_trailing_slash_and_status_changes_share_a_durable_receipt(): void
    {
        $this->addProduct(10, 'SKU-10', 30);
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 2]], 801);
        $this->receiver->handle_order_webhook($this->request($order, self::STORE . '/'));
        $this->transients = []; // Simulate expiry/cache eviction long after the old one-hour TTL.
        $order['status'] = 'completed';
        $result = (new WC_Multi_Store_Webhook_Receiver())->handle_order_webhook($this->request($order, self::STORE));
        $this->assertTrue($result->get_data()['duplicate']);
        $this->assertSame(28, $this->stock[10]);
        $this->assertCount(1, $this->receipts);
    }

    public function test_pending_receipt_blocks_concurrent_delivery_before_any_second_stock_write(): void
    {
        $this->webhookSettings['allow_negative_stock'] = true;
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 30);
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 2]], 802);
        $concurrent = null;
        Functions\when('wc_update_product_stock')->alias(function ($id, $quantity) use ($order, &$concurrent) {
            $concurrent = (new WC_Multi_Store_Webhook_Receiver())->handle_order_webhook($this->request($order));
            $this->stock[$id] -= $quantity;
            return $this->stock[$id];
        });
        $first = $this->receiver->handle_order_webhook($this->request($order));
        $this->assertSame(200, $first->get_status());
        $this->assertInstanceOf(WP_Error::class, $concurrent);
        $this->assertSame(503, $concurrent->get_error_data()['status']);
        $this->assertSame(28, $this->stock[10]);
    }

    public function test_pending_receipt_is_not_automatically_released_with_age(): void
    {
        $this->addProduct(10, 'SKU-10', 30);
        $this->receipts['wc_mss_wh_order_' . md5(self::STORE . '|803')] = ['state' => 'pending', 'created_at' => 1];
        $result = $this->receiver->handle_order_webhook($this->request($this->order([['sku' => 'SKU-10', 'quantity' => 2]], 803)));
        $this->assertSame(503, $result->get_error_data()['status']);
        $this->assertSame(30, $this->stock[10]);
    }

    public function test_legacy_receipts_cover_both_trigger_statuses_and_url_spellings(): void
    {
        $this->addProduct(10, 'SKU-10', 30);
        foreach (['processing', 'completed'] as $status) {
            foreach ([self::STORE, self::STORE . '/'] as $url) {
                $this->transients = ['wc_mss_wh_seen_' . md5($url . '|804|' . $status) => 1];
                $this->receipts = [];
                $order = $this->order([['sku' => 'SKU-10', 'quantity' => 2]], 804, 'completed');
                $response = $this->receiver->handle_order_webhook($this->request($order));
                $this->assertTrue($response->get_data()['duplicate']);
                $this->transients = [];
                $replay = $this->receiver->handle_order_webhook($this->request($order));
                $this->assertTrue($replay->get_data()['duplicate'], 'legacy receipt must be promoted to a durable receipt');
            }
        }
        $this->assertSame(30, $this->stock[10]);
    }

    public function test_exception_after_stock_write_never_releases_the_order_for_replay(): void
    {
        $this->webhookSettings['allow_negative_stock'] = true;
        WC_Multi_Store_Settings::clear_static_cache();
        $this->addProduct(10, 'SKU-10', 30);
        Functions\when('wc_update_product_stock')->alias(function ($id, $quantity): never {
            $this->stock[$id] -= $quantity;
            throw new RuntimeException('post-write hook failed');
        });
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 2]], 805);
        $first = $this->receiver->handle_order_webhook($this->request($order));
        $this->assertTrue($first->get_data()['deductions_uncertain']);
        $this->transients = [];
        $retry = $this->receiver->handle_order_webhook($this->request($order));
        $this->assertTrue($retry->get_data()['duplicate']);
        $this->assertSame(28, $this->stock[10]);
    }

    public function test_escaping_exception_after_deduction_leaves_pending_receipt_for_reconciliation(): void
    {
        $this->addProduct(10, 'SKU-10', 30);
        $queue = Mockery::mock(WC_Multi_Store_Queue_Manager::class);
        $queue->shouldReceive('add_product')->once()->andThrow(new RuntimeException('queue unavailable'));
        WC_MSS()->queue_manager = $queue;
        $order = $this->order([['sku' => 'SKU-10', 'quantity' => 2]], 806);
        $first = $this->receiver->handle_order_webhook($this->request($order));
        $this->assertSame(503, $first->get_error_data()['status']);
        $retry = $this->receiver->handle_order_webhook($this->request($order));
        $this->assertSame(503, $retry->get_error_data()['status']);
        $this->assertSame(28, $this->stock[10]);
        $this->assertFalse(WC_Multi_Store_Webhook_Receiver::$is_processing_webhook);
    }

}
