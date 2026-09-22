<?php
/**
 * Review Sync
 * Synchronizes WooCommerce product reviews to remote stores
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Review_Sync {
    use WC_Multi_Store_Ajax_Auth_Guard;
    use WC_Multi_Store_Async_Sync;

    /**
     * TTL for the per-store SKU→remote-product-ID lookup cache, same
     * rationale as WC_Multi_Store_Coupon_Sync::REMOTE_ID_CACHE_TTL.
     */
    const REMOTE_ID_CACHE_TTL = HOUR_IN_SECONDS;

    const ASYNC_SYNC_HOOK = 'wc_mss_sync_review_async';
    const ASYNC_DELETE_HOOK = 'wc_mss_delete_review_async';

    /**
     * Comment meta marking a review as having arrived here via sync, so this
     * store's own outbound hooks don't re-push it back out across the mesh
     * of stores (each store runs this same plugin).
     */
    const SYNCED_MARKER_META = '_wc_mss_review_synced';

    public function __construct() {
        // Registered unconditionally (not gated by the enabled setting
        // below): this store may be a sync target for another store even
        // if outbound review sync is off here, so it must still be able to
        // receive the wc_mss_synced field on incoming products/reviews
        // requests and strip the verified flag / mark the loop guard.
        add_action('rest_api_init', self::register_synced_field(...));

        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('comment_post', $this->on_review_posted(...), 10, 3);
        add_action('edit_comment', $this->on_review_changed(...), 10, 1);
        add_action('transition_comment_status', $this->on_review_status_changed(...), 10, 3);
        add_action('delete_comment', $this->on_review_deleted(...), 10, 1);

        // Action Scheduler callbacks, matching the coupon-sync convention of
        // deferring the actual remote-store fan-out out of the request that
        // triggered it.
        add_action(self::ASYNC_SYNC_HOOK, $this->sync_review_by_id(...), 10, 1);
        add_action(self::ASYNC_DELETE_HOOK, $this->delete_review_by_data(...), 10, 2);
    }

    /**
     * Adds a `wc_mss_synced` field to the products/reviews REST resource.
     * When a peer store pushes a review here with this field set, we mark
     * it with the loop-guard meta and explicitly delete the 'verified' meta
     * WooCommerce auto-computes on insert — a synced review never carries
     * the "verified owner" badge, regardless of whether this store
     * coincidentally has its own order matching the reviewer's email.
     */
    public static function register_synced_field(): void {
        register_rest_field('product_review', 'wc_mss_synced', [
            'update_callback' => function ($value, $comment) {
                if (empty($value) || empty($comment->comment_ID)) {
                    return;
                }
                update_comment_meta($comment->comment_ID, self::SYNCED_MARKER_META, 1);
                delete_comment_meta($comment->comment_ID, 'verified');
            },
            'schema' => null,
        ]);
    }

    /**
     * When a new product review is posted
     */
    public function on_review_posted(int $comment_id, int|string $approved, array $commentdata): void {
        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        if (($commentdata['comment_type'] ?? '') !== 'review') {
            return;
        }

        $this->schedule_async(self::ASYNC_SYNC_HOOK, [$comment_id]);
    }

    /**
     * When a review's content/rating is edited
     */
    public function on_review_changed(int $comment_id): void {
        if (!$this->is_review($comment_id)) {
            return;
        }

        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        $this->schedule_async(self::ASYNC_SYNC_HOOK, [$comment_id]);
    }

    /**
     * When a review is approved/unapproved/spammed etc.
     */
    public function on_review_status_changed(string $new_status, string $old_status, \WP_Comment $comment): void {
        if ($comment->comment_type !== 'review' || $new_status === $old_status) {
            return;
        }

        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        $this->schedule_async(self::ASYNC_SYNC_HOOK, [(int) $comment->comment_ID]);
    }

    /**
     * When a review is deleted. Capture the data needed to find it on
     * remote stores now — by the time the async job runs, the comment row
     * is gone (same rationale as
     * WC_Multi_Store_Coupon_Sync::on_coupon_deleted() capturing the code
     * up front).
     */
    public function on_review_deleted(int $comment_id): void {
        if (!$this->is_review($comment_id)) {
            return;
        }

        if (!WC_Multi_Store_Settings::get('enabled')) {
            return;
        }

        $comment = get_comment($comment_id);
        $product = wc_get_product((int) $comment->comment_post_ID);
        $sku = $product ? $product->get_sku() : '';

        if (empty($sku)) {
            return;
        }

        $this->schedule_async(self::ASYNC_DELETE_HOOK, [$sku, $comment->comment_author_email]);
    }

    private function is_review(int $comment_id): bool {
        $comment = get_comment($comment_id);
        return $comment instanceof \WP_Comment && $comment->comment_type === 'review';
    }

    /**
     * Action Scheduler callback for on_review_posted()/on_review_changed()/
     * on_review_status_changed().
     */
    public function sync_review_by_id(int $comment_id): void {
        // Loop guard: skip reviews that arrived here via sync (see
        // register_synced_field()). Checked here, at execution time, rather
        // than when the hook first fired — the marker meta is only set by
        // the REST field's update_callback, which runs after comment_post
        // already scheduled this very job.
        if (get_comment_meta($comment_id, self::SYNCED_MARKER_META, true)) {
            return;
        }

        $comment = get_comment($comment_id);
        if (!$comment instanceof \WP_Comment || $comment->comment_type !== 'review') {
            return;
        }

        $this->sync_review_to_all_stores($comment);
    }

    /**
     * Extract review data for the WooCommerce REST API
     */
    public function extract_review_data(\WP_Comment $comment): array {
        return [
            'review' => $comment->comment_content,
            'reviewer' => $comment->comment_author,
            'reviewer_email' => $comment->comment_author_email,
            'rating' => (int) get_comment_meta((int) $comment->comment_ID, 'rating', true),
            'status' => $comment->comment_approved === '1' ? 'approved' : 'hold',
            // Picked up by register_synced_field() on the receiving store to
            // set the loop guard and strip the verified-owner flag.
            'wc_mss_synced' => true,
        ];
    }

    /**
     * Sync a review to all active remote stores
     */
    public function sync_review_to_all_stores(\WP_Comment $comment): array {
        $product = wc_get_product((int) $comment->comment_post_ID);
        if (!$product || !$product->get_sku()) {
            return [];
        }

        $sku = $product->get_sku();
        $data = $this->extract_review_data($comment);
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $results = [];

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $results[$store['store_url']] = $this->sync_review_to_store($client, $sku, $data, $store['store_url']);
        }

        return $results;
    }

    /**
     * Sync a review to a specific remote store
     */
    public function sync_review_to_store(WC_Multi_Store_API_Client $client, string $sku, array $data, string $store_url): bool {
        $remote_product_id = $this->resolve_remote_product_id($client, $sku, $store_url);
        if (!$remote_product_id) {
            WC_Multi_Store_Logger::write(sprintf(
                'Skipped review sync to %s: product SKU "%s" not found remotely',
                $store_url,
                $sku
            ), 'warning');
            return false;
        }

        $data['product_id'] = $remote_product_id;

        $existing = $this->find_remote_review($client, $remote_product_id, $data['reviewer_email']);
        $response = $existing
            ? $client->put('products/reviews/' . $existing['id'], $data)
            : $client->post('products/reviews', $data);

        if (is_wp_error($response)) {
            WC_Multi_Store_Logger::write(sprintf(
                'Failed to sync review by "%s" to %s: %s',
                $data['reviewer_email'],
                $store_url,
                $response->get_error_message()
            ), 'error');
            return false;
        }

        return true;
    }

    /**
     * Action Scheduler callback for on_review_deleted(). Thin void wrapper
     * around delete_review_from_all_stores() — Action Scheduler hook
     * callbacks must return void.
     */
    public function delete_review_by_data(string $sku, string $reviewer_email): void {
        $this->delete_review_from_all_stores($sku, $reviewer_email);
    }

    /**
     * Delete a review from all remote stores by SKU + reviewer email.
     */
    public function delete_review_from_all_stores(string $sku, string $reviewer_email): array {
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $results = [];

        foreach ($stores as $store) {
            $client = self::get_api_client($store);
            $remote_product_id = $this->resolve_remote_product_id($client, $sku, $store['store_url']);
            if (!$remote_product_id) {
                continue;
            }

            $existing = $this->find_remote_review($client, $remote_product_id, $reviewer_email);
            if (!$existing) {
                continue;
            }

            $response = $client->delete('products/reviews/' . $existing['id'], ['force' => true]);
            $results[$store['store_url']] = !is_wp_error($response);
        }

        return $results;
    }

    /**
     * Find an existing review on the remote store for this product+reviewer,
     * so repeat syncs (edits, status changes) update instead of duplicate —
     * same "look it up by a natural key" approach as
     * WC_Multi_Store_Coupon_Sync::find_remote_coupon() (coupons have no
     * per-store ID mapping stored locally either).
     */
    private function find_remote_review(WC_Multi_Store_API_Client $client, int $remote_product_id, string $reviewer_email): ?array {
        $response = $client->get('products/reviews', [
            'product' => [$remote_product_id],
            'reviewer_email' => $reviewer_email,
            'per_page' => 1,
        ]);

        if (is_wp_error($response) || empty($response)) {
            return null;
        }

        return is_array($response) ? ($response[0] ?? null) : null;
    }

    /**
     * Resolve a local product SKU to its remote product ID, cached per
     * store so a burst of reviews for the same product doesn't issue one
     * lookup call per review.
     */
    private function resolve_remote_product_id(WC_Multi_Store_API_Client $client, string $sku, string $store_url): ?int {
        $cache_key = 'wc_mss_review_sku_map_' . md5($store_url);
        $map = get_transient($cache_key);
        if (!is_array($map)) {
            $map = [];
        }

        if (array_key_exists($sku, $map)) {
            return $map[$sku];
        }

        $response = $client->get('products', ['sku' => $sku, 'per_page' => 1]);
        $remote_id = (!is_wp_error($response) && !empty($response[0]['id'])) ? (int) $response[0]['id'] : null;

        $map[$sku] = $remote_id;
        set_transient($cache_key, $map, self::REMOTE_ID_CACHE_TTL);

        return $remote_id;
    }

    /**
     * Get review sync settings
     */
    public static function get_settings(): array {
        return get_option('wc_mss_review_sync_settings', [
            'enabled' => false,
        ]);
    }

    /**
     * AJAX handler: Toggle review sync on/off
     */
    public static function ajax_toggle(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'multi-store-sync-for-woocommerce'))) {
            return;
        }

        $enabled = !empty($_POST['enabled']);
        $settings = self::get_settings();
        $settings['enabled'] = $enabled;
        update_option('wc_mss_review_sync_settings', $settings);

        wp_send_json_success([
            'message' => $enabled
                ? __('Review sync enabled', 'multi-store-sync-for-woocommerce')
                : __('Review sync disabled', 'multi-store-sync-for-woocommerce'),
            'enabled' => $enabled,
        ]);
    }
}
