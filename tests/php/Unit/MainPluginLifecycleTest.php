<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Lifecycle code in wc-multi-store-sync.php: activation defaults, database
 * upgrades, post-activation cleanup and deactivation.
 *
 * The bootstrap defines a stub WC_Multi_Store_Sync (the real file bootstraps the
 * whole plugin on include), so the real class body is loaded here under another
 * name, without the constants and the trailing WC_MSS() bootstrap call.
 */
class MainPluginLifecycleTest extends WC_Multi_Store_TestCase
{
    private const SUBJECT = 'WC_Multi_Store_Sync_Lifecycle_Subject';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $addedOptions = [];

    /** @var array<string, mixed> */
    private array $updatedOptions = [];

    /** @var list<string> */
    private array $deletedOptions = [];

    /** @var list<array{0:string,1:string}> [message, level] */
    private array $logged = [];

    private int $tablesCreated = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options        = [];
        $this->addedOptions   = [];
        $this->updatedOptions = [];
        $this->deletedOptions = [];
        $this->logged         = [];
        $this->tablesCreated  = 0;

        self::loadSubjectClass();

        Functions\when('get_option')->alias(fn(string $key, mixed $default = false) => $this->options[$key] ?? $default);
        Functions\when('add_option')->alias(function (string $key, mixed $value = '') {
            $this->addedOptions[]  = $key;
            $this->options[$key]   = $value;
            return true;
        });
        Functions\when('update_option')->alias(function (string $key, mixed $value) {
            $this->updatedOptions[$key] = $value;
            $this->options[$key]        = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $key) {
            $this->deletedOptions[] = $key;
            unset($this->options[$key]);
            return true;
        });
        Functions\when('wp_generate_password')->alias(fn(int $length = 12) => str_repeat('x', $length));
        Functions\when('wp_generate_uuid4')->justReturn('00000000-0000-4000-8000-000000000000');
        Functions\when('wc_get_logger')->justReturn(new class ($this->logged) {
            /** @param list<array{0:string,1:string}> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function log(string $level, string $message, array $context = []): void
            {
                $this->sink[] = [$message, $level];
            }
        });

        // Every create_table() asks $wpdb for the charset exactly once, which
        // makes the number of tables a lifecycle step touches observable.
        $test = $this;
        global $wpdb;
        $wpdb = new class ($test) extends wpdb {
            public string $prefix = 'wp_';

            public function __construct(private object $test)
            {
            }
            public function get_charset_collate(): string
            {
                $this->test->tableCreated();
                return 'DEFAULT CHARACTER SET utf8mb4';
            }
        };

        $this->ensureDbDeltaAvailable();
    }

    /** Public so the anonymous wpdb can reach it. */
    public function tableCreated(): void
    {
        $this->tablesCreated++;
    }

    private static function loadSubjectClass(): void
    {
        if (class_exists(self::SUBJECT, false)) {
            return;
        }

        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/wc-multi-store-sync.php');
        $start  = strpos($source, 'class WC_Multi_Store_Sync {');
        $end    = strpos($source, "/**\n * Returns the main instance of WC_Multi_Store_Sync");
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        eval(str_replace(
            'class WC_Multi_Store_Sync {',
            'class ' . self::SUBJECT . ' {',
            substr($source, $start, $end - $start)
        ));
    }

    /** create_table() requires wp-admin/includes/upgrade.php; shared with SyncHistoryExtendedTest. */
    private function ensureDbDeltaAvailable(): void
    {
        $dir = ABSPATH . 'wp-admin/includes';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!file_exists($dir . '/upgrade.php')) {
            file_put_contents($dir . '/upgrade.php', '<?php function dbDelta($sql) { return []; }');
        }
    }

    private function subject(): object
    {
        return (new ReflectionClass(self::SUBJECT))->newInstanceWithoutConstructor();
    }

    /** @return list<string> */
    private function loggedMessages(): array
    {
        return array_column($this->logged, 0);
    }

    // ── maybe_upgrade_database ─────────────────────────────────────────────

    public function test_upgrade_is_skipped_on_frontend_requests_without_touching_the_database(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);

        $this->subject()->maybe_upgrade_database();

        $this->assertSame(0, $this->tablesCreated);
        $this->assertSame([], $this->updatedOptions);
    }

