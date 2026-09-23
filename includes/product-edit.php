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
            __('Multi-Store Sync', 'multi-store-sync-for-woocommerce'),
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
                    <?php esc_html_e('No active stores configured.', 'multi-store-sync-for-woocommerce'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-multi-store-sync-stores')); ?>">
                        <?php esc_html_e('Configure Stores', 'multi-store-sync-for-woocommerce'); ?>
                    </a>
                </p>
            <?php else: ?>
                <p>
                    <?php printf(
                        /* translators: %d: number of active stores. */
                        esc_html__('Sync this product to %d active store(s).', 'multi-store-sync-for-woocommerce'),
                        absint($store_count)
                    ); ?>
                </p>

                <?php if ($is_queued): ?>
                    <div class="wc-mss-queue-notice" style="background: #fff3cd; padding: 10px; margin-bottom: 10px; border-left: 3px solid #ffc107;">
                        <strong><?php esc_html_e('⏱ Queued for sync', 'multi-store-sync-for-woocommerce'); ?></strong>
                        <p style="margin: 5px 0 0 0; font-size: 12px;">
                            <?php esc_html_e('This product is in the sync queue and will be processed soon.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="wc-mss-sync-buttons">
                    <button type="button" class="button button-primary wc-mss-sync-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-sync-type="full_product">
                        <?php esc_html_e('🔄 Full Sync', 'multi-store-sync-for-woocommerce'); ?>
                    </button>

                    <button type="button" class="button wc-mss-sync-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-sync-type="price_quantity">
                        <?php esc_html_e('💰 Price & Stock', 'multi-store-sync-for-woocommerce'); ?>
                    </button>

                    <button type="button" class="button wc-mss-sync-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-sync-type="quantity">
                        <?php esc_html_e('📦 Stock Only', 'multi-store-sync-for-woocommerce'); ?>
                    </button>

                    <button type="button" class="button wc-mss-preview-btn" data-product-id="<?php echo esc_attr($product_id); ?>" style="margin-top: 10px;">
                        <?php esc_html_e('👁️ Preview Changes', 'multi-store-sync-for-woocommerce'); ?>
                    </button>
                </div>

                <div class="wc-mss-sync-result" style="margin-top: 10px; display: none;"></div>
                <div class="wc-mss-preview-result" style="margin-top: 10px; display: none;"></div>

                <p style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 12px; color: #666;">
                    <strong><?php esc_html_e('Sync Types:', 'multi-store-sync-for-woocommerce'); ?></strong><br>
                    <strong><?php esc_html_e('Full Sync:', 'multi-store-sync-for-woocommerce'); ?></strong> <?php esc_html_e('All product data', 'multi-store-sync-for-woocommerce'); ?><br>
                    <strong><?php esc_html_e('Price & Stock:', 'multi-store-sync-for-woocommerce'); ?></strong> <?php esc_html_e('Pricing and inventory only', 'multi-store-sync-for-woocommerce'); ?><br>
                    <strong><?php esc_html_e('Stock Only:', 'multi-store-sync-for-woocommerce'); ?></strong> <?php esc_html_e('Inventory only (fastest)', 'multi-store-sync-for-woocommerce'); ?>
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
            __('Selective Store Deletion', 'multi-store-sync-for-woocommerce'),
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
                <?php esc_html_e('No active stores configured.', 'multi-store-sync-for-woocommerce'); ?>
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
                <?php esc_html_e('Choose which stores to delete this product from when deleted locally:', 'multi-store-sync-for-woocommerce'); ?>
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
                <strong><?php esc_html_e('Note:', 'multi-store-sync-for-woocommerce'); ?></strong>
                <?php esc_html_e('These settings override the global deletion settings for this product only.', 'multi-store-sync-for-woocommerce'); ?>
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
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wc_mss_store_deletion_nonce'])), 'wc_mss_store_deletion_nonce')) {
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
                'message' => __('You do not have permission to sync products.', 'multi-store-sync-for-woocommerce'),
            ]);
            return;
        }

        $product_id = isset($_POST['product_id']) && is_string($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        $sync_type = isset($_POST['sync_type']) && is_string($_POST['sync_type']) ? sanitize_key(wp_unslash($_POST['sync_type'])) : 'full_product';
        if (!in_array($sync_type, ['full_product', 'price_quantity_categories', 'price_quantity', 'quantity'], true)) {
            $sync_type = 'full_product';
        }

        if (!$product_id) {
            wp_send_json_error([
                'message' => __('Invalid product ID.', 'multi-store-sync-for-woocommerce'),
            ]);
            return;
        }

        // Check specific product permission
        if (!current_user_can('edit_post', $product_id)) {
            wp_send_json_error([
                'message' => __('You do not have permission to edit this product.', 'multi-store-sync-for-woocommerce'),
            ]);
            return;
        }

        // Get product
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error([
                'message' => __('Product not found.', 'multi-store-sync-for-woocommerce'),
            ]);
            return;
        }

        // Get active stores
        $stores = WC_Multi_Store_Settings::get_active_stores();
        if (empty($stores)) {
            wp_send_json_error([
                'message' => __('No active stores configured.', 'multi-store-sync-for-woocommerce'),
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
                    /* translators: %d: number of active stores receiving the product sync. */
                    __('Product queued for sync to %d store(s). Sync will be processed shortly.', 'multi-store-sync-for-woocommerce'),
                    $queued_count
                ),
                'queued' => true,
                'queued_count' => $queued_count,
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to queue product for sync. Check logs for details.', 'multi-store-sync-for-woocommerce'),
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
                'message' => __('You do not have permission to preview sync.', 'multi-store-sync-for-woocommerce'),
            ]);
        }

        $product_id = isset($_POST['product_id']) && is_string($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        $sync_type = isset($_POST['sync_type']) && is_string($_POST['sync_type']) ? sanitize_key(wp_unslash($_POST['sync_type'])) : 'full_product';
        if (!in_array($sync_type, ['full_product', 'price_quantity_categories', 'price_quantity', 'quantity'], true)) {
            $sync_type = 'full_product';
        }

        if (!$product_id) {
            wp_send_json_error([
                'message' => __('Invalid product ID.', 'multi-store-sync-for-woocommerce'),
            ]);
        }

        // Check specific product permission
        if (!current_user_can('edit_post', $product_id)) {
            wp_send_json_error([
                'message' => __('You do not have permission to view this product.', 'multi-store-sync-for-woocommerce'),
            ]);
        }

        // Get product
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error([
                'message' => __('Product not found.', 'multi-store-sync-for-woocommerce'),
            ]);
        }

        // Get active stores
        $stores = WC_Multi_Store_Settings::get_active_stores();
        if (empty($stores)) {
            wp_send_json_error([
                'message' => __('No active stores configured.', 'multi-store-sync-for-woocommerce'),
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
            $html .= '<button type="button" class="button button-primary wc-mss-execute-sync" data-product-id="' . esc_attr((string) $product_id) . '" data-sync-type="' . esc_attr($sync_type) . '">';
            $html .= __('✓ Proceed with Sync', 'multi-store-sync-for-woocommerce');
            $html .= '</button> ';
            $html .= '<button type="button" class="button wc-mss-cancel-preview">';
            $html .= __('✗ Cancel', 'multi-store-sync-for-woocommerce');
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
                'syncing' => __('Syncing...', 'multi-store-sync-for-woocommerce'),
                'loadingPreview' => __('Loading preview...', 'multi-store-sync-for-woocommerce'),
                'fullSync' => __('Full Sync', 'multi-store-sync-for-woocommerce'),
                'priceStock' => __('Price & Stock', 'multi-store-sync-for-woocommerce'),
                'stockOnly' => __('Stock Only', 'multi-store-sync-for-woocommerce'),
                'previewChanges' => __('Preview Changes', 'multi-store-sync-for-woocommerce'),
                'errorOccurred' => __('An error occurred. Please try again.', 'multi-store-sync-for-woocommerce'),
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
