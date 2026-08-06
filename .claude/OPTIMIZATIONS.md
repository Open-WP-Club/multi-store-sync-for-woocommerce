# Performance Optimizations Guide

This document outlines all performance optimizations implemented in the WooCommerce Multi-Store Sync plugin and provides guidelines for maintaining optimal performance.

## Reliability Additions Since December 2025 (now v3.8.0)

These aren't pure perf wins but follow the same "reduce wasted API calls / don't retry forever" principles as the optimizations below:

- **Circuit breaker** (`includes/circuit-breaker.php`) — halts requests to a store after 10 consecutive failures for 1800s (transient-backed, no DB table), so a down remote store stops burning queue cycles and API quota on doomed retries. As of 3.7.5, state changes are wrapped in a per-store MySQL advisory lock (`GET_LOCK`/`RELEASE_LOCK`) to avoid race conditions when multiple queue workers hit the same store at once.
- **Dead Letter Queue** (`includes/dead-letter-queue.php`) — items that exhaust retries move out of the active queue instead of being retried indefinitely, keeping the main `wc_mss_queue` table lean.
- **Buffered API usage logging** (`includes/api-usage-tracker.php`) — logs every outbound request via a buffered multi-row `INSERT`, the same pattern as the Logger Buffering optimization below, applied to a new table.
- **Batched remote-prefetch cache** (`includes/weekly-sync-verifier.php`, 3.7.5) — the weekly verifier resolves remote product IDs and fetches remote product data for a whole chunk in one pass (`prefetch_remote_batch_data()`) instead of one API call per product; falls back to per-item lookups for a chunk if the batch fetch fails.
- **Deferred coupon/shipping sync** (3.7.5) — coupon and shipping class sync no longer run inline on every product sync; they're deferred to reduce per-request work on the main sync path.

## Recent Optimizations (December 2025)

### 1. Performance Tracking Helper
**File:** `includes/sync-engine.php`
**Impact:** Eliminated 40+ lines of duplicate code

**What it does:**
- Centralized initialization code across 5 methods
- Single `initialize_sync_context()` method handles performance tracking and API client setup

**Usage:**
```php
// Before
$start_time = microtime(true);
$api_calls_before = WC_Multi_Store_Performance_Monitor::get_api_call_count();
$api_client = new WC_Multi_Store_API_Client();

// After
$context = $this->initialize_sync_context();
// All variables available in $context array
```

**Benefits:**
- DRY principle adherence
- Consistent performance tracking
- Easier maintenance

---

### 2. Extract Duplicate Product Data Retrieval
**File:** `includes/sync-engine.php`
**Impact:** Eliminated ~30 lines of duplicate code

**What it does:**
- Refactored `get_full_product_data()` to reuse existing methods
- Eliminates duplicate sale price and stock data extraction logic

**Implementation:**
```php
// get_full_product_data() now calls:
$data = array_merge(
    $this->get_basic_product_data($product),
    $this->get_price_data($product),
    $this->get_stock_data($product),
    $this->get_image_data($product),
    // ... etc
);
```

**Benefits:**
- Single source of truth for each data type
- Easier to maintain and update
- Consistent data extraction

---

### 3. Logger Buffering
**File:** `includes/logger.php`
**Impact:** Reduced file I/O operations by up to 10x

**What it does:**
- Buffers log entries in memory
- Batch writes to file instead of individual writes
- Configurable buffer size (default: 10 entries)

**Configuration:**
```php
// In logger.php constructor
private $buffer_size = 10;  // Adjust based on logging frequency

// Force immediate write
WC_Multi_Store_Logger::flush_buffer();
```

**Benefits:**
- Dramatically reduced disk I/O
- Better performance during heavy logging
- Automatic cleanup via __destruct()

**When to adjust buffer size:**
- High logging frequency: Increase to 20-50
- Critical errors only: Keep at 5-10
- Development/debugging: Set to 1 (immediate write)

---

### 4. API Batch Syncing for Variations
**File:** `includes/api-client.php`, `includes/sync-engine.php`
**Impact:** Reduced API calls by up to 50x for variable products

