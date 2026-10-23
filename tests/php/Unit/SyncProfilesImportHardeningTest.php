<?php
/**
 * Hostile-input tests for WC_Multi_Store_Sync_Profiles import/apply/export.
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

class SyncProfilesImportHardeningTest extends WC_Multi_Store_TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var array<int, array{0: string, 1: mixed}> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];
        $this->writes = [];

        if (!class_exists('WC_Multi_Store_Sync_Profiles', false)) {
            require_once dirname(__DIR__, 3) . '/includes/sync-profiles.php';
        }

        Functions\when('add_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-03-10 12:00:00');
        Functions\when('wp_json_encode')->alias(fn($v, $f = 0) => json_encode($v, $f));
        Functions\when('get_option')->alias(fn($k, $d = false) => array_key_exists($k, $this->options) ? $this->options[$k] : $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->writes[] = [$k, $v];
            $this->options[$k] = $v;
            return true;
        });
        $n = 0;
        Functions\when('wp_generate_password')->alias(function () use (&$n) {
            return 'id' . str_pad((string) ++$n, 6, '0', STR_PAD_LEFT);
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        WC_Multi_Store_Settings::clear_static_cache();
    }

    private function profiles(): array
    {
        return $this->options[WC_Multi_Store_Sync_Profiles::OPTION_KEY] ?? [];
    }

    // ── import ────────────────────────────────────────────────────

    public static function undecodableProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'truncated' => ['{"name":"x",'],
            'json null' => ['null'],
            'json false' => ['false'],
            'json zero' => ['0'],
            'json scalar string' => ['"profile"'],
            'json scalar number' => ['42'],
            'empty object' => ['{}'],
            'empty list' => ['[]'],
            'plain text' => ['hello world'],
            'invalid utf-8' => ["{\"name\":\"\xB1\"}"],
            'too deep' => [str_repeat('[', 600) . str_repeat(']', 600)],
        ];
    }

    #[DataProvider('undecodableProvider')]
    public function test_undecodable_or_empty_json_is_rejected_without_writing(string $json): void
    {
        $this->assertNull(WC_Multi_Store_Sync_Profiles::import($json));
        $this->assertSame([], $this->writes);
    }

    public function test_import_always_marks_the_profile_as_imported_and_stamps_times(): void
    {
        $id = WC_Multi_Store_Sync_Profiles::import(json_encode(['name' => 'Night', 'created_at' => '1999-01-01', 'updated_at' => '1999-01-01']));

        $profile = $this->profiles()[$id];
        $this->assertSame('Night (Imported)', $profile['name']);
        $this->assertSame('2026-03-10 12:00:00', $profile['created_at'], 'forged timestamps in the file must not survive');
        $this->assertSame('2026-03-10 12:00:00', $profile['updated_at']);
    }

    public function test_import_never_overwrites_an_existing_profile_with_a_forged_id(): void
    {
        $this->options[WC_Multi_Store_Sync_Profiles::OPTION_KEY] = ['profile_keep' => ['name' => 'Keep']];

        $id = WC_Multi_Store_Sync_Profiles::import(json_encode(['id' => 'profile_keep', 'profile_id' => 'profile_keep', 'name' => 'Evil']));

        $this->assertNotSame('profile_keep', $id);
        $this->assertSame('Keep', $this->profiles()['profile_keep']['name']);
    }

    public function test_consecutive_imports_get_distinct_ids(): void
    {
        $a = WC_Multi_Store_Sync_Profiles::import(json_encode(['name' => 'A']));
        $b = WC_Multi_Store_Sync_Profiles::import(json_encode(['name' => 'B']));

        $this->assertNotSame($a, $b);
        $this->assertCount(2, $this->profiles());
    }

    public static function emptyNameProvider(): array
    {
        return [
            'missing' => [[]],
            'empty string' => [['name' => '']],
            'null' => [['name' => null]],
        ];
    }

    #[DataProvider('emptyNameProvider')]
    public function test_profiles_without_a_usable_name_get_a_default_name(array $data): void
    {
        $id = WC_Multi_Store_Sync_Profiles::import(json_encode($data + ['description' => 'd']));

        $this->assertSame('Imported Profile (Imported)', $this->profiles()[$id]['name']);
    }

    // ── export ────────────────────────────────────────────────────

    public function test_export_strips_store_credentials_but_keeps_everything_else(): void
    {
        $this->options[WC_Multi_Store_Sync_Profiles::OPTION_KEY] = ['p1' => [
            'name' => 'P',
            'sync_settings' => ['enabled' => true],
            'stores' => ['https://a.example.com' => ['consumer_key' => 'ck_SECRET', 'consumer_secret' => 'cs_SECRET']],
        ]];

        $json = WC_Multi_Store_Sync_Profiles::export('p1');

        $this->assertStringNotContainsString('SECRET', $json);
        $this->assertSame(['enabled' => true], json_decode($json, true)['sync_settings']);
    }

    public function test_export_then_import_round_trip_preserves_settings(): void
    {
        $this->options[WC_Multi_Store_Sync_Profiles::OPTION_KEY] = ['p1' => [
            'name' => 'P', 'sync_settings' => ['enabled' => true, 'x' => [1, 2]], 'scheduled_settings' => ['a' => 1],
        ]];

        $id = WC_Multi_Store_Sync_Profiles::import(WC_Multi_Store_Sync_Profiles::export('p1'));

        $this->assertSame(['enabled' => true, 'x' => [1, 2]], $this->profiles()[$id]['sync_settings']);
        $this->assertSame(['a' => 1], $this->profiles()[$id]['scheduled_settings']);
    }

    public function test_export_of_unknown_id_is_null_for_odd_ids(): void
    {
        foreach (['', '0', 'nope', '../../etc/passwd', "p1'; --"] as $id) {
            $this->assertNull(WC_Multi_Store_Sync_Profiles::export($id));
        }
    }

    // ── apply ─────────────────────────────────────────────────────

    public function test_apply_only_writes_sections_present_and_requested(): void
    {
        $this->options[WC_Multi_Store_Sync_Profiles::OPTION_KEY] = ['p1' => [
            'name' => 'P',
            'sync_settings' => ['enabled' => true],
            'scheduled_settings' => ['s' => 1],
            'order_settings' => [],
            'email_settings' => ['e' => 1],
        ]];

        $this->assertTrue(WC_Multi_Store_Sync_Profiles::apply('p1', true, true, false));

        $names = array_column($this->writes, 0);
        $this->assertContains('wc_multi_store_sync_settings', $names);
        $this->assertContains('wc_multi_store_sync_scheduled', $names);
        $this->assertNotContains('wc_multi_store_sync_orders', $names, 'empty section is skipped');
        $this->assertNotContains('wc_multi_store_sync_email_settings', $names, 'declined section is skipped');
    }

    public function test_apply_ignores_a_stores_section_even_if_the_file_contains_one(): void
    {
        $this->options[WC_Multi_Store_Sync_Profiles::OPTION_KEY] = ['p1' => [
            'name' => 'P',
            'sync_settings' => ['enabled' => true],
            'stores' => ['https://evil.example.com' => ['consumer_key' => 'a']],
        ]];

        WC_Multi_Store_Sync_Profiles::apply('p1');

        $this->assertNotContains('wc_multi_store_sync_stores', array_column($this->writes, 0));
    }

    public function test_apply_of_unknown_profile_writes_nothing(): void
    {
        $this->assertFalse(WC_Multi_Store_Sync_Profiles::apply('missing'));
        $this->assertSame([], $this->writes);
    }

    // ── save / delete / duplicate ─────────────────────────────────

    public function test_save_keeps_created_at_when_overwriting_and_delete_removes_only_the_target(): void
    {
        $this->options[WC_Multi_Store_Sync_Profiles::OPTION_KEY] = [
            'a' => ['name' => 'A', 'created_at' => '2020-01-01 00:00:00'],
            'b' => ['name' => 'B'],
        ];

        WC_Multi_Store_Sync_Profiles::save('a', ['name' => 'A2']);
        $this->assertSame('A2', $this->profiles()['a']['name']);

        $this->assertTrue(WC_Multi_Store_Sync_Profiles::delete('a'));
        $this->assertSame(['b'], array_keys($this->profiles()));
        $this->assertFalse(WC_Multi_Store_Sync_Profiles::delete('a'));
    }

    public function test_duplicate_gets_a_new_id_a_copy_name_and_leaves_the_source_alone(): void
    {
        $this->options[WC_Multi_Store_Sync_Profiles::OPTION_KEY] = ['a' => ['name' => 'A', 'created_at' => '2020-01-01 00:00:00']];

        $copy = WC_Multi_Store_Sync_Profiles::duplicate('a');

        $this->assertNotSame('a', $copy);
        $this->assertSame('A (Copy)', $this->profiles()[$copy]['name']);
        $this->assertSame('A', $this->profiles()['a']['name']);
        $this->assertSame('2020-01-01 00:00:00', $this->profiles()['a']['created_at']);
        $this->assertSame('2026-03-10 12:00:00', $this->profiles()[$copy]['created_at']);
    }

    public function test_create_from_current_settings_never_embeds_store_credentials(): void
    {
        $this->options['wc_multi_store_sync_stores'] = ['https://a.example.com' => ['consumer_key' => 'ck_SECRET', 'consumer_secret' => 'cs_SECRET']];
        $this->options['wc_multi_store_sync_settings'] = ['enabled' => true];

        $id = WC_Multi_Store_Sync_Profiles::create_from_current_settings('Snap', 'desc');

        $this->assertStringNotContainsString('SECRET', json_encode($this->profiles()[$id]));
        $this->assertSame([], $this->profiles()[$id]['stores']);
    }

    public function test_presets_only_touch_the_main_settings_and_keep_unrelated_keys(): void
    {
        $this->options['wc_multi_store_sync_settings'] = ['auth_method' => 'query_string', 'circuit_breaker_threshold' => 7];

        $this->assertTrue(WC_Multi_Store_Sync_Profiles::apply_preset('conservative'));

        $this->assertSame(['wc_multi_store_sync_settings'], array_values(array_unique(array_column($this->writes, 0))));
        $saved = $this->options['wc_multi_store_sync_settings'];
        $this->assertSame('query_string', $saved['auth_method']);
        $this->assertSame(7, $saved['circuit_breaker_threshold']);
        $this->assertFalse($saved['auto_sync_deletions']);
    }

    public static function badPresetProvider(): array
    {
        return [[''], ['FULL_SYNC'], ['../x'], ['full_sync '], ['__proto__']];
    }

    #[DataProvider('badPresetProvider')]
    public function test_unknown_preset_keys_are_rejected(string $key): void
    {
        $this->assertFalse(WC_Multi_Store_Sync_Profiles::apply_preset($key));
        $this->assertSame([], $this->writes);
    }

    // ── regressions: type confusion in imported profiles ──────────

    public static function listJsonProvider(): array
    {
        return [['[1,2,3]'], ['["a"]'], ['[{"name":"x"}]']];
    }

    #[DataProvider('listJsonProvider')]
    public function test_json_lists_are_not_profiles(string $json): void
    {
        $this->assertNull(WC_Multi_Store_Sync_Profiles::import($json));
        $this->assertSame([], $this->writes);
    }

    public static function nonStringNameProvider(): array
    {
        return [
            'array' => ['["a"]'],
            'object' => ['{"k":"v"}'],
            'number' => ['5'],
            'true' => ['true'],
        ];
    }

    #[DataProvider('nonStringNameProvider')]
    public function test_non_string_names_fall_back_to_the_default_name(string $name): void
    {
        $id = WC_Multi_Store_Sync_Profiles::import('{"name":' . $name . ',"description":"d"}');

        $this->assertSame('Imported Profile (Imported)', $this->profiles()[$id]['name']);
    }

    public function test_markup_in_the_name_is_stripped(): void
    {
        $id = WC_Multi_Store_Sync_Profiles::import('{"name":"<script>alert(1)</script>Night <b>run</b>"}');

        $this->assertStringNotContainsString('<', $this->profiles()[$id]['name']);
        $this->assertStringEndsWith('(Imported)', $this->profiles()[$id]['name']);
    }

    public function test_a_numeric_string_name_is_kept(): void
    {
        $id = WC_Multi_Store_Sync_Profiles::import('{"name":"0"}');

        $this->assertSame('0 (Imported)', $this->profiles()[$id]['name']);
    }

    public function test_scalar_settings_sections_are_dropped_on_import_and_never_reach_options(): void
    {
        $id = WC_Multi_Store_Sync_Profiles::import('{"name":"P","sync_settings":"evil","store_settings":7}');

        $this->assertArrayNotHasKey('sync_settings', $this->profiles()[$id]);
        $this->assertArrayNotHasKey('store_settings', $this->profiles()[$id]);

        WC_Multi_Store_Sync_Profiles::apply($id);

        $this->assertNotContains('wc_multi_store_sync_settings', array_column($this->writes, 0));
    }

    public function test_array_settings_sections_survive_import_unchanged(): void
    {
        $id = WC_Multi_Store_Sync_Profiles::import('{"name":"P","sync_settings":{"enabled":true}}');

        $this->assertSame(['enabled' => true], $this->profiles()[$id]['sync_settings']);
    }
}
