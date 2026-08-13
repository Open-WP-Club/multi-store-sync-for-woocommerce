# Code Patterns and Best Practices

This document outlines coding patterns, conventions, and best practices to follow when developing the WooCommerce Multi-Store Sync plugin.

## Table of Contents
1. [Method Organization](#method-organization)
2. [Error Handling](#error-handling)
3. [Logging Patterns](#logging-patterns)
4. [Caching Patterns](#caching-patterns)
5. [API Communication](#api-communication)
6. [Database Patterns](#database-patterns)
7. [Hook Usage](#hook-usage)
8. [Security Patterns](#security-patterns)

---

## Method Organization

### Principle: Single Responsibility
Each method should do ONE thing well.

**✅ Good Example:**
```php
/**
 * Sync product to a single store
 */
private function sync_product_to_store(int $product_id, array $store, string $sync_type): array {
    $context = $this->initialize_sync_context();
    $product = wc_get_product($product_id);

    // Prepare data
    $data = $this->prepare_product_data($product, $sync_type);

    // Apply store-specific rules
    $data = $this->apply_store_rules($data, $product, $store);

    // Perform sync
    $result = $this->create_or_update_remote_product($data, $product, $store, $context['api_client']);

    // Post-sync operations
    if ($result['success']) {
        $this->perform_post_sync_operations($product, $result['remote_id'], $store, $sync_type, $context['api_client']);
    }

    return $this->finalize_sync_result($result, $context);
}
```

**❌ Bad Example:**
```php
// 200+ line method that does everything
private function sync_product_to_store($product_id, $store, $sync_type) {
    // Initialize
    // Get product
    // Prepare data
    // Apply rules
    // Check cache
    // Create or update
    // Sync images
    // Sync variations
    // Sync categories
    // Update stock
    // Log everything
    // Update cache
    // Return result
    // ... 150 more lines
}
```

### Method Size Guidelines
- **Target:** 20-40 lines per method
- **Maximum:** 60 lines
- **If longer:** Extract helper methods

### Method Naming
```php
// Action verbs for public methods
public function sync_product()
public function update_stock()
public function delete_remote_product()

// Get/set for accessors
public function get_stores()
public function set_batch_size()

// Is/has for boolean checks
public function is_connected()
public function has_variations()

// Private helpers are descriptive
private function calculate_discounted_price()
private function prepare_variation_data()
private function validate_api_response()
```

---

## Error Handling

### Pattern: Try-Catch with Detailed Logging

**✅ Good Example:**
```php
public function sync_product(int $product_id, array $store_ids, string $sync_type): array {
    $results = [];

    try {
        $product = wc_get_product($product_id);

        if (!$product) {
            throw new Exception("Product {$product_id} not found");
        }

        foreach ($store_ids as $store_id) {
            try {
                $store = $this->get_store($store_id);
                $results[$store_id] = $this->sync_product_to_store($product_id, $store, $sync_type);

            } catch (Exception $e) {
                // Log individual store failure, but continue
                WC_Multi_Store_Logger::log(
                    sprintf('Failed to sync product %d to store %s: %s',
                        $product_id, $store_id, $e->getMessage()),
                    'error'
                );

                $results[$store_id] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'store_id' => $store_id
                ];
            }
        }

    } catch (Exception $e) {
        // Fatal error - log and return
        WC_Multi_Store_Logger::log(
            sprintf('Critical error in sync_product: %s', $e->getMessage()),
            'critical'
        );

        return [
            'success' => false,
            'error' => $e->getMessage(),
            'results' => $results
        ];
    }

    return [
        'success' => true,
        'results' => $results
    ];
}
```

### Error Response Pattern
Always return consistent error structures:

```php
// Success response
return [
    'success' => true,
    'data' => $data,
    'message' => 'Operation completed successfully'
];

// Error response
return [
    'success' => false,
    'error' => 'Error message',
    'error_code' => 'ERROR_CODE',
    'data' => [] // Empty or partial data
];
```

---

## Logging Patterns

### Use Appropriate Log Levels

```php
// DEBUG: Detailed information for debugging
WC_Multi_Store_Logger::log('Starting sync for product ' . $product_id, 'debug');

// INFO: General information
WC_Multi_Store_Logger::log('Synced product to 3 stores', 'info');

// WARNING: Something unexpected but not critical
WC_Multi_Store_Logger::log('Store connection slow (5s response time)', 'warning');

// ERROR: Error occurred but operation can continue
WC_Multi_Store_Logger::log('Failed to sync images, continuing with product sync', 'error');

// CRITICAL: Critical error requiring immediate attention
WC_Multi_Store_Logger::log('API credentials invalid, all syncs will fail', 'critical');
```

### Structured Logging Pattern

```php
private function log_sync_operation(string $operation, array $context, string $level = 'info'): void {
    $message = sprintf(
        '[%s] Product: %d | Store: %s | Duration: %.2fs | API Calls: %d',
        $operation,
        $context['product_id'] ?? 'N/A',
        $context['store_name'] ?? 'N/A',
        $context['duration'] ?? 0,
        $context['api_calls'] ?? 0
    );

    WC_Multi_Store_Logger::log($message, $level);
}

// Usage
$this->log_sync_operation('SYNC_COMPLETE', [
    'product_id' => 123,
    'store_name' => 'Store A',
    'duration' => 2.45,
    'api_calls' => 3
]);
```

### Performance Logging Pattern

```php
private function track_performance(string $operation, callable $callback): mixed {
    $start_time = microtime(true);
    $start_memory = memory_get_usage();
    $api_calls_before = WC_Multi_Store_Performance_Monitor::get_api_call_count();

    try {
        $result = $callback();

        $metrics = [
            'duration' => microtime(true) - $start_time,
            'memory' => memory_get_usage() - $start_memory,
            'api_calls' => WC_Multi_Store_Performance_Monitor::get_api_call_count() - $api_calls_before
        ];

        WC_Multi_Store_Logger::log(
            sprintf('[PERFORMANCE] %s - Duration: %.2fs, Memory: %s, API Calls: %d',
                $operation,
                $metrics['duration'],
                size_format($metrics['memory']),
                $metrics['api_calls']
            ),
            'debug'
        );

        return $result;

    } catch (Exception $e) {
        WC_Multi_Store_Logger::log(
            sprintf('[PERFORMANCE] %s FAILED - %s', $operation, $e->getMessage()),
            'error'
        );
        throw $e;
    }
}

// Usage
$result = $this->track_performance('sync_variations', function() use ($product, $store) {
    return $this->sync_variations($product, $store);
});
```

---

## Caching Patterns

### Cache-Aside Pattern (Most Common)

```php
public function get_remote_product(int $store_id, int $product_id): ?array {
    $cache_key = "wc_mss_product_{$store_id}_{$product_id}";

    // Try to get from cache
    $cached = wp_cache_get($cache_key, 'wc_mss');
    if ($cached !== false) {
        return $cached;
    }

    // Cache miss - fetch from source
    $product = $this->api_client->get_product($store_id, $product_id);

    if ($product) {
        // Store in cache
        wp_cache_set($cache_key, $product, 'wc_mss', HOUR_IN_SECONDS);
    }

    return $product;
}
```

### Cache-Through Pattern (For Writes)

```php
public function update_remote_product(int $store_id, int $product_id, array $data): bool {
    $cache_key = "wc_mss_product_{$store_id}_{$product_id}";

    // Update remote
    $result = $this->api_client->update_product($store_id, $product_id, $data);

    if ($result) {
        // Update cache immediately
        wp_cache_set($cache_key, $data, 'wc_mss', HOUR_IN_SECONDS);
    } else {
        // Invalidate cache on failure
        wp_cache_delete($cache_key, 'wc_mss');
    }

    return $result;
}
```

### Smart Cache Invalidation

```php
private function invalidate_product_cache(int $product_id): void {
    $stores = $this->get_all_stores();

    foreach ($stores as $store) {
        $patterns = [
            "wc_mss_product_{$store['id']}_{$product_id}",
            "wc_mss_variations_{$store['id']}_{$product_id}",
            "wc_mss_stock_{$store['id']}_{$product_id}"
        ];

        foreach ($patterns as $pattern) {
            wp_cache_delete($pattern, 'wc_mss');
            delete_transient($pattern);
        }
    }
}
```

---

## API Communication

### Retry Pattern with Exponential Backoff

```php
private function api_call_with_retry(callable $callback, int $max_attempts = 3): mixed {
    $attempt = 0;
    $delay = 1; // Start with 1 second

    while ($attempt < $max_attempts) {
        try {
            return $callback();

        } catch (Exception $e) {
            $attempt++;

            if ($attempt >= $max_attempts) {
                throw $e;
            }

            // Log retry
            WC_Multi_Store_Logger::log(
                sprintf('API call failed (attempt %d/%d), retrying in %ds: %s',
                    $attempt, $max_attempts, $delay, $e->getMessage()),
                'warning'
            );

            sleep($delay);
            $delay *= 2; // Exponential backoff
        }
    }
}

// Usage
$product = $this->api_call_with_retry(function() use ($store_id, $product_id) {
    return $this->api_client->get_product($store_id, $product_id);
});
```

### Batch Operations Pattern

```php
/**
 * Process items in batches to reduce API calls
 */
private function batch_process_variations(array $variations, array $store, int $batch_size = 50): array {
    $results = [];
    $batches = array_chunk($variations, $batch_size);

    foreach ($batches as $index => $batch) {
        WC_Multi_Store_Logger::log(
            sprintf('Processing variation batch %d/%d (%d items)',
                $index + 1, count($batches), count($batch)),
            'info'
        );

        try {
            $batch_result = $this->api_client->batch_product_variations(
                $store,
                $batch
            );

            $results = array_merge($results, $batch_result);

        } catch (Exception $e) {
            WC_Multi_Store_Logger::log(
                sprintf('Batch %d failed: %s', $index + 1, $e->getMessage()),
                'error'
            );
        }

        // Prevent rate limiting
        if ($index < count($batches) - 1) {
            sleep(1);
        }
    }

    return $results;
}
```

---

## Database Patterns

### Prepared Statements (Always)

```php
// ❌ NEVER do this
$results = $wpdb->get_results("SELECT * FROM {$table} WHERE product_id = {$product_id}");

// ✅ ALWAYS use prepared statements
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$table} WHERE product_id = %d",
    $product_id
));
```

### Efficient Existence Checks

```php
// ❌ Bad: Counts all rows
$count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE product_id = %d",
    $product_id
));
$exists = $count > 0;

// ✅ Good: Stops at first match
$exists = $wpdb->get_var($wpdb->prepare(
    "SELECT EXISTS(SELECT 1 FROM {$table} WHERE product_id = %d LIMIT 1)",
    $product_id
));
```

### Transaction Pattern

```php
private function update_with_transaction(callable $callback): bool {
    global $wpdb;

    $wpdb->query('START TRANSACTION');

    try {
        $result = $callback();

        if ($result) {
            $wpdb->query('COMMIT');
            return true;
        } else {
            $wpdb->query('ROLLBACK');
            return false;
        }

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        WC_Multi_Store_Logger::log('Transaction failed: ' . $e->getMessage(), 'error');
        return false;
    }
}
```

---

## Hook Usage

### Action Hook Pattern

```php
// Trigger before operation
do_action('wc_mss_before_sync', $product_id, $store_ids, $sync_type);

try {
    $result = $this->perform_sync($product_id, $store_ids, $sync_type);

    // Trigger after success
    do_action('wc_mss_after_sync', $product_id, $store_ids, $sync_type, $result);

} catch (Exception $e) {
    // Trigger on failure
    do_action('wc_mss_sync_failed', $product_id, $store_ids, $e);
    throw $e;
}
```

### Filter Hook Pattern

```php
// Allow modification of data
$product_data = apply_filters('wc_mss_sync_product_data', $data, $product, $sync_type, $store);

// Allow conditional skipping
$should_sync = apply_filters('wc_mss_should_sync_product', true, $product_id, $store_id);
if (!$should_sync) {
    return;
}

// Allow modification of settings
$batch_size = apply_filters('wc_mss_batch_size', $this->default_batch_size, $is_peak_hour);
```

### Creating Extensible Code

```php
public function prepare_product_data(WC_Product $product, string $sync_type): array {
    $data = [];

    // Build base data
    switch ($sync_type) {
        case 'full_product':
            $data = $this->get_full_product_data($product);
            break;
        case 'price_stock':
            $data = $this->get_price_stock_data($product);
            break;
    }

    // Allow plugins to modify
    $data = apply_filters('wc_mss_prepare_product_data', $data, $product, $sync_type);

    // Allow sync-type-specific modifications
    $data = apply_filters("wc_mss_prepare_{$sync_type}_data", $data, $product);

    return $data;
}
```

---

## Security Patterns

### Nonce Verification

For `wp_ajax_wc_mss_*` handlers specifically, use the shared trait instead of hand-rolling this:

```php
use WC_Multi_Store_Ajax_Auth_Guard;

class WC_Multi_Store_Something {
    use WC_Multi_Store_Ajax_Auth_Guard;

    public static function ajax_do_thing(): void {
        if (!self::verify_admin_request('wc_mss_admin', __('Unauthorized', 'wc-multi-store-sync'))) {
            return; // trait already sent wp_send_json_error()
        }
        // ... handle request
    }
}
```

`verify_admin_request()` runs `check_ajax_referer()` + `current_user_can('manage_woocommerce')` and sends the JSON error itself — callers just `return` on `false`. See `includes/ajax-auth-guard-trait.php`. The pattern below (`wp_die` on failure) is for classic form-POST handlers, not AJAX.

```php
public function handle_form_submission(): void {
    // Check nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wc_mss_save_store')) {
        wp_die('Security check failed');
    }

    // Check capabilities
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Insufficient permissions');
    }

    // Process form
    $this->save_store_data($_POST);
}
```

### Input Sanitization

```php
private function sanitize_store_data(array $data): array {
    return [
        'name' => sanitize_text_field($data['name'] ?? ''),
        'url' => esc_url_raw($data['url'] ?? ''),
        'consumer_key' => sanitize_text_field($data['consumer_key'] ?? ''),
        'consumer_secret' => sanitize_text_field($data['consumer_secret'] ?? ''),
        'enabled' => (bool) ($data['enabled'] ?? false),
        'sync_types' => array_map('sanitize_text_field', $data['sync_types'] ?? [])
    ];
}
```

### Output Escaping

```php
// In views
<h2><?php echo esc_html($store['name']); ?></h2>
<a href="<?php echo esc_url($store['url']); ?>">Visit Store</a>
<div><?php echo wp_kses_post($description); ?></div>
```

### Masking Sensitive Fields in Admin UI

Credential fields (consumer key/secret, App Password) render as `type="password"` with `autocomplete="off"` and a Show/Hide toggle button, never as plain text — even for the admin's own re-entry of an already-saved value.

```php
<input type="password" name="consumer_key" id="consumer_key" class="regular-text"
       placeholder="<?php echo !empty($editing_store['consumer_key'] ?? '') ? esc_attr__('Already set — leave blank to keep', 'wc-multi-store-sync') : ''; ?>"
       autocomplete="off">
<button type="button" class="wc-mss-toggle-visibility" data-target="consumer_key">
    <?php _e('Show/Hide', 'wc-multi-store-sync'); ?>
</button>
```

When exporting config (`config-manager.php`), redact these same fields by default and only include them when an explicit `include_keys` flag is passed.

### Whitelisting Dynamic SQL Identifiers (ORDER BY)

Column/table identifiers can't be parameterized with `$wpdb->prepare()`. Never interpolate a user-supplied `orderby` value directly — whitelist it against a fixed list of real column names and fall back to a safe default, then run it through `sanitize_sql_orderby()` as a second layer.

```php
// Build ORDER BY clause — whitelist valid columns to prevent schema exposure via SQL errors.
$allowed_columns = ['id', 'product_id', 'product_sku', 'product_name', 'store_url', 'sync_type', 'sync_source', 'status', 'duration_ms', 'api_calls', 'created_at'];
if (!in_array($args['orderby'], $allowed_columns, true)) {
    $args['orderby'] = 'created_at';
}
$orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
if (!$orderby) {
    $orderby = 'created_at DESC';
}
```

See `includes/sync-history.php`, `includes/deletion-audit.php`, `includes/weekly-sync-verifier.php` for real usages. Regression-tested in `tests/php/Unit/SecurityRegressionTest.php`.

### CSV Export — Formula Injection Guard

Always pass explicit delimiter/enclosure/escape arguments to `fputcsv()` — the default escape character can leave cells that Excel/Sheets interpret as formulas (`=`, `+`, `-`, `@` prefixes) unescaped, letting exported data execute as a formula when opened.

```php
fputcsv($output, $row, ',', '"', '\\');
```

See `includes/webhook-logger.php`, `includes/api-usage-tracker.php`.

### SSRF Guard on Outbound Test Requests

When a feature makes an outbound HTTP request based on admin-supplied input (e.g. a "test connection" button), block link-local/private IP ranges before dispatching the request — otherwise a malicious or misconfigured URL can be used to probe internal network services.

See `includes/store-health-check.php`'s Application Password test handler for the reference implementation.

---

## Constants and Configuration

### Define Constants for Magic Numbers

```php
// ❌ Bad: Magic numbers
if ($batch_size > 100) { ... }
sleep(5);
wp_cache_set($key, $value, 'group', 3600);

// ✅ Good: Named constants
class WC_Multi_Store_Constants {
    const MAX_BATCH_SIZE = 100;
    const API_RETRY_DELAY = 5;
    const CACHE_EXPIRATION = HOUR_IN_SECONDS;
    const BUFFER_SIZE = 10;
}

if ($batch_size > WC_Multi_Store_Constants::MAX_BATCH_SIZE) { ... }
```

### Configuration Arrays

```php
private function get_default_config(): array {
    return [
        'batch_size' => [
            'peak' => 5,
            'off_peak' => 20,
            'max' => 100
        ],
        'cache' => [
            'products' => HOUR_IN_SECONDS,
            'stores' => DAY_IN_SECONDS,
            'categories' => 6 * HOUR_IN_SECONDS
        ],
        'api' => [
            'timeout' => 30,
            'retry_attempts' => 3,
            'retry_delay' => 1
        ]
    ];
}
```

---

## Documentation Standards

### PHPDoc Blocks

```php
/**
 * Sync a product to multiple remote stores
 *
 * @param int    $product_id Product ID to sync
 * @param array  $store_ids  Array of store IDs to sync to
 * @param string $sync_type  Type of sync: 'full_product', 'price_stock', 'stock_only'
 * @return array {
 *     Results of sync operation
 *
 *     @type bool  $success Overall success status
 *     @type array $results Per-store results array
 *     @type int   $synced  Number of stores successfully synced
 * }
 * @throws Exception If product not found or critical error occurs
 */
public function sync_product(int $product_id, array $store_ids, string $sync_type): array {
    // Implementation
}
```

---

**Last Updated:** December 6, 2025
**Plugin Version:** 2.0.0
