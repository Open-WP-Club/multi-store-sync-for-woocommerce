# Developer Documentation - WooCommerce Multi-Store Sync

## Table of Contents
1. [Architecture Overview](#architecture-overview)
2. [Core Classes](#core-classes)
3. [Hooks and Filters](#hooks-and-filters)
4. [API Reference](#api-reference)
5. [Database Schema](#database-schema)
6. [Extending the Plugin](#extending-the-plugin)
7. [Development Setup](#development-setup)
8. [Code Standards](#code-standards)

---

## Architecture Overview

### Plugin Structure

A representative subset — `includes/` has 57 files in total (one class per file, autoloaded via Composer's classmap autoloader — `composer.json`'s `"classmap": ["includes/"]`). There is no `includes/admin.php`; the admin UI is a WooCommerce Settings tab (`wc-settings-integration.php`), not a separate top-level admin page.

```
wc-multi-store-sync/
├── wc-multi-store-sync.php          # Main plugin file
├── includes/                         # Core classes
│   ├── api-client.php               # WooCommerce REST API client
│   ├── sync-engine.php              # Product sync logic
│   ├── settings.php                 # Settings management
│   ├── logger.php                   # Logging system
│   ├── scheduler.php                # Thin Action Scheduler wrapper
│   ├── queue-manager.php            # Queue system
│   ├── time-manager.php             # Peak/off-peak management
│   ├── order-sync.php               # Order synchronization
│   ├── hooks.php                    # WordPress hooks
│   ├── product-edit.php             # Product edit page integration
│   ├── bulk-actions.php             # Bulk actions handler
│   ├── sync-history.php             # Sync history tracking
│   ├── cache-manager.php            # Caching system
│   ├── performance-monitor.php      # Performance tracking
│   └── wc-settings-integration.php  # WooCommerce settings tab (admin UI router)
├── admin/
│   ├── views/                       # Admin page templates (rendered by wc-settings-integration.php)
│   │   ├── dashboard.php
│   │   ├── stores.php
│   │   ├── settings.php
│   │   └── logs.php
│   ├── css/
│   │   └── admin-styles.css
│   └── js/
│       └── admin-scripts.js
```

Log files live outside the plugin folder entirely, in `wp-content/uploads/wc-mss-logs/sync.log` — see [Debugging](#debugging) below. For the full, audited file listing see `.claude.md` in the repo root.

### Design Patterns

**Singleton Pattern:**
- Main plugin class (`WC_Multi_Store_Sync`)
- All core classes accessible via main instance

**Factory Pattern:**
- API Client creation
- Logger instance creation

**Observer Pattern:**
- WordPress hooks system
- Event-driven sync triggers

**Strategy Pattern:**
- Sync type selection (Full, Price & Stock, Stock Only)
- Authentication method selection

---

## Core Classes

### WC_Multi_Store_Sync (Main Class)

**Purpose:** Main plugin controller and singleton instance

**Location:** `wc-multi-store-sync.php`

**Key Methods:**
```php
// Get singleton instance
WC_Multi_Store_Sync::instance();

// Access via global function (equivalent to instance())
WC_MSS();
```

**Public Properties:**
```php
$api_client         // WC_Multi_Store_API_Client instance for the settings-configured connection
$sync_engine        // WC_Multi_Store_Sync_Engine instance
$scheduler          // WC_Multi_Store_Scheduler instance
$order_sync         // WC_Multi_Store_Order_Sync instance
$hooks              // WC_Multi_Store_Hooks instance
$queue_manager      // WC_Multi_Store_Queue_Manager instance
```

---

### WC_Multi_Store_API_Client

**Purpose:** WooCommerce REST API v3 communication with a single remote store

**Location:** `includes/api-client.php`

**Initialization:**
```php
$api = new WC_Multi_Store_API_Client(
    store_url: $url,
    consumer_key: $consumer_key,
    consumer_secret: $consumer_secret,
    auth_method: 'query_string', // or 'basic_auth'
);
```

**Product Methods:**
```php
// Get products by search
$products = $api->get_products($search, $search_type, $params);

// Get single product
$product = $api->get_product($product_id);

// Create product
$result = $api->create_product($data);

// Update product
$result = $api->update_product($product_id, $data);

// Delete product
$result = $api->delete_product($product_id, $force);

// Get all products across pages, or stream page-by-page for large catalogs
$all = $api->get_all_products($params, $max_pages);
$api->stream_products($params, function (array $page) { /* ... */ }, $max_pages);
```

**Variation Methods:**
```php
// Get product variations
$variations = $api->get_product_variations($product_id, $params);

// Create variation
$result = $api->create_product_variation($product_id, $data);
```

**Category/Tag Methods:**
```php
// Get categories
$categories = $api->get_categories($slug, $params);

// Create category
$result = $api->create_category($data);

// Get tags
$tags = $api->get_tags($slug, $params);

// Create tag
$result = $api->create_tag($data);
```

**Utility Methods:**
```php
// Test API connection
$result = $api->test_connection();

// Upload an image via the WordPress REST Media API (bypasses WAF/Cloudflare blocks on direct download)
$result = $api->upload_image($image_info);
```

Most resources also expose `create_*`, `update_*`, `delete_*`, and batch variants (`batch_products()`, `batch_categories()`, `batch_tags()`, `batch_product_variations()`). See `includes/api-client.php` for the full method list — it's a fairly complete WooCommerce REST client, and not every method is exercised by the plugin's own sync features.

---

### WC_Multi_Store_Sync_Engine

**Purpose:** Core product synchronization logic

**Location:** `includes/sync-engine.php`

**Main Method:**
```php
/**
 * Sync a product to one or more stores
 *
 * @param int    $product_id  Product ID
 * @param array  $stores      Array of store configs, keyed by store URL (as returned by WC_Multi_Store_Settings::get_active_stores())
 * @param string $sync_type   'full_product', 'price_quantity', or 'quantity'
 * @return array Results keyed by store URL
 */
$results = $sync_engine->sync_product($product_id, $stores, $sync_type);
```

**Response Format:**

If the product ID doesn't resolve to a real product, the return is a single error shape:

```php
array(
    'success' => false,
    'message' => 'Product not found',
)
```

Otherwise, the return is an array keyed by store URL (no top-level `success`/`results` wrapper):

```php
array(
    'https://store-a.com' => array(
        'success' => true,
        'message' => 'Product synced successfully',
        'remote_id' => 123,       // Remote product ID
    ),
    'https://store-b.com' => array(
        'success' => false,
        'message' => 'Product excluded by category/tag filter',
        'excluded' => true,
    ),
)
```

**Other Public Methods:**
```php
// Sync a single product to a single store directly
$result = $sync_engine->sync_product_to_store($product, $store_url, $store_config, $sync_type);

// Sync many products at once
$results = $sync_engine->bulk_sync_products($product_ids, $stores, $sync_type);

// Deletions / restorations / status changes propagate the same way
$sync_engine->delete_product_from_store($product_id, $product_sku, $store_url, $store_config);
$sync_engine->restore_product_on_store($product_id, $product_sku, $store_url, $store_config);
```

---

### WC_Multi_Store_Settings

**Purpose:** Settings management and store configuration

**Location:** `includes/settings.php`

**Static Methods:**
```php
// Get main settings
$settings = WC_Multi_Store_Settings::get_settings();

// Get all stores
$stores = WC_Multi_Store_Settings::get_stores();

// Get active stores only (status === 'active')
$active_stores = WC_Multi_Store_Settings::get_active_stores();

// Get a single store's config, or null if it doesn't exist
$store = WC_Multi_Store_Settings::get_store($store_url);

// Create or update a store (validates the URL; returns WP_Error on failure)
WC_Multi_Store_Settings::update_store($store_url, $config);

// Delete a store
WC_Multi_Store_Settings::delete_store($store_url);
```

Category/tag exclusion per store is handled separately, by `WC_Multi_Store_Product_Exclusion_Filter`:

```php
$excluded = WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, $store_config);
```

---

### WC_Multi_Store_Queue_Manager

**Purpose:** Queue management for batch processing

**Location:** `includes/queue-manager.php`

This is an **instance** class, not static — create one (or use `WC_MSS()->queue_manager`):

```php
$queue = new WC_Multi_Store_Queue_Manager();
// or: $queue = WC_MSS()->queue_manager;

// Add a product to the queue
// $priority: PRIORITY_CRITICAL (1), PRIORITY_HIGH (2), PRIORITY_NORMAL (3), PRIORITY_LOW (10, default)
$queue->add_product($product_id, $trigger = 'manual', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH);

// Add many products at once
$queue->add_products($product_ids, 'bulk_action', WC_Multi_Store_Queue_Manager::PRIORITY_LOW);

// Process a batch right now (normally run every minute by Action Scheduler)
$results = $queue->process_queue($batch_size = 30);

// Queue introspection
$count = $queue->get_queue_count();
$stats = $queue->get_statistics();
$is_queued = $queue->is_queued($product_id);

// Remove / clear
$queue->remove_product($product_id);
$queue->clear_queue();
```

---

### WC_Multi_Store_Logger

**Purpose:** Logging and error tracking (implements PSR-3 `LoggerInterface` via `AbstractLogger`)

**Location:** `includes/logger.php`

**Methods:**
```php
// Preferred: static write() used throughout the codebase
WC_Multi_Store_Logger::write('Something happened', 'info');   // level: 'info' | 'warning' | 'error'
WC_Multi_Store_Logger::write('Product synced', 'info', 'sync'); // optional $context

// PSR-3 style also works, since the class extends AbstractLogger
$logger = WC_Multi_Store_Logger::instance();
$logger->info('Info message');
$logger->warning('Warning message');
$logger->error('Error message');

// Convenience helper for per-product/per-store sync log lines
$logger->log_product_sync($product_id, 'sync', $store_url, $success, $message);

// Read / clear the on-disk log file
$contents = $logger->get_log(100); // last 100 lines
$logger->clear_log();
```

---

### WC_Multi_Store_Sync_History

**Purpose:** Track sync history in a dedicated database table

**Location:** `includes/sync-history.php`

**Methods:**
```php
// Log a sync record
WC_Multi_Store_Sync_History::log_sync(array(
    'product_id'  => 123,
    'product_sku' => 'ABC123',
    'store_url'   => 'https://store.com',
    'sync_type'   => 'full_product',
    'status'      => 'success',
    'duration_ms' => 2500,
    'memory_mb'   => 10.0,
    'api_calls'   => 5,
));

// Get history — $args supports limit, offset, product_id, store_url, status, sync_type, date_from, date_to, orderby, order
$history = WC_Multi_Store_Sync_History::get_history(['status' => 'error', 'limit' => 20]);

// Get statistics — $args supports days (default 7) and store_url
$stats = WC_Multi_Store_Sync_History::get_statistics(['days' => 30]);
```

---

### WC_Multi_Store_Cache_Manager

**Purpose:** Transient-based caching for remote product/variation/taxonomy lookups

**Location:** `includes/cache-manager.php`

This class is **fully static** — there is no `instance()` singleton.

```php
// Get / set a cached remote product (matched by SKU or slug, per $match_by)
$product = WC_Multi_Store_Cache_Manager::get_remote_product($store_url, $search_value, $match_by);
WC_Multi_Store_Cache_Manager::set_remote_product($store_url, $search_value, $match_by, $product_data);

// Invalidate a single cached product
WC_Multi_Store_Cache_Manager::delete_remote_product($store_url, $search_value, $match_by);

// Taxonomy term cache
$terms = WC_Multi_Store_Cache_Manager::get_taxonomy_terms($taxonomy, $term_ids);

// Clear everything, or just one store's cache
WC_Multi_Store_Cache_Manager::clear_all();
WC_Multi_Store_Cache_Manager::clear_store_cache($store_url);

// Stats / warmup
$stats = WC_Multi_Store_Cache_Manager::get_statistics();
WC_Multi_Store_Cache_Manager::warmup();
```

---

## Hooks and Filters

This is the plugin's **complete** custom hook surface — every `wc_mss_*` action and filter that actually exists in the code. There's no generic "before/after sync" or "modify batch size" hook system; if you need an extension point that isn't listed here, either use one of the WordPress/WooCommerce hooks the plugin itself listens to (see below), or add your own hook in the relevant `includes/*.php` file and send a PR.

### Action Hooks

```php
// Fired after every outbound API call — used internally for usage stats (api-usage-tracker.php, api-client.php)
do_action('wc_mss_api_request', $store_url, $endpoint, $method, $response_data);

// Product sync failure — used internally to trigger the failure email (email-notifications.php)
// $error_message is always a plain string, not a WP_Error
do_action('wc_mss_sync_failed', $product_id, $store_url, $error_message);

// API-level error — used internally to trigger the error email (email-notifications.php)
do_action('wc_mss_api_error', $store_url, $error_message);

// Stock fell below the configured low-stock threshold (email-notifications.php)
do_action('wc_mss_low_stock_detected', $product_id, $store_url, $stock_quantity);

// An item was moved to the Dead Letter Queue after exhausting retries.
// No internal code hooks this — it's a free extension point for custom alerting/logging.
do_action('wc_mss_dead_letter_added', $queue_item);
```

The plugin also fires (and, via Action Scheduler, consumes) a few internal scheduling actions you can hook for observability: `wc_mss_archive_history` (log rotation), `wc_mss_async_verification_batch` (weekly verifier batch processing), `wc_mss_send_daily_summary` (daily email summary).

### Filter Hooks

```php
// Add a custom pricing-rule type (pricing-rules.php) — only fires when a store's
// pricing rule 'type' is set to 'custom'; $pricing_rules is that store's rule config
$product_data = apply_filters('wc_mss_custom_pricing_rule', $product_data, $pricing_rules, $product);

// Whitelist trusted proxy IPs for client-IP resolution behind a CDN/load balancer (webhook-receiver.php)
$trusted_ips = apply_filters('wc_mss_trusted_proxies', $trusted_ips);

// Exclude additional postmeta keys from the custom-field mapping UI (custom-field-mapper.php)
$excluded_keys = apply_filters('wc_mss_excluded_meta_keys', $excluded_keys);

// Add entries to the custom-field mapping list (custom-field-mapper.php)
$available_fields = apply_filters('wc_mss_available_custom_fields', $available_fields);
```

### WordPress/WooCommerce Hooks the Plugin Uses

If you need to react to a sync at a point this plugin doesn't expose a dedicated hook for, hooking the same underlying WordPress/WooCommerce events the plugin itself listens to usually works just as well:

```php
// Product save / create
add_action('woocommerce_update_product', $callback);
add_action('woocommerce_new_product', $callback);

// Order complete (triggers inventory sync)
add_action('woocommerce_order_status_completed', $callback);
add_action('woocommerce_order_status_processing', $callback);

// Coupons and shipping classes
add_action('woocommerce_new_coupon', $callback);
add_action('created_product_shipping_class', $callback);
```

---

## API Reference

### Global Functions

```php
/**
 * Get the main plugin instance (equivalent to WC_Multi_Store_Sync::instance())
 *
 * @return WC_Multi_Store_Sync
 */
function WC_MSS();
```

There's no `wc_mss_log()` global helper — logging goes through `WC_Multi_Store_Logger::write($message, $level)` (see [Core Classes](#wc_multi_store_logger) above).

### Programmatic Sync

```php
// Example: sync a specific product to all active stores
$product_id = 123;
$stores = WC_Multi_Store_Settings::get_active_stores();
$sync_type = 'full_product';

$results = WC_MSS()->sync_engine->sync_product($product_id, $stores, $sync_type);

// A missing product returns ['success' => false, 'message' => '...'] instead of a per-store array
if (isset($results['success']) && $results['success'] === false) {
    echo "Sync failed: {$results['message']}\n";
} else {
    foreach ($results as $store_url => $store_result) {
        if ($store_result['success']) {
            echo "Synced to {$store_url}: remote ID {$store_result['remote_id']}\n";
        } else {
            echo "Failed to sync to {$store_url}: {$store_result['message']}\n";
        }
    }
}
```

### Queue a Product

```php
$queue = WC_MSS()->queue_manager;

// Add a product for background processing (picked up by Action Scheduler every minute)
$queue->add_product(123, 'manual', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH);

// Or process a batch immediately instead of waiting for the scheduled run
$queue->process_queue(30);
```

---

## Database Schema

### wp_wc_mss_sync_history

Stores sync history and statistics (see `WC_Multi_Store_Sync_History::create_table()` in `includes/sync-history.php` for the authoritative definition).

```sql
CREATE TABLE wp_wc_mss_sync_history (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    product_id bigint(20) NOT NULL,
    product_sku varchar(200) DEFAULT '',
    product_name varchar(200) DEFAULT '',
    store_url varchar(200) NOT NULL,
    sync_type varchar(50) NOT NULL,
    sync_source varchar(50) DEFAULT 'manual',
    status varchar(20) NOT NULL,
    message text,
    remote_product_id bigint(20) DEFAULT NULL,
    duration_ms int DEFAULT NULL,
    memory_mb decimal(10,2) DEFAULT NULL,
    api_calls int DEFAULT 0,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY product_id (product_id),
    KEY store_url (store_url),
    KEY status (status),
    KEY created_at (created_at),
    KEY sync_type (sync_type),
    KEY sync_source (sync_source),
    KEY product_store (product_id, store_url(50)),
    KEY status_created (status, created_at),
    KEY sync_type_created (sync_type, created_at),
    KEY sync_source_created (sync_source, created_at),
    KEY product_created (product_id, created_at)
);
```

This is one of several plugin-specific tables — others include `wc_mss_queue` (background queue), `wc_mss_webhook_logs`, `wc_mss_deletion_audit`, `wc_mss_remote_orders`, `wc_mss_conflict_log`, and `wc_mss_api_usage`. Each is created by its own class's `create_table()` method, called on plugin activation.

---

## Extending the Plugin

All six examples below use hooks that actually exist in the plugin today (see [Hooks and Filters](#hooks-and-filters)).

### Example 1: Custom Pricing Rule Type

Set a store's pricing rule `type` to `custom` in its config, then compute the price yourself:

```php
add_filter('wc_mss_custom_pricing_rule', 'my_custom_pricing_rule', 10, 3);

function my_custom_pricing_rule($product_data, $pricing_rules, $product) {
    // Example: undercut the regular price by a flat 5% on this store, floor at 1.00
    $regular = (float) ($product_data['regular_price'] ?? 0);
    $product_data['regular_price'] = (string) max(1.00, round($regular * 0.95, 2));

    return $product_data;
}
```

### Example 2: Add a Custom Field to the Field Mapper

```php
add_filter('wc_mss_available_custom_fields', 'add_my_custom_field');

function add_my_custom_field($available_fields) {
    $available_fields['_my_plugin_warranty_years'] = __('Warranty (years)', 'my-plugin');
    return $available_fields;
}
```

### Example 3: Exclude a Meta Key from the Field Mapper

```php
add_filter('wc_mss_excluded_meta_keys', 'hide_internal_meta_key');

function hide_internal_meta_key($excluded_keys) {
    $excluded_keys[] = '_my_plugin_internal_cache';
    return $excluded_keys;
}
```

### Example 4: Trusted Proxies Behind a CDN

If webhook requests arrive via Cloudflare or a load balancer, the plugin needs to know which `X-Forwarded-For` values to trust for IP-based checks:

```php
add_filter('wc_mss_trusted_proxies', function ($trusted_ips) {
    return array_merge($trusted_ips, ['173.245.48.0/20', '103.21.244.0/22']); // Cloudflare ranges
});
```

### Example 5: Notify on Sync Failure

```php
add_action('wc_mss_sync_failed', 'notify_external_monitoring', 10, 3);

function notify_external_monitoring($product_id, $store_url, $error_message) {
    $product = wc_get_product($product_id);

    // e.g. post to a Slack webhook, write to an external log, etc.
    error_log(sprintf(
        'wc-mss sync failed: product %d (%s) -> store %s: %s',
        $product_id,
        $product ? $product->get_sku() : 'unknown',
        $store_url,
        $error_message
    ));
}
```

### Example 6: Build Alerting on the Dead Letter Queue

`wc_mss_dead_letter_added` has no built-in consumer — it exists purely as an extension point for items that exhausted retries:

```php
add_action('wc_mss_dead_letter_added', 'alert_on_permanent_failure');

function alert_on_permanent_failure($queue_item) {
    wp_mail(
        get_option('admin_email'),
        'Product sync permanently failed',
        sprintf('Queue item for product %d could not be synced after all retries.', $queue_item['product_id'] ?? 0)
    );
}
```

---

## Development Setup

### Requirements
- PHP 8.4+
- WordPress 5.8+
- WooCommerce 6.0+
- Composer (required — used for both the runtime `psr/log` dependency and the dev/test toolchain)

### Local Development

1. **Clone the repository**
   ```bash
   git clone https://github.com/MrGKanev/WooCommerce-API-Product-Sync-with-Multiple-WooCommerce-Stores.git wc-multi-store-sync
   cd wc-multi-store-sync
   composer install
   ```

2. **Install in WordPress**
   ```bash
   # Symlink to plugins directory
   ln -s $(pwd) /path/to/wordpress/wp-content/plugins/wc-multi-store-sync
   ```

3. **Activate Plugin**
   ```bash
   wp plugin activate wc-multi-store-sync
   ```

### Debugging

Enable WordPress debugging in `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

The plugin logs continuously (no separate debug-mode toggle) to `wp-content/uploads/wc-mss-logs/sync.log` (via `wp_upload_dir()`, not inside the plugin folder). The **Multi-Store Sync > Logs** admin page reads from the same file and supports search/filtering.

### Testing

The plugin ships with a full PHPUnit test suite (2000+ tests) using Brain Monkey/Mockery to mock WordPress/WooCommerce — no WordPress installation is needed to run it.

```bash
composer install

# Run the full unit suite
composer test
# equivalent to: vendor/bin/phpunit --testsuite Unit

# Run a single test file or filter by name
vendor/bin/phpunit --filter ApiClientTest
```

See `tests/php/bootstrap.php` for the WordPress/WooCommerce function stubs available to tests, and `phpunit.xml.dist` for suite configuration.

---

## Code Standards

### General Standards

This codebase uses **4-space indentation** (not tabs) and modern PHP 8.4 syntax (typed properties, constructor property promotion, `match`, readonly properties) rather than classic WordPress-core style throughout. It otherwise follows WordPress/WooCommerce conventions:
- Use WooCommerce functions/APIs when available instead of reinventing them
- Sanitize all input, escape all output
- Doc blocks for public methods, explaining the non-obvious "why" rather than restating the signature

### Plugin-Specific Standards

**Class Naming:**
```php
// All classes prefixed with WC_Multi_Store_, one class per file in includes/
class WC_Multi_Store_Feature_Name
```

**Hook Naming:**
```php
// Custom actions/filters use the wc_mss_ prefix (see Hooks and Filters above)
do_action('wc_mss_some_event', $arg1, $arg2);
apply_filters('wc_mss_some_filter', $value, $arg1);
```

**Option Names:**
```php
// Options (as opposed to hooks) use the wc_multi_store_sync_ prefix
wc_multi_store_sync_settings
wc_multi_store_sync_stores
wc_multi_store_sync_scheduled
```

---

## Contributing

### Pull Request Process

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Make changes following code standards
4. Test thoroughly
5. Commit with clear messages
6. Push to your fork
7. Submit pull request

### Code Review

All code changes must:
- Follow WordPress and WooCommerce coding standards
- Include doc blocks
- Be tested manually
- Not break existing functionality
- Include update to CHANGELOG.md

---

## Support

For developer support:
- **Documentation**: This file and related docs
- **Issues**: GitHub Issues

---

## License

GPLv3 or later. See LICENSE file for details.
