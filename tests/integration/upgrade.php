<?php
// Disposable integration environment only; invoked by run.sh via WP-CLI.
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    exit;
}

function mss_upgrade_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$uploads = wp_upload_dir();
$directory = $uploads['basedir'] . '/wc-mss-logs/';
// Compose simulates HTTPS termination for Basic Auth but Apache listens on HTTP.
$http_upload_url = set_url_scheme($uploads['baseurl'], 'http');
mss_upgrade_check(wp_mkdir_p($directory), 'Could not create legacy archive fixture');
$legacy_payload = wp_json_encode([
    'total_records' => 2,
    'records' => [
        ['message' => 'UPGRADE_PRIVATE_ARCHIVE_SENTINEL', 'created_at' => '2001-01-01 00:00:00'],
        ['message' => '<?php echo 123456789; ?>', 'created_at' => '2001-01-02 00:00:00'],
    ],
]);
$legacy_files = [
    $directory . 'history-archive-2001-01-01-to-2001-01-02.json',
    $directory . 'deletion-audit-archive-2001-01-01-to-2001-01-02.json',
];
$probe = $uploads['basedir'] . '/wc-mss-upgrade-guard-probe.php';
$download_path = $uploads['basedir'] . '/woocommerce_uploads/upgrade-original.pdf';
$protected_files = [];
$current_archive = substr($legacy_files[0], 0, -5) . '.php';
$current_payload = ['records' => [['message' => 'NEWER_BATCH_SAME_DATE_RANGE']]];
$saved_settings = get_option('wc_multi_store_sync_settings', []);
$legacy_settings = array_replace($saved_settings, [
    'downloadable_files_sync_enabled' => true,
    'downloadable_files_sync_transfer_mode' => 'api',
]);
$saved_stores = get_option('wc_multi_store_sync_stores', []);
$saved_webhooks = get_option('wc_multi_store_sync_webhook_settings', []);
$http_requests = [];
$observe_requests = static function ($preempt, array $arguments, string $url) use (&$http_requests) {
    $http_requests[] = $url;
    return $preempt;
};
try {
    foreach ($legacy_files as $file) {
        mss_upgrade_check(file_put_contents($file, $legacy_payload) === strlen($legacy_payload), 'Legacy archive fixture write failed');
        $protected_files[] = substr($file, 0, -5) . '-legacy-' . hash('sha256', $legacy_payload) . '.php';
    }
    mss_upgrade_check(WC_Multi_Store_Logger::write_protected_archive($current_archive, $current_payload), 'Could not seed newer archive with overlapping date range');
    $current_bytes = file_get_contents($current_archive);
    update_option('wc_multi_store_sync_settings', $legacy_settings);
    WC_Multi_Store_Settings::clear_static_cache();
    delete_option('wc_mss_archive_storage_version');
    wp_set_current_user(1);
    // Find and invoke the actual registered migration callback. Invoking every
    // admin_init callback from WP-CLI also invokes unrelated WooCommerce admin
    // controllers without their admin bootstrap dependencies.
    global $wp_filter;
    $migration = null;
    foreach ($wp_filter['admin_init']->callbacks ?? [] as $callbacks) {
        foreach ($callbacks as $registration) {
            $callback = $registration['function'];
            if ($callback instanceof Closure) {
                $reflection = new ReflectionFunction($callback);
                if ($reflection->getName() === 'migrate_archive_storage'
                    && $reflection->getClosureScopeClass()?->getName() === WC_Multi_Store_Logger::class) {
                    $migration = $callback;
                }
            }
        }
    }
    mss_upgrade_check($migration !== null && has_action('admin_init', $migration) !== false, 'Archive upgrade is not registered on admin_init');
    $migration();
    mss_upgrade_check((int) get_option('wc_mss_archive_storage_version') === 1, 'Archive upgrade was not marked complete');
    mss_upgrade_check(get_option('wc_multi_store_sync_settings', []) === $legacy_settings && get_option('wc_multi_store_sync_stores', []) === $saved_stores && get_option('wc_multi_store_sync_webhook_settings', []) === $saved_webhooks, 'Archive upgrade changed existing store credentials or plugin settings');
    foreach ($legacy_files as $index => $legacy_file) {
        mss_upgrade_check(!file_exists($legacy_file), 'Legacy public JSON remained after upgrade');
        $content = file_get_contents($protected_files[$index]);
        mss_upgrade_check($content === WC_Multi_Store_Logger::ARCHIVE_GUARD . $legacy_payload, 'Archive migration changed or lost original bytes');
        $response = wp_remote_get($http_upload_url . '/wc-mss-logs/' . basename($protected_files[$index]), ['timeout' => 10]);
        $diagnostic = is_wp_error($response) ? $response->get_error_code() . ': ' . $response->get_error_message()
            : 'HTTP ' . wp_remote_retrieve_response_code($response) . ', body bytes ' . strlen(wp_remote_retrieve_body($response));
        $protected = !is_wp_error($response) && (wp_remote_retrieve_response_code($response) === 403
            || (wp_remote_retrieve_response_code($response) === 200 && wp_remote_retrieve_body($response) === ''));
        mss_upgrade_check($protected, 'Archive HTTP access was neither denied nor stopped by its PHP guard: ' . $diagnostic);
        mss_upgrade_check(!str_contains(wp_remote_retrieve_body($response), 'UPGRADE_PRIVATE_ARCHIVE_SENTINEL'), 'Archive data escaped through Apache');
    }
    mss_upgrade_check(file_get_contents($current_archive) === $current_bytes, 'Upgrade overwrote a newer archive sharing the same date range');
    $before = array_map('hash_file', array_fill(0, count($protected_files), 'sha256'), $protected_files);
    $migration();
    $after = array_map('hash_file', array_fill(0, count($protected_files), 'sha256'), $protected_files);
    mss_upgrade_check($before === $after, 'Repeated upgrade changed an existing protected archive');

    // Execute the same protected archive through Apache outside the deny directory.
    // This proves the PHP guard itself works even without archive .htaccess rules.
    mss_upgrade_check(copy($protected_files[0], $probe), 'Could not create PHP guard execution probe');
    $response = wp_remote_get($http_upload_url . '/' . basename($probe), ['timeout' => 10]);
    mss_upgrade_check(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 && wp_remote_retrieve_body($response) === '', 'PHP guard did not stop archive disclosure/execution');

    $download = new WC_Product_Download();
    $download->set_id('upgrade-download');
    $download->set_name('Protected original');
    mss_upgrade_check(wp_mkdir_p(dirname($download_path)), 'Could not create original protected download directory');
    mss_upgrade_check(file_put_contents($download_path, '%PDF-1.4 UPGRADE_PRIVATE_DOWNLOAD_SENTINEL') !== false, 'Could not seed original downloadable file');
    $original_url = $uploads['baseurl'] . '/woocommerce_uploads/' . basename($download_path);
    $download->set_file($original_url);
    // The object is intentionally not saved: this scenario upgrades stored plugin
    // settings, and must not enqueue another product synchronization scenario.
    $product = new WC_Product_Simple();
    $product->set_downloadable(true);
    $product->set_downloads([$download]);
    $product->set_download_limit(3);
    $product->set_download_expiry(5);
    add_filter('pre_http_request', $observe_requests, 10, 3);
    $client = new WC_Multi_Store_API_Client('http://http-fixture', 'fixture-key', 'fixture-secret', 'basic_auth');
    $extracted = WC_Multi_Store_Downloadable_Files_Sync::extract_downloads($product);
    $synced = WC_Multi_Store_Downloadable_Files_Sync::sync_downloads($client, $product, [], 'http://http-fixture');
    remove_filter('pre_http_request', $observe_requests, 10);
    mss_upgrade_check($extracted === [['name' => 'Protected original', 'file' => $original_url]], 'Legacy API mode embedded file content or changed its URL');
    mss_upgrade_check($synced['downloads'] === $extracted && $synced['download_limit'] === 3 && $synced['download_expiry'] === 5, 'Legacy API mode lost download access settings');
    mss_upgrade_check($http_requests === [], 'Legacy API mode unexpectedly fetched or uploaded a protected download');
    mss_upgrade_check(get_option('wc_multi_store_sync_settings')['downloadable_files_sync_transfer_mode'] === 'api', 'Upgrade corrupted the existing legacy setting');
} finally {
    remove_filter('pre_http_request', $observe_requests, 10);
    update_option('wc_multi_store_sync_settings', $saved_settings);
    WC_Multi_Store_Settings::clear_static_cache();
    foreach (array_merge($legacy_files, $protected_files, [$probe, $current_archive, $download_path]) as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}
WP_CLI::success('upgrade: legacy archives preserve bytes, deny HTTP access, and legacy downloads retain protected URLs');
