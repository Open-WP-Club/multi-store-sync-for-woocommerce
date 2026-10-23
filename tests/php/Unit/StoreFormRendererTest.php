<?php
/**
 * Tests for WC_Multi_Store_Store_Form_Renderer (shared Add/Edit store form markup).
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 3) . '/includes/store-form-renderer.php';

class StoreFormRendererTest extends WC_Multi_Store_TestCase
{
    private const HOSTILE = '"><script>alert(1)</script>\'';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('esc_html')->alias(fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(function ($t, $d = 'default') {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('esc_attr__')->alias(fn($t, $d = 'default') => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_js')->alias(fn($t) => addslashes(htmlspecialchars((string) $t, ENT_COMPAT, 'UTF-8')));
        Functions\when('wp_kses_post')->alias(fn($t) => $t);
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('selected')->alias(
            function ($selected, $current = true, $echo = true) {
                $out = ((string) $selected === (string) $current) ? " selected='selected'" : '';
                if ($echo) {
                    echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
                }
                return $out;
            }
        );
        Functions\when('checked')->alias(
            function ($checked, $current = true, $echo = true) {
                $out = ((string) $checked === (string) $current) ? " checked='checked'" : '';
                if ($echo) {
                    echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
                }
                return $out;
            }
        );
    }

    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    /**
     * Return the opening tag of the element carrying the given id attribute.
     */
    private function tag_with_id(string $html, string $id): string
    {
        $this->assertSame(
            1,
            preg_match('/<[a-z]+\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/s', $html, $m),
            "No element with id=\"$id\" found"
        );
        return $m[0];
    }

    // ─── render_core_fields ────────────────────────

    public function test_core_fields_add_mode_has_unprefixed_ids_and_required_inputs(): void
    {
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_core_fields('add', [], ''));

        foreach (['store_url', 'consumer_key', 'consumer_secret', 'status'] as $id) {
            $this->tag_with_id($html, $id);
        }
        $this->assertStringNotContainsString('edit_', $html);
        $this->assertStringContainsString('placeholder="https://example.com"', $html);
        $this->assertSame(3, substr_count($html, '<span class="required">*</span>'));
        $this->assertMatchesRegularExpression('/id="consumer_key"[^>]*\brequired\b/s', $html);
        $this->assertMatchesRegularExpression('/id="consumer_secret"[^>]*\brequired\b/s', $html);
        $this->assertStringNotContainsString('Leave blank to keep', $html);
    }

    public function test_core_fields_add_mode_does_not_preselect_status(): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_core_fields('add', ['status' => 'active'], '')
        );

        $this->assertStringNotContainsString("selected='selected'", $html);
    }

    public function test_core_fields_edit_mode_uses_edit_prefix_and_not_required(): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_core_fields('edit', ['status' => 'active'], 'https://shop.test')
        );

        foreach (['edit_store_url', 'edit_consumer_key', 'edit_consumer_secret', 'edit_status'] as $id) {
            $this->tag_with_id($html, $id);
        }
        $this->assertStringNotContainsString('<span class="required">', $html);
        $this->assertDoesNotMatchRegularExpression('/id="edit_consumer_key"[^>]*\brequired\b/s', $html);
        $this->assertStringContainsString('value="https://shop.test"', $html);
        $this->assertStringContainsString('Leave blank to keep the saved key', $html);
        $this->assertStringContainsString('Leave blank to keep the saved secret', $html);
    }

    public static function status_provider(): array
    {
        return [
            'active'   => ['active', 'active'],
            'inactive' => ['inactive', 'inactive'],
        ];
    }

    #[DataProvider('status_provider')]
    public function test_core_fields_edit_mode_selects_only_the_saved_status(string $status, string $expected): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_core_fields('edit', ['status' => $status], 'https://shop.test')
        );

        $this->assertSame(1, substr_count($html, "selected='selected'"));
        $this->assertMatchesRegularExpression('/<option value="' . $expected . '"\s+ selected=\'selected\'>/', $html);
    }

    public function test_core_fields_edit_mode_with_unknown_status_selects_nothing(): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_core_fields('edit', ['status' => 'bogus'], 'https://shop.test')
        );

        $this->assertStringNotContainsString("selected='selected'", $html);
    }

    public function test_core_fields_escape_hostile_store_url(): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_core_fields('edit', [], self::HOSTILE)
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;&gt;', $html);
        $this->assertStringContainsString('&#039;', $html);
    }

    public function test_core_fields_never_echo_saved_credentials(): void
    {
        $store = [
            'status'          => 'active',
            'consumer_key'    => 'ck_SUPERSECRETKEY123',
            'consumer_secret' => 'cs_SUPERSECRETVALUE456',
        ];
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_core_fields('edit', $store, 'https://shop.test')
        );

        $this->assertStringNotContainsString('ck_SUPERSECRETKEY123', $html);
        $this->assertStringNotContainsString('cs_SUPERSECRETVALUE456', $html);
        $this->assertSame(2, substr_count($html, 'type="password"'));
        $this->assertSame(2, substr_count($html, 'autocomplete="off"'));
        $this->assertDoesNotMatchRegularExpression('/type="password"[^>]*\bvalue=/s', $html);
    }

    public function test_core_fields_placeholder_only_when_credential_already_saved(): void
    {
        $saved = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_core_fields(
            'edit',
            ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
            'https://shop.test'
        ));
        $this->assertSame(2, substr_count($saved, 'placeholder="Already set'));

        $none = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_core_fields(
            'edit',
            ['consumer_key' => 'ck', 'consumer_secret' => ''],
            'https://shop.test'
        ));
        $this->assertSame(1, substr_count($none, 'placeholder="Already set'));
        $this->assertStringContainsString('placeholder=""', $none);
    }

    // ─── render_app_password_fields ────────────────

    public function test_app_password_add_mode_has_no_saved_values_and_add_test_button(): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_app_password_fields('add', ['wp_username' => 'leak'], '')
        );

        $this->tag_with_id($html, 'wp_username');
        $this->tag_with_id($html, 'wp_app_password');
        $this->tag_with_id($html, 'wc-mss-test-app-password');
        $this->assertStringContainsString('id="wc-mss-test-app-password-result"', $html);
        $this->assertStringNotContainsString('wc-mss-test-app-password-edit', $html);
        $this->assertStringNotContainsString('leak', $html, 'Add mode must ignore any passed store data');
        $this->assertStringContainsString('data-store-url-field="store_url"', $html);
    }

    public function test_app_password_edit_mode_prefills_username_but_never_password(): void
    {
        $store = ['wp_username' => 'admin_user', 'wp_app_password' => 'abcd efgh IJKL mnop'];
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_app_password_fields('edit', $store, 'https://shop.test')
        );

        $this->assertStringContainsString('value="admin_user"', $html);
        $this->assertStringNotContainsString('abcd efgh IJKL mnop', $html);
        $this->assertDoesNotMatchRegularExpression('/id="edit_wp_app_password"[^>]*\bvalue=/s', $html);
        $this->assertStringContainsString('placeholder="Already set', $html);
        $this->assertStringContainsString('data-has-saved-password="1"', $html);
        $this->assertStringContainsString('data-store-url="https://shop.test"', $html);
        $this->assertStringContainsString('data-saved-username="admin_user"', $html);
        $this->assertStringContainsString('id="wc-mss-test-app-password-edit-result"', $html);
    }

    public function test_app_password_edit_mode_without_saved_password_flags_zero(): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_app_password_fields('edit', ['wp_username' => 'u'], 'https://shop.test')
        );

        $this->assertStringContainsString('data-has-saved-password="0"', $html);
        $this->assertStringContainsString('placeholder=""', $html);
    }

    public function test_app_password_edit_mode_escapes_hostile_values(): void
    {
        $store = ['wp_username' => self::HOSTILE, 'wp_app_password' => 'x'];
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_app_password_fields('edit', $store, self::HOSTILE)
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame(3, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'username in value + data-saved-username, store url in data-store-url');
    }

    // ─── render_cache_purge_fields ─────────────────

    public function test_cache_purge_add_mode_defaults_to_get_and_empty_url(): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_cache_purge_fields(
                'add',
                ['cache_purge_url' => 'https://ignored.test', 'cache_purge_method' => 'POST']
            )
        );

        $this->tag_with_id($html, 'add_cache_purge_url');
        $this->tag_with_id($html, 'add_cache_purge_method');
        $this->assertStringContainsString('value=""', $html);
        $this->assertStringNotContainsString('ignored.test', $html);
        $this->assertMatchesRegularExpression("/<option value=\"GET\"\s+ selected='selected'>/", $html);
        $this->assertDoesNotMatchRegularExpression("/<option value=\"POST\"[^>]*selected/", $html);
    }

    public static function purge_method_provider(): array
    {
        return [
            'GET'     => ['GET', 'GET', 'POST'],
            'POST'    => ['POST', 'POST', 'GET'],
        ];
    }

    #[DataProvider('purge_method_provider')]
    public function test_cache_purge_edit_mode_selects_saved_method(string $saved, string $selected, string $other): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_cache_purge_fields(
                'edit',
                ['cache_purge_url' => 'https://remote.test/purge?id={remote_id}', 'cache_purge_method' => $saved]
            )
        );

        $this->tag_with_id($html, 'edit_cache_purge_url');
        $this->assertStringContainsString('value="https://remote.test/purge?id={remote_id}"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $selected . '"[^>]*selected=/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $other . '"[^>]*selected=/', $html);
    }

    public function test_cache_purge_edit_mode_without_method_defaults_to_get(): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_cache_purge_fields('edit', [])
        );

        $this->assertMatchesRegularExpression('/<option value="GET"[^>]*selected=/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="POST"[^>]*selected=/', $html);
    }

    public function test_cache_purge_edit_mode_escapes_hostile_url_and_unknown_method_selects_nothing(): void
    {
        $html = $this->capture(
            fn() => WC_Multi_Store_Store_Form_Renderer::render_cache_purge_fields(
                'edit',
                ['cache_purge_url' => self::HOSTILE, 'cache_purge_method' => 'DELETE']
            )
        );

        $this->assertStringNotContainsString('alert(1)</script>', $html);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;&#039;"', $html);
        $this->assertStringNotContainsString("selected='selected'", $html);
    }

    // ─── render_sync_preview ───────────────────────

    public function test_sync_preview_renders_counts_and_prefixed_span_id(): void
    {
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_sync_preview('edit_', 7, 40));

        $this->tag_with_id($html, 'edit_sync_preview');
        $this->assertStringContainsString('7 of 40 products will be synced', $html);
    }

    public function test_sync_preview_clamps_negative_counts_via_absint(): void
    {
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_sync_preview('add_', -5, -9));

        $this->assertStringContainsString('5 of 9 products will be synced', $html);
        $this->assertStringNotContainsString('-', strip_tags($html));
    }

    public function test_sync_preview_escapes_hostile_prefix(): void
    {
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_sync_preview(self::HOSTILE, 1, 1));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('id="&quot;&gt;&lt;script&gt;', $html);
    }

    // ─── render_exclusion_grid ─────────────────────

    private function term(int $id, string $name, int|string $count): object
    {
        return (object) ['term_id' => $id, 'name' => $name, 'count' => $count];
    }

    public function test_exclusion_grid_renders_checked_and_unchecked_terms(): void
    {
        $terms = [$this->term(3, 'Shoes', 12), $this->term(4, 'Hats', 0), $this->term(5, 'Bags', 7)];
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
            'edit_',
            'categories',
            $terms,
            [3, 5],
            'Exclude Categories',
            'Skip these'
        ));

        $this->tag_with_id($html, 'edit_exclude_categories');
        $this->assertSame(3, substr_count($html, 'name="exclude_categories[]"'));
        $this->assertSame(2, substr_count($html, "checked='checked'"));
        $this->assertMatchesRegularExpression('/value="3"\s+ checked=/', $html);
        $this->assertMatchesRegularExpression('/value="5"\s+ checked=/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="4"\s+ checked=/', $html);
        $this->assertSame(2, substr_count($html, 'wc-mss-checkbox-item selected'));
        $this->assertStringContainsString('2 selected', $html);
        $this->assertStringContainsString('<h4>Exclude Categories</h4>', $html);
        $this->assertStringContainsString('(12)', $html);
        $this->assertStringContainsString('(0)', $html);
        $this->assertStringContainsString("wcMssSelectAll('edit_exclude_categories')", $html);
        $this->assertStringContainsString("wcMssDeselectAll('edit_exclude_categories')", $html);
        $this->assertStringContainsString('data-target="edit_exclude_categories"', $html);
    }

    public function test_exclusion_grid_tags_group_uses_tags_field_and_container(): void
    {
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
            'add_',
            'tags',
            [$this->term(9, 'Sale', 1)],
            [],
            'Exclude Tags',
            'desc'
        ));

        $this->tag_with_id($html, 'add_exclude_tags');
        $this->assertStringContainsString('name="exclude_tags[]"', $html);
        $this->assertStringNotContainsString('exclude_categories', $html);
        $this->assertStringNotContainsString("checked='checked'", $html);
        $this->assertStringContainsString('0 selected', $html);
    }

    public function test_exclusion_grid_with_no_terms_renders_empty_container(): void
    {
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
            'add_',
            'categories',
            [],
            [1, 2, 3],
            'H',
            'D'
        ));

        $this->assertStringNotContainsString('<input', $html);
        $this->assertStringContainsString('3 selected', $html);
        $this->assertMatchesRegularExpression('/<div class="wc-mss-checkbox-grid" id="add_exclude_categories">\s*<\/div>/', $html);
    }

    public function test_exclusion_grid_escapes_hostile_term_name_heading_and_description(): void
    {
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
            'edit_',
            'categories',
            [$this->term(1, '<img src=x onerror=alert(1)>', '<b>9</b>')],
            [],
            '<script>h</script>',
            '<script>d</script>'
        ));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>9</b>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('(&lt;b&gt;9&lt;/b&gt;)', $html);
    }

    public function test_exclusion_grid_escapes_hostile_prefix_in_js_and_attributes(): void
    {
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
            "x');alert(1);//",
            'categories',
            [],
            [],
            'H',
            'D'
        ));

        $this->assertStringNotContainsString("wcMssSelectAll('x');alert(1)", $html, 'quote must be escaped in the JS context');
        $this->assertStringContainsString("wcMssSelectAll('x\\');alert(1);//exclude_categories')", $html);
    }

    public function test_exclusion_grid_uses_loose_in_array_for_numeric_string_excluded_ids(): void
    {
        $html = $this->capture(fn() => WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
            'edit_',
            'tags',
            [$this->term(8, 'A', 1), $this->term(9, 'B', 1)],
            ['8'],
            'H',
            'D'
        ));

        $this->assertSame(1, substr_count($html, "checked='checked'"));
        $this->assertMatchesRegularExpression('/value="8"\s+ checked=/', $html);
    }
}
