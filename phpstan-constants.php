<?php
// Constants normally defined by wc-multi-store-sync.php at runtime — declared
// here so PHPStan can resolve them without executing the plugin bootstrap.

define('WC_MSS_VERSION', '4.1.0');
define('WC_MSS_PLUGIN_FILE', __DIR__ . '/wc-multi-store-sync.php');
define('WC_MSS_PLUGIN_DIR', __DIR__ . '/');
define('WC_MSS_PLUGIN_URL', 'https://example.com/wp-content/plugins/wc-multi-store-sync/');
define('WC_MSS_PLUGIN_BASENAME', 'wc-multi-store-sync/wc-multi-store-sync.php');
