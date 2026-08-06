<?php
/**
 * Product Edit Integration Class
 * Adds sync buttons and metabox to product edit page
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Product_Edit {

    /**
     * Initialize product edit integration
     */
    public function __construct() {
        // Add metabox to product edit page
        add_action('add_meta_boxes', $this->add_sync_metabox(...));
        add_action('add_meta_boxes', $this->add_store_deletion_metabox(...));

        // Handle AJAX sync request
        add_action('wp_ajax_wc_mss_sync_product', $this->ajax_sync_product(...));

        // Handle AJAX preview request
        add_action('wp_ajax_wc_mss_preview_sync', $this->ajax_preview_sync(...));

        // Save selective deletion settings
        add_action('save_post_product', $this->save_store_deletion_settings(...), 10, 1);

        // Add styles to product edit page
        add_action('admin_enqueue_scripts', $this->enqueue_scripts(...));
    }

    /**
     * Add sync metabox to product edit page
     */
    public function add_sync_metabox(): void {
        add_meta_box(
            'wc_multi_store_sync_actions',
            __('Multi-Store Sync', 'wc-multi-store-sync'),
            $this->render_sync_metabox(...),
            'product',
            'side',
            'high'
        );
    }

    /**
     * Render sync metabox content
     *
     * @param WP_Post $post Post object
     */
    public function render_sync_metabox(WP_Post $post): void {
        $product_id = $post->ID;
        $is_queued = WC_MSS()->queue_manager->is_queued($product_id);
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $store_count = count($stores);

        ?>
        <div class="wc-mss-sync-actions">
            <?php if ($store_count === 0): ?>
                <p style="color: #d63638;">
                    <?php _e('No active stores configured.', 'wc-multi-store-sync'); ?>
                </p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=wc-multi-store-sync-stores'); ?>">
                        <?php _e('Configure Stores', 'wc-multi-store-sync'); ?>
                    </a>
                </p>
            <?php else: ?>
                <p>
                    <?php printf(
                        __('Sync this product to %d active store(s).', 'wc-multi-store-sync'),
                        $store_count
                    ); ?>
                </p>

                <?php if ($is_queued): ?>
                    <div class="wc-mss-queue-notice" style="background: #fff3cd; padding: 10px; margin-bottom: 10px; border-left: 3px solid #ffc107;">
                        <strong><?php _e('⏱ Queued for sync', 'wc-multi-store-sync'); ?></strong>
                        <p style="margin: 5px 0 0 0; font-size: 12px;">
                            <?php _e('This product is in the sync queue and will be processed soon.', 'wc-multi-store-sync'); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="wc-mss-sync-buttons">
                    <button type="button" class="button button-primary wc-mss-sync-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-sync-type="full_product">
                        <?php _e('🔄 Full Sync', 'wc-multi-store-sync'); ?>
                    </button>

                    <button type="button" class="button wc-mss-sync-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-sync-type="price_quantity">
                        <?php _e('💰 Price & Stock', 'wc-multi-store-sync'); ?>
                    </button>

                    <button type="button" class="button wc-mss-sync-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-sync-type="quantity">
                        <?php _e('📦 Stock Only', 'wc-multi-store-sync'); ?>
                    </button>

                    <button type="button" class="button wc-mss-preview-btn" data-product-id="<?php echo esc_attr($product_id); ?>" style="margin-top: 10px;">
                        <?php _e('👁️ Preview Changes', 'wc-multi-store-sync'); ?>
                    </button>
                </div>

                <div class="wc-mss-sync-result" style="margin-top: 10px; display: none;"></div>
                <div class="wc-mss-preview-result" style="margin-top: 10px; display: none;"></div>

                <p style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 12px; color: #666;">
                    <strong><?php _e('Sync Types:', 'wc-multi-store-sync'); ?></strong><br>
                    <strong><?php _e('Full Sync:', 'wc-multi-store-sync'); ?></strong> <?php _e('All product data', 'wc-multi-store-sync'); ?><br>
                    <strong><?php _e('Price & Stock:', 'wc-multi-store-sync'); ?></strong> <?php _e('Pricing and inventory only', 'wc-multi-store-sync'); ?><br>
                    <strong><?php _e('Stock Only:', 'wc-multi-store-sync'); ?></strong> <?php _e('Inventory only (fastest)', 'wc-multi-store-sync'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Add store deletion settings metabox
     */
    public function add_store_deletion_metabox(): void {
        add_meta_box(
            'wc_multi_store_deletion_settings',
            __('Selective Store Deletion', 'wc-multi-store-sync'),
            $this->render_store_deletion_metabox(...),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render store deletion settings metabox
     *
     * @param WP_Post $post Post object
     */
    public function render_store_deletion_metabox(WP_Post $post): void {
        $product_id = $post->ID;
        $stores = WC_Multi_Store_Settings::get_active_stores();

        if (empty($stores)) {
            ?>
            <p style="color: #666;">
                <?php _e('No active stores configured.', 'wc-multi-store-sync'); ?>
            </p>
            <?php
            return;
        }

        // Get saved settings
        $deletion_settings = get_post_meta($product_id, '_wc_mss_selective_deletion', true);
        if (!is_array($deletion_settings)) {
            $deletion_settings = [];
        }

        // Nonce for security
        wp_nonce_field('wc_mss_store_deletion_nonce', 'wc_mss_store_deletion_nonce');

        ?>
        <div class="wc-mss-store-deletion">
            <p style="margin-bottom: 10px; color: #666;">
                <?php _e('Choose which stores to delete this product from when deleted locally:', 'wc-multi-store-sync'); ?>
            </p>

            <?php foreach ($stores as $store_url => $store_config): ?>
                <?php
                $store_name = $store_config['name'] ?? $store_url;
                $store_key = md5($store_url);
                $is_checked = $deletion_settings[$store_key] ?? true; // Default to checked
                ?>
                <label style="display: block; margin-bottom: 8px;">
                    <input type="checkbox"
                           name="_wc_mss_delete_from_stores[<?php echo esc_attr($store_key); ?>]"
                           value="1"
                           <?php checked($is_checked, true); ?>>
                    <span><?php echo esc_html($store_name); ?></span>
                </label>
            <?php endforeach; ?>

            <p style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 12px; color: #666;">
                <strong><?php _e('Note:', 'wc-multi-store-sync'); ?></strong>
                <?php _e('These settings override the global deletion settings for this product only.', 'wc-multi-store-sync'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Save store deletion settings
     *
     * @param int $post_id Post ID
     */
    public function save_store_deletion_settings(int $post_id): void {
        // Check nonce
        if (!isset($_POST['wc_mss_store_deletion_nonce']) ||
            !wp_verify_nonce($_POST['wc_mss_store_deletion_nonce'], 'wc_mss_store_deletion_nonce')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_product', $post_id)) {
            return;
        }

        // Save settings
        $deletion_settings = [];

        if (isset($_POST['_wc_mss_delete_from_stores']) && is_array($_POST['_wc_mss_delete_from_stores'])) {
            $stores = WC_Multi_Store_Settings::get_active_stores();

            foreach ($stores as $store_url => $store_config) {
                $store_key = md5($store_url);
                $deletion_settings[$store_key] = isset($_POST['_wc_mss_delete_from_stores'][$store_key]);
            }
        }

        update_post_meta($post_id, '_wc_mss_selective_deletion', $deletion_settings);
        update_post_meta($post_id, '_wc_mss_deletion_stores_map', $this->get_store_url_map());
    }

    /**
     * Get store URL map for reference
     *
     * @return array Store key to URL mapping
     */
    private function get_store_url_map(): array {
        $stores = WC_Multi_Store_Settings::get_active_stores();
        $map = [];

        foreach ($stores as $store_url => $store_config) {
            $store_key = md5($store_url);
            $map[$store_key] = $store_url;
        }

        return $map;
    }

    /**
     * Handle AJAX sync product request
     */
    public function ajax_sync_product(): void {
        // Check nonce
        check_ajax_referer('wc_mss_sync_product', 'nonce');

        // Check general permissions
        if (!current_user_can('edit_products')) {
            wp_send_json_error([
                'message' => __('You do not have permission to sync products.', 'wc-multi-store-sync'),
            ]);
            return;
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $sync_type = sanitize_text_field($_POST['sync_type'] ?? 'full_product');

        if (!$product_id) {
            wp_send_json_error([
                'message' => __('Invalid product ID.', 'wc-multi-store-sync'),
            ]);
            return;
        }

        // Check specific product permission
        if (!current_user_can('edit_post', $product_id)) {
            wp_send_json_error([
                'message' => __('You do not have permission to edit this product.', 'wc-multi-store-sync'),
            ]);
            return;
        }

        // Get product
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error([
                'message' => __('Product not found.', 'wc-multi-store-sync'),
            ]);
            return;
        }

        // Get active stores
        $stores = WC_Multi_Store_Settings::get_active_stores();
        if (empty($stores)) {
            wp_send_json_error([
                'message' => __('No active stores configured.', 'wc-multi-store-sync'),
            ]);
            return;
        }

        // Queue product for sync instead of immediate sync.
        // Uses 'manual_test' source so the smart-skip for unchanged data (images,
        // categories, tags) is bypassed — the user explicitly requested a full sync.
        $queued_count = WC_MSS()->queue_manager->add_product(
            $product_id,
            'manual_test',
            WC_Multi_Store_Queue_Manager::PRIORITY_HIGH,
            $sync_type
        );

        if ($queued_count > 0) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Product queued for sync to %d store(s). Sync will be processed shortly.', 'wc-multi-store-sync'),
                    $queued_count
                ),
                'queued' => true,
                'queued_count' => $queued_count,
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to queue product for sync. Check logs for details.', 'wc-multi-store-sync'),
            ]);
        }
    }

    /**
     * Handle AJAX preview sync request
     */
    public function ajax_preview_sync(): void {
        // Check nonce
        check_ajax_referer('wc_mss_preview_sync', 'nonce');

        // Check general permissions
        if (!current_user_can('edit_products')) {
            wp_send_json_error([
                'message' => __('You do not have permission to preview sync.', 'wc-multi-store-sync'),
            ]);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $sync_type = sanitize_text_field($_POST['sync_type'] ?? 'full_product');

        if (!$product_id) {
            wp_send_json_error([
                'message' => __('Invalid product ID.', 'wc-multi-store-sync'),
            ]);
        }

        // Check specific product permission
        if (!current_user_can('edit_post', $product_id)) {
            wp_send_json_error([
                'message' => __('You do not have permission to view this product.', 'wc-multi-store-sync'),
            ]);
        }

        // Get product
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error([
                'message' => __('Product not found.', 'wc-multi-store-sync'),
            ]);
        }

        // Get active stores
        $stores = WC_Multi_Store_Settings::get_active_stores();
        if (empty($stores)) {
            wp_send_json_error([
                'message' => __('No active stores configured.', 'wc-multi-store-sync'),
            ]);
        }

        // Preview sync
        $previewer = new WC_Multi_Store_Sync_Previewer();
        $result = $previewer->preview_product_sync($product_id, $stores, $sync_type);

        if ($result['success']) {
            // Format preview HTML
            $html = '<div class="wc-mss-preview-container" style="max-height: 500px; overflow-y: auto;">';

            foreach ($result['previews'] as $store_url => $preview) {
                $html .= $previewer->format_preview_html($preview);
            }

            $html .= '</div>';
            $html .= '<div class="wc-mss-preview-actions" style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd;">';
            $html .= '<button type="button" class="button button-primary wc-mss-execute-sync" data-product-id="' . esc_attr($product_id) . '" data-sync-type="' . esc_attr($sync_type) . '">';
            $html .= __('✓ Proceed with Sync', 'wc-multi-store-sync');
            $html .= '</button> ';
            $html .= '<button type="button" class="button wc-mss-cancel-preview">';
            $html .= __('✗ Cancel', 'wc-multi-store-sync');
            $html .= '</button>';
            $html .= '</div>';

            wp_send_json_success([
                'html' => $html,
                'result' => $result,
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * Enqueue scripts for product edit page
     *
     * @param string $hook Hook suffix
     */
    public function enqueue_scripts(string $hook): void {
        // Only on product edit page
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        // Enqueue product sync script
        wp_enqueue_script(
            'wc-mss-product-sync',
            WC_MSS_PLUGIN_URL . 'admin/js/product-sync.js',
            ['jquery'],
            WC_MSS_VERSION,
            true
        );

        // Pass data to script securely via wp_localize_script
        wp_localize_script('wc-mss-product-sync', 'wcMssProduct', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'syncNonce' => wp_create_nonce('wc_mss_sync_product'),
            'previewNonce' => wp_create_nonce('wc_mss_preview_sync'),
            'i18n' => [
                'syncing' => __('Syncing...', 'wc-multi-store-sync'),
                'loadingPreview' => __('Loading preview...', 'wc-multi-store-sync'),
                'fullSync' => __('Full Sync', 'wc-multi-store-sync'),
                'priceStock' => __('Price & Stock', 'wc-multi-store-sync'),
                'stockOnly' => __('Stock Only', 'wc-multi-store-sync'),
                'previewChanges' => __('Preview Changes', 'wc-multi-store-sync'),
                'errorOccurred' => __('An error occurred. Please try again.', 'wc-multi-store-sync'),
            ],
        ]);

        wp_enqueue_style(
            'wc-mss-product-edit',
            WC_MSS_PLUGIN_URL . 'admin/css/product-edit.css',
            [],
            WC_MSS_VERSION
        );
    }
}
