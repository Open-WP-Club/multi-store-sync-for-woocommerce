<?php
/**
 * Shared markup for the Add Store / Edit Store forms in admin/views/stores.php.
 *
 * Both forms post the same field names (so the same handler in
 * WC_Multi_Store_Settings_Integration processes either one) but differ in
 * id prefix (JS in admin-scripts.js relies on the 'edit_'/'add_' prefix to
 * find the matching sync-preview/exclusion containers), in which fields are
 * required, and in whether a saved value already exists to keep-or-replace.
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Store Form Renderer Class
 */
class WC_Multi_Store_Store_Form_Renderer {

    /**
     * Render the Store URL / Consumer Key / Consumer Secret / Status rows.
     *
     * @param string $mode      'add' or 'edit'.
     * @param array  $store     Existing store config (empty array for 'add').
     * @param string $store_url Store URL (empty for 'add').
     */
    public static function render_core_fields(string $mode, array $store, string $store_url): void {
        $is_edit = $mode === 'edit';
        $prefix = $is_edit ? 'edit_' : '';
        ?>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr($prefix); ?>store_url">
                    <?php _e('Store URL', 'wc-multi-store-sync'); ?>
                    <?php if (!$is_edit): ?><span class="required">*</span><?php endif; ?>
                </label>
            </th>
            <td>
                <input type="url" name="store_url" id="<?php echo esc_attr($prefix); ?>store_url" class="regular-text" required
                       value="<?php echo esc_attr($store_url); ?>"
                       <?php if (!$is_edit): ?>placeholder="https://example.com"<?php endif; ?>>
                <?php if (!$is_edit): ?>
                <p class="description"><?php _e('Enter the full URL of your WooCommerce store', 'wc-multi-store-sync'); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr($prefix); ?>consumer_key">
                    <?php _e('Consumer Key', 'wc-multi-store-sync'); ?>
                    <?php if (!$is_edit): ?><span class="required">*</span><?php endif; ?>
                </label>
            </th>
            <td>
                <input type="password" name="consumer_key" id="<?php echo esc_attr($prefix); ?>consumer_key" class="regular-text"
                       autocomplete="off"
                       <?php echo !$is_edit ? 'required' : ''; ?>
                       <?php if ($is_edit): ?>placeholder="<?php echo !empty($store['consumer_key'] ?? '') ? esc_attr__('Already set — leave blank to keep', 'wc-multi-store-sync') : ''; ?>"<?php endif; ?>>
                <button type="button" class="button button-small wc-mss-toggle-password">
                    <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                </button>
                <?php if ($is_edit): ?>
                <p class="description"><?php _e('Leave blank to keep the saved key, or enter a new one to replace it.', 'wc-multi-store-sync'); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr($prefix); ?>consumer_secret">
                    <?php _e('Consumer Secret', 'wc-multi-store-sync'); ?>
                    <?php if (!$is_edit): ?><span class="required">*</span><?php endif; ?>
                </label>
            </th>
            <td>
                <input type="password" name="consumer_secret" id="<?php echo esc_attr($prefix); ?>consumer_secret" class="regular-text"
                       autocomplete="off"
                       <?php echo !$is_edit ? 'required' : ''; ?>
                       <?php if ($is_edit): ?>placeholder="<?php echo !empty($store['consumer_secret'] ?? '') ? esc_attr__('Already set — leave blank to keep', 'wc-multi-store-sync') : ''; ?>"<?php endif; ?>>
                <button type="button" class="button button-small wc-mss-toggle-password">
                    <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                </button>
                <?php if ($is_edit): ?>
                <p class="description"><?php _e('Leave blank to keep the saved secret, or enter a new one to replace it.', 'wc-multi-store-sync'); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr($prefix); ?>status"><?php _e('Status', 'wc-multi-store-sync'); ?></label>
            </th>
            <td>
                <select name="status" id="<?php echo esc_attr($prefix); ?>status">
                    <option value="active" <?php echo $is_edit ? selected($store['status'] ?? '', 'active', false) : ''; ?>><?php _e('Active', 'wc-multi-store-sync'); ?></option>
                    <option value="inactive" <?php echo $is_edit ? selected($store['status'] ?? '', 'inactive', false) : ''; ?>><?php _e('Inactive', 'wc-multi-store-sync'); ?></option>
                </select>
            </td>
        </tr>
        <?php
    }

