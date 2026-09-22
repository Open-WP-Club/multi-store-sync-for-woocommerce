<?php
/**
 * Attribute Sync
 * Synchronizes global product attributes (e.g. Color, Size) and their terms
 * to remote stores.
 *
 * Distinct from WC_Multi_Store_Attribute_Remapper, which only renames
 * attribute/term names in the product payload at sync time — it never
 * creates or updates the attribute/term definitions themselves on the
 * remote store.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Attribute_Sync {
    use WC_Multi_Store_Ajax_Auth_Guard;
    use WC_Multi_Store_Async_Sync;

    const ASYNC_SYNC_ATTRIBUTE_HOOK = 'wc_mss_sync_attribute_async';
    const ASYNC_DELETE_ATTRIBUTE_HOOK = 'wc_mss_delete_attribute_async';
    const ASYNC_SYNC_TERM_HOOK = 'wc_mss_sync_attribute_term_async';
    const ASYNC_DELETE_TERM_HOOK = 'wc_mss_delete_attribute_term_async';

    public function __construct() {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('woocommerce_attribute_added', $this->on_attribute_saved(...), 10, 1);
        add_action('woocommerce_attribute_updated', $this->on_attribute_saved(...), 10, 1);
        add_action('woocommerce_attribute_deleted', $this->on_attribute_deleted(...), 10, 2);

        // Generic WP taxonomy-term hooks — fire for every taxonomy, so
        // filtered down to attribute taxonomies (pa_*) in the handlers.
        add_action('created_term', $this->on_term_saved(...), 10, 3);
        add_action('edited_term', $this->on_term_saved(...), 10, 3);
        add_action('delete_term', $this->on_term_deleted(...), 10, 4);

        add_action(self::ASYNC_SYNC_ATTRIBUTE_HOOK, $this->sync_attribute_by_id(...), 10, 1);
        add_action(self::ASYNC_DELETE_ATTRIBUTE_HOOK, $this->delete_attribute_by_slug(...), 10, 1);
        add_action(self::ASYNC_SYNC_TERM_HOOK, $this->sync_term_by_id(...), 10, 2);
        add_action(self::ASYNC_DELETE_TERM_HOOK, $this->delete_term_by_slug(...), 10, 2);
    }

    private function is_attribute_taxonomy(string $taxonomy): bool {
        return str_starts_with($taxonomy, 'pa_');
    }

    /**
     * When a global attribute is created or updated. Renaming an attribute
     * (slug change) isn't specially handled — like coupon codes elsewhere in
     * this plugin, the remote lookup is always by current slug, so a rename
     * creates a new remote attribute rather than renaming the existing one.
     */
    public function on_attribute_saved(int $attribute_id): void {
        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        $this->schedule_async(self::ASYNC_SYNC_ATTRIBUTE_HOOK, [$attribute_id]);
    }

    public function on_attribute_deleted(int $attribute_id, string $attribute_name): void {
        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        $this->schedule_async(self::ASYNC_DELETE_ATTRIBUTE_HOOK, [$attribute_name]);
    }

    public function on_term_saved(int $term_id, int $tt_id, string $taxonomy): void {
        if (!$this->is_attribute_taxonomy($taxonomy) || !WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        $this->schedule_async(self::ASYNC_SYNC_TERM_HOOK, [$term_id, $taxonomy]);
    }

    /**
     * $deleted_term is the WP_Term snapshot WordPress passes just before
     * removal — needed because by the time the async job runs, get_term()
     * would return null.
     */
    public function on_term_deleted(int $term_id, int $tt_id, string $taxonomy, mixed $deleted_term): void {
        if (!$this->is_attribute_taxonomy($taxonomy) || !WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        if (!$deleted_term instanceof \WP_Term) {
            return;
        }

        $this->schedule_async(self::ASYNC_DELETE_TERM_HOOK, [$deleted_term->slug, $taxonomy]);
    }

    /**
     * Action Scheduler callback for on_attribute_saved().
     */
    public function sync_attribute_by_id(int $attribute_id): void {
        $attribute = $this->get_local_attribute($attribute_id);
        if (!$attribute) {
            return;
        }

        $this->sync_attribute_to_all_stores($attribute);
    }

    /**
     * Action Scheduler callback for on_attribute_deleted().
     */
    public function delete_attribute_by_slug(string $slug): void {
        $stores = WC_Multi_Store_Settings::get_active_stores();

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $remote = $this->find_remote_attribute($client, $slug);
            if ($remote) {
                $client->delete('products/attributes/' . $remote['id'], ['force' => true]);
            }
        }
    }

    /**
     * Action Scheduler callback for on_term_saved().
     */
    public function sync_term_by_id(int $term_id, string $taxonomy): void {
        $term = get_term($term_id, $taxonomy);
        if (!$term instanceof \WP_Term) {
            return;
        }

        $this->sync_term_to_all_stores($term, wc_attribute_taxonomy_slug($taxonomy));
    }

    /**
     * Action Scheduler callback for on_term_deleted().
     */
    public function delete_term_by_slug(string $slug, string $taxonomy): void {
        $attribute_slug = wc_attribute_taxonomy_slug($taxonomy);
        $stores = WC_Multi_Store_Settings::get_active_stores();

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $remote_attribute = $this->find_remote_attribute($client, $attribute_slug);
            if (!$remote_attribute) {
                continue;
            }

            $remote_term = $this->find_remote_term($client, (int) $remote_attribute['id'], $slug);
            if ($remote_term) {
                $client->delete('products/attributes/' . $remote_attribute['id'] . '/terms/' . $remote_term['id'], ['force' => true]);
            }
        }
    }

    /**
     * Extract attribute data for the WooCommerce REST API
     */
    public function extract_attribute_data(object $attribute): array {
        return [
            'name' => $attribute->attribute_label,
            'slug' => $attribute->attribute_name,
            'type' => $attribute->attribute_type,
            'order_by' => $attribute->attribute_orderby,
            'has_archives' => (bool) $attribute->attribute_public,
        ];
    }

    /**
     * Sync a global attribute to all active remote stores
     */
    public function sync_attribute_to_all_stores(object $attribute): array {
        $data = $this->extract_attribute_data($attribute);
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $results = [];

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $results[$store['store_url']] = $this->sync_attribute_to_store($client, $data);
        }

        return $results;
    }

    /**
     * Sync a global attribute to a specific remote store
     */
    public function sync_attribute_to_store(WC_Multi_Store_API_Client $client, array $data): bool {
        $existing = $this->find_remote_attribute($client, $data['slug']);
        $response = $existing
            ? $client->put('products/attributes/' . $existing['id'], $data)
            : $client->post('products/attributes', $data);

        if (is_wp_error($response)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Failed to sync attribute "%s": %s',
                $data['slug'],
                $response->get_error_message()
            ), 'error');
            return false;
        }

        return true;
    }

    /**
     * Extract term data for the WooCommerce REST API
     */
    public function extract_term_data(\WP_Term $term): array {
        return [
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
        ];
    }

    /**
     * Sync an attribute term to all active remote stores
     */
    public function sync_term_to_all_stores(\WP_Term $term, string $attribute_slug): array {
        $data = $this->extract_term_data($term);
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $results = [];

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $results[$store['store_url']] = $this->sync_term_to_store($client, $attribute_slug, $data);
        }

        return $results;
    }

    /**
     * Sync an attribute term to a specific remote store. The parent
     * attribute must already exist there (resolved by slug) — if it
     * doesn't, the attribute sync hook will create it on its own next save,
     * and this term will follow on its own next save too.
     */
    public function sync_term_to_store(WC_Multi_Store_API_Client $client, string $attribute_slug, array $data): bool {
        $remote_attribute = $this->find_remote_attribute($client, $attribute_slug);
        if (!$remote_attribute) {
            WC_Multi_Store_Logger::write(sprintf(
                'Skipped term sync: attribute "%s" not found remotely',
                $attribute_slug
            ), 'warning');
            return false;
        }

        $endpoint = 'products/attributes/' . $remote_attribute['id'] . '/terms';
        $existing = $this->find_remote_term($client, (int) $remote_attribute['id'], $data['slug']);
        $response = $existing
            ? $client->put($endpoint . '/' . $existing['id'], $data)
            : $client->post($endpoint, $data);

        if (is_wp_error($response)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Failed to sync attribute term "%s": %s',
                $data['slug'],
                $response->get_error_message()
            ), 'error');
            return false;
        }

        return true;
    }

    /**
     * Find a remote global attribute by slug. The attributes list endpoint
     * has no slug filter, so unlike find_remote_term() this fetches the
     * (typically small) full list and matches client-side.
     */
    private function find_remote_attribute(WC_Multi_Store_API_Client $client, string $slug): ?array {
        $response = $client->get('products/attributes', ['per_page' => 100]);
        if (is_wp_error($response) || empty($response)) {
            return null;
        }

        foreach ($response as $attribute) {
            if (($attribute['slug'] ?? '') === $slug) {
                return $attribute;
            }
        }

        return null;
    }

    private function find_remote_term(WC_Multi_Store_API_Client $client, int $remote_attribute_id, string $slug): ?array {
        $response = $client->get('products/attributes/' . $remote_attribute_id . '/terms', ['slug' => $slug, 'per_page' => 1]);
        if (is_wp_error($response) || empty($response)) {
            return null;
        }

        return is_array($response) ? ($response[0] ?? null) : null;
    }

    private function get_local_attribute(int $attribute_id): ?object {
        foreach (wc_get_attribute_taxonomies() as $attribute) {
            if ((int) $attribute->attribute_id === $attribute_id) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Get attribute sync settings
     */
    public static function get_settings(): array {
        return get_option('wc_mss_attribute_sync_settings', [
            'enabled' => false,
        ]);
    }

    /**
     * AJAX handler: Toggle attribute sync on/off
     */
    public static function ajax_toggle(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'multi-store-sync-for-woocommerce'))) {
            return;
        }

        $enabled = !empty($_POST['enabled']);
        $settings = self::get_settings();
        $settings['enabled'] = $enabled;
        update_option('wc_mss_attribute_sync_settings', $settings);

        wp_send_json_success([
            'message' => $enabled
                ? __('Attribute sync enabled', 'multi-store-sync-for-woocommerce')
                : __('Attribute sync disabled', 'multi-store-sync-for-woocommerce'),
            'enabled' => $enabled,
        ]);
    }
}
