<?php
// Disposable local integration environment only. Stages run in separate PHP processes.
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    exit;
}

function mss_concurrency_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mss_concurrency_deliver(bool $test = false, string $status = 'processing'): WP_REST_Response {
    $_SERVER['REMOTE_ADDR'] = $test ? '192.0.2.212' : '192.0.2.211';
    $payload = $test ? ['test' => true] : [
        'id' => 700501,
        'status' => $status,
        'line_items' => [['sku' => 'MSS-CONCURRENT-STOCK', 'quantity' => 3]],
    ];
    $body = wp_json_encode($payload);
    $request = new WP_REST_Request('POST', '/wc-multi-store-sync/v1/webhook/' . ($test ? 'test' : 'order'));
    $request->set_header('content-type', 'application/json');
    $request->set_header('x-wc-webhook-signature', base64_encode(hash_hmac('sha256', $body, 'disposable-concurrency-secret', true)));
    $request->set_query_params(['store_url' => $status === 'completed' ? 'http://target/' : 'http://target']);
    $request->set_body($body);
    return rest_do_request($request);
}

$mode = getenv('WC_MSS_CACHE_MODE') ?: 'none';
mss_concurrency_check(in_array($mode, ['none', 'redis'], true), 'Unknown integration cache mode');
$stage = $args[0] ?? '';
$cache_value = wp_using_ext_object_cache();
$cache_diagnostic = wp_json_encode([
    'mode' => $mode,
    'using_external_cache' => $cache_value,
    'value_type' => get_debug_type($cache_value),
    'cache_class' => isset($GLOBALS['wp_object_cache']) ? get_debug_type($GLOBALS['wp_object_cache']) : 'unset',
    'drop_in_exists' => is_file(WP_CONTENT_DIR . '/object-cache.php'),
]);
if ($stage === 'setup') {
    WP_CLI::log('concurrency cache diagnostic: ' . $cache_diagnostic);
}
// Core's native WP-CLI cache path may leave the external-cache flag null.
// Match its boolean semantics while retaining the exact value for diagnostics.
mss_concurrency_check((bool) $cache_value === ($mode === 'redis'), 'Object cache does not match requested integration mode: ' . $cache_diagnostic);
$barrier = '/tmp/wc-mss-concurrency-' . $mode;
$key = 'wc_mss_wh_order_' . md5('http://target|700501');
$rate_key = 'wc_mss_webhook_rate_' . md5('192.0.2.212');

