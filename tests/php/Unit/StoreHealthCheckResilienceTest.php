<?php
/**
 * Failure-streak, alerting and credential-test hardening for
 * WC_Multi_Store_Health_Check.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class StoreHealthCheckResilienceTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var array<int, array> */
    private array $mails = [];
    private array $json = ['success' => null, 'error' => null];
    private int $httpCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];
        $this->mails = [];
        $this->json = ['success' => null, 'error' => null];
        $this->httpCalls = 0;

        if (!class_exists('WC_Multi_Store_Health_Check', false)) {
            require_once dirname(__DIR__, 3) . '/includes/store-health-check.php';
        }

        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-03-10 12:00:00');
        Functions\when('wp_parse_url')->alias(fn($url, $c = -1) => $c === -1 ? parse_url($url) : parse_url($url, $c));
        Functions\when('esc_url_raw')->alias(fn($v) => $v);
        Functions\when('get_option')->alias(fn($k, $d = false) => array_key_exists($k, $this->options) ? $this->options[$k] : $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('get_bloginfo')->justReturn('My Site');
        Functions\when('home_url')->justReturn('https://home.example.com');
        Functions\when('wp_mail')->alias(function ($to, $subject, $message) {
            $this->mails[] = [$to, $subject, $message];
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_success')->alias(function ($d = null) {
            $this->json['success'] = $d;
        });
        Functions\when('wp_send_json_error')->alias(function ($d = null) {
            $this->json['error'] = $d;
        });
        Functions\when('wp_remote_get')->alias(function () {
            $this->httpCalls++;
            return ['response' => ['code' => 200], 'body' => '{"id":1,"name":"Admin"}'];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);
        WC_Multi_Store_Settings::clear_static_cache();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    private function track(string $url, bool $healthy, string $message = 'boom'): void
    {
        $hc = new WC_Multi_Store_Health_Check();
        $ref = new ReflectionMethod($hc, 'track_failures');
        $ref->invoke($hc, $url, ['healthy' => $healthy, 'message' => $message, 'checked_at' => '2026-03-10 12:00:00']);
    }

    private function enableMail(array $extra = []): void
    {
        $this->options['wc_multi_store_sync_email_settings'] = ['notifications_enabled' => true] + $extra;
        $this->options['admin_email'] = 'admin@example.com';
    }

    // ── failure streaks ───────────────────────────────────────────

    public function test_alert_fires_once_at_the_third_consecutive_failure_only(): void
    {
        $this->enableMail();

        foreach (range(1, 7) as $_) {
            $this->track('https://a.example.com', false);
        }

        $this->assertCount(1, $this->mails);
        $this->assertSame(7, $this->options['wc_mss_health_failure_counts']['https://a.example.com']);
    }

    public function test_a_success_resets_the_streak_and_a_new_streak_alerts_again(): void
    {
        $this->enableMail();

        foreach ([false, false, true, false, false, false] as $healthy) {
            $this->track('https://a.example.com', $healthy);
        }

        $this->assertCount(1, $this->mails);

        $this->track('https://a.example.com', true);
        $this->assertSame(0, $this->options['wc_mss_health_failure_counts']['https://a.example.com']);
        foreach (range(1, 3) as $_) {
            $this->track('https://a.example.com', false);
        }
        $this->assertCount(2, $this->mails);
    }

    public function test_two_failures_never_alert(): void
    {
        $this->enableMail();
        $this->track('https://a.example.com', false);
        $this->track('https://a.example.com', false);

        $this->assertSame([], $this->mails);
    }

    public function test_streaks_are_tracked_per_store(): void
    {
        $this->enableMail();

        foreach (['https://a.example.com', 'https://b.example.com', 'https://a.example.com', 'https://b.example.com', 'https://a.example.com'] as $url) {
            $this->track($url, false);
        }

        $this->assertCount(1, $this->mails, 'only store A reached three failures');
        $this->assertStringContainsString('a.example.com', $this->mails[0][1]);
        $this->assertSame(2, $this->options['wc_mss_health_failure_counts']['https://b.example.com']);
    }

    public function test_counts_persisted_as_strings_still_reach_the_threshold(): void
    {
        $this->enableMail();
        $this->options['wc_mss_health_failure_counts'] = ['https://a.example.com' => '2'];

        $this->track('https://a.example.com', false);

        $this->assertCount(1, $this->mails);
        $this->assertSame(3, $this->options['wc_mss_health_failure_counts']['https://a.example.com']);
    }

    // ── alert content ─────────────────────────────────────────────

    public function test_alert_is_skipped_silently_when_notifications_are_off(): void
    {
        foreach ([[], ['notifications_enabled' => false], ['notifications_enabled' => 0], ['notifications_enabled' => '']] as $settings) {
            $this->options['wc_multi_store_sync_email_settings'] = $settings;
            foreach (range(1, 3) as $_) {
                $this->track('https://off' . count($this->mails) . '.example.com', false);
            }
        }

        $this->assertSame([], $this->mails);
    }

    public function test_alert_goes_to_configured_recipients_and_names_the_store_host_only_in_the_subject(): void
    {
        $this->enableMail(['recipients' => 'ops@example.com']);

        foreach (range(1, 3) as $_) {
            $this->track('https://user:pw@shop.example.com/path?consumer_key=ck_SECRET', false, 'HTTP 500');
        }

        $this->assertSame('ops@example.com', $this->mails[0][0]);
        $this->assertSame('[My Site] Store Health Alert: shop.example.com', $this->mails[0][1]);
        $this->assertStringNotContainsString('pw@', $this->mails[0][1]);
        $this->assertStringContainsString('HTTP 500', $this->mails[0][2]);
        $this->assertStringContainsString('3 consecutive', $this->mails[0][2]);
    }

    public function test_alert_falls_back_to_the_admin_email_when_no_recipients_key_exists(): void
    {
        $this->enableMail();

        foreach (range(1, 3) as $_) {
            $this->track('https://a.example.com', false);
        }

        $this->assertSame('admin@example.com', $this->mails[0][0]);
    }

    // ── run / check_and_update ────────────────────────────────────

    public function test_check_and_update_store_returns_null_and_writes_nothing_for_unknown_stores(): void
    {
        $hc = new WC_Multi_Store_Health_Check();

        $this->assertNull($hc->check_and_update_store('https://nope.example.com'));
        $this->assertArrayNotHasKey('wc_mss_health_failure_counts', $this->options);
        $this->assertSame(0, $this->httpCalls);
    }

    public function test_run_health_check_with_no_stores_still_records_a_timestamp(): void
    {
        $results = (new WC_Multi_Store_Health_Check())->run_health_check();

        $this->assertSame([], $results);
        $this->assertSame('2026-03-10 12:00:00', $this->options['wc_mss_last_health_check']['timestamp']);
    }

    // ── ajax_test_app_password ────────────────────────────────────

    private function callAppPassword(array $post): void
    {
        $_POST = $post;
        (new WC_Multi_Store_Health_Check())->ajax_test_app_password();
    }

    public static function blockedTargetProvider(): array
    {
        return [
            'aws metadata' => ['https://169.254.169.254'],
            'metadata with path' => ['https://169.254.169.254/latest/meta-data/'],
            'ipv6 link-local' => ['https://[fe80::1]'],
            'no host' => ['https://'],
            'file scheme' => ['file:///etc/passwd'],
            'plain word' => ['localhost-without-scheme'],
        ];
    }

    #[DataProvider('blockedTargetProvider')]
    public function test_credentials_are_never_sent_to_unsafe_targets(string $url): void
    {
        $this->callAppPassword(['store_url' => $url, 'wp_username' => 'admin', 'wp_app_password' => 'abcd efgh']);

        $this->assertSame(0, $this->httpCalls);
        $this->assertNotNull($this->json['error']);
        $this->assertNull($this->json['success']);
    }

    public function test_saved_password_is_only_usable_for_a_url_on_file(): void
    {
        $this->options['wc_multi_store_sync_stores'] = ['https://known.example.com' => [
            'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'wp_username' => 'admin', 'wp_app_password' => 'saved pw',
        ]];

        $this->callAppPassword(['store_url' => 'https://attacker.example.com', 'use_saved_password' => '1']);

        $this->assertSame(0, $this->httpCalls);
        $this->assertSame('Unknown store URL', $this->json['error']['message']);
    }

    public function test_saved_password_is_used_for_a_known_store_and_never_echoed_back(): void
    {
        $this->options['wc_multi_store_sync_stores'] = ['https://1.1.1.1' => [
            'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'wp_username' => 'admin', 'wp_app_password' => 'saved pw',
        ]];
        $captured = null;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$captured) {
            $captured = [$url, $args];
            return ['response' => ['code' => 200], 'body' => '{"id":3,"name":"Admin"}'];
        });

        $this->callAppPassword(['store_url' => 'https://1.1.1.1', 'use_saved_password' => '1']);

        $this->assertSame('Basic ' . base64_encode('admin:saved pw'), $captured[1]['headers']['Authorization']);
        $this->assertStringEndsWith('/wp-json/wp/v2/users/me', $captured[0]);
        $this->assertStringNotContainsString('saved pw', json_encode($this->json));
    }

    public function test_non_string_post_values_are_treated_as_missing(): void
    {
        $this->callAppPassword(['store_url' => ['https://1.1.1.1'], 'wp_username' => ['a'], 'wp_app_password' => ['b']]);

        $this->assertSame(0, $this->httpCalls);
        $this->assertNotNull($this->json['error']);
    }

    public function test_connection_error_message_does_not_expose_the_password(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http_request_failed', 'cURL error 6: could not resolve'));

        $this->callAppPassword(['store_url' => 'https://1.1.1.1', 'wp_username' => 'admin', 'wp_app_password' => 'topsecretpw']);

        $this->assertStringNotContainsString('topsecretpw', json_encode($this->json));
        $this->assertStringContainsString('Connection failed', $this->json['error']['message']);
    }

    public function test_ssl_verification_follows_the_global_setting(): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['disable_ssl_verification' => true];
        $seen = null;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$seen) {
            $seen = $args['sslverify'];
            return ['response' => ['code' => 200], 'body' => '{"id":1,"name":"A"}'];
        });

        $this->callAppPassword(['store_url' => 'https://1.1.1.1', 'wp_username' => 'a', 'wp_app_password' => 'b']);

        $this->assertFalse($seen);
    }

    public function test_unauthenticated_callers_get_nothing(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->callAppPassword(['store_url' => 'https://1.1.1.1', 'wp_username' => 'a', 'wp_app_password' => 'b']);

        $this->assertSame(0, $this->httpCalls);
        $this->assertSame('Insufficient permissions', $this->json['error']['message']);
    }

    // ── regressions ──────────────────────────────────────────────

    public static function emptyRecipientsProvider(): array
    {
        return [[''], [null], [[]], ['   '], [[' ']]];
    }

    #[DataProvider('emptyRecipientsProvider')]
    public function test_empty_recipients_fall_back_to_the_admin_email(mixed $recipients): void
    {
        $this->enableMail(['recipients' => $recipients]);

        foreach (range(1, 3) as $_) {
            $this->track('https://a.example.com', false);
        }

        $this->assertCount(1, $this->mails);
        $this->assertSame('admin@example.com', $this->mails[0][0]);
    }

    public static function corruptCountsProvider(): array
    {
        return [[''], ['garbage'], [false], [null], [7]];
    }

    #[DataProvider('corruptCountsProvider')]
    public function test_a_corrupted_failure_count_option_is_reset_instead_of_crashing(mixed $corrupt): void
    {
        $this->enableMail();
        $this->options['wc_mss_health_failure_counts'] = $corrupt;

        $this->track('https://a.example.com', false);

        $this->assertSame(1, $this->options['wc_mss_health_failure_counts']['https://a.example.com']);
    }
}
