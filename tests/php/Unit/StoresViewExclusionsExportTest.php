<?php
/**
 * Regression tests for the stores view's inline exclusions-export script.
 *
 * The export payload is embedded in a <script> block, and exclusion term
 * names are user-controlled: the JSON literal must be hex-escaped or a term
 * named "</script><script>…" breaks out of the block (stores.php).
 */

use Brain\Monkey\Functions;

require_once dirname(__DIR__, 3) . '/includes/store-form-renderer.php';

class StoresViewExclusionsExportTest extends WC_Multi_Store_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once WC_MSS_PLUGIN_DIR . 'includes/admin-list-table.php';

        Functions\when('esc_html')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(static function ($t, $d = 'default'): void {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('esc_html__')->alias(static fn($t, $d = 'default') => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr_e')->alias(static function ($t, $d = 'default'): void {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });
        Functions\when('esc_attr__')->alias(static fn($t, $d = 'default') => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_js')->alias(static fn($t) => addslashes(htmlspecialchars((string) $t, ENT_COMPAT, 'UTF-8')));
        Functions\when('esc_url')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('wp_kses_post')->alias(static fn($t) => $t);
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('selected')->alias(static function ($selected, $current = true, $echo = true) {
            $out = ((string) $selected === (string) $current) ? " selected='selected'" : '';
            if ($echo) {
                echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
            }
            return $out;
        });
        Functions\when('checked')->alias(static function ($checked, $current = true, $echo = true) {
            $out = ((string) $checked === (string) $current) ? " checked='checked'" : '';
            if ($echo) {
                echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
            }
            return $out;
        });
        Functions\when('settings_errors')->justReturn(null);
        Functions\when('wp_nonce_field')->justReturn(null);
        Functions\when('add_query_arg')->alias(static fn(...$args) => 'https://primary.test/wp-admin/admin.php');
        Functions\when('remove_query_arg')->alias(static fn(...$args) => 'https://primary.test/wp-admin/admin.php');
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 0]);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_site_url')->justReturn('https://primary.test');
        Functions\when('get_bloginfo')->justReturn('Primary Test');
        Functions\when('wp_json_encode')->alias(static fn($data, $flags = 0) => json_encode($data, $flags));
        Functions\when('_n')->alias(static fn($single, $plural, $number) => ((int) $number === 1) ? $single : $plural);
        Functions\when('get_terms')->justReturn([]);
    }

    /** Render the stores view with one store whose excluded category has a hostile name. */
    private function renderStoresView(string $hostileName): string
    {
        Functions\when('get_term')->alias(static fn($term, $taxonomy = '') => (object) [
            'term_id' => 10,
            'name' => $hostileName,
            'slug' => 'hostile',
        ]);

        // One store with one excluded category whose NAME is hostile; the
        // exclusions export resolves IDs to names via get_term().
        $stores = [
            'https://a.example.com' => [
                'status' => 'active',
                'exclude_categories' => [10],
                'exclude_tags' => [],
            ],
        ];

        $_GET = [];
        ob_start();
        try {
            include WC_MSS_PLUGIN_DIR . 'admin/views/stores.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function test_exclusions_export_json_is_hex_escaped_against_script_breakout(): void
    {
        $hostileName = '</script><script>alert(1)</script>';
        $html = $this->renderStoresView($hostileName);

        $this->assertStringNotContainsString(
            $hostileName,
            $html,
            'A raw "</script>" inside the inline JSON literal would close the script block early'
        );
        $this->assertStringContainsString(
            'alert(1)',
            $html,
            'the export payload must still carry the term name'
        );
    }

    public function test_exclusions_export_json_still_parses_back_to_the_real_name(): void
    {
        $hostileName = '</script><script>alert(1)</script>';
        $html = $this->renderStoresView($hostileName);

        // JSON_HEX_TAG/AMP/APOS/QUOT render markup characters as \uXXXX
        // escapes inside the literal; the escapes are transparent to the
        // parser (the client re-encodes from the parsed object for the
        // download, so the exported file keeps the original characters).
        $this->assertStringContainsString('alert(1)', $html);
        $this->assertStringContainsString('u003C', $html, 'markup characters must appear as unicode escapes');

        $this->assertSame(
            1,
            preg_match('/var exclusionsData = (\{.*?\});/s', $html, $m),
            'the inline exclusionsData literal must be present'
        );
        $data = json_decode($m[1], true);
        $this->assertSame(
            $hostileName,
            $data['stores'][0]['excluded_categories'][0]['name'] ?? null,
            'the escaped literal must parse back to the original term name'
        );
    }
}