    public static function backgroundContextProvider(): array
    {
        return [
            'admin'    => [true, false, false],
            'cron'     => [false, true, false],
            'ajax'     => [false, false, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backgroundContextProvider')]
    public function test_fresh_install_creates_every_table_and_records_the_version(bool $admin, bool $cron, bool $ajax): void
    {
        Functions\when('is_admin')->justReturn($admin);
        Functions\when('wp_doing_cron')->justReturn($cron);
        Functions\when('wp_doing_ajax')->justReturn($ajax);

        $this->subject()->maybe_upgrade_database();

        $this->assertSame(9, $this->tablesCreated);
        $this->assertSame('1.9', $this->updatedOptions['wc_mss_db_version']);
        $this->assertContains('Database upgraded to version 1.9', $this->loggedMessages());
    }

    public function test_older_schema_is_upgraded(): void
    {
        Functions\when('is_admin')->justReturn(true);
        $this->options['wc_mss_db_version'] = '1.8';

        $this->subject()->maybe_upgrade_database();

        $this->assertSame(9, $this->tablesCreated);
        $this->assertSame('1.9', $this->options['wc_mss_db_version']);
    }

    public static function currentVersionProvider(): array
    {
        return [
            'exactly current' => ['1.9'],
            'newer (downgrade of plugin)' => ['2.0'],
            'two-digit minor sorts after 1.9' => ['1.10'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('currentVersionProvider')]
    public function test_current_or_newer_schema_is_left_alone(string $stored): void
    {
        Functions\when('is_admin')->justReturn(true);
        $this->options['wc_mss_db_version'] = $stored;

        $this->subject()->maybe_upgrade_database();

        $this->assertSame(0, $this->tablesCreated);
        $this->assertSame([], $this->updatedOptions);
        $this->assertSame([], $this->logged);
    }

    // ── activate ───────────────────────────────────────────────────────────

    public function test_activation_creates_all_tables(): void
    {
        Functions\when('get_option')->alias(fn(string $key, mixed $default = false) => $key === 'admin_email' ? 'owner@example.com' : $default);

        $this->subject()->activate();

        $this->assertSame(10, $this->tablesCreated, 'the 9 upgradable tables plus the conflict-detector table');
    }

    public function test_activation_seeds_every_settings_option_disabled_by_default(): void
    {
        $this->options['admin_email'] = 'owner@example.com';

        $this->subject()->activate();

        $expected = [
            'wc_multi_store_sync_settings',
            'wc_multi_store_sync_stores',
            'wc_multi_store_sync_scheduled',
            'wc_multi_store_sync_orders',
            'wc_multi_store_sync_webhook_settings',
            'wc_multi_store_sync_email_settings',
            'wc_multi_store_sync_weekly_verification',
            'wc_mss_conflict_settings',
            'wc_multi_store_sync_coupon_settings',
            'wc_mss_review_sync_settings',
            'wc_mss_attribute_sync_settings',
            'wc_mss_attribute_remapping_settings',
            'wc_mss_shipping_class_sync_settings',
            'wc_mss_downloadable_files_sync_settings',
            'wc_mss_category_mapper_settings',
        ];
        $this->assertEqualsCanonicalizing($expected, $this->addedOptions);

        // A fresh install must never start syncing (or e-mailing) on its own.
        $this->assertFalse($this->options['wc_multi_store_sync_settings']['enabled']);
        $this->assertFalse($this->options['wc_multi_store_sync_webhook_settings']['enabled']);
        $this->assertFalse($this->options['wc_multi_store_sync_email_settings']['enabled']);
        $this->assertFalse($this->options['wc_multi_store_sync_weekly_verification']['enabled']);
        $this->assertSame([], $this->options['wc_multi_store_sync_stores']);
    }

    public function test_activation_generates_a_webhook_secret_and_mails_the_site_admin(): void
    {
        $this->options['admin_email'] = 'owner@example.com';

        $this->subject()->activate();

        $this->assertSame(32, strlen($this->options['wc_multi_store_sync_webhook_settings']['webhook_secret']));
        $this->assertSame('owner@example.com', $this->options['wc_multi_store_sync_email_settings']['recipient_email']);
        $this->assertSame('owner@example.com', $this->options['wc_multi_store_sync_weekly_verification']['email_recipients']);
    }

    public function test_reactivation_never_overwrites_existing_settings_or_stores(): void
    {
        $stores   = ['https://store.example.com' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs']];
        $settings = ['enabled' => true, 'sync_type_default' => 'quantity'];
        $webhook  = ['enabled' => true, 'webhook_secret' => 'keep-this-secret'];
        $this->options['wc_multi_store_sync_stores']           = $stores;
        $this->options['wc_multi_store_sync_settings']         = $settings;
        $this->options['wc_multi_store_sync_webhook_settings'] = $webhook;

        $this->subject()->activate();

        $this->assertNotContains('wc_multi_store_sync_stores', $this->addedOptions);
        $this->assertNotContains('wc_multi_store_sync_settings', $this->addedOptions);
        $this->assertNotContains('wc_multi_store_sync_webhook_settings', $this->addedOptions);
        $this->assertSame($stores, $this->options['wc_multi_store_sync_stores']);
        $this->assertSame($settings, $this->options['wc_multi_store_sync_settings']);
        $this->assertSame($webhook, $this->options['wc_multi_store_sync_webhook_settings']);
    }

    public function test_activation_flags_cleanup_for_later_and_invalidates_caches(): void
    {
        $this->subject()->activate();

        $this->assertTrue($this->updatedOptions['wc_mss_needs_action_cleanup']);
        // Cache generation is bumped so stale remote data from a previous install is ignored.
        $this->assertArrayHasKey(WC_Multi_Store_Cache_Manager::CACHE_GROUP . '_generation', $this->updatedOptions);
        $this->assertContains('Plugin activated - Version ' . WC_MSS_VERSION, $this->loggedMessages());
    }

    // ── maybe_cleanup_scheduled_actions ────────────────────────────────────

    public function test_cleanup_does_nothing_without_the_activation_flag(): void
    {
        $this->subject()->maybe_cleanup_scheduled_actions();

        $this->assertSame([], $this->deletedOptions);
        $this->assertSame([], $this->logged);
    }

    public function test_cleanup_keeps_the_flag_while_action_scheduler_is_unavailable(): void
    {
        $this->options['wc_mss_needs_action_cleanup'] = true;

        $this->subject()->maybe_cleanup_scheduled_actions();

        // Action Scheduler may not be ready yet; the cleanup must be retried on a later request.
        $this->assertSame([], $this->deletedOptions);
        $this->assertTrue($this->options['wc_mss_needs_action_cleanup']);
    }

    // Defines a global ActionScheduler class, which would otherwise make is_available() true for every later test.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cleanup_deletes_all_but_the_earliest_pending_duplicate_then_clears_the_flag(): void
    {
        eval('
            class ActionScheduler_Store { const STATUS_PENDING = "pending"; }
            class ActionScheduler_Fake_Store {
                public static array $deleted = [];
                public function query_actions(array $args): array { return [10, 11, 12]; }
                public function delete_action($id): void { self::$deleted[] = $id; }
            }
            class ActionScheduler {
                public static function store(): ActionScheduler_Fake_Store { return new ActionScheduler_Fake_Store(); }
            }
        ');
        Functions\when('as_schedule_recurring_action')->justReturn(1);
        Functions\when('as_schedule_single_action')->justReturn(1);
        $this->options['wc_mss_needs_action_cleanup'] = true;

        $this->subject()->maybe_cleanup_scheduled_actions();

        // Two hooks, three duplicates each: the earliest (10) of each hook survives.
        $this->assertSame([11, 12, 11, 12], ActionScheduler_Fake_Store::$deleted);
        $this->assertSame(['wc_mss_needs_action_cleanup'], $this->deletedOptions);
        $this->assertContains('Post-activation cleanup: Removed 4 duplicate scheduled action(s)', $this->loggedMessages());
    }

    // ── deactivate ─────────────────────────────────────────────────────────

    public function test_deactivation_unschedules_recurring_work_and_logs(): void
    {
        $unscheduled = [];
        Functions\when('as_unschedule_all_actions')->alias(function (string $hook) use (&$unscheduled) {
            $unscheduled[] = $hook;
        });

        $this->subject()->deactivate();

        $this->assertContains('wc_multi_store_health_check', $unscheduled);
        $this->assertContains('wc_multi_store_weekly_verification', $unscheduled);
        $this->assertContains('wc_multi_store_sync_remote_orders', $unscheduled);
        $this->assertContains('Plugin deactivated - scheduled events cleared', $this->loggedMessages());
    }

    // ── trivial accessors ──────────────────────────────────────────────────

    public function test_plugin_action_links_puts_the_settings_link_first(): void
    {
        Functions\when('admin_url')->alias(fn(string $path = '') => 'https://example.com/wp-admin/' . $path);

        $links = $this->subject()->plugin_action_links(['<a href="#">Deactivate</a>']);

        $this->assertCount(2, $links);
        $this->assertStringContainsString('admin.php?page=wc-settings&tab=multi_store_sync', $links[0]);
        $this->assertStringContainsString('Deactivate', $links[1]);
    }

    public function test_get_version_matches_the_plugin_constant(): void
    {
        $this->assertSame(WC_MSS_VERSION, $this->subject()->get_version());
    }
}
