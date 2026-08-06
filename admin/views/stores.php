<?php
/**
 * Stores Management View
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get categories and tags once for reuse
$categories = get_terms([
    'taxonomy' => 'product_cat',
    'hide_empty' => false,
    'orderby' => 'name',
]);

$tags = get_terms([
    'taxonomy' => 'product_tag',
    'hide_empty' => false,
    'orderby' => 'name',
]);

// Get total product count
$total_products = wp_count_posts('product')->publish;

// Check if editing a store
$editing_store_url = isset($_GET['edit_store']) ? sanitize_text_field($_GET['edit_store']) : '';
$editing_store = $editing_store_url && isset($stores[$editing_store_url]) ? $stores[$editing_store_url] : null;
?>

<div class="wrap wc-mss-stores">
    <h1 class="wp-heading-inline"><?php _e('Manage Stores', 'wc-multi-store-sync'); ?></h1>
    <?php if (!$editing_store && !isset($_GET['add_new'])): ?>
    <a href="<?php echo esc_url(add_query_arg('add_new', '1')); ?>" class="page-title-action"><?php _e('Add New Store', 'wc-multi-store-sync'); ?></a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <?php settings_errors('wc_mss_stores'); ?>

    <?php if ($editing_store): ?>
    <!-- Edit Store Form -->
    <div class="wc-mss-store-form">
        <div class="wc-mss-store-form-header">
            <h2><?php printf(__('Edit Store: %s', 'wc-multi-store-sync'), esc_html($editing_store_url)); ?></h2>
            <a href="<?php echo esc_url(remove_query_arg('edit_store')); ?>" class="button"><?php _e('Cancel', 'wc-multi-store-sync'); ?></a>
        </div>
        <div class="wc-mss-store-form-body">
            <form method="post" action="">
                <?php wp_nonce_field('wc_mss_update_store'); ?>
                <input type="hidden" name="original_store_url" value="<?php echo esc_attr($editing_store_url); ?>">

                <table class="form-table">
                    <?php
                    WC_Multi_Store_Store_Form_Renderer::render_core_fields('edit', $editing_store, $editing_store_url);
                    WC_Multi_Store_Store_Form_Renderer::render_app_password_fields('edit', $editing_store, $editing_store_url);
                    ?>
                </table>

                <?php WC_Multi_Store_Store_Form_Renderer::render_cache_purge_fields('edit', $editing_store); ?>

                <?php
                $edit_exc_cats = $editing_store['exclude_categories'] ?? [];
                $edit_exc_tags = $editing_store['exclude_tags'] ?? [];
                $edit_sync_count = WC_Multi_Store_Settings::get_sync_product_count($edit_exc_cats, $edit_exc_tags);
                WC_Multi_Store_Store_Form_Renderer::render_sync_preview('edit_', $edit_sync_count, $total_products);
                ?>

                <div class="wc-mss-exclusions-section wc-mss-exclusions-first">
                    <?php
                    WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
                        'edit_',
                        'categories',
                        $categories,
                        $edit_exc_cats,
                        __('Exclude Categories', 'wc-multi-store-sync'),
                        __('Products in selected categories will NOT be synced to this store.', 'wc-multi-store-sync')
                    );
                    ?>
                </div>

                <div class="wc-mss-exclusions-section">
                    <?php
                    WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
                        'edit_',
                        'tags',
                        $tags,
                        $edit_exc_tags,
                        __('Exclude Tags', 'wc-multi-store-sync'),
                        __('Products with selected tags will NOT be synced to this store.', 'wc-multi-store-sync')
                    );
                    ?>
                </div>

                <p class="submit">
                    <button type="submit" name="wc_mss_update_store" class="button button-primary">
                        <?php _e('Save Changes', 'wc-multi-store-sync'); ?>
                    </button>
                    <a href="<?php echo esc_url(remove_query_arg('edit_store')); ?>" class="button">
                        <?php _e('Cancel', 'wc-multi-store-sync'); ?>
                    </a>
                </p>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Show Add New Store form only when explicitly requested via URL param
    $show_add_form = isset($_GET['add_new']) && !$editing_store;
    ?>

    <?php if ($show_add_form): ?>
    <!-- Add New Store Form -->
    <div class="wc-mss-store-form">
        <div class="wc-mss-store-form-header">
            <h2><?php _e('Add New Store', 'wc-multi-store-sync'); ?></h2>
            <a href="<?php echo esc_url(remove_query_arg('add_new')); ?>" class="button"><?php _e('Cancel', 'wc-multi-store-sync'); ?></a>
        </div>
        <div class="wc-mss-store-form-body">
        <form method="post" action="">
            <?php wp_nonce_field('wc_mss_add_store'); ?>

            <table class="form-table">
                <?php
                WC_Multi_Store_Store_Form_Renderer::render_core_fields('add', [], '');
                WC_Multi_Store_Store_Form_Renderer::render_app_password_fields('add', [], '');
                ?>
            </table>

            <?php WC_Multi_Store_Store_Form_Renderer::render_cache_purge_fields('add', []); ?>

            <?php WC_Multi_Store_Store_Form_Renderer::render_sync_preview('add_', $total_products, $total_products); ?>

            <div class="wc-mss-exclusions-section wc-mss-exclusions-first">
                <?php
                WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
                    'add_',
                    'categories',
                    $categories,
                    [],
                    __('Exclude Categories', 'wc-multi-store-sync'),
                    __('Products in selected categories will NOT be synced to this store.', 'wc-multi-store-sync')
                );
                ?>
            </div>

            <div class="wc-mss-exclusions-section">
                <?php
                WC_Multi_Store_Store_Form_Renderer::render_exclusion_grid(
                    'add_',
                    'tags',
                    $tags,
                    [],
                    __('Exclude Tags', 'wc-multi-store-sync'),
                    __('Products with selected tags will NOT be synced to this store.', 'wc-multi-store-sync')
                );
                ?>
            </div>

            <p class="submit">
                <button type="submit" name="wc_mss_add_store" class="button button-primary">
                    <?php _e('Add Store', 'wc-multi-store-sync'); ?>
                </button>
                <button type="button" id="wc-mss-test-connection" class="button">
                    <?php _e('Test Connection', 'wc-multi-store-sync'); ?>
                </button>
                <a href="<?php echo esc_url(remove_query_arg('add_new')); ?>" class="button">
                    <?php _e('Cancel', 'wc-multi-store-sync'); ?>
                </a>
            </p>
        </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Existing Stores List -->
    <?php if (!$editing_store && !$show_add_form): ?>
    <div class="wc-mss-card">
        <h2><?php _e('Configured Stores', 'wc-multi-store-sync'); ?></h2>
        <?php if (!empty($stores)): ?>
        <p>
            <button type="button" id="wc-mss-run-health-check" class="button">
                <?php _e('Run Health Check', 'wc-multi-store-sync'); ?>
            </button>
            <button type="button" id="wc-mss-export-exclusions" class="button" style="margin-left: 10px;">
                <?php _e('Export Exclusions', 'wc-multi-store-sync'); ?>
            </button>
            <span id="wc-mss-health-check-result" style="margin-left: 10px;"></span>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Store URL', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Status', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Health', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Exclusions', 'wc-multi-store-sync'); ?></th>
                    <th><?php _e('Added Date', 'wc-multi-store-sync'); ?></th>
                    <th style="width: 150px;"><?php _e('Actions', 'wc-multi-store-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stores as $url => $store): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($url); ?></strong>
                    </td>
                    <td>
                        <?php
                        $status = isset($store['status']) ? $store['status'] : 'inactive';
                        if ($status === 'active') {
                            echo '<span class="status-active"><span class="wc-mss-status-dot active"></span>Active</span>';
                        } else {
                            echo '<span class="status-inactive"><span class="wc-mss-status-dot inactive"></span>Inactive</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if (isset($store['health_status'])) {
                            $health = $store['health_status'];
                            if ($health['healthy']) {
                                echo '<span class="status-active"><span class="wc-mss-status-dot active"></span>' . esc_html($health['message']) . '</span>';
                            } else {
                                echo '<span class="status-inactive"><span class="wc-mss-status-dot inactive"></span>' . esc_html($health['message']) . '</span>';
                            }
                            echo '<br><small style="color: #666;">Last: ' . esc_html($health['checked_at']) . '</small>';
                        } else {
                            echo '<span style="color: #999;">Not checked</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        $exc_cats = $store['exclude_categories'] ?? [];
                        $exc_tags = $store['exclude_tags'] ?? [];
                        $sync_count = WC_Multi_Store_Settings::get_sync_product_count($exc_cats, $exc_tags);
                        $excluded_count = $total_products - $sync_count;

                        if (!empty($exc_cats) || !empty($exc_tags)) {
                            $exclusions = [];
                            if (!empty($exc_cats)) {
                                $exclusions[] = sprintf(_n('%d cat', '%d cats', count($exc_cats), 'wc-multi-store-sync'), count($exc_cats));
                            }
                            if (!empty($exc_tags)) {
                                $exclusions[] = sprintf(_n('%d tag', '%d tags', count($exc_tags), 'wc-multi-store-sync'), count($exc_tags));
                            }
                            echo '<span style="color:#b32d2e;">' . esc_html(implode(', ', $exclusions)) . '</span>';
                            echo '<br><strong style="color:#2271b1;">' . sprintf(__('%d / %d products', 'wc-multi-store-sync'), $sync_count, $total_products) . '</strong>';
                        } else {
                            echo '<span style="color:#999;">None</span>';
                            echo '<br><strong style="color:#2271b1;">' . sprintf(__('%d products', 'wc-multi-store-sync'), $total_products) . '</strong>';
                        }
                        ?>
                    </td>
                    <td><?php echo isset($store['added_date']) ? esc_html(date('M j, Y', strtotime($store['added_date']))) : '-'; ?></td>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg('edit_store', urlencode($url))); ?>" class="button button-small">
                            <?php _e('Edit', 'wc-multi-store-sync'); ?>
                        </a>
                        <button type="button" class="button button-small wc-mss-check-store-btn"
                                data-store-url="<?php echo esc_attr($url); ?>">
                            <?php _e('Check', 'wc-multi-store-sync'); ?>
                        </button>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('wc_mss_delete_store'); ?>
                            <input type="hidden" name="store_url" value="<?php echo esc_attr($url); ?>">
                            <button type="submit" name="wc_mss_delete_store" class="button button-small" style="color: #b32d2e;"
                                    onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this store?', 'wc-multi-store-sync'); ?>')">
                                <?php _e('Delete', 'wc-multi-store-sync'); ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <!-- Empty State -->
        <div class="wc-mss-empty-state">
            <p><?php _e('No stores configured yet.', 'wc-multi-store-sync'); ?></p>
            <a href="<?php echo esc_url(add_query_arg('add_new', '1')); ?>" class="button button-primary">
                <?php _e('Add Your First Store', 'wc-multi-store-sync'); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="wc-mss-card">
        <h3><?php _e('How to get WooCommerce API Keys', 'wc-multi-store-sync'); ?></h3>
        <ol>
            <li><?php _e('Go to your remote WooCommerce store admin panel', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('Navigate to WooCommerce > Settings > Advanced > REST API', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('Click "Add key"', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('Set Description, User, and Permissions to "Read/Write"', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('Click "Generate API key"', 'wc-multi-store-sync'); ?></li>
            <li><?php _e('Copy the Consumer Key and Consumer Secret', 'wc-multi-store-sync'); ?></li>
        </ol>
    </div>
</div>

<?php
// Prepare exclusions data for export (with names, not just IDs)
$exclusions_export = [
    'exported_at' => current_time('mysql'),
    'source_site' => get_site_url(),
    'source_site_name' => get_bloginfo('name'),
    'stores' => [],
];

foreach ($stores as $store_url => $store) {
    $store_exclusions = [
        'store_url' => $store_url,
        'status' => $store['status'] ?? 'inactive',
        'excluded_categories' => [],
        'excluded_tags' => [],
    ];

    // Get category names
    if (!empty($store['exclude_categories'])) {
        foreach ($store['exclude_categories'] as $cat_id) {
            $term = get_term($cat_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $store_exclusions['excluded_categories'][] = [
                    'id' => $cat_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
        }
    }

    // Get tag names
    if (!empty($store['exclude_tags'])) {
        foreach ($store['exclude_tags'] as $tag_id) {
            $term = get_term($tag_id, 'product_tag');
            if ($term && !is_wp_error($term)) {
                $store_exclusions['excluded_tags'][] = [
                    'id' => $tag_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
        }
    }

    $exclusions_export['stores'][] = $store_exclusions;
}
?>

<script>
(function() {
    var exportBtn = document.getElementById('wc-mss-export-exclusions');
    if (!exportBtn) return;

    var exclusionsData = <?php echo wp_json_encode($exclusions_export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?>;

    exportBtn.addEventListener('click', function() {
        // Create formatted JSON string
        var jsonStr = JSON.stringify(exclusionsData, null, 2);

        // Create blob and download
        var blob = new Blob([jsonStr], { type: 'application/json' });
        var url = URL.createObjectURL(blob);

        var a = document.createElement('a');
        a.href = url;
        a.download = 'store-exclusions-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
})();
</script>
