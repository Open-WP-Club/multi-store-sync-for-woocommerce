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
    <h1><?php _e('Multi-Store Sync Settings', 'wc-multi-store-sync'); ?></h1>

    <?php settings_errors('wc_mss_settings'); ?>

    <div class="wc-mss-settings-layout">
        <div class="wc-mss-settings-main">

    <form method="post" action="">
        <?php wp_nonce_field('wc_mss_save_settings'); ?>

        <div class="wc-mss-card">
            <h2><?php _e('General Settings', 'wc-multi-store-sync'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="enabled"><?php _e('Enable Sync', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" id="enabled" value="1"
                                   <?php checked($settings['enabled'], true); ?>>
                            <?php _e('Enable product synchronization', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('Master switch for all sync operations', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sync_type_default"><?php _e('Default Sync Type', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="sync_type_default" id="sync_type_default">
                            <?php
                            WC_Multi_Store_Sync_Type_Options::render([
                                'full_product' => __('Full Product (all data)', 'wc-multi-store-sync'),
                                'price_quantity_categories' => __('Price, Quantity & Categories', 'wc-multi-store-sync'),
                                'price_quantity' => __('Price & Quantity', 'wc-multi-store-sync'),
                                'quantity' => __('Quantity Only', 'wc-multi-store-sync'),
                            ], $settings['sync_type_default']);
                            ?>
                        </select>
                        <p class="description"><?php _e('What type of data to sync by default', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auth_method"><?php _e('Authentication Method', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="auth_method" id="auth_method">
                            <option value="basic_auth" <?php selected($settings['auth_method'], 'basic_auth'); ?>>
                                <?php _e('HTTP Basic Auth (recommended)', 'wc-multi-store-sync'); ?>
                            </option>
                            <option value="query_string" <?php selected($settings['auth_method'], 'query_string'); ?>>
                                <?php _e('Query String (not recommended — credentials appear in server logs)', 'wc-multi-store-sync'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('How to authenticate with remote stores. Basic Auth is recommended as it keeps credentials out of URLs and server logs.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="match_products_by"><?php _e('Match Products By', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="match_products_by" id="match_products_by">
                            <option value="sku" <?php selected($settings['match_products_by'], 'sku'); ?>>
                                <?php _e('SKU (recommended)', 'wc-multi-store-sync'); ?>
                            </option>
                            <option value="slug" <?php selected($settings['match_products_by'], 'slug'); ?>>
                                <?php _e('Slug', 'wc-multi-store-sync'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('How to match local products with remote products', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="category_match_mode"><?php _e('Category Match Mode', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="category_match_mode" id="category_match_mode">
                            <option value="full_path" <?php selected(isset($settings['category_match_mode']) ? $settings['category_match_mode'] : 'full_path', 'full_path'); ?>>
                                <?php _e('Full Path (all categories in hierarchy)', 'wc-multi-store-sync'); ?>
                            </option>
                            <option value="leaf_only" <?php selected(isset($settings['category_match_mode']) ? $settings['category_match_mode'] : 'full_path', 'leaf_only'); ?>>
                                <?php _e('Leaf Only (only deepest category)', 'wc-multi-store-sync'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('Full Path: syncs all categories in the hierarchy (e.g., Clothing, Men, Shirts). Leaf Only: syncs only the deepest category in each branch (e.g., only Shirts). Use Leaf Only when remote stores have different category structures.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="category_match_by"><?php _e('Category Match By', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="category_match_by" id="category_match_by">
                            <option value="slug" <?php selected(isset($settings['category_match_by']) ? $settings['category_match_by'] : 'slug', 'slug'); ?>>
                                <?php _e('Slug', 'wc-multi-store-sync'); ?>
                            </option>
                            <option value="name" <?php selected(isset($settings['category_match_by']) ? $settings['category_match_by'] : 'slug', 'name'); ?>>
                                <?php _e('Name (supports Cyrillic)', 'wc-multi-store-sync'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('Slug: matches categories by URL slug (e.g., "mens-clothing"). Name: matches by display name (e.g., "Мъжки дрехи"). Use Name when stores have different slugs but identical category names.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="category_auto_create"><?php _e('Auto-create Categories', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="category_auto_create" id="category_auto_create" value="1"
                                   <?php checked(isset($settings['category_auto_create']) ? $settings['category_auto_create'] : true, true); ?>>
                            <?php _e('Create missing categories on remote stores', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When enabled, categories that don\'t exist on remote stores will be created automatically. When disabled, only existing categories will be assigned (missing ones are skipped).', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="stock_sync_enabled"><?php _e('Stock Synchronization', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="stock_sync_enabled" id="stock_sync_enabled" value="1"
                                   <?php checked($settings['stock_sync_enabled'], true); ?>>
                            <?php _e('Enable stock quantity synchronization', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When enabled, stock quantities will be synced to remote stores', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="image_proxy_enabled"><?php _e('API Image Transfer', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="image_proxy_enabled" id="image_proxy_enabled" value="1"
                                   <?php checked(isset($settings['image_proxy_enabled']) ? $settings['image_proxy_enabled'] : false, true); ?>>
                            <?php _e('Transfer images directly through the API instead of URLs', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('Enable this if the site is behind Cloudflare or a firewall that blocks image downloads. Images are read locally and sent as data through the API — the remote store never needs to download images over HTTP.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_create_missing_products"><?php _e('Auto-Create Missing Products', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_create_missing_products" id="auto_create_missing_products" value="1"
                                   <?php checked(isset($settings['auto_create_missing_products']) ? $settings['auto_create_missing_products'] : false, true); ?>>
                            <?php _e('Queue full sync for products not found on remote stores', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When a partial sync (price/quantity) encounters a product that doesn\'t exist on a remote store, automatically queue a full product sync with low priority to create it. Without this option, such products are simply skipped.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_sync_new_products"><?php _e('Auto-Sync New Products', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_sync_new_products" id="auto_sync_new_products" value="1"
                                   <?php checked(isset($settings['auto_sync_new_products']) ? $settings['auto_sync_new_products'] : false, true); ?>>
                            <?php _e('Automatically sync newly created products', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When a new product is created and matches the category/tag filters for a store, automatically queue it for synchronization. Works independently of "Auto-Sync on Save".', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_sync_deletions"><?php _e('Product Deletion Sync', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_sync_deletions" id="auto_sync_deletions" value="1"
                                   <?php checked(isset($settings['auto_sync_deletions']) ? $settings['auto_sync_deletions'] : false, true); ?>>
                            <?php _e('Automatically delete products and variations from remote stores', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When a product or variation is deleted on the main store, automatically delete it from all synced remote stores (respects category/tag exclusions)', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="deletion_mode"><?php _e('Deletion Mode', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="deletion_mode" id="deletion_mode">
                            <option value="trash" <?php selected(isset($settings['deletion_mode']) ? $settings['deletion_mode'] : 'trash', 'trash'); ?>>
                                <?php _e('Move to Trash (reversible)', 'wc-multi-store-sync'); ?>
                            </option>
                            <option value="force" <?php selected(isset($settings['deletion_mode']) ? $settings['deletion_mode'] : 'trash', 'force'); ?>>
                                <?php _e('Delete Permanently (not reversible)', 'wc-multi-store-sync'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('How to delete products on remote stores', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="delete_orphan_variations"><?php _e('Delete Orphan Variations', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="delete_orphan_variations" id="delete_orphan_variations" value="1"
                                   <?php checked(isset($settings['delete_orphan_variations']) ? $settings['delete_orphan_variations'] : false, true); ?>>
                            <?php _e('Delete variations on remote stores that do not exist locally', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When enabled, during variation sync, any variations on the remote store that do not have a matching SKU in the source store will be deleted. This keeps variation counts in sync and removes "ghost" variations.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_sync_restorations"><?php _e('Product Restoration Sync', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_sync_restorations" id="auto_sync_restorations" value="1"
                                   <?php checked(isset($settings['auto_sync_restorations']) ? $settings['auto_sync_restorations'] : false, true); ?>>
                            <?php _e('Automatically restore products on remote stores', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When a product is restored from trash on the main store, automatically restore it on all synced remote stores (respects category/tag exclusions)', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_sync_status"><?php _e('Product Status Change Sync', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_sync_status" id="auto_sync_status" value="1"
                                   <?php checked(isset($settings['auto_sync_status']) ? $settings['auto_sync_status'] : false, true); ?>>
                            <?php _e('Sync product status changes (draft ↔ published ↔ private)', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('When a product status changes on the main store, automatically update the status on all synced remote stores (respects category/tag exclusions)', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php _e('Performance Settings', 'wc-multi-store-sync'); ?></h2>
            <p class="description">
                <?php _e('Control how many products are synced per batch. Higher values = faster sync but more server load.', 'wc-multi-store-sync'); ?>
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
                        <label for="circuit_breaker_threshold"><?php _e('Circuit Breaker Threshold', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="circuit_breaker_threshold" id="circuit_breaker_threshold"
                               value="<?php echo esc_attr($cb_threshold); ?>"
                               min="1" max="100" step="1" class="small-text">
                        <span><?php _e('consecutive errors', 'wc-multi-store-sync'); ?></span>
                        <p class="description"><?php _e('Number of consecutive failures to a store before pausing requests. Default: 10', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="circuit_breaker_duration"><?php _e('Circuit Breaker Duration', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="circuit_breaker_duration" id="circuit_breaker_duration"
                               value="<?php echo esc_attr($cb_duration); ?>"
                               min="60" max="86400" step="60" class="small-text">
                        <span><?php _e('seconds', 'wc-multi-store-sync'); ?></span>
                        <p class="description"><?php printf(__('How long to pause requests to a failing store. Default: 1800 (30 minutes). Current: %d min.', 'wc-multi-store-sync'), round($cb_duration / 60)); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="batch_size_peak"><?php _e('Batch Size (Peak Hours)', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="batch_size_peak" id="batch_size_peak"
                               value="<?php echo esc_attr($batch_size_peak); ?>"
                               min="1" max="500" step="1" class="small-text">
                        <span><?php _e('products per batch', 'wc-multi-store-sync'); ?></span>
                        <p class="description"><?php _e('Number of products to sync per run during peak hours (9am-6pm). Default: 5', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="batch_size_offpeak"><?php _e('Batch Size (Off-Peak Hours)', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="batch_size_offpeak" id="batch_size_offpeak"
                               value="<?php echo esc_attr($batch_size_offpeak); ?>"
                               min="1" max="500" step="1" class="small-text">
                        <span><?php _e('products per batch', 'wc-multi-store-sync'); ?></span>
                        <p class="description"><?php _e('Number of products to sync per run during off-peak hours (6pm-9am). Default: 20', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php _e('Scheduled Sync', 'wc-multi-store-sync'); ?></h2>
            <p class="description">
                <?php _e('Automatic background sync that periodically checks for products to synchronize. This is a "catch-all" mechanism for products that may have been missed by real-time sync.', 'wc-multi-store-sync'); ?>
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
                        <label for="scheduled_sync_enabled"><?php _e('Enable Scheduled Sync', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="scheduled_sync_enabled" id="scheduled_sync_enabled" value="1" <?php checked($scheduled_sync_enabled, true); ?>>
                            <?php _e('Enable automatic scheduled sync', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description">
                            <?php _e('If disabled, only real-time sync (on product save, stock change, etc.) will be active. Disable this if real-time sync is working well and you want to reduce server load.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>
                <tr class="scheduled-sync-option" <?php echo !$scheduled_sync_enabled ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label for="scheduled_sync_interval"><?php _e('Sync Interval', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <select name="scheduled_sync_interval" id="scheduled_sync_interval">
                            <option value="10min" <?php selected($scheduled_sync_interval, '10min'); ?>><?php _e('Every 10 minutes', 'wc-multi-store-sync'); ?></option>
                            <option value="30min" <?php selected($scheduled_sync_interval, '30min'); ?>><?php _e('Every 30 minutes', 'wc-multi-store-sync'); ?></option>
                            <option value="hourly" <?php selected($scheduled_sync_interval, 'hourly'); ?>><?php _e('Every hour', 'wc-multi-store-sync'); ?></option>
                            <option value="daily" <?php selected($scheduled_sync_interval, 'daily'); ?>><?php _e('Once daily (recommended)', 'wc-multi-store-sync'); ?></option>
                        </select>
                        <p class="description">
                            <?php _e('How often to run the scheduled sync. "Once daily" is recommended if real-time sync is working properly.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>
                <tr class="scheduled-sync-option" <?php echo !$scheduled_sync_enabled ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label><?php _e('Products to Sync', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="sync_all_products" value="1" <?php checked($sync_all_products, true); ?>>
                                <?php _e('All products', 'wc-multi-store-sync'); ?>
                                <span class="description" style="color: #d63638;"><?php _e('(Not recommended - can cause queue overload)', 'wc-multi-store-sync'); ?></span>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="sync_all_products" value="0" <?php checked($sync_all_products, false); ?>>
                                <?php _e('Only recently modified products', 'wc-multi-store-sync'); ?>
                                <span class="description" style="color: #00a32a;"><?php _e('(Recommended)', 'wc-multi-store-sync'); ?></span>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr id="sync_modified_hours_row" class="scheduled-sync-option" style="<?php echo (!$scheduled_sync_enabled || $sync_all_products) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="sync_modified_hours"><?php _e('Modified Within', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="sync_modified_hours" id="sync_modified_hours"
                               value="<?php echo esc_attr($sync_modified_hours); ?>"
                               min="1" max="168" step="1" class="small-text">
                        <span><?php _e('hours', 'wc-multi-store-sync'); ?></span>
                        <p class="description">
                            <?php _e('Only sync products modified within this time window. Default: 24 hours.', 'wc-multi-store-sync'); ?>
                            <br>
                            <strong><?php _e('What counts as "modified":', 'wc-multi-store-sync'); ?></strong>
                            <?php _e('Product saved, stock changed, price updated, status changed, categories/tags modified.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php _e('Sync by Category', 'wc-multi-store-sync'); ?></h2>
            <p class="description">
                <?php _e('Queue all products in a category (and optionally its children) for immediate synchronisation.', 'wc-multi-store-sync'); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wc-mss-cat-sync-category"><?php _e('Category', 'wc-multi-store-sync'); ?></label></th>
                    <td>
                        <?php
                        wp_dropdown_categories([
                            'taxonomy'         => 'product_cat',
                            'name'             => 'wc_mss_cat_sync_category',
                            'id'               => 'wc-mss-cat-sync-category',
                            'show_option_none' => __('— Select a category —', 'wc-multi-store-sync'),
                            'option_none_value'=> '',
                            'hide_empty'       => false,
                            'hierarchical'     => true,
                        ]);
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wc-mss-cat-sync-type"><?php _e('Sync Type', 'wc-multi-store-sync'); ?></label></th>
                    <td>
                        <select id="wc-mss-cat-sync-type">
                            <?php
                            WC_Multi_Store_Sync_Type_Options::render([
                                'full_product' => __('Full Product', 'wc-multi-store-sync'),
                                'price_quantity' => __('Price & Quantity', 'wc-multi-store-sync'),
                                'price_quantity_categories' => __('Price, Quantity & Categories', 'wc-multi-store-sync'),
                                'quantity' => __('Quantity only', 'wc-multi-store-sync'),
                            ]);
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Include Children', 'wc-multi-store-sync'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wc-mss-cat-sync-children" checked>
                            <?php _e('Also sync products in child categories', 'wc-multi-store-sync'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"></th>
                    <td>
                        <button type="button" class="button button-primary" id="wc-mss-cat-sync-btn"
                            data-msg-select-category="<?php echo esc_attr__('Please select a category.', 'wc-multi-store-sync'); ?>"
                            data-msg-queuing="<?php echo esc_attr__('Queuing…', 'wc-multi-store-sync'); ?>"
                            data-msg-error="<?php echo esc_attr__('Error', 'wc-multi-store-sync'); ?>">
                            <?php _e('Queue Category Sync', 'wc-multi-store-sync'); ?>
                        </button>
                        <span id="wc-mss-cat-sync-result" style="margin-left:12px;"></span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php _e('Queue Status', 'wc-multi-store-sync'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Estimated Sync Time', 'wc-multi-store-sync'); ?></th>
                    <td>
                        <?php
                        $product_count = wp_count_posts('product');
                        $total_products = isset($product_count->publish) ? (int) $product_count->publish : 0;
                        $queue_count = class_exists('WC_Multi_Store_Queue_Table') ? (WC_Multi_Store_Queue_Table::get_stats()['pending'] ?? 0) : 0;

                        if ($queue_count > 0) {
                            $peak_hours = ceil($queue_count / $batch_size_peak) * 5 / 60;
                            $offpeak_hours = ceil($queue_count / $batch_size_offpeak) * 5 / 60;
                            printf(
                                __('<strong>%d products in queue</strong><br>Peak: ~%.1f hours | Off-peak: ~%.1f hours', 'wc-multi-store-sync'),
                                $queue_count,
                                $peak_hours,
                                $offpeak_hours
                            );
                        } else {
                            _e('Queue is empty', 'wc-multi-store-sync');
                        }
                        ?>
                        <p class="description"><?php _e('Based on current batch sizes and 5-minute intervals', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php _e('Order-Based Stock Sync (Webhooks)', 'wc-multi-store-sync'); ?></h2>
            <p class="description">
                <?php _e('Automatically reduce stock on the main store when orders are placed on remote stores via webhooks.', 'wc-multi-store-sync'); ?>
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
                        <label for="webhook_enabled"><?php _e('Enable Webhook Receiver', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="webhook_enabled" id="webhook_enabled" value="1"
                                   <?php checked($webhook_settings['enabled'], true); ?>>
                            <?php _e('Enable order-based stock synchronization', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When enabled, the main store will receive webhooks from remote stores when orders are placed, and automatically reduce stock.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="webhook_secret"><?php _e('Webhook Secret', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="password" name="webhook_secret" id="webhook_secret" class="regular-text code"
                               value=""
                               placeholder="<?php echo !empty($webhook_settings['webhook_secret']) ? esc_attr__('Already set — leave blank to keep', 'wc-multi-store-sync') : ''; ?>"
                               autocomplete="new-password" />
                        <button type="button" class="button button-small" onclick="var f = document.getElementById('webhook_secret'); f.type = f.type === 'password' ? 'text' : 'password';">
                            <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
                        </button>
                        <button type="button" id="generate-secret" class="button">
                            <?php _e('Generate Secret', 'wc-multi-store-sync'); ?>
                        </button>
                        <p class="description">
                            <?php _e('Secret key for authenticating webhook requests. Click "Generate Secret" to create a random secure key.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php _e('Trigger Order Statuses', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <?php
                        $order_statuses = [
                            'pending' => __('Pending Payment', 'wc-multi-store-sync'),
                            'processing' => __('Processing', 'wc-multi-store-sync'),
                            'on-hold' => __('On Hold', 'wc-multi-store-sync'),
                            'completed' => __('Completed', 'wc-multi-store-sync'),
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
                            <?php _e('Which order statuses should trigger stock deduction. Recommended: Processing and Completed.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="allow_negative_stock"><?php _e('Allow Negative Stock', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="allow_negative_stock" id="allow_negative_stock" value="1"
                                   <?php checked($webhook_settings['allow_negative_stock'], true); ?>>
                            <?php _e('Allow stock to go negative', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description">
                            <?php _e('If disabled, webhook will fail when trying to reduce stock below zero.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="auto_verify"><?php _e('Auto-Verify Stock', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_verify" id="auto_verify" value="1"
                                   <?php checked($webhook_settings['auto_verify'], true); ?>>
                            <?php _e('Automatically verify stock after sync', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description">
                            <?php _e('After syncing stock to remote stores, verify that the actual stock matches expected values.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="webhook_log_retention_days"><?php _e('Webhook Log Retention', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="webhook_log_retention_days" id="webhook_log_retention_days"
                               value="<?php echo esc_attr(isset($webhook_settings['webhook_log_retention_days']) ? $webhook_settings['webhook_log_retention_days'] : 90); ?>"
                               min="30" max="180" step="1" class="small-text" /> <?php _e('days', 'wc-multi-store-sync'); ?>
                        <p class="description">
                            <?php _e('How long to keep webhook logs (30-180 days). Logs are automatically cleaned up daily.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php _e('Webhook Logs', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <button type="button" id="view-webhook-logs" class="button">
                            <?php _e('View Webhook Logs', 'wc-multi-store-sync'); ?>
                        </button>
                        <button type="button" id="export-webhook-logs" class="button">
                            <?php _e('Export to CSV', 'wc-multi-store-sync'); ?>
                        </button>
                        <p class="description">
                            <?php _e('View detailed logs of all webhook activity including stock changes, errors, and sync events.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>

                <?php if (!empty($webhook_settings['enabled'])): ?>
                <tr>
                    <th scope="row">
                        <label><?php _e('Webhook URLs', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <p class="description" style="margin-bottom: 10px;">
                            <?php _e('Use these URLs when configuring webhooks on your remote WooCommerce stores:', 'wc-multi-store-sync'); ?>
                        </p>

                        <?php $stores = WC_Multi_Store_Settings::get_stores(); ?>
                        <?php if (empty($stores)): ?>
                            <p><em><?php _e('No stores configured yet.', 'wc-multi-store-sync'); ?></em></p>
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
                                            <?php _e('Copy URL', 'wc-multi-store-sync'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <p class="description">
                            <?php _e('See documentation below for instructions on setting up webhooks on remote stores.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php _e('Additional Sync Features', 'wc-multi-store-sync'); ?></h2>
            <p class="description">
                <?php _e('Enable or disable additional sync modules. Each feature operates independently and can be configured separately.', 'wc-multi-store-sync'); ?>
            </p>

            <?php
            $shipping_class_settings = WC_Multi_Store_Shipping_Class_Sync::get_settings();
            $coupon_settings = get_option('wc_multi_store_sync_coupon_settings', ['enabled' => false]);
            $download_settings = WC_Multi_Store_Downloadable_Files_Sync::get_settings();
            $category_mapper_settings = WC_Multi_Store_Category_Mapper::get_settings();
            $attribute_remapper_settings = WC_Multi_Store_Attribute_Remapper::get_settings();
            $conflict_settings = WC_Multi_Store_Conflict_Detector::get_settings();
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php _e('Shipping Class Sync', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_shipping_class_sync"
                                   <?php checked(!empty($shipping_class_settings['enabled'])); ?>>
                            <?php _e('Sync shipping classes to remote stores', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('Automatically create, update, and delete shipping classes on remote stores when they change locally.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php _e('Coupon Sync', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_coupon_sync"
                                   <?php checked(!empty($coupon_settings['enabled'])); ?>>
                            <?php _e('Sync coupons/discounts to remote stores', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('Automatically sync coupon codes, discount rules, and restrictions. Product/category IDs are resolved via SKU/slug across stores.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php _e('Downloadable Files Sync', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_downloadable_files_sync"
                                   <?php checked(!empty($download_settings['enabled'])); ?>>
                            <?php _e('Sync downloadable product files', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('Include downloadable files when syncing digital products. Supports URL-based sync or direct file transfer via Media API.', 'wc-multi-store-sync'); ?></p>
                        <?php if (!empty($download_settings['enabled'])): ?>
                        <div style="margin-top: 8px; padding: 8px; background: #f0f0f1; border-radius: 4px;">
                            <label>
                                <strong><?php _e('Transfer Mode:', 'wc-multi-store-sync'); ?></strong>
                                <select class="wc-mss-feature-setting" data-option="wc_mss_downloadable_files_sync_settings" data-key="transfer_mode" style="margin-left: 5px;">
                                    <option value="url" <?php selected($download_settings['transfer_mode'] ?? 'url', 'url'); ?>><?php _e('URL (remote downloads from source)', 'wc-multi-store-sync'); ?></option>
                                    <option value="api" <?php selected($download_settings['transfer_mode'] ?? 'url', 'api'); ?>><?php _e('API Transfer (upload files directly)', 'wc-multi-store-sync'); ?></option>
                                </select>
                            </label>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php _e('Category Mapping', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_category_mapper"
                                   <?php checked(!empty($category_mapper_settings['enabled'])); ?>>
                            <?php _e('Enable per-store category/tag mapping', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description">
                            <?php printf(
                                /* translators: %s: link to the Category Mapping tab */
                                __('Map local categories to different remote categories per store (e.g., "Дрехи" → "Clothing"). Enter mappings in the %s tab.', 'wc-multi-store-sync'),
                                '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=category-mapping')) . '">' . __('Category Mapping', 'wc-multi-store-sync') . '</a>'
                            ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php _e('Attribute Remapping', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_attribute_remapping"
                                   <?php checked(!empty($attribute_remapper_settings['enabled'])); ?>>
                            <?php _e('Enable per-store attribute name/value remapping', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description">
                            <?php printf(
                                /* translators: %s: link to the Attribute Mapping tab */
                                __('Remap attribute names and values per store (e.g., "Цвят" → "Color", "Червен" → "Red"). Useful for multilingual stores. Enter mappings in the %s tab.', 'wc-multi-store-sync'),
                                '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=attribute-mapping')) . '">' . __('Attribute Mapping', 'wc-multi-store-sync') . '</a>'
                            ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php _e('Conflict Detection', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label class="wc-mss-toggle">
                            <input type="checkbox" class="wc-mss-feature-toggle"
                                   data-action="wc_mss_toggle_conflict_detection"
                                   <?php checked(!empty($conflict_settings['enabled'])); ?>>
                            <?php _e('Detect remote product changes before overwriting', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('Tracks remote product state and warns when someone manually edited a product on a remote store. Prevents accidental data loss.', 'wc-multi-store-sync'); ?></p>
                        <?php
                        $conflict_stats = WC_Multi_Store_Conflict_Detector::get_stats();
                        if ($conflict_stats['unresolved'] > 0): ?>
                        <p style="margin-top: 5px; color: #d63638;">
                            <strong>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=multi_store_sync&section=conflicts')); ?>" style="color: #d63638;">
                                    <?php printf(__('%d unresolved conflict(s)', 'wc-multi-store-sync'), $conflict_stats['unresolved']); ?>
                                </a>
                            </strong>
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card">
            <h2><?php _e('Email Notifications', 'wc-multi-store-sync'); ?></h2>
            <p class="description">
                <?php _e('Configure email alerts for sync events. All instant notifications are rate-limited to once per day to avoid inbox flooding.', 'wc-multi-store-sync'); ?>
            </p>

            <?php
            $email_settings = WC_Multi_Store_Email_Notifications::get_settings();
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="email_notifications_enabled"><?php _e('Enable Email Notifications', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="email_notifications_enabled" id="email_notifications_enabled" value="1"
                                   <?php checked(!empty($email_settings['enabled'])); ?>>
                            <?php _e('Send email notifications for sync events', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description"><?php _e('Master switch — disabling this suppresses all notification types below.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="email_recipient"><?php _e('Recipient Email', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="email" name="email_recipient" id="email_recipient" class="regular-text"
                               value="<?php echo esc_attr($email_settings['recipient_email']); ?>">
                        <p class="description"><?php _e('Address that receives all notifications. Defaults to the site admin email.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php _e('Notification Types', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="email_failed_sync_enabled" value="1"
                                       <?php checked(!empty($email_settings['failed_sync_enabled'])); ?>>
                                <?php _e('Failed sync alerts (once per day)', 'wc-multi-store-sync'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="email_api_error_enabled" value="1"
                                       <?php checked(!empty($email_settings['api_error_enabled'])); ?>>
                                <?php _e('API error alerts (once per day)', 'wc-multi-store-sync'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="email_low_stock_enabled" value="1"
                                       <?php checked(!empty($email_settings['low_stock_enabled'])); ?>>
                                <?php _e('Low stock alerts (once per day)', 'wc-multi-store-sync'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="email_daily_summary_enabled" value="1"
                                       <?php checked(!empty($email_settings['daily_summary_enabled'])); ?>>
                                <?php _e('Daily sync summary', 'wc-multi-store-sync'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="email_low_stock_threshold"><?php _e('Low Stock Threshold', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="email_low_stock_threshold" id="email_low_stock_threshold"
                               value="<?php echo esc_attr($email_settings['low_stock_threshold']); ?>"
                               min="0" max="9999" step="1" class="small-text">
                        <span><?php _e('units', 'wc-multi-store-sync'); ?></span>
                        <p class="description"><?php _e('Send low-stock alert when stock drops to or below this value.', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="email_daily_summary_time"><?php _e('Daily Summary Time', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <input type="time" name="email_daily_summary_time" id="email_daily_summary_time"
                               value="<?php echo esc_attr($email_settings['daily_summary_time']); ?>">
                        <p class="description"><?php _e('Time of day to send the daily summary (server timezone).', 'wc-multi-store-sync'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wc-mss-card" style="border-left: 4px solid #d63638;">
            <h2><?php _e('Uninstall', 'wc-multi-store-sync'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="cleanup_on_uninstall"><?php _e('Delete data on uninstall', 'wc-multi-store-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="cleanup_on_uninstall" id="cleanup_on_uninstall" value="1"
                                   <?php checked($settings['cleanup_on_uninstall'] ?? false, true); ?>>
                            <?php _e('Remove all plugin data when the plugin is deleted', 'wc-multi-store-sync'); ?>
                        </label>
                        <p class="description" style="color: #d63638;">
                            <?php _e('When enabled and the plugin is deleted via the WordPress plugins screen, all custom database tables and plugin options will be permanently removed. <strong>This cannot be undone.</strong> Leave disabled (default) to preserve your data if you reinstall the plugin later.', 'wc-multi-store-sync'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button type="submit" name="wc_mss_save_settings" class="button button-primary">
                <?php _e('Save Settings', 'wc-multi-store-sync'); ?>
            </button>
        </p>
    </form>

        </div><!-- .wc-mss-settings-main -->

        <div class="wc-mss-settings-sidebar">
            <div class="wc-mss-card">
                <h3><?php _e('Sync Types', 'wc-multi-store-sync'); ?></h3>
                <ul>
                    <li><strong><?php _e('Full Product', 'wc-multi-store-sync'); ?></strong> - <?php _e('All data: name, description, prices, stock, images, categories, tags, attributes.', 'wc-multi-store-sync'); ?></li>
                    <li><strong><?php _e('Price, Qty & Categories', 'wc-multi-store-sync'); ?></strong> - <?php _e('Prices, stock, status, categories, tags. No description/images.', 'wc-multi-store-sync'); ?></li>
                    <li><strong><?php _e('Price & Quantity', 'wc-multi-store-sync'); ?></strong> - <?php _e('Only prices and stock quantities.', 'wc-multi-store-sync'); ?></li>
                    <li><strong><?php _e('Quantity Only', 'wc-multi-store-sync'); ?></strong> - <?php _e('Only stock quantities. Fastest option.', 'wc-multi-store-sync'); ?></li>
                </ul>
            </div>

            <div class="wc-mss-card">
                <h3><?php _e('Webhook Setup', 'wc-multi-store-sync'); ?></h3>
                <p><?php _e('Configure webhooks on remote stores:', 'wc-multi-store-sync'); ?></p>
                <ol>
                    <li><?php _e('Go to WooCommerce → Settings → Advanced → Webhooks', 'wc-multi-store-sync'); ?></li>
                    <li><?php _e('Add webhook with Topic: "Order updated"', 'wc-multi-store-sync'); ?></li>
                    <li><?php _e('Copy Delivery URL from above', 'wc-multi-store-sync'); ?></li>
                    <li><?php _e('Use same Secret key', 'wc-multi-store-sync'); ?></li>
                    <li><?php _e('Set API Version: WP REST API v3', 'wc-multi-store-sync'); ?></li>
                </ol>
                <div class="wc-mss-webhook-note">
                    <strong><?php _e('Note:', 'wc-multi-store-sync'); ?></strong>
                    <?php _e('Secret must match on both sides.', 'wc-multi-store-sync'); ?>
                </div>
            </div>
        </div><!-- .wc-mss-settings-sidebar -->

    </div><!-- .wc-mss-settings-layout -->
</div>