**What it does:**
- New `batch_product_variations()` method in api-client.php
- Refactored `sync_variations()` to use batch operations
- Single batch API call instead of N individual calls

**Before:**
```php
// For 50 variations: 50 API calls
foreach ($variations as $variation) {
    $api_client->update_product($store, $variation_id, $variation_data);
}
```

**After:**
```php
// For 50 variations: 1 API call
$api_client->batch_product_variations($store, $remote_product_id, $variations_data);
```

**Benefits:**
- Massive reduction in API calls
- Faster sync for variable products
- Reduced rate limiting issues
- Lower server load

**When to use batch operations:**
- Variable products with 3+ variations
- Bulk updates
- Any operation affecting multiple items

---

### 5. Break Down Long Methods
**File:** `includes/sync-engine.php`
**Impact:** Improved testability and maintainability

**What it does:**
- Extracted `sync_product_to_store()` from 154 lines to ~50 lines
- Created focused helper methods

**New helper methods:**
```php
apply_store_rules($data, $product, $store)
create_or_update_remote_product($data, $product, $store, $api_client)
perform_post_sync_operations($product, $remote_product_id, $store, $sync_type, $api_client)
```

**Benefits:**
- Easier to test individual components
- Better code organization
- Easier to debug
- More maintainable

**Guidelines:**
- Methods should be < 50 lines
- Single responsibility principle
- Clear, descriptive names
- Minimal parameters (< 5)

---

### 6. Type Hints
**Files:** `includes/sync-engine.php`, `includes/logger.php`
**Impact:** Better IDE support, early error detection

**What it does:**
- Added parameter and return type hints
- Comprehensive type coverage

**Example:**
```php
// Before
public function sync_product($product_id, $store_ids, $sync_type)

// After
public function sync_product(int $product_id, array $store_ids, string $sync_type): array
```

**Benefits:**
- IDE autocomplete and type checking
- Better static analysis
- Catches type errors early
- Self-documenting code

**Type hint guidelines:**
- Always use for new methods
- Add during refactoring
- Document complex types with PHPDoc

---

## Optimization Principles to Follow

### 1. API Call Reduction
**Priority:** CRITICAL

**Strategies:**
- Use batch operations whenever possible
- Cache remote data aggressively
- Implement debouncing for rapid updates
- Use conditional requests (If-Modified-Since)

**Example:**
```php
// Bad: 10 API calls
for ($i = 0; $i < 10; $i++) {
    $api_client->update_product($store, $ids[$i], $data[$i]);
}

// Good: 1 API call
$api_client->batch_update($store, [
    'update' => array_map(function($i) use ($ids, $data) {
        return ['id' => $ids[$i], ...$data[$i]];
    }, range(0, 9))
]);
```

### 2. Database Query Optimization
**Priority:** HIGH

**Strategies:**
- Use prepared statements
- Add indexes on frequently queried columns
- Limit result sets
- Use EXISTS instead of COUNT when checking

**Example:**
```php
// Bad
$count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE product_id = {$id}");
if ($count > 0) { ... }

// Good
$exists = $wpdb->get_var($wpdb->prepare(
    "SELECT EXISTS(SELECT 1 FROM {$table} WHERE product_id = %d LIMIT 1)",
    $id
));
if ($exists) { ... }
```

### 3. Memory Management
**Priority:** HIGH

**Strategies:**
- Unset large variables after use
- Use generator functions for large datasets
- Process in batches
- Clear object cache periodically

**Example:**
```php
// Bad
$all_products = wc_get_products(['limit' => -1]);
foreach ($all_products as $product) {
    process_product($product);
}

// Good
$page = 1;
while ($products = wc_get_products(['limit' => 100, 'page' => $page++])) {
    foreach ($products as $product) {
        process_product($product);
        unset($product); // Free memory
    }
    unset($products);
    wp_cache_flush(); // Clear object cache
}
```

### 4. Caching Strategy
**Priority:** HIGH

**Cache layers:**
1. Object cache (WordPress transients)
2. File cache (for API responses)
3. Database cache (query results)