switch ($stage) {
    case 'setup':
        if (!is_dir($barrier)) {
            mkdir($barrier, 0700, true);
        }
        foreach (['held', 'release', 'late-claim', 'release-claim'] as $file) {
            if (is_file($barrier . '/' . $file)) {
                unlink($barrier . '/' . $file);
            }
        }
        delete_option($key);
        foreach (['processing', 'completed'] as $status) {
            foreach (['http://target', 'http://target/'] as $store) {
                delete_transient('wc_mss_wh_seen_' . md5($store . '|700501|' . $status));
            }
        }
        $product_id = wc_get_product_id_by_sku('MSS-CONCURRENT-STOCK');
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
        $product->set_name('Concurrent stock integration');
        $product->set_sku('MSS-CONCURRENT-STOCK');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(20);
        $product->save();
        update_option('wc_multi_store_sync_webhook_settings', [
            'enabled' => true,
            'webhook_secret' => 'disposable-concurrency-secret',
            'trigger_statuses' => ['processing', 'completed'],
            'allow_negative_stock' => false,
        ]);
        WP_CLI::success('concurrency setup: ' . $mode);
        break;

    case 'held':
        // Hold AFTER the unique order claim and BEFORE the actual stock write.
        // The competing WP-CLI process must observe pending and leave stock untouched.
        $paused = false;
        add_filter('query', static function (string $query) use ($barrier, &$paused): string {
            if (!$paused && str_contains($query, 'SET meta_value = CAST(meta_value AS SIGNED) -')) {
                $paused = true;
                file_put_contents($barrier . '/held', (string) getmypid());
                $deadline = microtime(true) + 40;
                do {
                    clearstatcache(true, $barrier . '/release');
                    if (is_file($barrier . '/release')) {
                        return $query;
                    }
                    usleep(50000);
                } while (microtime(true) < $deadline);
                throw new RuntimeException('Concurrency barrier timed out');
            }
            return $query;
        });
        $response = mss_concurrency_deliver();
        mss_concurrency_check($paused, 'Stock write never reached concurrency barrier');
        mss_concurrency_check($response->get_status() === 200 && ($response->get_data()['products_processed'] ?? 0) === 1, 'Claim owner did not complete exactly one deduction');
        WP_CLI::success('concurrency owner completed: ' . $mode);
        break;

    case 'late-claim':
        // Pause just before INSERT. Another process will finish the order while
        // this request is waiting; the late insert must never overwrite it.
        $paused = false;
        add_filter('query', static function (string $query) use ($barrier, $key, &$paused): string {
            if (!$paused && str_starts_with($query, 'INSERT IGNORE INTO') && str_contains($query, $key)) {
                $paused = true;
                file_put_contents($barrier . '/late-claim', (string) getmypid());
                $deadline = microtime(true) + 40;
                do {
                    clearstatcache(true, $barrier . '/release-claim');
                    if (is_file($barrier . '/release-claim')) {
                        return $query;
                    }
                    usleep(50000);
                } while (microtime(true) < $deadline);
                throw new RuntimeException('Late claim barrier timed out');
            }
            return $query;
        });
        $response = mss_concurrency_deliver();
        mss_concurrency_check($paused, 'Late claimant did not reach insert barrier');
        mss_concurrency_check($response->get_status() === 200 && !empty($response->get_data()['duplicate']), 'Late claimant overwrote a completed receipt');
        WP_CLI::success('late concurrent claim preserved completed receipt: ' . $mode);
        break;

    case 'deliver':
        $response = mss_concurrency_deliver();
        mss_concurrency_check($response->get_status() === 200 && ($response->get_data()['products_processed'] ?? 0) === 1, 'Independent claim owner failed');
        WP_CLI::success('independent claim owner completed: ' . $mode);
        break;

    case 'contender':
        mss_concurrency_check(is_file($barrier . '/held'), 'Claim owner is not waiting at barrier');
        $response = mss_concurrency_deliver();
        mss_concurrency_check($response->get_status() === 503, 'Concurrent delivery did not receive pending 503');
        $product = wc_get_product(wc_get_product_id_by_sku('MSS-CONCURRENT-STOCK'));
        mss_concurrency_check((int) $product->get_stock_quantity() === 20, 'Concurrent delivery changed stock before owner was released');
        WP_CLI::success('concurrent delivery blocked: ' . $mode);
        break;

    case 'verify':
        $response = mss_concurrency_deliver(false, 'completed');
        mss_concurrency_check($response->get_status() === 200 && !empty($response->get_data()['duplicate']), 'Completed order was not acknowledged as duplicate');
        $product = wc_get_product(wc_get_product_id_by_sku('MSS-CONCURRENT-STOCK'));
        mss_concurrency_check((int) $product->get_stock_quantity() === 17, 'Separate-process deliveries deducted stock more than once');
        $receipt = get_option($key);
        mss_concurrency_check(is_array($receipt) && $receipt['state'] === 'completed', 'Durable receipt was not completed');
        WP_CLI::success('separate-process stock concurrency verified: ' . $mode);
        break;

    case 'rate-prime':
        delete_transient($rate_key);
        wp_cache_delete($rate_key, 'wc_mss_rate_limits');
        // Actual authenticated deliveries establish the bucket, without seeding counters.
        for ($i = 0; $i < 99; $i++) {
            mss_concurrency_check(mss_concurrency_deliver(true)->get_status() === 200, 'Rate limiter blocked before request 100');
        }
        WP_CLI::success('rate bucket primed by 99 requests: ' . $mode);
        break;

    case 'rate-check':
        // This is a fresh PHP process: request-local cache cannot pass this test.
        mss_concurrency_check(mss_concurrency_deliver(true)->get_status() === 200, 'Request 100 should be allowed');
        mss_concurrency_check(mss_concurrency_deliver(true)->get_status() === 429, 'Request 101 should be rejected across PHP processes');
        $count = $mode === 'redis' ? wp_cache_get($rate_key, 'wc_mss_rate_limits') : get_transient($rate_key);
        mss_concurrency_check((int) $count === 101, 'Persistent rate counter did not record both processes');
        WP_CLI::success('separate-process rate limit verified: ' . $mode);
        break;

    default:
        throw new RuntimeException('Unknown concurrency stage');
}
