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
                    <tr>
                        <th scope="row">
                            <label for="edit_store_url"><?php _e('Store URL', 'wc-multi-store-sync'); ?></label>
                        </th>
                        <td>
                            <input type="url" name="store_url" id="edit_store_url" class="regular-text"
                                   value="<?php echo esc_attr($editing_store_url); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="edit_consumer_key"><?php _e('Consumer Key', 'wc-multi-store-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="consumer_key" id="edit_consumer_key" class="regular-text"
                                   placeholder="<?php echo !empty($editing_store['consumer_key'] ?? '') ? esc_attr__('Already set — leave blank to keep', 'wc-multi-store-sync') : ''; ?>"
                                   autocomplete="off">
                            <button type="button" class="button button-small wc-mss-toggle-password">
                                <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                            </button>
                            <p class="description"><?php _e('Leave blank to keep the saved key, or enter a new one to replace it.', 'wc-multi-store-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="edit_consumer_secret"><?php _e('Consumer Secret', 'wc-multi-store-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="consumer_secret" id="edit_consumer_secret" class="regular-text"
                                   placeholder="<?php echo !empty($editing_store['consumer_secret'] ?? '') ? esc_attr__('Already set — leave blank to keep', 'wc-multi-store-sync') : ''; ?>"
                                   autocomplete="off">
                            <button type="button" class="button button-small wc-mss-toggle-password">
                                <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                            </button>
                            <p class="description"><?php _e('Leave blank to keep the saved secret, or enter a new one to replace it.', 'wc-multi-store-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="edit_status"><?php _e('Status', 'wc-multi-store-sync'); ?></label>
                        </th>
                        <td>
                            <select name="status" id="edit_status">
                                <option value="active" <?php selected($editing_store['status'] ?? '', 'active'); ?>><?php _e('Active', 'wc-multi-store-sync'); ?></option>
                                <option value="inactive" <?php selected($editing_store['status'] ?? '', 'inactive'); ?>><?php _e('Inactive', 'wc-multi-store-sync'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" colspan="2">
                            <h3 style="margin:0;"><?php _e('Image Upload Credentials (Application Password)', 'wc-multi-store-sync'); ?></h3>
                            <p class="description" style="font-weight:normal;"><?php _e('Required for image sync when Image Proxy is enabled. WooCommerce API keys cannot upload media — use a WordPress Application Password instead. In the remote store: Users → Edit Admin → Application Passwords → create new.', 'wc-multi-store-sync'); ?></p>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="edit_wp_username"><?php _e('WordPress Username', 'wc-multi-store-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="wp_username" id="edit_wp_username" class="regular-text"
                                   value="<?php echo esc_attr($editing_store['wp_username'] ?? ''); ?>"
                                   autocomplete="off">
                            <p class="description"><?php _e('Admin username on the remote store', 'wc-multi-store-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="edit_wp_app_password"><?php _e('Application Password', 'wc-multi-store-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="wp_app_password" id="edit_wp_app_password" class="regular-text"
                                   placeholder="<?php echo !empty($editing_store['wp_app_password'] ?? '') ? esc_attr__('Already set — leave blank to keep', 'wc-multi-store-sync') : ''; ?>"
                                   autocomplete="off">
                            <button type="button" class="button button-small wc-mss-toggle-password">
                                <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                            </button>
                            <button type="button" class="button button-small" id="wc-mss-test-app-password-edit"
                                    data-store-url="<?php echo esc_attr($editing_store_url); ?>"
                                    data-username-field="edit_wp_username"
                                    data-password-field="edit_wp_app_password"
                                    data-saved-username="<?php echo esc_attr($editing_store['wp_username'] ?? ''); ?>"
                                    data-has-saved-password="<?php echo !empty($editing_store['wp_app_password'] ?? '') ? '1' : '0'; ?>">
                                <?php _e('Test App Password', 'wc-multi-store-sync'); ?>
                            </button>
                            <span id="wc-mss-test-app-password-edit-result" style="margin-left: 8px;"></span>
                            <p class="description"><?php _e('The generated Application Password (spaces are OK)', 'wc-multi-store-sync'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Cache Purge -->
                <div class="wc-mss-exclusions-section" style="margin-bottom:16px;">
                    <h4><?php _e('Cache Purge', 'wc-multi-store-sync'); ?></h4>
                    <p class="description">
                        <?php _e('After each successful sync, the plugin sends a request to this URL to clear the page cache on the remote store. Leave blank to disable.', 'wc-multi-store-sync'); ?>
                        <br>
                        <?php _e('Placeholders: <code>{product_id}</code>, <code>{sku}</code>, <code>{remote_id}</code>', 'wc-multi-store-sync'); ?>
                        <br>
                        <?php _e('Examples: WP Rocket — <code>https://remote.com/?action=rocket_clear_cache&nonce=KEY&post_id={remote_id}</code> &nbsp;|&nbsp; LiteSpeed — <code>https://remote.com/wp-json/litespeed/v1/purge</code> (POST)', 'wc-multi-store-sync'); ?>
                    </p>
                    <table class="form-table" style="margin-top:8px;">
                        <tr>
                            <th scope="row" style="width:140px;"><label for="edit_cache_purge_url"><?php _e('Purge URL', 'wc-multi-store-sync'); ?></label></th>
                            <td>
                                <input type="url" name="cache_purge_url" id="edit_cache_purge_url" class="large-text"
                                       value="<?php echo esc_attr($editing_store['cache_purge_url'] ?? ''); ?>"
                                       placeholder="https://remote-store.com/cache-purge?secret=KEY&url={product_id}">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_cache_purge_method"><?php _e('Method', 'wc-multi-store-sync'); ?></label></th>
                            <td>
                                <select name="cache_purge_method" id="edit_cache_purge_method">
                                    <option value="GET"  <?php selected($editing_store['cache_purge_method'] ?? 'GET', 'GET'); ?>>GET</option>
                                    <option value="POST" <?php selected($editing_store['cache_purge_method'] ?? 'GET', 'POST'); ?>>POST</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Sync Preview Box -->
                <div class="wc-mss-sync-preview">
                    <strong><?php _e('Sync Preview:', 'wc-multi-store-sync'); ?></strong>
                    <span id="edit_sync_preview">
                        <?php
                        $edit_exc_cats = $editing_store['exclude_categories'] ?? [];
                        $edit_exc_tags = $editing_store['exclude_tags'] ?? [];
                        $edit_sync_count = WC_Multi_Store_Settings::get_sync_product_count($edit_exc_cats, $edit_exc_tags);
                        printf(
                            __('<strong style="color:#2271b1; font-size: 16px;">%d</strong> of %d products will be synced', 'wc-multi-store-sync'),
                            $edit_sync_count,
                            $total_products
                        );
                        ?>
                    </span>
                </div>

                <div class="wc-mss-exclusions-section wc-mss-exclusions-first">
                    <h4><?php _e('Exclude Categories', 'wc-multi-store-sync'); ?></h4>
                    <p class="description"><?php _e('Products in selected categories will NOT be synced to this store.', 'wc-multi-store-sync'); ?></p>

                    <div class="wc-mss-select-actions">
                        <button type="button" class="button button-small" onclick="wcMssSelectAll('edit_exclude_categories')"><?php _e('Select All', 'wc-multi-store-sync'); ?></button>
                        <button type="button" class="button button-small" onclick="wcMssDeselectAll('edit_exclude_categories')"><?php _e('Deselect All', 'wc-multi-store-sync'); ?></button>
                        <span class="wc-mss-selected-count" data-target="edit_exclude_categories">
                            <?php echo count($editing_store['exclude_categories'] ?? []); ?> selected
                        </span>
                    </div>

                    <div class="wc-mss-checkbox-grid" id="edit_exclude_categories">
                        <?php
                        $excluded_cats = $editing_store['exclude_categories'] ?? [];
                        foreach ($categories as $category):
                            $is_checked = in_array($category->term_id, $excluded_cats);
                        ?>
                        <label class="wc-mss-checkbox-item <?php echo $is_checked ? 'selected' : ''; ?>">
                            <input type="checkbox" name="exclude_categories[]" value="<?php echo esc_attr($category->term_id); ?>"
                                   <?php checked($is_checked); ?>>
                            <span><?php echo esc_html($category->name); ?></span>
                            <span class="count">(<?php echo esc_html($category->count); ?>)</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="wc-mss-exclusions-section">
                    <h4><?php _e('Exclude Tags', 'wc-multi-store-sync'); ?></h4>
                    <p class="description"><?php _e('Products with selected tags will NOT be synced to this store.', 'wc-multi-store-sync'); ?></p>

                    <div class="wc-mss-select-actions">
                        <button type="button" class="button button-small" onclick="wcMssSelectAll('edit_exclude_tags')"><?php _e('Select All', 'wc-multi-store-sync'); ?></button>
                        <button type="button" class="button button-small" onclick="wcMssDeselectAll('edit_exclude_tags')"><?php _e('Deselect All', 'wc-multi-store-sync'); ?></button>
                        <span class="wc-mss-selected-count" data-target="edit_exclude_tags">
                            <?php echo count($editing_store['exclude_tags'] ?? []); ?> selected
                        </span>
                    </div>

                    <div class="wc-mss-checkbox-grid" id="edit_exclude_tags">
                        <?php
                        $excluded_tags = $editing_store['exclude_tags'] ?? [];
                        foreach ($tags as $tag):
                            $is_checked = in_array($tag->term_id, $excluded_tags);
                        ?>
                        <label class="wc-mss-checkbox-item <?php echo $is_checked ? 'selected' : ''; ?>">
                            <input type="checkbox" name="exclude_tags[]" value="<?php echo esc_attr($tag->term_id); ?>"
                                   <?php checked($is_checked); ?>>
                            <span><?php echo esc_html($tag->name); ?></span>
                            <span class="count">(<?php echo esc_html($tag->count); ?>)</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
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
                <tr>
                    <th scope="row">
                        <label for="store_url"><?php _e('Store URL', 'wc-multi-store-sync'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="url" name="store_url" id="store_url" class="regular-text" required
                               placeholder="https://example.com">
                        <p class="description"><?php _e('Enter the full URL of your WooCommerce store', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consumer_key"><?php _e('Consumer Key', 'wc-multi-store-sync'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="password" name="consumer_key" id="consumer_key" class="regular-text" required autocomplete="off">
                        <button type="button" class="button button-small wc-mss-toggle-password">
                            <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                        </button>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consumer_secret"><?php _e('Consumer Secret', 'wc-multi-store-sync'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="password" name="consumer_secret" id="consumer_secret" class="regular-text" required autocomplete="off">
                        <button type="button" class="button button-small wc-mss-toggle-password">
                            <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                        </button>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="status"><?php _e('Status', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="status" id="status">
                            <option value="active"><?php _e('Active', 'wc-multi-store-sync'); ?></option>
                            <option value="inactive"><?php _e('Inactive', 'wc-multi-store-sync'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row" colspan="2">
                        <h3 style="margin:0;"><?php _e('Image Upload Credentials (Application Password)', 'wc-multi-store-sync'); ?></h3>
                        <p class="description" style="font-weight:normal;"><?php _e('Required for image sync when Image Proxy is enabled. WooCommerce API keys cannot upload media — use a WordPress Application Password instead. In the remote store: Users → Edit Admin → Application Passwords → create new.', 'wc-multi-store-sync'); ?></p>
                    </th>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_username"><?php _e('WordPress Username', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="wp_username" id="wp_username" class="regular-text" autocomplete="off">
                        <p class="description"><?php _e('Admin username on the remote store', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_app_password"><?php _e('Application Password', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="password" name="wp_app_password" id="wp_app_password" class="regular-text" autocomplete="off">
                        <button type="button" class="button button-small wc-mss-toggle-password">
                            <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                        </button>
                        <button type="button" class="button button-small" id="wc-mss-test-app-password"
                                data-store-url-field="store_url"
                                data-username-field="wp_username"
                                data-password-field="wp_app_password">
                            <?php _e('Test App Password', 'wc-multi-store-sync'); ?>
                        </button>
                        <span id="wc-mss-test-app-password-result" style="margin-left: 8px;"></span>
                        <p class="description"><?php _e('The generated Application Password (spaces are OK)', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
            </table>

            <!-- Cache Purge -->
            <div class="wc-mss-exclusions-section" style="margin-bottom:16px;">
                <h4><?php _e('Cache Purge', 'wc-multi-store-sync'); ?></h4>
                <p class="description">
                    <?php _e('After each successful sync, the plugin sends a request to this URL to clear the page cache on the remote store. Leave blank to disable.', 'wc-multi-store-sync'); ?>
                    <br>
                    <?php _e('Placeholders: <code>{product_id}</code>, <code>{sku}</code>, <code>{remote_id}</code>', 'wc-multi-store-sync'); ?>
                    <br>
                    <?php _e('Examples: WP Rocket — <code>https://remote.com/?action=rocket_clear_cache&nonce=KEY&post_id={remote_id}</code> &nbsp;|&nbsp; LiteSpeed — <code>https://remote.com/wp-json/litespeed/v1/purge</code> (POST)', 'wc-multi-store-sync'); ?>
                </p>
                <table class="form-table" style="margin-top:8px;">
                    <tr>
                        <th scope="row" style="width:140px;"><label for="add_cache_purge_url"><?php _e('Purge URL', 'wc-multi-store-sync'); ?></label></th>
                        <td>
                            <input type="url" name="cache_purge_url" id="add_cache_purge_url" class="large-text"
                                   placeholder="https://remote-store.com/cache-purge?secret=KEY&url={product_id}">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="add_cache_purge_method"><?php _e('Method', 'wc-multi-store-sync'); ?></label></th>
                        <td>
                            <select name="cache_purge_method" id="add_cache_purge_method">
                                <option value="GET">GET</option>
                                <option value="POST">POST</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Sync Preview Box -->
            <div class="wc-mss-sync-preview">
                <strong><?php _e('Sync Preview:', 'wc-multi-store-sync'); ?></strong>
                <span id="add_sync_preview">
                    <?php
                    printf(
                        __('<strong style="color:#2271b1; font-size: 16px;">%d</strong> of %d products will be synced', 'wc-multi-store-sync'),
                        $total_products,
                        $total_products
                    );
                    ?>
                </span>
            </div>

            <div class="wc-mss-exclusions-section wc-mss-exclusions-first">
                <h4><?php _e('Exclude Categories', 'wc-multi-store-sync'); ?></h4>
                <p class="description"><?php _e('Products in selected categories will NOT be synced to this store.', 'wc-multi-store-sync'); ?></p>

                <div class="wc-mss-select-actions">
                    <button type="button" class="button button-small" onclick="wcMssSelectAll('add_exclude_categories')"><?php _e('Select All', 'wc-multi-store-sync'); ?></button>
                    <button type="button" class="button button-small" onclick="wcMssDeselectAll('add_exclude_categories')"><?php _e('Deselect All', 'wc-multi-store-sync'); ?></button>
                    <span class="wc-mss-selected-count" data-target="add_exclude_categories">0 selected</span>
                </div>

                <div class="wc-mss-checkbox-grid" id="add_exclude_categories">
                    <?php foreach ($categories as $category): ?>
                    <label class="wc-mss-checkbox-item">
                        <input type="checkbox" name="exclude_categories[]" value="<?php echo esc_attr($category->term_id); ?>">
                        <span><?php echo esc_html($category->name); ?></span>
                        <span class="count">(<?php echo esc_html($category->count); ?>)</span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wc-mss-exclusions-section">
                <h4><?php _e('Exclude Tags', 'wc-multi-store-sync'); ?></h4>
                <p class="description"><?php _e('Products with selected tags will NOT be synced to this store.', 'wc-multi-store-sync'); ?></p>

                <div class="wc-mss-select-actions">
                    <button type="button" class="button button-small" onclick="wcMssSelectAll('add_exclude_tags')"><?php _e('Select All', 'wc-multi-store-sync'); ?></button>
                    <button type="button" class="button button-small" onclick="wcMssDeselectAll('add_exclude_tags')"><?php _e('Deselect All', 'wc-multi-store-sync'); ?></button>
                    <span class="wc-mss-selected-count" data-target="add_exclude_tags">0 selected</span>
                </div>

                <div class="wc-mss-checkbox-grid" id="add_exclude_tags">
                    <?php foreach ($tags as $tag): ?>
                    <label class="wc-mss-checkbox-item">
                        <input type="checkbox" name="exclude_tags[]" value="<?php echo esc_attr($tag->term_id); ?>">
                        <span><?php echo esc_html($tag->name); ?></span>
                        <span class="count">(<?php echo esc_html($tag->count); ?>)</span>
                    </label>
                    <?php endforeach; ?>
                </div>
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