**Example:**
```php
public function get_remote_product($store_id, $product_id) {
    $cache_key = "wc_mss_product_{$store_id}_{$product_id}";

    // Try object cache first
    $cached = wp_cache_get($cache_key, 'wc_mss');
    if ($cached !== false) {
        return $cached;
    }

    // Try transient
    $transient = get_transient($cache_key);
    if ($transient !== false) {
        wp_cache_set($cache_key, $transient, 'wc_mss', 300);
        return $transient;
    }

    // Fetch from API
    $product = $this->api_client->get_product($store_id, $product_id);

    // Cache at all layers
    set_transient($cache_key, $product, HOUR_IN_SECONDS);
    wp_cache_set($cache_key, $product, 'wc_mss', 300);

    return $product;
}
```

### 5. Lazy Loading
**Priority:** MEDIUM

**When to use:**
- Large datasets that may not be needed
- Expensive operations in conditional blocks
- Admin pages with multiple tabs

**Example:**
```php
class WC_Multi_Store_Admin {
    private $api_client;

    // Lazy load API client only when needed
    private function get_api_client() {
        if (!$this->api_client) {
            $this->api_client = new WC_Multi_Store_API_Client();
        }
        return $this->api_client;
    }
}
```

---

## Performance Benchmarks

### Sync Performance
- Simple product: < 2 seconds
- Variable product (10 variations): < 5 seconds (with batch API)
- Bulk sync (100 products): < 5 minutes
- Full catalog (1000 products): < 1 hour (with queue)

### API Call Reduction
- Before optimization: ~150 calls for 100 variable products
- After optimization: ~50 calls for 100 variable products
- **Reduction: 66%**

### Memory Usage
- Simple product sync: ~5MB
- Variable product sync: ~10MB
- Bulk sync (batch of 20): ~50MB
- Peak usage (queue processing): ~128MB

---

## Optimization Checklist for New Features

When adding new features, ensure you:

- [ ] Use batch API operations for multiple items
- [ ] Implement caching for repeated data access
- [ ] Add type hints to all methods
- [ ] Keep methods under 50 lines
- [ ] Add performance tracking
- [ ] Log performance metrics
- [ ] Test with large datasets (1000+ products)
- [ ] Profile memory usage
- [ ] Check for N+1 query problems
- [ ] Implement lazy loading where appropriate
- [ ] Add database indexes for new queries
- [ ] Document performance characteristics
- [ ] Use buffering for file I/O operations
- [ ] Implement debouncing for rapid events
- [ ] Add progress indicators for long operations

---

## Tools for Performance Analysis

### WordPress Query Monitor
Identifies slow queries and API calls

### PHP Profiler (XDebug)
Find performance bottlenecks

### New Relic / Blackfire
Production performance monitoring

### Custom Performance Tracking
```php
$monitor = WC_Multi_Store_Performance_Monitor::instance();
$monitor->start_tracking('operation_name');
// ... operation code ...
$metrics = $monitor->stop_tracking('operation_name');
// Logs: duration, memory usage, API calls
```

---

## Common Performance Anti-Patterns to Avoid

### ❌ Don't: Query in a loop
```php
foreach ($products as $product) {
    $store_data = get_option("store_{$product->get_id()}");
}
```

### ✅ Do: Single query with IN clause
```php
$product_ids = wp_list_pluck($products, 'id');
$store_data = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$table} WHERE product_id IN (" . implode(',', array_fill(0, count($product_ids), '%d')) . ")",
    ...$product_ids
));
```

### ❌ Don't: Load all data into memory
```php
$all_products = wc_get_products(['limit' => -1]);
```

### ✅ Do: Paginate
```php
$args = ['limit' => 100, 'page' => $page];
$products = wc_get_products($args);
```

### ❌ Don't: Ignore caching
```php
$product = $api_client->get_product($store, $id);
```

### ✅ Do: Cache aggressively
```php
$product = $cache_manager->get_or_fetch("product_{$id}", function() use ($store, $id) {
    return $api_client->get_product($store, $id);
}, HOUR_IN_SECONDS);
```

---

**Last Updated:** August 6, 2026
**Plugin Version:** 3.8.0
