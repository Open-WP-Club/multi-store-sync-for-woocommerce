<?php
// Run only through tests/integration/run.sh, inside disposable WordPress containers.
function mss_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$stage = $args[0];
if ($stage === 'setup-target' || $stage === 'setup-source') {
    update_option('wc_multi_store_sync_settings', ['enabled' => false]);
    WC_Multi_Store_Settings::clear_static_cache();
    if ($stage === 'setup-target') {
        $filler = new WC_Product_Simple();
        $filler->set_name('Different local IDs');
        $filler->save();
    }
    $product = new WC_Product_Simple();
    $product->set_name('Integration product');
    $product->set_sku('MSS-INTEGRATION');
    $product->set_regular_price('20');
    $product->set_status('publish');
    $product->save();
    update_option('mss_test_product_id', $product->get_id());
    if ($stage === 'setup-target') {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'woocommerce_api_keys', [
            'user_id' => 1, 'description' => 'Disposable integration key', 'permissions' => 'read_write',
            'consumer_key' => wc_api_hash('ck_integration'), 'consumer_secret' => 'cs_integration', 'truncated_key' => 'gration',
        ]);
    } else {
        update_option('wc_multi_store_sync_settings', ['enabled' => true, 'auth_method' => 'basic_auth', 'shipping_class_sync_enabled' => true]);
        update_option('wc_multi_store_sync_coupon_settings', ['enabled' => true]);
        update_option('wc_mss_review_sync_settings', ['enabled' => true]);
        update_option('wc_mss_attribute_sync_settings', ['enabled' => true]);
        update_option('wc_multi_store_sync_stores', ['http://target' => [
            'store_url' => 'http://target', 'status' => 'active', 'consumer_key' => 'ck_integration', 'consumer_secret' => 'cs_integration',
        ]]);
    }
} elseif ($stage === 'create') {
    $coupon = new WC_Coupon();
    $coupon->set_code('mss-integration');
    $coupon->set_discount_type('percent');
    $coupon->set_amount('10');
    $coupon->set_product_ids([(int) get_option('mss_test_product_id')]);
    $coupon->set_email_restrictions(['buyer@example.test']);
    $coupon->save();
    update_option('mss_test_coupon_id', $coupon->get_id());

    $shipping = wp_insert_term('Integration shipping', 'product_shipping_class', ['slug' => 'mss-shipping']);
    mss_check(!is_wp_error($shipping), 'Could not create shipping class');
    update_option('mss_test_shipping_id', $shipping['term_id']);
    $attribute_id = wc_create_attribute(['name' => 'Integration color', 'slug' => 'mss-color']);
    mss_check(!is_wp_error($attribute_id), 'Could not create attribute');
    update_option('mss_test_attribute_id', $attribute_id);
    register_taxonomy('pa_mss-color', ['product']);
    $term = wp_insert_term('Red', 'pa_mss-color', ['slug' => 'red']);
    mss_check(!is_wp_error($term), 'Could not create attribute term');

    $review_id = wp_new_comment([
        'comment_post_ID' => (int) get_option('mss_test_product_id'), 'comment_type' => 'review',
        'comment_author' => 'Integration buyer', 'comment_author_email' => 'buyer@example.test',
        'comment_content' => 'Original review', 'comment_approved' => 1, 'user_id' => 1,
    ], true);
    mss_check(!is_wp_error($review_id), 'Could not create review');
    update_comment_meta($review_id, 'rating', 5);
    update_option('mss_test_review_id', $review_id);
    mss_check((bool) as_next_scheduled_action(WC_Multi_Store_Coupon_Sync::ASYNC_SYNC_HOOK, [$coupon->get_id()], 'wc_multi_store_sync'), 'Coupon save did not queue a job');
} elseif ($stage === 'update') {
    $coupon = new WC_Coupon((int) get_option('mss_test_coupon_id'));
    $coupon->set_product_ids([]);
    $coupon->set_email_restrictions([]);
    $coupon->set_amount('15');
    $coupon->save();
    wp_update_term((int) get_option('mss_test_shipping_id'), 'product_shipping_class', ['description' => 'Updated shipping']);
    wc_update_attribute((int) get_option('mss_test_attribute_id'), ['name' => 'Updated color', 'slug' => 'mss-color']);
    wp_update_comment(['comment_ID' => (int) get_option('mss_test_review_id'), 'comment_content' => 'Updated review', 'comment_approved' => 0]);
} elseif ($stage === 'delete') {
    (new WC_Coupon((int) get_option('mss_test_coupon_id')))->delete(true);
    wp_delete_term((int) get_option('mss_test_shipping_id'), 'product_shipping_class');
    wc_delete_attribute((int) get_option('mss_test_attribute_id'));
    wp_delete_comment((int) get_option('mss_test_review_id'), true);
} elseif (str_starts_with($stage, 'verify-') && $stage !== 'verify-jobs') {
    $coupon_id = wc_get_coupon_id_by_code('mss-integration');
    $shipping = get_term_by('slug', 'mss-shipping', 'product_shipping_class');
    $attribute_id = wc_attribute_taxonomy_id_by_name('mss-color');
    $reviews = get_comments(['post_id' => (int) get_option('mss_test_product_id'), 'type' => 'review', 'author_email' => 'buyer@example.test', 'status' => 'all']);
    if ($stage === 'verify-delete') {
        mss_check(!$coupon_id && !$shipping && !$attribute_id && !$reviews, 'Remote deletion incomplete');
    } else {
        mss_check((bool) $coupon_id && (bool) $shipping && (bool) $attribute_id && count($reviews) === 1, 'Remote records missing or duplicated');
        $coupon = new WC_Coupon($coupon_id);
        mss_check((bool) get_comment_meta($reviews[0]->comment_ID, WC_Multi_Store_Review_Sync::SYNCED_MARKER_META, true), 'Review loop marker missing');
        mss_check(!get_comment_meta($reviews[0]->comment_ID, 'verified', true), 'Synced review incorrectly verified');
        if ($stage === 'verify-create') {
            mss_check($coupon->get_product_ids() === [(int) get_option('mss_test_product_id')], 'Coupon restriction mapped to wrong product');
            mss_check($coupon->get_email_restrictions() === ['buyer@example.test'], 'Email restriction missing');
            mss_check((bool) get_term_by('slug', 'red', 'pa_mss-color'), 'Attribute term missing');
        } else {
            mss_check(!$coupon->get_product_ids() && !$coupon->get_email_restrictions() && (float) $coupon->get_amount() === 15.0, 'Coupon restrictions not cleared');
            mss_check($shipping->description === 'Updated shipping', 'Shipping class not updated');
            mss_check(wc_get_attribute($attribute_id)->name === 'Updated color', 'Attribute not updated');
            mss_check($reviews[0]->comment_content === 'Updated review' && $reviews[0]->comment_approved === '0', 'Review not updated');
        }
    }
} elseif ($stage === 'verify-jobs') {
    $failed = as_get_scheduled_actions(['group' => 'wc_multi_store_sync', 'status' => 'failed'], 'ids');
    mss_check(!$failed, 'Action Scheduler contains failed jobs: ' . implode(', ', $failed));
} else {
    throw new RuntimeException('Unknown test stage: ' . $stage);
}
WP_CLI::success($stage);