    /**
     * Render the "Image Upload Credentials (Application Password)" block.
     *
     * @param string $mode      'add' or 'edit'.
     * @param array  $store     Existing store config (empty array for 'add').
     * @param string $store_url Store URL (empty for 'add').
     */
    public static function render_app_password_fields(string $mode, array $store, string $store_url): void {
        $is_edit = $mode === 'edit';
        $prefix = $is_edit ? 'edit_' : '';
        ?>
        <tr>
            <th scope="row" colspan="2">
                <h3 style="margin:0;"><?php _e('Image Upload Credentials (Application Password)', 'wc-multi-store-sync'); ?></h3>
                <p class="description" style="font-weight:normal;"><?php _e('Required for image sync when Image Proxy is enabled. WooCommerce API keys cannot upload media — use a WordPress Application Password instead. In the remote store: Users → Edit Admin → Application Passwords → create new.', 'wc-multi-store-sync'); ?></p>
            </th>
        </tr>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr($prefix); ?>wp_username"><?php _e('WordPress Username', 'wc-multi-store-sync'); ?></label>
            </th>
            <td>
                <input type="text" name="wp_username" id="<?php echo esc_attr($prefix); ?>wp_username" class="regular-text"
                       autocomplete="off"
                       <?php if ($is_edit): ?>value="<?php echo esc_attr($store['wp_username'] ?? ''); ?>"<?php endif; ?>>
                <p class="description"><?php _e('Admin username on the remote store', 'wc-multi-store-sync'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr($prefix); ?>wp_app_password"><?php _e('Application Password', 'wc-multi-store-sync'); ?></label>
            </th>
            <td>
                <input type="password" name="wp_app_password" id="<?php echo esc_attr($prefix); ?>wp_app_password" class="regular-text"
                       autocomplete="off"
                       <?php if ($is_edit): ?>placeholder="<?php echo !empty($store['wp_app_password'] ?? '') ? esc_attr__('Already set — leave blank to keep', 'wc-multi-store-sync') : ''; ?>"<?php endif; ?>>
                <button type="button" class="button button-small wc-mss-toggle-password">
                    <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                </button>
                <?php if ($is_edit): ?>
                <button type="button" class="button button-small" id="wc-mss-test-app-password-edit"
                        data-store-url="<?php echo esc_attr($store_url); ?>"
                        data-username-field="edit_wp_username"
                        data-password-field="edit_wp_app_password"
                        data-saved-username="<?php echo esc_attr($store['wp_username'] ?? ''); ?>"
                        data-has-saved-password="<?php echo !empty($store['wp_app_password'] ?? '') ? '1' : '0'; ?>">
                    <?php _e('Test App Password', 'wc-multi-store-sync'); ?>
                </button>
                <span id="wc-mss-test-app-password-edit-result" style="margin-left: 8px;"></span>
                <?php else: ?>
                <button type="button" class="button button-small" id="wc-mss-test-app-password"
                        data-store-url-field="store_url"
                        data-username-field="wp_username"
                        data-password-field="wp_app_password">
                    <?php _e('Test App Password', 'wc-multi-store-sync'); ?>
                </button>
                <span id="wc-mss-test-app-password-result" style="margin-left: 8px;"></span>
                <?php endif; ?>
                <p class="description"><?php _e('The generated Application Password (spaces are OK)', 'wc-multi-store-sync'); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Render the Cache Purge block.
     *
     * @param string $mode  'add' or 'edit'.
     * @param array  $store Existing store config (empty array for 'add').
     */
    public static function render_cache_purge_fields(string $mode, array $store): void {
        $is_edit = $mode === 'edit';
        $prefix = $is_edit ? 'edit_' : 'add_';
        $purge_url = $is_edit ? ($store['cache_purge_url'] ?? '') : '';
        $purge_method = $is_edit ? ($store['cache_purge_method'] ?? 'GET') : 'GET';
        ?>
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
                    <th scope="row" style="width:140px;"><label for="<?php echo esc_attr($prefix); ?>cache_purge_url"><?php _e('Purge URL', 'wc-multi-store-sync'); ?></label></th>
                    <td>
                        <input type="url" name="cache_purge_url" id="<?php echo esc_attr($prefix); ?>cache_purge_url" class="large-text"
                               value="<?php echo esc_attr($purge_url); ?>"
                               placeholder="https://remote-store.com/cache-purge?secret=KEY&url={product_id}">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($prefix); ?>cache_purge_method"><?php _e('Method', 'wc-multi-store-sync'); ?></label></th>
                    <td>
                        <select name="cache_purge_method" id="<?php echo esc_attr($prefix); ?>cache_purge_method">
                            <option value="GET"  <?php echo selected($purge_method, 'GET', false); ?>>GET</option>
                            <option value="POST" <?php echo selected($purge_method, 'POST', false); ?>>POST</option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render the Sync Preview box.
     *
     * @param string $prefix        'edit_' or 'add_' (matches the span id JS looks for).
     * @param int    $synced_count  Products that will be synced.
     * @param int    $total_products Total published products.
     */
    public static function render_sync_preview(string $prefix, int $synced_count, int $total_products): void {
        ?>
        <div class="wc-mss-sync-preview">
            <strong><?php _e('Sync Preview:', 'wc-multi-store-sync'); ?></strong>
            <span id="<?php echo esc_attr($prefix); ?>sync_preview">
                <?php
                printf(
                    __('<strong style="color:#2271b1; font-size: 16px;">%d</strong> of %d products will be synced', 'wc-multi-store-sync'),
                    $synced_count,
                    $total_products
                );
                ?>
            </span>
        </div>
        <?php
    }

    /**
     * Render an exclusion checkbox grid (used for both categories and tags).
     *
     * @param string        $prefix      'edit_' or 'add_' (matches the container id JS looks for).
     * @param string        $group       'categories' or 'tags' — drives field name and container id.
     * @param array<object> $terms       WP_Term objects to list.
     * @param array<int>    $excluded    Term IDs currently excluded.
     * @param string        $heading     Section heading text.
     * @param string        $description Section description text.
     */
    public static function render_exclusion_grid(string $prefix, string $group, array $terms, array $excluded, string $heading, string $description): void {
        $container_id = $prefix . 'exclude_' . $group;
        $field_name = 'exclude_' . $group . '[]';
        ?>
        <h4><?php echo esc_html($heading); ?></h4>
        <p class="description"><?php echo esc_html($description); ?></p>

        <div class="wc-mss-select-actions">
            <button type="button" class="button button-small" onclick="wcMssSelectAll('<?php echo esc_js($container_id); ?>')"><?php _e('Select All', 'wc-multi-store-sync'); ?></button>
            <button type="button" class="button button-small" onclick="wcMssDeselectAll('<?php echo esc_js($container_id); ?>')"><?php _e('Deselect All', 'wc-multi-store-sync'); ?></button>
            <span class="wc-mss-selected-count" data-target="<?php echo esc_attr($container_id); ?>">
                <?php echo count($excluded); ?> selected
            </span>
        </div>

        <div class="wc-mss-checkbox-grid" id="<?php echo esc_attr($container_id); ?>">
            <?php foreach ($terms as $term):
                $is_checked = in_array($term->term_id, $excluded);
            ?>
            <label class="wc-mss-checkbox-item <?php echo $is_checked ? 'selected' : ''; ?>">
                <input type="checkbox" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr($term->term_id); ?>"
                       <?php checked($is_checked); ?>>
                <span><?php echo esc_html($term->name); ?></span>
                <span class="count">(<?php echo esc_html($term->count); ?>)</span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
