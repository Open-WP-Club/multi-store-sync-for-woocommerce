# Feature Development Guide

This guide provides a step-by-step process for implementing new features in the WooCommerce Multi-Store Sync plugin.

## Table of Contents
1. [Planning Phase](#planning-phase)
2. [Implementation Phase](#implementation-phase)
3. [Testing Phase](#testing-phase)
4. [Documentation Phase](#documentation-phase)
5. [Feature Templates](#feature-templates)

---

## Planning Phase

### Step 1: Define Requirements
Create a feature specification document:

```markdown
## Feature: [Feature Name]

### Overview
Brief description of what the feature does and why it's needed.

### User Stories
- As a [user type], I want to [action] so that [benefit]
- As a [user type], I want to [action] so that [benefit]

### Requirements
- [ ] Functional requirement 1
- [ ] Functional requirement 2
- [ ] Non-functional requirement (performance, security, etc.)

### Technical Requirements
- Database changes needed
- API endpoints needed
- UI components needed
- Background jobs needed

### Success Criteria
- How do we know the feature is successful?
- What metrics will we track?
```

### Step 2: Design the Solution

#### Database Design
```sql
-- If new table needed
CREATE TABLE {prefix}_wc_multi_store_[table_name] (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    store_id VARCHAR(50) NOT NULL,
    [custom_fields],
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY product_id (product_id),
    KEY store_id (store_id),
    KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Class Structure
```php
/**
 * Class WC_Multi_Store_[Feature_Name]
 *
 * Handles [feature description]
 *
 * @since 2.1.0
 */
class WC_Multi_Store_[Feature_Name] {
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance(): self {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks(): void {
        // Add hooks here
    }

    // Feature methods...
}
```

### Step 3: Plan Integration Points

#### Where does this feature integrate?
- [ ] Main plugin file (`wc-multi-store-sync.php`)
- [ ] Sync engine (`includes/sync-engine.php`)
- [ ] Admin interface (`admin/views/`)
- [ ] Settings (`includes/settings.php`)
- [ ] Hooks system (`includes/hooks.php`)

#### What hooks/filters are needed?
```php
// Actions
do_action('wc_mss_before_[feature_action]', $params);
do_action('wc_mss_after_[feature_action]', $params, $result);
do_action('wc_mss_[feature]_failed', $params, $error);

// Filters
apply_filters('wc_mss_[feature]_data', $data, $context);
apply_filters('wc_mss_should_[feature]', true, $params);
apply_filters('wc_mss_[feature]_options', $options);
```

---

## Implementation Phase

### Step 1: Create Class File

**File:** `includes/[feature-name].php`

```php
<?php
/**
 * [Feature Name]
 *
 * @package WC_Multi_Store_Sync
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WC_Multi_Store_[Feature_Name]
 *
 * [Detailed description of what this class does]
 *
 * @since 2.1.0
 */
class WC_Multi_Store_[Feature_Name] {

    /**
     * Singleton instance
     *
     * @var WC_Multi_Store_[Feature_Name]|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return WC_Multi_Store_[Feature_Name]
     */
    public static function instance(): self {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks(): void {
        add_action('wc_mss_[event]', [$this, 'handle_event']);
        add_filter('wc_mss_[data]', [$this, 'modify_data'], 10, 2);
    }

    /**
     * Main feature method
     *
     * @param int   $product_id Product ID
     * @param array $params     Additional parameters
     * @return array Result array
     */
    public function process_feature(int $product_id, array $params = []): array {
        try {
            // Validate input
            $this->validate_input($product_id, $params);

            // Trigger before hook
            do_action('wc_mss_before_[feature]', $product_id, $params);

            // Process feature
            $result = $this->do_process($product_id, $params);

            // Trigger after hook
            do_action('wc_mss_after_[feature]', $product_id, $params, $result);

            return [
                'success' => true,
                'data' => $result
            ];

        } catch (Exception $e) {
            // Log error
            WC_Multi_Store_Logger::log(
                sprintf('[Feature] Error: %s', $e->getMessage()),
                'error'
            );

            // Trigger error hook
            do_action('wc_mss_[feature]_failed', $product_id, $params, $e);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate input parameters
     *
     * @param int   $product_id Product ID
     * @param array $params     Parameters to validate
     * @throws Exception If validation fails
     */
    private function validate_input(int $product_id, array $params): void {
        if ($product_id <= 0) {
            throw new Exception('Invalid product ID');
        }

        // Additional validation...
    }

    /**
     * Process the feature logic
     *
     * @param int   $product_id Product ID
     * @param array $params     Processing parameters
     * @return array Processing result
     */
    private function do_process(int $product_id, array $params): array {
        // Implementation here
        return [];
    }
}
```

### Step 2: Add Database Table (if needed)

**Add to:** `wc-multi-store-sync.php` in the `create_tables()` method

```php
private function create_tables(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'wc_multi_store_[table_name]';

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        store_id VARCHAR(50) NOT NULL,
        [custom_fields] TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY store_id (store_id),
        KEY created_at (created_at)
    ) {$charset_collate};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
```

### Step 3: Add Settings

**Add to:** `includes/settings.php`

```php
private function get_[feature]_settings(): array {
    return [
        'title' => __('[Feature] Settings', 'wc-multi-store-sync'),
        'type' => 'title',
        'id' => 'wc_mss_[feature]_settings'
    ],
    [
        'title' => __('Enable [Feature]', 'wc-multi-store-sync'),
        'desc' => __('Enable [feature description]', 'wc-multi-store-sync'),
        'id' => 'wc_mss_[feature]_enabled',
        'default' => 'no',
        'type' => 'checkbox'
    ],
    [
        'title' => __('[Option Name]', 'wc-multi-store-sync'),
        'desc' => __('[Option description]', 'wc-multi-store-sync'),
        'id' => 'wc_mss_[feature]_option',
        'type' => 'text',
        'default' => ''
    ],
    [
        'type' => 'sectionend',
        'id' => 'wc_mss_[feature]_settings'
    ];
}
```

### Step 4: Add Admin UI (if needed)

**Create file:** `admin/views/[feature].php`

```php
<?php
/**
 * [Feature] Admin View
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

$feature_manager = WC_Multi_Store_[Feature_Name]::instance();
$data = $feature_manager->get_admin_data();
?>

<div class="wrap wc-multi-store-sync">
    <h1><?php echo esc_html__('[Feature Name]', 'wc-multi-store-sync'); ?></h1>

    <div class="card">
        <h2><?php echo esc_html__('Overview', 'wc-multi-store-sync'); ?></h2>
        <p><?php echo esc_html__('[Feature description]', 'wc-multi-store-sync'); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('wc_mss_[feature]_action', 'wc_mss_[feature]_nonce'); ?>

            <!-- Form fields here -->

            <p class="submit">
                <button type="submit" name="submit" class="button button-primary">
                    <?php echo esc_html__('Save Changes', 'wc-multi-store-sync'); ?>
                </button>
            </p>
        </form>
    </div>
</div>
```

### Step 5: Integrate with Main Plugin

**Add to:** `wc-multi-store-sync.php`

```php
// In includes() method
require_once plugin_dir_path(__FILE__) . 'includes/[feature-name].php';

// In init() method
WC_Multi_Store_[Feature_Name]::instance();
```

### Step 6: Add to Sync Engine (if applicable)

**Add to:** `includes/sync-engine.php`

```php
private function apply_store_rules(array $data, WC_Product $product, array $store): array {
    // Existing code...

    // Apply [feature] rules
    if (!empty($store['[feature]_enabled'])) {
        $feature = WC_Multi_Store_[Feature_Name]::instance();
        $data = $feature->apply_rules($data, $product, $store);
    }

    return $data;
}
```

---

## Testing Phase

### Manual Testing Script

```php
// Create test file: tests/test-[feature].php

// Test 1: Basic functionality
function test_[feature]_basic() {
    $feature = WC_Multi_Store_[Feature_Name]::instance();
    $result = $feature->process_feature(123, ['param' => 'value']);

    assert($result['success'] === true, 'Feature should succeed');
    assert(!empty($result['data']), 'Result should contain data');

    echo "✓ Basic functionality test passed\n";
}

// Test 2: Error handling
function test_[feature]_error_handling() {
    $feature = WC_Multi_Store_[Feature_Name]::instance();
    $result = $feature->process_feature(0, []); // Invalid ID

    assert($result['success'] === false, 'Should fail with invalid input');
    assert(!empty($result['error']), 'Should return error message');

    echo "✓ Error handling test passed\n";
}

// Run tests
test_[feature]_basic();
test_[feature]_error_handling();

echo "\nAll tests passed!\n";
```

### Performance Testing

```php
// Test with varying load
function test_[feature]_performance() {
    $sizes = [1, 10, 100];

    foreach ($sizes as $size) {
        $start_time = microtime(true);
        $start_memory = memory_get_usage();

        // Run feature with $size items
        for ($i = 0; $i < $size; $i++) {
            $feature->process_feature($i);
        }

        $duration = microtime(true) - $start_time;
        $memory = memory_get_usage() - $start_memory;

        printf(
            "Size: %d | Duration: %.2fs | Memory: %s\n",
            $size,
            $duration,
            size_format($memory)
        );
    }
}
```

---

## Documentation Phase

### Update .claude.md

Add new class to the Core Classes section:

```markdown
### X. WC_Multi_Store_[Feature_Name]
**File:** `includes/[feature-name].php`
**Purpose:** [Feature description]
**Key Methods:**
- `process_feature($product_id, $params)` - Main processing method
- `apply_rules($data, $product, $store)` - Apply feature-specific rules
- `get_admin_data()` - Get data for admin interface

**Key Features:**
- Feature point 1
- Feature point 2
- Feature point 3
```

### Update TODO.md

Move feature from "Planned" to "Completed":

```markdown
## ✅ Completed Features

- **[Feature Name]**: [Brief description] (Added in v2.1.0)
```

### Update CHANGELOG.md

```markdown
## [2.1.0] - 2025-XX-XX

### Added
- **[Feature Name]**: [Description of what was added and why]
  - [Specific capability 1]
  - [Specific capability 2]
  - [Performance improvement or benefit]
```

---

## Feature Templates

### Simple Data Transformation Feature

```php
class WC_Multi_Store_[Feature] {
    public function transform_data(array $data, array $config): array {
        // Validate
        if (empty($data)) {
            return $data;
        }

        // Apply transformation
        $transformed = $this->apply_transformation($data, $config);

        // Allow filtering
        return apply_filters('wc_mss_[feature]_data', $transformed, $data, $config);
    }

    private function apply_transformation(array $data, array $config): array {
        // Implementation
        return $data;
    }
}
```

### Background Processing Feature

```php
class WC_Multi_Store_[Feature] {
    public function queue_processing(int $product_id, array $params): void {
        $queue_manager = WC_Multi_Store_Queue_Manager::instance();

        $queue_manager->add_to_queue([
            'action' => '[feature]_process',
            'product_id' => $product_id,
            'params' => $params,
            'priority' => 'normal'
        ]);
    }

    public function process_queue_item(array $item): array {
        return $this->process_feature($item['product_id'], $item['params']);
    }
}
```

### Admin Interface Feature

```php
class WC_Multi_Store_[Feature]_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function add_menu_page(): void {
        add_submenu_page(
            'wc-multi-store-sync',
            __('[Feature]', 'wc-multi-store-sync'),
            __('[Feature]', 'wc-multi-store-sync'),
            'manage_woocommerce',
            'wc-multi-store-[feature]',
            [$this, 'render_page']
        );
    }

    public function render_page(): void {
        require_once plugin_dir_path(__FILE__) . '../views/[feature].php';
    }

    public function enqueue_scripts($hook): void {
        if ($hook !== 'multi-store-sync_page_wc-multi-store-[feature]') {
            return;
        }

        wp_enqueue_script(
            'wc-mss-[feature]',
            plugins_url('admin/js/[feature].js', dirname(__FILE__)),
            ['jquery'],
            '1.0.0',
            true
        );
    }
}
```

---

## Commit Message Template

```
feat: Add [feature name]

This commit implements [feature name] which allows users to [main benefit].

Key changes:
- Added WC_Multi_Store_[Feature_Name] class
- Created database table for [purpose]
- Added admin UI for [purpose]
- Integrated with sync engine
- Added hooks: wc_mss_before_[feature], wc_mss_after_[feature]

Performance impact:
- [Metric]: [Value]
- [Metric]: [Value]

Testing:
- Tested with [scenarios]
- All tests passing

Closes #[issue-number]
```

---

**Last Updated:** December 6, 2025
**Plugin Version:** 2.0.0
