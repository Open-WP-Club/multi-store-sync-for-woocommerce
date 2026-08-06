<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

class SyncProfilesTest extends WC_Multi_Store_TestCase
{
    private array $profiles = [];

    private function loadClass(): void
    {
        if (!class_exists('WC_Multi_Store_Sync_Profiles', false)) {
            require_once dirname(__DIR__, 3) . '/includes/sync-profiles.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);

        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                WC_Multi_Store_Sync_Profiles::OPTION_KEY => $this->profiles,
                'wc_multi_store_sync_settings'       => ['enabled' => true],
                'wc_multi_store_sync_scheduled'      => [],
                'wc_multi_store_sync_orders'         => [],
                'wc_multi_store_sync_email_settings' => [],
                default => $default ?? [],
            };
        });

        Functions\when('update_option')->alias(function ($opt, $value) {
            if ($opt === WC_Multi_Store_Sync_Profiles::OPTION_KEY) {
                $this->profiles = $value;
            }
            return true;
        });

        Functions\when('current_time')->justReturn('2024-01-15 12:00:00');
        Functions\when('wp_generate_password')->justReturn('abc123de');
        Functions\when('wp_json_encode')->alias(fn($v, $flags = 0) => json_encode($v, $flags));

        // Needed by other Cache_Manager cache types (e.g. active_stores) touched indirectly
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);

        $this->loadClass();
    }

    protected function tearDown(): void
    {
        $this->profiles = [];
        WC_Multi_Store_Settings::clear_static_cache();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // get_all() / get()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_all_returns_empty_when_no_profiles(): void
    {
        $result = WC_Multi_Store_Sync_Profiles::get_all();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_all_returns_stored_profiles(): void
    {
        $this->profiles = [
            'profile_abc' => ['name' => 'Profile A'],
            'profile_def' => ['name' => 'Profile B'],
        ];

        $result = WC_Multi_Store_Sync_Profiles::get_all();

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('profile_abc', $result);
        $this->assertArrayHasKey('profile_def', $result);
    }

    public function test_get_returns_null_for_nonexistent_id(): void
    {
        $result = WC_Multi_Store_Sync_Profiles::get('nonexistent_id');

        $this->assertNull($result);
    }

    public function test_get_returns_profile_by_id(): void
    {
        $this->profiles = [
            'profile_xyz' => ['name' => 'My Profile', 'description' => 'A test profile'],
        ];

        $result = WC_Multi_Store_Sync_Profiles::get('profile_xyz');

        $this->assertIsArray($result);
        $this->assertEquals('My Profile', $result['name']);
        $this->assertEquals('A test profile', $result['description']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // save()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_save_with_explicit_id_uses_given_id(): void
    {
        WC_Multi_Store_Sync_Profiles::save('my_profile', ['name' => 'Test']);

        $this->assertArrayHasKey('my_profile', $this->profiles);
    }

    public function test_save_with_empty_id_generates_new_id(): void
    {
        $returned_id = WC_Multi_Store_Sync_Profiles::save('', ['name' => 'Test']);

        $this->assertStringStartsWith('profile_', $returned_id);
        $this->assertArrayHasKey($returned_id, $this->profiles);
    }

    public function test_save_sets_updated_at_timestamp(): void
    {
        WC_Multi_Store_Sync_Profiles::save('ts_profile', ['name' => 'Timestamp Test']);

        $profile = $this->profiles['ts_profile'];
        $this->assertEquals('2024-01-15 12:00:00', $profile['updated_at']);
    }

    public function test_save_sets_created_at_only_for_new_profiles(): void
    {
        // First save — should set created_at because the profile does not yet exist
        WC_Multi_Store_Sync_Profiles::save('existing_profile', ['name' => 'First Save']);
        $first_created_at = $this->profiles['existing_profile']['created_at'];
        $this->assertEquals('2024-01-15 12:00:00', $first_created_at);

        // Second save of same ID with an explicit created_at preserved by caller —
        // the code only adds created_at when the profile is NEW; existing profiles
        // must carry it forward in the data they pass to save().
        $existing_data = $this->profiles['existing_profile'];
        $existing_data['name'] = 'Second Save';
        WC_Multi_Store_Sync_Profiles::save('existing_profile', $existing_data);
        $profile = $this->profiles['existing_profile'];

        // created_at must still be present and unchanged
        $this->assertArrayHasKey('created_at', $profile);
        $this->assertEquals($first_created_at, $profile['created_at']);
    }

    public function test_save_does_not_overwrite_created_at_on_existing_profiles(): void
    {
        // Seed an existing profile with a known created_at
        $this->profiles = [
            'old_profile' => [
                'name'       => 'Old Name',
                'created_at' => '2023-06-01 10:00:00',
                'updated_at' => '2023-06-01 10:00:00',
            ],
        ];

        // Pass the full existing data (including created_at) as a real caller would —
        // save() does not set created_at when the profile already exists, so whatever
        // the caller includes is what gets stored.
        WC_Multi_Store_Sync_Profiles::save('old_profile', [
            'name'       => 'Updated Name',
            'created_at' => '2023-06-01 10:00:00',
        ]);

        // created_at must remain the original value, not be overwritten
        $this->assertEquals('2023-06-01 10:00:00', $this->profiles['old_profile']['created_at']);
    }

    public function test_save_returns_the_profile_id(): void
    {
        $returned_id = WC_Multi_Store_Sync_Profiles::save('return_test', ['name' => 'Return Test']);

        $this->assertEquals('return_test', $returned_id);
    }

    public function test_save_calls_update_option(): void
    {
        $update_called = false;

        Functions\when('update_option')->alias(function ($opt, $value) use (&$update_called) {
            if ($opt === WC_Multi_Store_Sync_Profiles::OPTION_KEY) {
                $update_called = true;
                $this->profiles = $value;
            }
            return true;
        });

        WC_Multi_Store_Sync_Profiles::save('call_test', ['name' => 'Call Test']);

        $this->assertTrue($update_called, 'update_option should have been called with the profiles option key');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // delete()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_delete_returns_false_for_nonexistent_id(): void
    {
        $result = WC_Multi_Store_Sync_Profiles::delete('does_not_exist');

        $this->assertFalse($result);
    }

    public function test_delete_returns_true_and_removes_profile(): void
    {
        $this->profiles = [
            'profile_to_delete' => ['name' => 'Delete Me'],
        ];

        $result = WC_Multi_Store_Sync_Profiles::delete('profile_to_delete');

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('profile_to_delete', $this->profiles);
    }

    public function test_delete_calls_update_option_without_the_profile(): void
    {
        $this->profiles = [
            'keep_me'   => ['name' => 'Keep'],
            'remove_me' => ['name' => 'Remove'],
        ];

        $saved_value = null;

        Functions\when('update_option')->alias(function ($opt, $value) use (&$saved_value) {
            if ($opt === WC_Multi_Store_Sync_Profiles::OPTION_KEY) {
                $saved_value = $value;
                $this->profiles = $value;
            }
            return true;
        });

        WC_Multi_Store_Sync_Profiles::delete('remove_me');

        $this->assertNotNull($saved_value);
        $this->assertArrayHasKey('keep_me', $saved_value);
        $this->assertArrayNotHasKey('remove_me', $saved_value);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // duplicate()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_duplicate_returns_null_for_nonexistent_source(): void
    {
        $result = WC_Multi_Store_Sync_Profiles::duplicate('nonexistent');

        $this->assertNull($result);
    }

    public function test_duplicate_creates_copy_with_name_suffix(): void
    {
        $this->profiles = [
            'original' => ['name' => 'Original', 'sync_settings' => ['enabled' => true]],
        ];

        $new_id = WC_Multi_Store_Sync_Profiles::duplicate('original');

        $this->assertNotNull($new_id);
        $this->assertArrayHasKey($new_id, $this->profiles);
        $this->assertEquals('Original (Copy)', $this->profiles[$new_id]['name']);
    }

    public function test_duplicate_generates_new_id(): void
    {
        $this->profiles = [
            'source_profile' => ['name' => 'Source'],
        ];

        $new_id = WC_Multi_Store_Sync_Profiles::duplicate('source_profile');

        $this->assertNotEquals('source_profile', $new_id);
        $this->assertStringStartsWith('profile_', $new_id);
    }

    public function test_duplicate_removes_created_at_and_updated_at_from_copy(): void
    {
        $this->profiles = [
            'timestamped' => [
                'name'       => 'Has Timestamps',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => '2023-06-01 00:00:00',
            ],
        ];

        $new_id = WC_Multi_Store_Sync_Profiles::duplicate('timestamped');

        // The duplicate goes through save() which will set fresh timestamps, so
        // we only assert that the original timestamps were stripped before saving —
        // i.e. the copy's created_at is the current_time mock value, not the source's.
        $copy = $this->profiles[$new_id];
        $this->assertEquals('2024-01-15 12:00:00', $copy['created_at']);
        $this->assertNotEquals('2023-01-01 00:00:00', $copy['created_at']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // apply()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_apply_returns_false_for_nonexistent_profile(): void
    {
        $result = WC_Multi_Store_Sync_Profiles::apply('nonexistent_profile');

        $this->assertFalse($result);
    }

    public function test_apply_updates_main_sync_settings(): void
    {
        $this->profiles = [
            'apply_profile' => [
                'name'          => 'Apply Test',
                'sync_settings' => ['enabled' => true, 'sync_type_default' => 'quantity'],
            ],
        ];

        $updated_options = [];

        Functions\when('update_option')->alias(function ($opt, $value) use (&$updated_options) {
            $updated_options[$opt] = $value;
            if ($opt === WC_Multi_Store_Sync_Profiles::OPTION_KEY) {
                $this->profiles = $value;
            }
            return true;
        });

        WC_Multi_Store_Sync_Profiles::apply('apply_profile');

        $this->assertArrayHasKey('wc_multi_store_sync_settings', $updated_options);
        $this->assertEquals(['enabled' => true, 'sync_type_default' => 'quantity'], $updated_options['wc_multi_store_sync_settings']);
    }

    public function test_apply_skips_scheduled_settings_when_flag_false(): void
    {
        $this->profiles = [
            'sched_profile' => [
                'name'               => 'Scheduled Skip Test',
                'sync_settings'      => ['enabled' => true],
                'scheduled_settings' => ['scheduled_sync_enabled' => true],
            ],
        ];

        $updated_options = [];

        Functions\when('update_option')->alias(function ($opt, $value) use (&$updated_options) {
            $updated_options[$opt] = $value;
            if ($opt === WC_Multi_Store_Sync_Profiles::OPTION_KEY) {
                $this->profiles = $value;
            }
            return true;
        });

        WC_Multi_Store_Sync_Profiles::apply('sched_profile', apply_scheduled: false);

        $this->assertArrayNotHasKey('wc_multi_store_sync_scheduled', $updated_options);
    }

    public function test_apply_skips_order_settings_when_flag_false(): void
    {
        $this->profiles = [
            'order_profile' => [
                'name'           => 'Order Skip Test',
                'sync_settings'  => ['enabled' => true],
                'order_settings' => ['auto_sync_enabled' => true],
            ],
        ];

        $updated_options = [];

        Functions\when('update_option')->alias(function ($opt, $value) use (&$updated_options) {
            $updated_options[$opt] = $value;
            if ($opt === WC_Multi_Store_Sync_Profiles::OPTION_KEY) {
                $this->profiles = $value;
            }
            return true;
        });

        WC_Multi_Store_Sync_Profiles::apply('order_profile', apply_orders: false);

        $this->assertArrayNotHasKey('wc_multi_store_sync_orders', $updated_options);
    }

    public function test_apply_skips_email_settings_when_flag_false(): void
    {
        $this->profiles = [
            'email_profile' => [
                'name'           => 'Email Skip Test',
                'sync_settings'  => ['enabled' => true],
                'email_settings' => ['enabled' => true, 'email_address' => 'test@example.com'],
            ],
        ];

        $updated_options = [];

        Functions\when('update_option')->alias(function ($opt, $value) use (&$updated_options) {
            $updated_options[$opt] = $value;
            if ($opt === WC_Multi_Store_Sync_Profiles::OPTION_KEY) {
                $this->profiles = $value;
            }
            return true;
        });

        WC_Multi_Store_Sync_Profiles::apply('email_profile', apply_email: false);

        $this->assertArrayNotHasKey('wc_multi_store_sync_email_settings', $updated_options);
    }

    public function test_apply_calls_clear_static_cache_after_applying_settings(): void
    {
        // Seed the Settings static cache with a known value
        WC_Multi_Store_Settings::clear_static_cache();

        $this->profiles = [
            'cache_profile' => [
                'name'          => 'Cache Clear Test',
                'sync_settings' => ['enabled' => false],
            ],
        ];

        // Prime the static cache by calling get_settings() once
        WC_Multi_Store_Settings::get_settings();

        WC_Multi_Store_Sync_Profiles::apply('cache_profile');

        // After apply(), the static cache should have been cleared (set to null).
        // We verify indirectly: get_settings() must call get_option() again (not return
        // a cached value). Since get_option returns ['enabled' => true] for the settings key,
        // if the cache was NOT cleared it would return whatever was cached. This assertion
        // is structural — the real proof is that clear_static_cache() sets the property null.
        $reflection = new ReflectionClass(WC_Multi_Store_Settings::class);
        $prop = $reflection->getProperty('settings_cache');
        // After apply() calls clear_static_cache() the cache is null
        $this->assertNull($prop->getValue());
    }

    public function test_apply_returns_true_on_success(): void
    {
        $this->profiles = [
            'success_profile' => [
                'name'          => 'Success Test',
                'sync_settings' => ['enabled' => true],
            ],
        ];

        $result = WC_Multi_Store_Sync_Profiles::apply('success_profile');

        $this->assertTrue($result);
    }

    public function test_apply_does_not_apply_empty_sections(): void
    {
        $this->profiles = [
            'empty_settings_profile' => [
                'name'          => 'Empty Sections',
                'sync_settings' => [],   // empty — should NOT trigger update_option
            ],
        ];

        $updated_options = [];

        Functions\when('update_option')->alias(function ($opt, $value) use (&$updated_options) {
            $updated_options[$opt] = $value;
            if ($opt === WC_Multi_Store_Sync_Profiles::OPTION_KEY) {
                $this->profiles = $value;
            }
            return true;
        });

        WC_Multi_Store_Sync_Profiles::apply('empty_settings_profile');

        $this->assertArrayNotHasKey('wc_multi_store_sync_settings', $updated_options);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // export()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_export_returns_null_for_nonexistent_profile(): void
    {
        $result = WC_Multi_Store_Sync_Profiles::export('nonexistent');

        $this->assertNull($result);
    }

    public function test_export_returns_valid_json_string(): void
    {
        $this->profiles = [
            'export_profile' => [
                'name'          => 'Export Me',
                'sync_settings' => ['enabled' => true],
            ],
        ];

        $json = WC_Multi_Store_Sync_Profiles::export('export_profile');

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('Export Me', $decoded['name']);
    }

    public function test_export_does_not_include_stores_key(): void
    {
        $this->profiles = [
            'stores_profile' => [
                'name'          => 'Profile With Stores',
                'sync_settings' => ['enabled' => true],
                'stores'        => [
                    'https://shop2.example.com' => [
                        'consumer_key'    => 'ck_secret',
                        'consumer_secret' => 'cs_secret',
                    ],
                ],
            ],
        ];

        $json = WC_Multi_Store_Sync_Profiles::export('stores_profile');

        $this->assertNotNull($json);
        $decoded = json_decode($json, true);
        $this->assertArrayNotHasKey('stores', $decoded);
    }

    public function test_export_result_is_pretty_printed(): void
    {
        $this->profiles = [
            'pretty_profile' => [
                'name'          => 'Pretty Print Test',
                'sync_settings' => ['enabled' => true],
            ],
        ];

        $json = WC_Multi_Store_Sync_Profiles::export('pretty_profile');

        $this->assertNotNull($json);
        $this->assertStringContainsString("\n", $json);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // import()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_import_returns_null_for_invalid_json(): void
    {
        $result = WC_Multi_Store_Sync_Profiles::import('this is not json {{{');

        $this->assertNull($result);
    }

    public function test_import_returns_null_for_empty_string(): void
    {
        $result = WC_Multi_Store_Sync_Profiles::import('');

        $this->assertNull($result);
    }

    public function test_import_adds_imported_suffix_to_name(): void
    {
        $json = json_encode([
            'name'          => 'My Exported Profile',
            'sync_settings' => ['enabled' => true],
        ]);

        $id = WC_Multi_Store_Sync_Profiles::import($json);

        $this->assertNotNull($id);
        $this->assertEquals('My Exported Profile (Imported)', $this->profiles[$id]['name']);
    }

    public function test_import_uses_default_name_when_name_missing(): void
    {
        $json = json_encode([
            'sync_settings' => ['enabled' => false],
        ]);

        $id = WC_Multi_Store_Sync_Profiles::import($json);

        $this->assertNotNull($id);
        $this->assertEquals('Imported Profile (Imported)', $this->profiles[$id]['name']);
    }

    public function test_import_returns_new_profile_id(): void
    {
        $json = json_encode([
            'name'          => 'Import Return ID Test',
            'sync_settings' => ['enabled' => true],
        ]);

        $id = WC_Multi_Store_Sync_Profiles::import($json);

        $this->assertNotNull($id);
        $this->assertIsString($id);
        $this->assertStringStartsWith('profile_', $id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // get_presets()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_presets_returns_four_presets(): void
    {
        $presets = WC_Multi_Store_Sync_Profiles::get_presets();

        $this->assertCount(4, $presets);
        $this->assertArrayHasKey('full_sync', $presets);
        $this->assertArrayHasKey('price_stock_only', $presets);
        $this->assertArrayHasKey('stock_only', $presets);
        $this->assertArrayHasKey('conservative', $presets);
    }

    public function test_full_sync_preset_has_required_keys(): void
    {
        $presets = WC_Multi_Store_Sync_Profiles::get_presets();
        $full_sync = $presets['full_sync'];

        $this->assertArrayHasKey('name', $full_sync);
        $this->assertArrayHasKey('description', $full_sync);
        $this->assertArrayHasKey('sync_settings', $full_sync);
        $this->assertArrayHasKey('sync_type_default', $full_sync['sync_settings']);
        $this->assertEquals('full_product', $full_sync['sync_settings']['sync_type_default']);
    }

    public function test_price_stock_only_preset_has_correct_sync_type(): void
    {
        $presets = WC_Multi_Store_Sync_Profiles::get_presets();
        $preset = $presets['price_stock_only'];

        $this->assertEquals('price_quantity', $preset['sync_settings']['sync_type_default']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // apply_preset()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_apply_preset_returns_false_for_nonexistent_key(): void
    {
        $result = WC_Multi_Store_Sync_Profiles::apply_preset('nonexistent_preset');

        $this->assertFalse($result);
    }

    public function test_apply_preset_merges_with_current_settings(): void
    {
        // Simulate current settings with an extra key not present in the preset
        Functions\when('get_option')->alias(function ($opt, $default = null) {
            return match ($opt) {
                WC_Multi_Store_Sync_Profiles::OPTION_KEY   => $this->profiles,
                'wc_multi_store_sync_settings'             => [
                    'enabled'           => true,
                    'custom_extra_key'  => 'custom_value',
                    'sync_type_default' => 'full_product',
                ],
                default => $default ?? [],
            };
        });

        $merged = null;

        Functions\when('update_option')->alias(function ($opt, $value) use (&$merged) {
            if ($opt === 'wc_multi_store_sync_settings') {
                $merged = $value;
            }
            return true;
        });

        WC_Multi_Store_Sync_Profiles::apply_preset('stock_only');

        $this->assertNotNull($merged);
        // The extra key from existing settings must survive the merge
        $this->assertArrayHasKey('custom_extra_key', $merged);
        $this->assertEquals('custom_value', $merged['custom_extra_key']);
        // The preset's sync_type_default must override the existing one
        $this->assertEquals('quantity', $merged['sync_type_default']);
    }

    public function test_apply_preset_returns_true_on_success(): void
    {
        Functions\when('update_option')->justReturn(true);

        $result = WC_Multi_Store_Sync_Profiles::apply_preset('conservative');

        $this->assertTrue($result);
    }
}
