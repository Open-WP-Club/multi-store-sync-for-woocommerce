<?php
/**
 * Settings View
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-mss-settings">
    <h1><?php esc_html_e('Multi-Store Sync Settings', 'multi-store-sync-for-woocommerce'); ?></h1>

    <?php settings_errors('wc_mss_settings'); ?>

    <div class="wc-mss-settings-layout">
        <div class="wc-mss-settings-main">

    <form method="post" action="">
        <?php wp_nonce_field('wc_mss_save_settings'); ?>

        <div class="wc-mss-card">
            <h2><?php esc_html_e('General Settings', 'multi-store-sync-for-woocommerce'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="enabled"><?php esc_html_e('Enable Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" id="enabled" value="1"
                                   <?php checked($settings['enabled'], true); ?>>
                            <?php esc_html_e('Enable product synchronization', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Master switch for all sync operations', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sync_type_default"><?php esc_html_e('Default Sync Type', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="sync_type_default" id="sync_type_default">
                            <?php
                            WC_Multi_Store_Sync_Type_Options::render([
                                'full_product' => __('Full Product (all data)', 'multi-store-sync-for-woocommerce'),
                                'price_quantity_categories' => __('Price, Quantity & Categories', 'multi-store-sync-for-woocommerce'),
                                'price_quantity' => __('Price & Quantity', 'multi-store-sync-for-woocommerce'),
                                'quantity' => __('Quantity Only', 'multi-store-sync-for-woocommerce'),
                            ], $settings['sync_type_default']);
                            ?>
                        </select>
                        <p class="description"><?php esc_html_e('What type of data to sync by default', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auth_method"><?php esc_html_e('Authentication Method', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="auth_method" id="auth_method">
                            <option value="basic_auth" <?php selected($settings['auth_method'], 'basic_auth'); ?>>
                                <?php esc_html_e('HTTP Basic Auth (recommended)', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                            <option value="query_string" <?php selected($settings['auth_method'], 'query_string'); ?>>
                                <?php esc_html_e('Query String (not recommended — credentials appear in server logs)', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                        </select>
                        <p class="description"><?php esc_html_e('How to authenticate with remote stores. Basic Auth is recommended as it keeps credentials out of URLs and server logs.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="match_products_by"><?php esc_html_e('Match Products By', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="match_products_by" id="match_products_by">
                            <option value="sku" <?php selected($settings['match_products_by'], 'sku'); ?>>
                                <?php esc_html_e('SKU (recommended)', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                            <option value="slug" <?php selected($settings['match_products_by'], 'slug'); ?>>
                                <?php esc_html_e('Slug', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                        </select>
                        <p class="description"><?php esc_html_e('How to match local products with remote products', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="category_match_mode"><?php esc_html_e('Category Match Mode', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="category_match_mode" id="category_match_mode">
                            <option value="full_path" <?php selected(isset($settings['category_match_mode']) ? $settings['category_match_mode'] : 'full_path', 'full_path'); ?>>
                                <?php esc_html_e('Full Path (all categories in hierarchy)', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                            <option value="leaf_only" <?php selected(isset($settings['category_match_mode']) ? $settings['category_match_mode'] : 'full_path', 'leaf_only'); ?>>
                                <?php esc_html_e('Leaf Only (only deepest category)', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                        </select>
                        <p class="description"><?php esc_html_e('Full Path: syncs all categories in the hierarchy (e.g., Clothing, Men, Shirts). Leaf Only: syncs only the deepest category in each branch (e.g., only Shirts). Use Leaf Only when remote stores have different category structures.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="category_match_by"><?php esc_html_e('Category Match By', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="category_match_by" id="category_match_by">
                            <option value="slug" <?php selected(isset($settings['category_match_by']) ? $settings['category_match_by'] : 'slug', 'slug'); ?>>
                                <?php esc_html_e('Slug', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                            <option value="name" <?php selected(isset($settings['category_match_by']) ? $settings['category_match_by'] : 'slug', 'name'); ?>>
                                <?php esc_html_e('Name (supports Cyrillic)', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                        </select>
                        <p class="description"><?php esc_html_e('Slug: matches categories by URL slug (e.g., "mens-clothing"). Name: matches by display name (e.g., "Мъжки дрехи"). Use Name when stores have different slugs but identical category names.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="category_auto_create"><?php esc_html_e('Auto-create Categories', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="category_auto_create" id="category_auto_create" value="1"
                                   <?php checked(isset($settings['category_auto_create']) ? $settings['category_auto_create'] : true, true); ?>>
                            <?php esc_html_e('Create missing categories on remote stores', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, categories that don\'t exist on remote stores will be created automatically. When disabled, only existing categories will be assigned (missing ones are skipped).', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="stock_sync_enabled"><?php esc_html_e('Stock Synchronization', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="stock_sync_enabled" id="stock_sync_enabled" value="1"
                                   <?php checked($settings['stock_sync_enabled'], true); ?>>
                            <?php esc_html_e('Enable stock quantity synchronization', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, stock quantities will be synced to remote stores', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="image_proxy_enabled"><?php esc_html_e('API Image Transfer', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="image_proxy_enabled" id="image_proxy_enabled" value="1"
                                   <?php checked(isset($settings['image_proxy_enabled']) ? $settings['image_proxy_enabled'] : false, true); ?>>
                            <?php esc_html_e('Transfer images directly through the API instead of URLs', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Enable this if the site is behind Cloudflare or a firewall that blocks image downloads. Images are read locally and sent as data through the API — the remote store never needs to download images over HTTP.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_create_missing_products"><?php esc_html_e('Auto-Create Missing Products', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_create_missing_products" id="auto_create_missing_products" value="1"
                                   <?php checked(isset($settings['auto_create_missing_products']) ? $settings['auto_create_missing_products'] : false, true); ?>>
                            <?php esc_html_e('Queue full sync for products not found on remote stores', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When a partial sync (price/quantity) encounters a product that doesn\'t exist on a remote store, automatically queue a full product sync with low priority to create it. Without this option, such products are simply skipped.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_sync_new_products"><?php esc_html_e('Auto-Sync New Products', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_sync_new_products" id="auto_sync_new_products" value="1"
                                   <?php checked(isset($settings['auto_sync_new_products']) ? $settings['auto_sync_new_products'] : false, true); ?>>
                            <?php esc_html_e('Automatically sync newly created products', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When a new product is created and matches the category/tag filters for a store, automatically queue it for synchronization. Works independently of "Auto-Sync on Save".', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_sync_deletions"><?php esc_html_e('Product Deletion Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_sync_deletions" id="auto_sync_deletions" value="1"
                                   <?php checked(isset($settings['auto_sync_deletions']) ? $settings['auto_sync_deletions'] : false, true); ?>>
                            <?php esc_html_e('Automatically delete products and variations from remote stores', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When a product or variation is deleted on the main store, automatically delete it from all synced remote stores (respects category/tag exclusions)', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="deletion_mode"><?php esc_html_e('Deletion Mode', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="deletion_mode" id="deletion_mode">
                            <option value="trash" <?php selected(isset($settings['deletion_mode']) ? $settings['deletion_mode'] : 'trash', 'trash'); ?>>
                                <?php esc_html_e('Move to Trash (reversible)', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                            <option value="force" <?php selected(isset($settings['deletion_mode']) ? $settings['deletion_mode'] : 'trash', 'force'); ?>>
                                <?php esc_html_e('Delete Permanently (not reversible)', 'multi-store-sync-for-woocommerce'); ?>
                            </option>
                        </select>
                        <p class="description"><?php esc_html_e('How to delete products on remote stores', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="delete_orphan_variations"><?php esc_html_e('Delete Orphan Variations', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="delete_orphan_variations" id="delete_orphan_variations" value="1"
                                   <?php checked(isset($settings['delete_orphan_variations']) ? $settings['delete_orphan_variations'] : false, true); ?>>
                            <?php esc_html_e('Delete variations on remote stores that do not exist locally', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, during variation sync, any variations on the remote store that do not have a matching SKU in the source store will be deleted. This keeps variation counts in sync and removes "ghost" variations.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_sync_restorations"><?php esc_html_e('Product Restoration Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_sync_restorations" id="auto_sync_restorations" value="1"
                                   <?php checked(isset($settings['auto_sync_restorations']) ? $settings['auto_sync_restorations'] : false, true); ?>>
                            <?php esc_html_e('Automatically restore products on remote stores', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When a product is restored from trash on the main store, automatically restore it on all synced remote stores (respects category/tag exclusions)', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_sync_status"><?php esc_html_e('Product Status Change Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_sync_status" id="auto_sync_status" value="1"
                                   <?php checked(isset($settings['auto_sync_status']) ? $settings['auto_sync_status'] : false, true); ?>>
                            <?php esc_html_e('Sync product status changes (draft ↔ published ↔ private)', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When a product status changes on the main store, automatically update the status on all synced remote stores (respects category/tag exclusions)', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php esc_html_e('Performance Settings', 'multi-store-sync-for-woocommerce'); ?></h2>
            <p class="description">
                <?php esc_html_e('Control how many products are synced per batch. Higher values = faster sync but more server load.', 'multi-store-sync-for-woocommerce'); ?>
            </p>

            <?php
            $scheduled_settings = get_option('wc_multi_store_sync_scheduled', []);
            $batch_size_peak = isset($scheduled_settings['batch_size_peak']) ? (int) $scheduled_settings['batch_size_peak'] : 5;
            $batch_size_offpeak = isset($scheduled_settings['batch_size_offpeak']) ? (int) $scheduled_settings['batch_size_offpeak'] : 20;
            ?>

            <?php
            $cb_threshold = (int) WC_Multi_Store_Settings::get('circuit_breaker_threshold', 10);
            $cb_duration  = (int) WC_Multi_Store_Settings::get('circuit_breaker_duration', 1800);
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="circuit_breaker_threshold"><?php esc_html_e('Circuit Breaker Threshold', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="circuit_breaker_threshold" id="circuit_breaker_threshold"
                               value="<?php echo esc_attr($cb_threshold); ?>"
                               min="1" max="100" step="1" class="small-text">
                        <span><?php esc_html_e('consecutive errors', 'multi-store-sync-for-woocommerce'); ?></span>
                        <p class="description"><?php esc_html_e('Number of consecutive failures to a store before pausing requests. Default: 10', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="circuit_breaker_duration"><?php esc_html_e('Circuit Breaker Duration', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="circuit_breaker_duration" id="circuit_breaker_duration"
                               value="<?php echo esc_attr($cb_duration); ?>"
                               min="60" max="86400" step="60" class="small-text">
                        <span><?php esc_html_e('seconds', 'multi-store-sync-for-woocommerce'); ?></span>
                        <p class="description"><?php /* translators: %d: current circuit breaker duration in minutes. */ echo esc_html(sprintf(esc_html__('How long to pause requests to a failing store. Default: 1800 (30 minutes). Current: %d min.', 'multi-store-sync-for-woocommerce'), absint(round($cb_duration / 60)))); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="batch_size_peak"><?php esc_html_e('Batch Size (Peak Hours)', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="batch_size_peak" id="batch_size_peak"
                               value="<?php echo esc_attr($batch_size_peak); ?>"
                               min="1" max="500" step="1" class="small-text">
                        <span><?php esc_html_e('products per batch', 'multi-store-sync-for-woocommerce'); ?></span>
                        <p class="description"><?php esc_html_e('Number of products to sync per run during peak hours (9am-6pm). Default: 5', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="batch_size_offpeak"><?php esc_html_e('Batch Size (Off-Peak Hours)', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="batch_size_offpeak" id="batch_size_offpeak"
                               value="<?php echo esc_attr($batch_size_offpeak); ?>"
                               min="1" max="500" step="1" class="small-text">
                        <span><?php esc_html_e('products per batch', 'multi-store-sync-for-woocommerce'); ?></span>
                        <p class="description"><?php esc_html_e('Number of products to sync per run during off-peak hours (6pm-9am). Default: 20', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php esc_html_e('Scheduled Sync', 'multi-store-sync-for-woocommerce'); ?></h2>
            <p class="description">
                <?php esc_html_e('Automatic background sync that periodically checks for products to synchronize. This is a "catch-all" mechanism for products that may have been missed by real-time sync.', 'multi-store-sync-for-woocommerce'); ?>
            </p>

            <?php
            $scheduled_sync_enabled = isset($scheduled_settings['scheduled_sync_enabled']) ? $scheduled_settings['scheduled_sync_enabled'] : true;
            $scheduled_sync_interval = isset($scheduled_settings['scheduled_sync_interval']) ? $scheduled_settings['scheduled_sync_interval'] : '10min';
            $sync_all_products = isset($scheduled_settings['sync_all_products']) ? $scheduled_settings['sync_all_products'] : true;
            $sync_modified_hours = isset($scheduled_settings['sync_modified_hours']) ? (int) $scheduled_settings['sync_modified_hours'] : 24;
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="scheduled_sync_enabled"><?php esc_html_e('Enable Scheduled Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="scheduled_sync_enabled" id="scheduled_sync_enabled" value="1" <?php checked($scheduled_sync_enabled, true); ?>>
                            <?php esc_html_e('Enable automatic scheduled sync', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('If disabled, only real-time sync (on product save, stock change, etc.) will be active. Disable this if real-time sync is working well and you want to reduce server load.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr class="scheduled-sync-option" <?php echo !$scheduled_sync_enabled ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label for="scheduled_sync_interval"><?php esc_html_e('Sync Interval', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select name="scheduled_sync_interval" id="scheduled_sync_interval">
                            <option value="10min" <?php selected($scheduled_sync_interval, '10min'); ?>><?php esc_html_e('Every 10 minutes', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="30min" <?php selected($scheduled_sync_interval, '30min'); ?>><?php esc_html_e('Every 30 minutes', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="hourly" <?php selected($scheduled_sync_interval, 'hourly'); ?>><?php esc_html_e('Every hour', 'multi-store-sync-for-woocommerce'); ?></option>
                            <option value="daily" <?php selected($scheduled_sync_interval, 'daily'); ?>><?php esc_html_e('Once daily (recommended)', 'multi-store-sync-for-woocommerce'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('How often to run the scheduled sync. "Once daily" is recommended if real-time sync is working properly.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr class="scheduled-sync-option" <?php echo !$scheduled_sync_enabled ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label><?php esc_html_e('Products to Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="sync_all_products" value="1" <?php checked($sync_all_products, true); ?>>
                                <?php esc_html_e('All products', 'multi-store-sync-for-woocommerce'); ?>
                                <span class="description" style="color: #d63638;"><?php esc_html_e('(Not recommended - can cause queue overload)', 'multi-store-sync-for-woocommerce'); ?></span>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="sync_all_products" value="0" <?php checked($sync_all_products, false); ?>>
                                <?php esc_html_e('Only recently modified products', 'multi-store-sync-for-woocommerce'); ?>
                                <span class="description" style="color: #00a32a;"><?php esc_html_e('(Recommended)', 'multi-store-sync-for-woocommerce'); ?></span>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr id="sync_modified_hours_row" class="scheduled-sync-option" style="<?php echo (!$scheduled_sync_enabled || $sync_all_products) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="sync_modified_hours"><?php esc_html_e('Modified Within', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="sync_modified_hours" id="sync_modified_hours"
                               value="<?php echo esc_attr($sync_modified_hours); ?>"
                               min="1" max="168" step="1" class="small-text">
                        <span><?php esc_html_e('hours', 'multi-store-sync-for-woocommerce'); ?></span>
                        <p class="description">
                            <?php esc_html_e('Only sync products modified within this time window. Default: 24 hours.', 'multi-store-sync-for-woocommerce'); ?>
                            <br>
                            <strong><?php esc_html_e('What counts as "modified":', 'multi-store-sync-for-woocommerce'); ?></strong>
                            <?php esc_html_e('Product saved, stock changed, price updated, status changed, categories/tags modified.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php esc_html_e('Sync by Category', 'multi-store-sync-for-woocommerce'); ?></h2>
            <p class="description">
                <?php esc_html_e('Queue all products in a category (and optionally its children) for immediate synchronisation.', 'multi-store-sync-for-woocommerce'); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wc-mss-cat-sync-category"><?php esc_html_e('Category', 'multi-store-sync-for-woocommerce'); ?></label></th>
                    <td>
                        <?php
                        wp_dropdown_categories([
                            'taxonomy'         => 'product_cat',
                            'name'             => 'wc_mss_cat_sync_category',
                            'id'               => 'wc-mss-cat-sync-category',
                            'show_option_none' => __('— Select a category —', 'multi-store-sync-for-woocommerce'),
                            'option_none_value'=> '',
                            'hide_empty'       => false,
                            'hierarchical'     => true,
                        ]);
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wc-mss-cat-sync-type"><?php esc_html_e('Sync Type', 'multi-store-sync-for-woocommerce'); ?></label></th>
                    <td>
                        <select id="wc-mss-cat-sync-type">
                            <?php
                            WC_Multi_Store_Sync_Type_Options::render([
                                'full_product' => __('Full Product', 'multi-store-sync-for-woocommerce'),
                                'price_quantity' => __('Price & Quantity', 'multi-store-sync-for-woocommerce'),
                                'price_quantity_categories' => __('Price, Quantity & Categories', 'multi-store-sync-for-woocommerce'),
                                'quantity' => __('Quantity only', 'multi-store-sync-for-woocommerce'),
                            ]);
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Include Children', 'multi-store-sync-for-woocommerce'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wc-mss-cat-sync-children" checked>
                            <?php esc_html_e('Also sync products in child categories', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"></th>
                    <td>
                        <button type="button" class="button button-primary" id="wc-mss-cat-sync-btn"
                            data-msg-select-category="<?php echo esc_attr__('Please select a category.', 'multi-store-sync-for-woocommerce'); ?>"
                            data-msg-queuing="<?php echo esc_attr__('Queuing…', 'multi-store-sync-for-woocommerce'); ?>"
                            data-msg-error="<?php echo esc_attr__('Error', 'multi-store-sync-for-woocommerce'); ?>">
                            <?php esc_html_e('Queue Category Sync', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                        <span id="wc-mss-cat-sync-result" style="margin-left:12px;"></span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php esc_html_e('Queue Status', 'multi-store-sync-for-woocommerce'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Estimated Sync Time', 'multi-store-sync-for-woocommerce'); ?></th>
                    <td>
                        <?php
                        $product_count = wp_count_posts('product');
                        $total_products = isset($product_count->publish) ? (int) $product_count->publish : 0;
                        $queue_count = class_exists('WC_Multi_Store_Queue_Table') ? (WC_Multi_Store_Queue_Table::get_stats()['pending'] ?? 0) : 0;

                        if ($queue_count > 0) {
                            $peak_hours = ceil($queue_count / $batch_size_peak) * 5 / 60;
                            $offpeak_hours = ceil($queue_count / $batch_size_offpeak) * 5 / 60;
                            echo wp_kses_post(sprintf(
                                /* translators: 1: products queued, 2: estimated peak-hours duration, 3: estimated off-peak duration. */
                                __('<strong>%1$d products in queue</strong><br>Peak: ~%2$.1f hours | Off-peak: ~%3$.1f hours', 'multi-store-sync-for-woocommerce'),
                                absint($queue_count),
                                (float) $peak_hours,
                                (float) $offpeak_hours
                            ));
                        } else {
                            esc_html_e('Queue is empty', 'multi-store-sync-for-woocommerce');
                        }
                        ?>
                        <p class="description"><?php esc_html_e('Based on current batch sizes and 5-minute intervals', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php esc_html_e('Order-Based Stock Sync (Webhooks)', 'multi-store-sync-for-woocommerce'); ?></h2>
            <p class="description">
                <?php esc_html_e('Automatically reduce stock on the main store when orders are placed on remote stores via webhooks.', 'multi-store-sync-for-woocommerce'); ?>
            </p>

            <?php
            $webhook_settings = get_option('wc_multi_store_sync_webhook_settings', [
                'enabled' => false,
                'webhook_secret' => '',
                'trigger_statuses' => ['processing', 'completed'],
                'allow_negative_stock' => false,
                'auto_verify' => true,
            ]);
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="webhook_enabled"><?php esc_html_e('Enable Webhook Receiver', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="webhook_enabled" id="webhook_enabled" value="1"
                                   <?php checked($webhook_settings['enabled'], true); ?>>
                            <?php esc_html_e('Enable order-based stock synchronization', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, the main store will receive webhooks from remote stores when orders are placed, and automatically reduce stock.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="webhook_secret"><?php esc_html_e('Webhook Secret', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="password" name="webhook_secret" id="webhook_secret" class="regular-text code"
                               value=""
                               placeholder="<?php echo !empty($webhook_settings['webhook_secret']) ? esc_attr__('Already set — leave blank to keep', 'multi-store-sync-for-woocommerce') : ''; ?>"
                               autocomplete="new-password" />
                        <button type="button" class="button button-small" onclick="var f = document.getElementById('webhook_secret'); f.type = f.type === 'password' ? 'text' : 'password';">
                            <?php esc_html_e('Show/Hide', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                        <button type="button" id="generate-secret" class="button">
                            <?php esc_html_e('Generate Secret', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('Secret key for authenticating webhook requests. Click "Generate Secret" to create a random secure key.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Trigger Order Statuses', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <?php
                        $order_statuses = [
                            'pending' => __('Pending Payment', 'multi-store-sync-for-woocommerce'),
                            'processing' => __('Processing', 'multi-store-sync-for-woocommerce'),
                            'on-hold' => __('On Hold', 'multi-store-sync-for-woocommerce'),
                            'completed' => __('Completed', 'multi-store-sync-for-woocommerce'),
                        ];
                        $selected_statuses = isset($webhook_settings['trigger_statuses']) ? $webhook_settings['trigger_statuses'] : ['processing', 'completed'];

                        foreach ($order_statuses as $status => $label) {
                            $checked = in_array($status, $selected_statuses);
                            ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="trigger_statuses[]" value="<?php echo esc_attr($status); ?>"
                                       <?php checked($checked); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                            <?php
                        }
                        ?>
                        <p class="description">
                            <?php esc_html_e('Which order statuses should trigger stock deduction. Recommended: Processing and Completed.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="allow_negative_stock"><?php esc_html_e('Allow Negative Stock', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="allow_negative_stock" id="allow_negative_stock" value="1"
                                   <?php checked($webhook_settings['allow_negative_stock'], true); ?>>
                            <?php esc_html_e('Allow stock to go negative', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('If disabled, webhook will fail when trying to reduce stock below zero.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_verify"><?php esc_html_e('Auto-Verify Stock', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_verify" id="auto_verify" value="1"
                                   <?php checked($webhook_settings['auto_verify'], true); ?>>
                            <?php esc_html_e('Automatically verify stock after sync', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('After syncing stock to remote stores, verify that the actual stock matches expected values.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="webhook_log_retention_days"><?php esc_html_e('Webhook Log Retention', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="webhook_log_retention_days" id="webhook_log_retention_days"
                               value="<?php echo esc_attr(isset($webhook_settings['webhook_log_retention_days']) ? $webhook_settings['webhook_log_retention_days'] : 90); ?>"
                               min="30" max="180" step="1" class="small-text" /> <?php esc_html_e('days', 'multi-store-sync-for-woocommerce'); ?>
                        <p class="description">
                            <?php esc_html_e('How long to keep webhook logs (30-180 days). Logs are automatically cleaned up daily.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Webhook Logs', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <button type="button" id="view-webhook-logs" class="button">
                            <?php esc_html_e('View Webhook Logs', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                        <button type="button" id="export-webhook-logs" class="button">
                            <?php esc_html_e('Export to CSV', 'multi-store-sync-for-woocommerce'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('View detailed logs of all webhook activity including stock changes, errors, and sync events.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>

                <?php if (!empty($webhook_settings['enabled'])): ?>
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Webhook URLs', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <p class="description" style="margin-bottom: 10px;">
                            <?php esc_html_e('Use these URLs when configuring webhooks on your remote WooCommerce stores:', 'multi-store-sync-for-woocommerce'); ?>
                        </p>

                        <?php $stores = WC_Multi_Store_Settings::get_stores(); ?>
                        <?php if (empty($stores)): ?>
                            <p><em><?php esc_html_e('No stores configured yet.', 'multi-store-sync-for-woocommerce'); ?></em></p>
                        <?php else: ?>
                            <?php foreach ($stores as $store_url => $store): ?>
                                <?php if (!is_string($store_url) || empty($store_url)) continue; ?>
                                <?php if (isset($store['status']) && $store['status'] === 'active'): ?>
                                    <div style="margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                                        <strong><?php echo esc_html($store['name'] ?? $store_url); ?></strong><br>
                                        <code style="display: block; margin: 5px 0; padding: 5px; background: #fff; font-size: 11px; word-break: break-all;">
                                            <?php echo esc_html(WC_Multi_Store_Webhook_Receiver::get_webhook_url($store_url)); ?>
                                        </code>
                                        <button type="button" class="button button-small copy-webhook-url" data-url="<?php echo esc_attr(WC_Multi_Store_Webhook_Receiver::get_webhook_url($store_url)); ?>">
                                            <?php esc_html_e('Copy URL', 'multi-store-sync-for-woocommerce'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <p class="description">
                            <?php esc_html_e('See documentation below for instructions on setting up webhooks on remote stores.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php esc_html_e('Additional Sync Features', 'multi-store-sync-for-woocommerce'); ?></h2>
            <p class="description">
                <?php esc_html_e('Enable or disable additional sync modules. Each feature operates independently and can be configured separately.', 'multi-store-sync-for-woocommerce'); ?>
            </p>

            <?php
            $shipping_class_settings = WC_Multi_Store_Shipping_Class_Sync::get_settings();
            $coupon_settings = get_option('wc_multi_store_sync_coupon_settings', ['enabled' => false]);
            $review_settings = WC_Multi_Store_Review_Sync::get_settings();
            $attribute_sync_settings = WC_Multi_Store_Attribute_Sync::get_settings();
            $download_settings = WC_Multi_Store_Downloadable_Files_Sync::get_settings();
            $category_mapper_settings = WC_Multi_Store_Category_Mapper::get_settings();
            $attribute_remapper_settings = WC_Multi_Store_Attribute_Remapper::get_settings();
            $conflict_settings = WC_Multi_Store_Conflict_Detector::get_settings();
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Shipping Class Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_shipping_class_sync"
                                   <?php checked(!empty($shipping_class_settings['enabled'])); ?>>
                            <?php esc_html_e('Sync shipping classes to remote stores', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Automatically create, update, and delete shipping classes on remote stores when they change locally.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Coupon Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_coupon_sync"
                                   <?php checked(!empty($coupon_settings['enabled'])); ?>>
                            <?php esc_html_e('Sync coupons/discounts to remote stores', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Automatically sync coupon codes, discount rules, and restrictions. Product/category IDs are resolved via SKU/slug across stores.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Review Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_review_sync"
                                   <?php checked(!empty($review_settings['enabled'])); ?>>
                            <?php esc_html_e('Sync product reviews to remote stores', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Automatically sync new/edited/deleted product reviews. Products are matched by SKU; synced reviews never carry the "verified owner" badge on the remote store.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Attribute Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_attribute_sync"
                                   <?php checked(!empty($attribute_sync_settings['enabled'])); ?>>
                            <?php esc_html_e('Sync global product attributes and terms to remote stores', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Creates/updates/deletes global attributes (e.g. Color, Size) and their terms on remote stores when they change locally, matched by slug. For renaming attribute/term names only in the synced product data instead, use Attribute Remapping below.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Downloadable Files Sync', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_downloadable_files_sync"
                                   <?php checked(!empty($download_settings['enabled'])); ?>>
                            <?php esc_html_e('Sync downloadable product files', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Include downloadable files when syncing digital products. Supports URL-based sync or direct file transfer via Media API.', 'multi-store-sync-for-woocommerce'); ?></p>
                        <?php if (!empty($download_settings['enabled'])): ?>
                        <div style="margin-top: 8px; padding: 8px; background: #f0f0f1; border-radius: 4px;">
                            <label>
                                <strong><?php esc_html_e('Transfer Mode:', 'multi-store-sync-for-woocommerce'); ?></strong>
                                <select class="wc-mss-feature-setting" data-option="wc_mss_downloadable_files_sync_settings" data-key="transfer_mode" style="margin-left: 5px;">
                                    <option value="url" <?php selected($download_settings['transfer_mode'] ?? 'url', 'url'); ?>><?php esc_html_e('URL (remote downloads from source)', 'multi-store-sync-for-woocommerce'); ?></option>
                                    <option value="api" <?php selected($download_settings['transfer_mode'] ?? 'url', 'api'); ?>><?php esc_html_e('API Transfer (upload files directly)', 'multi-store-sync-for-woocommerce'); ?></option>
                                </select>
                            </label>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Category Mapping', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_category_mapper"
                                   <?php checked(!empty($category_mapper_settings['enabled'])); ?>>
                            <?php esc_html_e('Enable per-store category/tag mapping', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description">
                            <?php echo wp_kses_post(sprintf(
                                /* translators: %s: link to the Category Mapping tab */
                                __('Map local categories to different remote categories per store (e.g., "Дрехи" → "Clothing"). Enter mappings in the %s tab.', 'multi-store-sync-for-woocommerce'),
                                '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=category-mapping')) . '">' . esc_html__('Category Mapping', 'multi-store-sync-for-woocommerce') . '</a>'
                            )); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Attribute Remapping', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_attribute_remapping"
                                   <?php checked(!empty($attribute_remapper_settings['enabled'])); ?>>
                            <?php esc_html_e('Enable per-store attribute name/value remapping', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description">
                            <?php echo wp_kses_post(sprintf(
                                /* translators: %s: link to the Attribute Mapping tab */
                                __('Remap attribute names and values per store (e.g., "Цвят" → "Color", "Червен" → "Red"). Useful for multilingual stores. Enter mappings in the %s tab.', 'multi-store-sync-for-woocommerce'),
                                '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=attribute-mapping')) . '">' . esc_html__('Attribute Mapping', 'multi-store-sync-for-woocommerce') . '</a>'
                            )); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Conflict Detection', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_conflict_detection"
                                   <?php checked(!empty($conflict_settings['enabled'])); ?>>
                            <?php esc_html_e('Detect remote product changes before overwriting', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Tracks remote product state and warns when someone manually edited a product on a remote store. Prevents accidental data loss.', 'multi-store-sync-for-woocommerce'); ?></p>
                        <?php
                        $conflict_stats = WC_Multi_Store_Conflict_Detector::get_stats();
                        if ($conflict_stats['unresolved'] > 0): ?>
                        <p style="margin-top: 5px; color: #d63638;">
                            <strong>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=conflicts')); ?>" style="color: #d63638;">
                                    <?php /* translators: %d: number of unresolved conflicts. */ echo esc_html(sprintf(esc_html__('%d unresolved conflict(s)', 'multi-store-sync-for-woocommerce'), absint($conflict_stats['unresolved']))); ?>
                                </a>
                            </strong>
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php esc_html_e('Email Notifications', 'multi-store-sync-for-woocommerce'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure email alerts for sync events. All instant notifications are rate-limited to once per day to avoid inbox flooding.', 'multi-store-sync-for-woocommerce'); ?>
            </p>

            <?php
            $email_settings = WC_Multi_Store_Email_Notifications::get_settings();
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="email_notifications_enabled"><?php esc_html_e('Enable Email Notifications', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="email_notifications_enabled" id="email_notifications_enabled" value="1"
                                   <?php checked(!empty($email_settings['enabled'])); ?>>
                            <?php esc_html_e('Send email notifications for sync events', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Master switch — disabling this suppresses all notification types below.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="email_recipient"><?php esc_html_e('Recipient Email', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="email" name="email_recipient" id="email_recipient" class="regular-text"
                               value="<?php echo esc_attr($email_settings['recipient_email']); ?>">
                        <p class="description"><?php esc_html_e('Address that receives all notifications. Defaults to the site admin email.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Notification Types', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="email_failed_sync_enabled" value="1"
                                       <?php checked(!empty($email_settings['failed_sync_enabled'])); ?>>
                                <?php esc_html_e('Failed sync alerts (once per day)', 'multi-store-sync-for-woocommerce'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="email_api_error_enabled" value="1"
                                       <?php checked(!empty($email_settings['api_error_enabled'])); ?>>
                                <?php esc_html_e('API error alerts (once per day)', 'multi-store-sync-for-woocommerce'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="email_low_stock_enabled" value="1"
                                       <?php checked(!empty($email_settings['low_stock_enabled'])); ?>>
                                <?php esc_html_e('Low stock alerts (once per day)', 'multi-store-sync-for-woocommerce'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="email_daily_summary_enabled" value="1"
                                       <?php checked(!empty($email_settings['daily_summary_enabled'])); ?>>
                                <?php esc_html_e('Daily sync summary', 'multi-store-sync-for-woocommerce'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="email_low_stock_threshold"><?php esc_html_e('Low Stock Threshold', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="email_low_stock_threshold" id="email_low_stock_threshold"
                               value="<?php echo esc_attr($email_settings['low_stock_threshold']); ?>"
                               min="0" max="9999" step="1" class="small-text">
                        <span><?php esc_html_e('units', 'multi-store-sync-for-woocommerce'); ?></span>
                        <p class="description"><?php esc_html_e('Send low-stock alert when stock drops to or below this value.', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="email_daily_summary_time"><?php esc_html_e('Daily Summary Time', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="time" name="email_daily_summary_time" id="email_daily_summary_time"
                               value="<?php echo esc_attr($email_settings['daily_summary_time']); ?>">
                        <p class="description"><?php esc_html_e('Time of day to send the daily summary (server timezone).', 'multi-store-sync-for-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card" style="border-left: 4px solid #d63638;">
            <h2><?php esc_html_e('Uninstall', 'multi-store-sync-for-woocommerce'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="cleanup_on_uninstall"><?php esc_html_e('Delete data on uninstall', 'multi-store-sync-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="cleanup_on_uninstall" id="cleanup_on_uninstall" value="1"
                                   <?php checked($settings['cleanup_on_uninstall'] ?? false, true); ?>>
                            <?php esc_html_e('Remove all plugin data when the plugin is deleted', 'multi-store-sync-for-woocommerce'); ?>
                        </label>
                        <p class="description" style="color: #d63638;">
                            <?php esc_html_e('When enabled and the plugin is deleted via the WordPress plugins screen, all custom database tables and plugin options will be permanently removed. <strong>This cannot be undone.</strong> Leave disabled (default) to preserve your data if you reinstall the plugin later.', 'multi-store-sync-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button type="submit" name="wc_mss_save_settings" class="button button-primary">
                <?php esc_html_e('Save Settings', 'multi-store-sync-for-woocommerce'); ?>
            </button>
        </p>
    </form>

        </div><!-- .wc-mss-settings-main -->

        <div class="wc-mss-settings-sidebar">
            <div class="wc-mss-card">
                <h3><?php esc_html_e('Sync Types', 'multi-store-sync-for-woocommerce'); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e('Full Product', 'multi-store-sync-for-woocommerce'); ?></strong> - <?php esc_html_e('All data: name, description, prices, stock, images, categories, tags, attributes.', 'multi-store-sync-for-woocommerce'); ?></li>
                    <li><strong><?php esc_html_e('Price, Qty & Categories', 'multi-store-sync-for-woocommerce'); ?></strong> - <?php esc_html_e('Prices, stock, status, categories, tags. No description/images.', 'multi-store-sync-for-woocommerce'); ?></li>
                    <li><strong><?php esc_html_e('Price & Quantity', 'multi-store-sync-for-woocommerce'); ?></strong> - <?php esc_html_e('Only prices and stock quantities.', 'multi-store-sync-for-woocommerce'); ?></li>
                    <li><strong><?php esc_html_e('Quantity Only', 'multi-store-sync-for-woocommerce'); ?></strong> - <?php esc_html_e('Only stock quantities. Fastest option.', 'multi-store-sync-for-woocommerce'); ?></li>
                </ul>
            </div>

            <div class="wc-mss-card">
                <h3><?php esc_html_e('Webhook Setup', 'multi-store-sync-for-woocommerce'); ?></h3>
                <p><?php esc_html_e('Configure webhooks on remote stores:', 'multi-store-sync-for-woocommerce'); ?></p>
                <ol>
                    <li><?php esc_html_e('Go to WooCommerce → Settings → Advanced → Webhooks', 'multi-store-sync-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Add webhook with Topic: "Order updated"', 'multi-store-sync-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Copy Delivery URL from above', 'multi-store-sync-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Use same Secret key', 'multi-store-sync-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Set API Version: WP REST API v3', 'multi-store-sync-for-woocommerce'); ?></li>
                </ol>
                <div class="wc-mss-webhook-note">
                    <strong><?php esc_html_e('Note:', 'multi-store-sync-for-woocommerce'); ?></strong>
                    <?php esc_html_e('Secret must match on both sides.', 'multi-store-sync-for-woocommerce'); ?>
                </div>
            </div>
        </div><!-- .wc-mss-settings-sidebar -->

    </div><!-- .wc-mss-settings-layout -->
</div>
