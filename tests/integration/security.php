<?php
// Disposable integration environment only; invoked by run.sh via WP-CLI.
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    exit;
}

function mss_security_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$product = new WC_Product_Simple();
$product->set_name('Stock receipt regression');
$product->set_sku('MSS-SECURITY-STOCK');
$product->set_manage_stock(true);
$product->set_stock_quantity(20);
$product->save();
update_option('wc_multi_store_sync_webhook_settings', [
    'enabled' => true, 'webhook_secret' => 'disposable-integration-secret',
    'trigger_statuses' => ['processing', 'completed'], 'allow_negative_stock' => false,
]);
WC_Multi_Store_Settings::clear_static_cache();
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';

$deliver = static function (int $order_id, string $store, string $status = 'processing') {
    $body = wp_json_encode([
        'id' => $order_id, 'status' => $status,
        'line_items' => [['sku' => 'MSS-SECURITY-STOCK', 'quantity' => 3]],
    ]);
    $request = new WP_REST_Request('POST', '/wc-multi-store-sync/v1/webhook/order');
    $request->set_header('content-type', 'application/json');
    $request->set_header('x-wc-webhook-signature', base64_encode(hash_hmac('sha256', $body, 'disposable-integration-secret', true)));
    $request->set_query_params(['store_url' => $store]);
    $request->set_body($body);
    return rest_do_request($request);
};

$first = $deliver(501, 'http://target');
mss_security_check($first->get_status() === 200, 'Initial webhook failed');
mss_security_check((int) wc_get_product($product->get_id())->get_stock_quantity() === 17, 'Initial stock deduction failed');
foreach ([['http://target/', 'processing'], ['http://target//', 'completed']] as [$store, $status]) {
    $response = $deliver(501, $store, $status);
    mss_security_check($response->get_status() === 200 && !empty($response->get_data()['duplicate']), 'Order receipt did not survive URL/status changes');
}
delete_transient('wc_mss_wh_seen_' . md5('http://target|501|processing'));
$response = $deliver(501, 'http://target');
mss_security_check(!empty($response->get_data()['duplicate']), 'Receipt depended on transient storage');
mss_security_check((int) wc_get_product($product->get_id())->get_stock_quantity() === 17, 'Duplicate delivery changed stock');

// An interrupted delivery must not release an order for another stock write.
$pending_key = 'wc_mss_wh_order_' . md5('http://target|502');
add_option($pending_key, ['state' => 'pending'], '', false);
$pending = $deliver(502, 'http://target');
mss_security_check($pending->get_status() === 503, 'Pending order did not fail closed');
mss_security_check((int) wc_get_product($product->get_id())->get_stock_quantity() === 17, 'Pending order changed stock');

global $wpdb;
$autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'wc_mss_wh_order_' . md5('http://target|501')));
mss_security_check(in_array($autoload, ['no', 'off'], true), 'Order receipts must not autoload');
WP_CLI::success('security: durable stock receipts');
