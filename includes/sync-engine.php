<?php
/**
 * WooCommerce Multi-Store Sync Engine
 *
 * Handles product data extraction and synchronization logic
 *
 * @package WC_Multi_Store_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync Engine Class
 */
class WC_Multi_Store_Sync_Engine {

    /**
     * Category/Tag cache TTL in seconds (24 hours)
     * Cleared during daily maintenance and weekly verification
     */
    const TERM_CACHE_TTL = DAY_IN_SECONDS;

    /**
     * Chunk size when retrying batch term creation after a full-batch failure.
     * Some remotes reject 50-item batches but accept ~10-item ones; this also
     * caps blast radius when only some items are problematic.
     */
    const int TERM_FALLBACK_CHUNK_SIZE = 10;

    /**
     * Consecutive per-item failures that trip the abort guard during the
     * per-item fallback path. Prevents blasting a misbehaving remote with
     * dozens of doomed requests after the batch endpoint has already failed.
     */
    const int TERM_FALLBACK_MAX_FAILURES = 5;

    /**
     * Logger instance (PSR-3 compliant)
     *
     * @var \Psr\Log\LoggerInterface
     */
    private \Psr\Log\LoggerInterface $logger;

    /**
     * Cached active stores (per-request cache)
     *
     * @var array|null
     */
    private ?array $cached_active_stores = null;

    /**
     * Product extractor instance
     *
     * @var WC_Multi_Store_Product_Extractor
     */
    private WC_Multi_Store_Product_Extractor $extractor;

    /**
     * Product transformer instance
     *
     * @var WC_Multi_Store_Product_Transformer
     */
    private WC_Multi_Store_Product_Transformer $transformer;

    /**
     * Remote product manager instance
     *
     * @var WC_Multi_Store_Remote_Product_Manager
     */
    private WC_Multi_Store_Remote_Product_Manager $remote_manager;

    /**
     * Variation synchronizer instance
     *
     * @var WC_Multi_Store_Variation_Synchronizer
     */
    private WC_Multi_Store_Variation_Synchronizer $variation_sync;

    /**
     * Constructor
     *
     * @param WC_Multi_Store_API_Client|null $api_client API client instance
     */
    public function __construct(
        private ?WC_Multi_Store_API_Client $api_client = null
    ) {
        $this->logger = new WC_Multi_Store_Logger();

        // Initialize component instances
        $this->extractor = new WC_Multi_Store_Product_Extractor();
        $this->transformer = new WC_Multi_Store_Product_Transformer();
        $this->remote_manager = new WC_Multi_Store_Remote_Product_Manager();
        $this->variation_sync = new WC_Multi_Store_Variation_Synchronizer($this->extractor, $this->transformer);
    }

    /**
     * Get settings (uses centralized cache via WC_Multi_Store_Settings)
     *
     * @return array Settings array
     */
    private function get_settings(): array {
        return WC_Multi_Store_Settings::get_settings();
    }

    /**
     * Get cached active stores
     *
     * @return array Active stores array
     */
    private function get_active_stores(): array {
        if ($this->cached_active_stores === null) {
            $this->cached_active_stores = WC_Multi_Store_Settings::get_active_stores();
        }
        return $this->cached_active_stores;
    }


    /**
     * Initialize sync context with performance tracking and API client
     * Eliminates duplicate initialization code across methods
     *
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @return array Context array with start_time, start_memory, api_calls, api, and settings
     */
    private function initialize_sync_context(string $store_url, array $store_config): array {
        // Track performance
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);

        // Initialize API client for this store (using cached settings)
        $settings = $this->get_settings();
        $api = WC_Multi_Store_API_Client::for_store($store_url, $store_config);

        return [
            'start_time' => $start_time,
            'start_memory' => $start_memory,
            'api_calls' => 0,
            'api' => $api,
            'settings' => $settings,
        ];
    }

    /**
     * Consolidated history logging method
     * Handles logging for all sync operations
     *
     * @param array $args {
     *     Logging arguments
     *     @type WC_Product|null $product Product object (optional if product_id/sku/name provided)
     *     @type int|null $product_id Product ID (optional if product provided)
     *     @type string|null $product_sku Product SKU (optional if product provided)
     *     @type string|null $product_name Product name (optional if product provided)
     *     @type string $store_url Store URL
     *     @type string $sync_type Sync type
     *     @type string $sync_source Sync source
     *     @type string $status Status (success/error)
     *     @type string $message Message
     *     @type int|null $remote_product_id Remote product ID
     *     @type float $start_time Start microtime
     *     @type int $start_memory Start memory usage
     *     @type int $api_calls Number of API calls
     * }
     * @return void
     */
    private function log_operation_history(array $args): void {
        // Calculate performance metrics
        $duration_ms = round((microtime(true) - $args['start_time']) * 1000);
        $memory_used = memory_get_usage(true) - $args['start_memory'];
        $memory_mb = round($memory_used / 1024 / 1024, 2);

        // Extract product info from product object or individual fields
        if (isset($args['product']) && $args['product']) {
            $product_id = $args['product']->get_id();
            $product_sku = $args['product']->get_sku();
            $product_name = $args['product']->get_name();
        } else {
            $product_id = $args['product_id'] ?? 0;
            $product_sku = $args['product_sku'] ?? '';
            $product_name = $args['product_name'] ?? '';
        }

        // Log to history table
        WC_Multi_Store_Sync_History::log_sync([
            'product_id' => $product_id,
            'product_sku' => $product_sku,
            'product_name' => $product_name,
            'store_url' => $args['store_url'],
            'sync_type' => $args['sync_type'],
            'sync_source' => $args['sync_source'],
            'status' => $args['status'],
            'message' => $args['message'],
            'remote_product_id' => $args['remote_product_id'] ?? null,
            'duration_ms' => $duration_ms,
            'memory_mb' => $memory_mb,
            'api_calls' => $args['api_calls'],
        ]);
    }

    /**
     * Sync product to multiple stores
     *
     * @param int $product_id Local product ID
     * @param array $stores Array of store configurations
     * @param string $sync_type Sync type: full_product|price_quantity|quantity
     * @return array Results array with success/error info per store
     */
    public function sync_product($product_id, $stores, $sync_type = 'full_product'): array {
        $product = wc_get_product($product_id);

        if (!$product) {
            $this->logger->error('Product not found: ' . $product_id);
            return [
                'success' => false,
                'message' => 'Product not found',
            ];
        }

        $results = [];

        foreach ($stores as $store_url => $store_config) {
            // Skip if store is not active
            if (isset($store_config['status']) && $store_config['status'] !== 'active') {
                continue;
            }

            // Check if product should be excluded for this store
            if ($this->should_exclude_product($product, $store_config)) {
                $results[$store_url] = [
                    'success' => false,
                    'message' => 'Product excluded by category/tag filter',
                    'excluded' => true,
                ];
                continue;
            }

            $result = $this->sync_product_to_store($product, $store_url, $store_config, $sync_type);
            $results[$store_url] = $result;
        }

        return $results;
    }

    /**
     * Check if product should be excluded for a store
     *
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return bool True if product should be excluded
     */
    private function should_exclude_product($product, $store_config): bool {
        return WC_Multi_Store_Product_Exclusion_Filter::should_exclude($product, $store_config);
    }

    /**
     * Apply per-store rules to product data (pricing and stock allocation)
     *
     * @param array $product_data Product data array
     * @param WC_Product $product Product object
     * @param array $store_config Store configuration
     * @return array Modified product data
     */
    private function apply_store_rules(array $product_data, WC_Product $product, array $store_config): array {
        return $this->transformer->apply_store_rules($product_data, $product, $store_config);
    }

    /**
     * Check if an error message indicates an image download failure
     *
     * Detects image-related errors from remote WooCommerce stores, which may
     * be translated. Matches on HTTP status text (Forbidden/Not Found) and
     * common URL patterns that indicate image access issues.
     *
     * @param string $message Error message
     * @return bool True if the error is image-related
     */
    private function is_image_download_error(string $message): bool {
        // HTTP status texts are typically not translated
        $image_error_patterns = [
            'Forbidden',           // HTTP 403 status text
            '403',                 // HTTP status code
            'Not Acceptable',      // HTTP 406 (CDN blocks)
        ];

        // Check if error mentions image-related URL patterns
        $has_image_url = str_contains($message, 'wp-content/uploads/')
            || str_contains($message, '/image/')
            || (bool) preg_match('/\.(jpg|jpeg|png|gif|webp|avif)/i', $message);

        if (!$has_image_url) {
            return false;
        }

        foreach ($image_error_patterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upload product images to remote store via API and return data with remote IDs
     *
     * Reads image files from local disk, base64-encodes them, and sends to the
     * remote store's upload endpoint. Returns image data with {id} instead of {src}
     * so WooCommerce doesn't need to download images over HTTP.
     *
     * @param WC_Multi_Store_API_Client $api API client
     * @param WC_Product $product Product object (for logging)
     * @param array $images Original images array with 'src' and 'position'
     * @return array Modified images array with 'id' for uploaded images, 'src' for failures
     */
    private function upload_images_via_api(WC_Multi_Store_API_Client $api, WC_Product $product, array $images): array {
        $uploaded = [];
        $failed_count = 0;
        $all_ids = array_merge(
            [$product->get_image_id()],
            $product->get_gallery_image_ids()
        );

        foreach ($images as $index => $image) {
            $attachment_id = $all_ids[$index] ?? 0;
            if (!$attachment_id) {
                // No local attachment ID — skip this image entirely when proxy is enabled
                // Sending a src URL would cause the remote store to download it, which may
                // fail silently and leave orphaned media attachments in the remote library
                $this->logger->warning(sprintf(
                    'No local attachment ID for image index %d of product %s — skipping (proxy enabled)',
                    $index,
                    $product->get_sku()
                ));
                $failed_count++;
                continue;
            }

            $image_data = WC_Multi_Store_Image_Proxy::get_image_data($attachment_id);
            if (!$image_data) {
                $this->logger->warning(sprintf(
                    'Could not read local image %d for product %s — skipping (proxy enabled)',
                    $attachment_id,
                    $product->get_sku()
                ));
                $failed_count++;
                continue;
            }

            $result = $api->upload_image($image_data);

            if (is_wp_error($result)) {
                $this->logger->warning(sprintf(
                    'Image upload failed for product %s (attachment %d): %s — skipping (proxy enabled, not falling back to URL to avoid orphaned remote media)',
                    $product->get_sku(),
                    $attachment_id,
                    $result->get_error_message()
                ));
                $failed_count++;
                continue;
            }

            // Use remote attachment ID — WooCommerce won't need to download anything
            $uploaded[] = [
                'id' => $result['id'],
                'position' => $image['position'] ?? $index,
            ];
        }

        $uploaded_count = count($uploaded);
        $total = count($images);

        if ($uploaded_count > 0) {
            $this->logger->debug(sprintf(
                'Uploaded %d/%d images via API for product %s',
                $uploaded_count,
                $total,
                $product->get_sku()
            ));
        }

        if ($failed_count > 0) {
            $this->logger->warning(sprintf(
                '%d/%d images could not be uploaded for product %s — those images will not be updated on the remote store',
                $failed_count,
                $total,
                $product->get_sku()
            ));
        }

        return $uploaded;
    }

    /**
     * Create or update product on remote store
     *
     * @param WC_Multi_Store_API_Client $api API client
     * @param WC_Product $product Product object
     * @param array|null $remote_product Existing remote product or null
     * @param array $product_data Product data to send
     * @param string $sync_type Sync type
     * @param string $sync_source Sync source for error reporting
     * @return array Result with 'success', 'action', 'result', 'message'
     */
    private function create_or_update_remote_product(WC_Multi_Store_API_Client $api, WC_Product $product, $remote_product, array $product_data, string $sync_type, string $sync_source = 'manual'): array {
        return $this->remote_manager->create_or_update($api, $product, $remote_product, $product_data, $sync_type, $sync_source);
    }

    /**
     * Perform post-sync operations (variations, custom fields, stock verification)
     *
     * @param WC_Product $product Product object
     * @param int $remote_product_id Remote product ID
     * @param WC_Multi_Store_API_Client $api API client
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @param string $sync_type Sync type
     * @return int Number of additional API calls made
     */
    private function perform_post_sync_operations(WC_Product $product, int $remote_product_id, WC_Multi_Store_API_Client $api, string $store_url, array $store_config, string $sync_type): int {
        $api_calls = 0;

        // Sync variations if variable product. Light sync types (quantity/
        // price_quantity/price_quantity_categories) now also touch variations
        // — previously variation stock/price only ever updated on a
        // full_product sync, so a variable product's variations could go
        // stale indefinitely under a lighter sync_type_default.
        if ($product->is_type('variable')) {
            $variation_api_calls = $this->sync_variations($product, $remote_product_id, $api, $store_url, $store_config, $sync_type);
            $api_calls += $variation_api_calls;
        }

        // Sync custom fields if configured
        if (isset($store_config['custom_field_mapping']) && !empty($store_config['custom_field_mapping'])) {
            $custom_fields_result = WC_Multi_Store_Custom_Field_Mapper::sync_custom_fields(
                $product->get_id(),
                $store_config['custom_field_mapping'],
                $api,
                $remote_product_id
            );

            if ($custom_fields_result['success']) {
                $this->logger->info($custom_fields_result['message'] . ' for product ' . $product->get_sku());
            } else {
                $this->logger->warning('Custom fields sync failed: ' . $custom_fields_result['message']);
            }

            $api_calls++;
        }

        // Schedule stock verification if enabled and stock was synced
        $webhook_settings = WC_Multi_Store_Settings::get_webhook_settings();
        if (!empty($webhook_settings['auto_verify']) && in_array($sync_type, ['full_product', 'price_quantity', 'quantity'])) {
            // Only verify if product manages stock
            if ($product->managing_stock()) {
                $expected_stock = $product->get_stock_quantity();
                WC_Multi_Store_Stock_Verifier::schedule_verification($product->get_id(), $store_url, $expected_stock);
            }
        }

        return $api_calls;
    }

    /**
     * Sync product to a single store
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @param string $sync_type Sync type
     * @param string $sync_source Source of sync (manual, scheduled, hook, etc.)
     * @return array Result array
     */
    public function sync_product_to_store(WC_Product $product, string $store_url, array $store_config, string $sync_type, string $sync_source = 'manual'): array {
        // Safety net: if a variation object reaches here, sync its parent instead.
        // Variations must be synced through the parent product's variation sync flow,
        // otherwise they would be created as standalone simple products on the remote store.
        if ($product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            $parent = wc_get_product($parent_id);

            if ($parent) {
                $this->logger->info(sprintf(
                    'Redirecting variation %s (ID %d) to parent product %s (ID %d) for sync',
                    $product->get_sku(),
                    $product->get_id(),
                    $parent->get_sku(),
                    $parent_id
                ));
                return $this->sync_product_to_store($parent, $store_url, $store_config, $sync_type, $sync_source);
            }

            $this->logger->warning(sprintf(
                'Variation %s (ID %d) has no valid parent product (parent ID: %d) - skipping sync',
                $product->get_sku(),
                $product->get_id(),
                $parent_id
            ));
            return [
                'success' => false,
                'message' => 'Variation has no valid parent product',
            ];
        }

        // Initialize sync context (performance tracking + API client)
        $context = $this->initialize_sync_context($store_url, $store_config);
        ['start_time' => $start_time, 'start_memory' => $start_memory, 'api_calls' => $api_calls, 'api' => $api, 'settings' => $settings] = $context;

        // Apply source-specific setting overrides
        if (in_array($sync_source, ['scheduled_sync', 'weekly_verification_correction'], true)) {
            $settings = WC_Multi_Store_Settings::get_effective_settings($sync_source);
        }

        // Early check: for non-full syncs, skip if product is known to not exist on remote store
        // This prevents unnecessary API calls and error logs for products that only exist locally
        $match_by = $settings['match_products_by'] ?? 'sku';
        $search_value = ($match_by === 'sku') ? $product->get_sku() : $product->get_slug();

        if ($sync_type !== 'full_product' && !empty($search_value)) {
            if (WC_Multi_Store_Cache_Manager::is_product_not_found($store_url, $search_value, $match_by)) {
                // Check if auto-create missing products is enabled
                $auto_create_missing = $settings['auto_create_missing_products'] ?? false;

                if ($auto_create_missing) {
                    // Queue a full_product sync with low priority to create the product
                    $this->queue_full_sync_for_missing_product($product, $store_url, $sync_source);

                    $this->logger->debug(sprintf(
                        'Skipping %s sync for %s - product not found on remote store. Queued full sync with low priority.',
                        $sync_type,
                        $product->get_sku()
                    ));

                    return [
                        'success' => true,
                        'skipped' => true,
                        'queued_full_sync' => true,
                        'message' => 'Product not found on remote store (queued full sync to create)',
                    ];
                }

                // Product is known to not exist on remote store - skip silently for partial syncs
                // This is not an error, it's expected behavior for products that only exist locally
                $this->logger->debug(sprintf(
                    'Skipping %s sync for %s - product not found on remote store (cached)',
                    $sync_type,
                    $product->get_sku()
                ));

                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => 'Product not found on remote store (skipped - use full sync to create)',
                ];
            }
        }

        // Find remote product
        $remote_product = $this->find_remote_product($api, $product, $match_by, $store_url);
        $api_calls++;

        // Determine if this is an update or create
        $is_update = !empty($remote_product);

        // Build and apply rules to product data
        $product_data = $this->build_product_data($product, $sync_type);
        $product_data = $this->apply_store_rules($product_data, $product, $store_config);

        // Catch name/slug drift on lightweight syncs (quantity/price_quantity/
        // price_quantity_categories) using the remote product we already fetched
        // above for matching — no extra API call. full_product already includes
        // name/slug via get_full_product_data(), so this only adds them when the
        // lighter sync types would otherwise omit them entirely.
        if ($is_update && $sync_type !== 'full_product') {
            if (isset($remote_product['name']) && $remote_product['name'] !== $product->get_name()) {
                $product_data['name'] = $product->get_name();
            }
            if (isset($remote_product['slug']) && $remote_product['slug'] !== $product->get_slug()) {
                $product_data['slug'] = $product->get_slug();
            }
        }

        // Smart data handling: skip unchanged data on updates.
        // Force/manual syncs always send everything — no skipping.
        if ($is_update && $sync_source !== 'manual_test' && in_array($sync_type, ['full_product', 'price_quantity_categories'], true)) {
            $skipped = [];

            // Skip description/short_description if unchanged (full_product only).
            // These are the largest text fields in the payload and change far less
            // often than price/stock, so this is where the biggest payload win is.
            if ($sync_type === 'full_product' && (isset($product_data['description']) || isset($product_data['short_description']))) {
                if (!$this->extractor->have_description_changed($product, $store_url)) {
                    unset($product_data['description'], $product_data['short_description']);
                    $skipped[] = 'description';
                }
            }

            // Skip attributes/default_attributes if unchanged (full_product only).
            // Attribute option lists can be sizeable for variable products with
            // many terms and rarely change between syncs — same rationale as
            // the description skip above.
            if ($sync_type === 'full_product' && (isset($product_data['attributes']) || isset($product_data['default_attributes']))) {
                if (!$this->extractor->have_attributes_changed($product, $store_url)) {
                    unset($product_data['attributes'], $product_data['default_attributes']);
                    $skipped[] = 'attributes';
                }
            }

            // Skip images if unchanged (full_product only).
            // Never skip when:
            //   (a) product has no images — empty array must reach remote to clear its images, or
            //   (b) remote product has no images but local does — remote lost its image, must restore.
            if ($sync_type === 'full_product' && !empty($product_data['images'])) {
                $remote_has_no_images = empty($remote_product['images']);
                if (!$remote_has_no_images && !$this->extractor->have_images_changed($product, $store_url)) {
                    unset($product_data['images']);
                    $skipped[] = 'images';
                }
            }

            // Skip categories if unchanged — but never trust the hash if the
            // remote product's current categories share nothing with what
            // we're about to send. A prior sync may have silently failed to
            // create/attach the categories (see ensure_terms_exist()),
            // leaving the remote product stuck on the store's default
            // category while the local-only hash still reports "unchanged"
            // forever. Mirrors the remote-state check used for images above.
            if (isset($product_data['categories'])) {
                $local_category_slugs = array_map(
                    fn($cat) => strtolower($cat['slug'] ?? ''),
                    $product_data['categories']
                );
                $remote_category_slugs = array_map(
                    fn($cat) => strtolower($cat['slug'] ?? ''),
                    is_array($remote_product['categories'] ?? null) ? $remote_product['categories'] : []
                );
                $remote_missing_local_categories = !empty($local_category_slugs)
                    && !array_intersect($remote_category_slugs, $local_category_slugs);

                if (!$remote_missing_local_categories && !$this->extractor->have_categories_changed($product, $store_url)) {
                    unset($product_data['categories']);
                    $skipped[] = 'categories';
                }
            }

            // Skip tags if unchanged — same remote-state guard as categories
            // above: a prior sync may have silently failed to create/attach
            // the tags, so never trust the hash if the remote product's
            // current tags share nothing with what we're about to send.
            // Compare using whichever field category_match_by actually
            // populates: format_tags() omits 'slug' entirely in 'name' match
            // mode (to support Cyrillic tag names), while remote tags always
            // carry both — so a hardcoded 'slug' comparison would always
            // report "no match" and defeat the skip optimization there.
            if (isset($product_data['tags'])) {
                $tag_match_by = $settings['category_match_by'] ?? 'slug';
                $local_tag_keys = array_map(
                    fn($tag) => mb_strtolower($tag[$tag_match_by] ?? $tag['name'] ?? ''),
                    $product_data['tags']
                );
                $remote_tag_keys = array_map(
                    fn($tag) => mb_strtolower($tag[$tag_match_by] ?? $tag['name'] ?? ''),
                    is_array($remote_product['tags'] ?? null) ? $remote_product['tags'] : []
                );
                $remote_missing_local_tags = !empty($local_tag_keys)
                    && !array_intersect($remote_tag_keys, $local_tag_keys);

                if (!$remote_missing_local_tags && !$this->extractor->have_tags_changed($product, $store_url)) {
                    unset($product_data['tags']);
                    $skipped[] = 'tags';
                }
            }

            if (!empty($skipped)) {
                $this->logger->debug(sprintf(
                    'Skipping unchanged data for %s: %s',
                    $product->get_sku(),
                    implode(', ', $skipped)
                ));
            }
        }

        // Filter categories if auto-create is disabled (only if categories are being sent)
        if (isset($product_data['categories']) || isset($product_data['tags'])) {
            $product_data = $this->filter_categories_if_no_auto_create($product_data, $api, $settings);
        }

        // Upload images via API if enabled (bypasses CDN/firewall)
        if (!empty($product_data['images']) && WC_Multi_Store_Image_Proxy::is_enabled()) {
            $original_image_count = count($product_data['images']);
            $product_data['images'] = $this->upload_images_via_api($api, $product, $product_data['images']);
            $api_calls += count($product_data['images']);

            // If all uploads failed (returned empty), remove images key entirely to avoid
            // sending images:[] which would delete existing remote product images
            if (empty($product_data['images']) && $original_image_count > 0) {
                unset($product_data['images']);
                $this->logger->warning(sprintf(
                    'All image uploads failed for product %s — images key removed to preserve existing remote images',
                    $product->get_sku()
                ));
            }
        }

        // Record sync to remote store (for centralized master tracking)
        if (in_array($sync_type, ['full_product', 'price_quantity', 'quantity'])) {
            WC_Multi_Store_Stock_Update_Tracker::record_sync_to_store($product->get_id(), $store_url);
        }

        // Create or update product
        $operation_result = $this->create_or_update_remote_product($api, $product, $remote_product, $product_data, $sync_type, $sync_source);
        $api_calls++;

        // Handle errors
        if (!$operation_result['success']) {
            // Check if this is a "product not found" error and auto-create is enabled
            $is_not_found_error = str_contains($operation_result['message'], 'Product not found on remote store');
            $auto_create_missing = $settings['auto_create_missing_products'] ?? false;

            if ($is_not_found_error && $auto_create_missing && $sync_type !== 'full_product') {
                // Queue a full_product sync with low priority to create the product
                $this->queue_full_sync_for_missing_product($product, $store_url, $sync_source);

                $this->logger->debug(sprintf(
                    'Product sync skipped for %s - not found on remote store. Queued full sync with low priority.',
                    $product->get_sku()
                ));

                return [
                    'success' => true,
                    'skipped' => true,
                    'queued_full_sync' => true,
                    'message' => 'Product not found on remote store (queued full sync to create)',
                ];
            }

            // If the error is image-related (403/Forbidden), retry without images
            // The remote store may be unable to download images due to firewall/CDN protection
            if (isset($product_data['images']) && $this->is_image_download_error($operation_result['message'])) {
                $this->logger->warning(sprintf(
                    'Product sync for %s failed due to image download error. Retrying without images. Error: %s',
                    $product->get_sku(),
                    $operation_result['message']
                ));

                unset($product_data['images']);
                $retry_result = $this->create_or_update_remote_product($api, $product, $remote_product, $product_data, $sync_type, $sync_source);
                $api_calls++;

                if ($retry_result['success']) {
                    $operation_result = $retry_result;
                    $this->logger->info(sprintf(
                        'Product %s synced successfully without images (images skipped due to download error)',
                        $product->get_sku()
                    ));
                }
                // If retry also failed, fall through to the normal error handling below
            }
        }

        // Handle persistent errors (after image retry)
        if (!$operation_result['success']) {
            $this->logger->warning('Product sync failed: ' . $product->get_sku() . ' - ' . $operation_result['message']);
            $this->log_sync_history($product, $store_url, $sync_type, $sync_source, 'error', $operation_result['message'], null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $operation_result['message'],
            ];
        }

        // Extract results
        $action = $operation_result['action'];
        $remote_product_id = $operation_result['result']['id'];
        $message = $operation_result['message'];

        // Detect image attachment failures: we sent images but the remote product has none
        // This happens when the remote WooCommerce fails to download image URLs silently
        if (!empty($product_data['images']) && isset($operation_result['result']['images'])) {
            $remote_images_after = $operation_result['result']['images'] ?? [];
            if (empty($remote_images_after)) {
                $this->logger->warning(sprintf(
                    'Images were sent for %s but remote product has no images after sync — remote store may have failed to download image URLs',
                    $product->get_sku()
                ));
            }
        }

        // Wrap all post-success local DB writes in a transaction so that product sync,
        // variation sync, hashes, and history log are committed atomically.
        // Remote API calls inside perform_post_sync_operations() cannot be rolled back,
        // but local state (hashes, history) stays consistent if any step throws.
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            // Update cache with the new remote product data (refreshes expiration)
            $search_value = ($match_by === 'sku') ? $product->get_sku() : $product->get_slug();
            if (!empty($search_value) && !empty($operation_result['result'])) {
                WC_Multi_Store_Cache_Manager::update_remote_product_after_sync(
                    $store_url,
                    $search_value,
                    $match_by,
                    $operation_result['result']
                );
            }

            // Persist the stable local↔remote ID mapping so a later SKU/slug
            // rename can't make a future sync miss this remote product and
            // (on light syncs with auto_create_missing_products) create a
            // duplicate instead of updating it.
            WC_Multi_Store_Remote_Product_Manager::save_remote_product_id($product->get_id(), $store_url, $remote_product_id);

            // Perform post-sync operations (variations, custom fields, stock verification)
            $additional_api_calls = $this->perform_post_sync_operations($product, $remote_product_id, $api, $store_url, $store_config, $sync_type);
            $api_calls += $additional_api_calls;

            // Save sync hashes for smart comparison on next sync
            if (in_array($sync_type, ['full_product', 'price_quantity_categories'], true)) {
                // For full_product, save all hashes (images, categories, tags)
                if ($sync_type === 'full_product') {
                    $this->extractor->save_all_sync_hashes($product, $store_url);
                } else {
                    // For price_quantity_categories, save only categories and tags
                    $this->extractor->save_synced_categories_hash(
                        $product->get_id(),
                        $store_url,
                        $product->get_category_ids()
                    );
                    $this->extractor->save_synced_tags_hash(
                        $product->get_id(),
                        $store_url,
                        $product->get_tag_ids()
                    );
                }
            }

            // Log success
            $this->logger->info($message . ': ' . $product->get_sku() . ' to ' . $store_url);
            $this->log_sync_history($product, $store_url, $sync_type, $sync_source, 'success', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $tx_message = 'Post-sync transaction failed: ' . $e->getMessage();
            $this->logger->error($tx_message . ' for product ' . $product->get_sku());
            $this->log_sync_history($product, $store_url, $sync_type, $sync_source, 'error', $tx_message, $remote_product_id, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $tx_message,
            ];
        }

        $this->maybe_purge_remote_cache($product, $store_url, $store_config, $remote_product_id);

        return [
            'success' => true,
            'action' => $action,
            'remote_id' => $remote_product_id,
        ];
    }

    /**
     * Fire a non-blocking cache purge request to the remote store if configured.
     *
     * Supported placeholders in the URL: {product_id}, {sku}, {remote_id}
     *
     * @param WC_Product $product
     * @param string $store_url
     * @param array $store_config
     * @param int|null $remote_id
     */
    private function maybe_purge_remote_cache(WC_Product $product, string $store_url, array $store_config, ?int $remote_id): void {
        $purge_url = trim($store_config['cache_purge_url'] ?? '');
        if (empty($purge_url)) {
            return;
        }

        $purge_url = str_replace(
            ['{product_id}', '{sku}',                          '{remote_id}'],
            [$product->get_id(), urlencode($product->get_sku()), (int) $remote_id],
            $purge_url
        );

        $method = strtoupper($store_config['cache_purge_method'] ?? 'GET');

        $args = [
            'timeout'  => 3,
            'blocking' => false,
            'headers'  => ['User-Agent' => 'WC-Multi-Store-Sync/' . WC_MSS_VERSION],
        ];

        if ($method === 'POST') {
            wp_remote_post($purge_url, $args);
        } else {
            wp_remote_get($purge_url, $args);
        }

        $this->logger->debug(sprintf(
            'Cache purge (%s) sent for SKU %s → %s',
            $method,
            $product->get_sku() ?: 'no-sku',
            $purge_url
        ));
    }

    /**
     * Log sync operation to history
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL
     * @param string $sync_type Sync type
     * @param string $sync_source Sync source
     * @param string $status Status (success/error)
     * @param string $message Message
     * @param int|null $remote_product_id Remote product ID
     * @param float $start_time Start time
     * @param int $start_memory Start memory
     * @param int $api_calls Number of API calls
     */
    private function log_sync_history($product, $store_url, $sync_type, $sync_source, $status, $message, $remote_product_id, $start_time, $start_memory, $api_calls): void {
        $this->log_operation_history([
            'product' => $product,
            'store_url' => $store_url,
            'sync_type' => $sync_type,
            'sync_source' => $sync_source,
            'status' => $status,
            'message' => $message,
            'remote_product_id' => $remote_product_id,
            'start_time' => $start_time,
            'start_memory' => $start_memory,
            'api_calls' => $api_calls,
        ]);
    }

    /**
     * Find product on remote store
     *
     * @param WC_Multi_Store_API_Client $api API client
     * @param WC_Product $product Product object
     * @param string $match_by Match by 'sku' or 'slug'
     * @param string $store_url Store URL for caching
     * @return array|null Remote product data or null if not found
     */
    private function find_remote_product($api, $product, $match_by, $store_url = ''): ?array {
        return $this->remote_manager->find_remote_product($api, $product, $match_by, $store_url);
    }

    /**
     * Queue a full product sync for a product not found on a specific store
     *
     * This is used when auto_create_missing_products setting is enabled and
     * a partial sync encounters a product that doesn't exist on the remote store.
     *
     * @param WC_Product $product Product object
     * @param string $store_url Store URL to sync to
     * @param string $sync_source Original sync source (for trigger info)
     * @return bool True if queued successfully
     */
    private function queue_full_sync_for_missing_product(WC_Product $product, string $store_url, string $sync_source): bool {
        $result = WC_Multi_Store_Queue_Table::add(
            $product->get_id(),
            $store_url,
            'full_product',
            WC_Multi_Store_Queue_Manager::PRIORITY_LOW,
            'auto_create_missing_' . $sync_source,
            null,
            $product->get_sku()
        );

        if ($result) {
            $this->logger->info(sprintf(
                'Queued full sync for missing product: %s (ID %d) to %s with low priority',
                $product->get_sku(),
                $product->get_id(),
                $store_url
            ));
        }

        return (bool) $result;
    }

    /**
     * Build product data array for API
     *
     * @param WC_Product $product Product object
     * @param string $sync_type Sync type
     * @return array Product data for WooCommerce API
     */
    private function build_product_data($product, $sync_type): array {
        return $this->extractor->build_product_data($product, $sync_type);
    }

    /**
     * Filter categories when auto-create is disabled
     * Only includes categories that already exist on the remote store
     *
     * @param array $product_data Product data array
     * @param WC_Multi_Store_API_Client $api API client
     * @param array $settings Plugin settings
     * @return array Modified product data
     */
    private function filter_categories_if_no_auto_create($product_data, $api, $settings): array {
        // Check if auto-create is enabled (default true)
        $auto_create = $settings['category_auto_create'] ?? true;

        // Process categories
        if (!empty($product_data['categories'])) {
            if ($auto_create) {
                // Auto-create: ensure categories exist, create if missing
                $product_data['categories'] = $this->ensure_terms_exist($product_data['categories'], $api, 'categories', $settings);
            } else {
                // No auto-create: filter to only existing categories
                $product_data['categories'] = $this->filter_existing_terms($product_data['categories'], $api, 'categories', $settings);
            }
        }

        // Process tags the same way
        if (!empty($product_data['tags'])) {
            if ($auto_create) {
                $product_data['tags'] = $this->ensure_terms_exist($product_data['tags'], $api, 'tags', $settings);
            } else {
                $product_data['tags'] = $this->filter_existing_terms($product_data['tags'], $api, 'tags', $settings);
            }
        }

        return $product_data;
    }

    /**
     * Ensure terms exist on remote store, creating missing ones
     *
     * @param array $terms Terms array (categories or tags)
     * @param WC_Multi_Store_API_Client $api API client
     * @param string $type 'categories' or 'tags'
     * @param array $settings Plugin settings
     * @return array Terms with IDs
     */
    private function ensure_terms_exist($terms, $api, $type, $settings): array {
        $match_by = $settings['category_match_by'] ?? 'slug';

        // Get cached remote terms
        $remote_terms = $this->get_cached_remote_terms($api, $type);

        // Build lookup maps
        $name_map = [];
        $slug_map = [];
        foreach ($remote_terms as $remote_term) {
            $name_map[mb_strtolower($remote_term['name'])] = $remote_term;
            $slug_map[strtolower($remote_term['slug'])] = $remote_term;
        }

        $result_terms = [];
        $terms_to_create = [];
        $terms_to_rename = []; // Existing remote terms whose non-match-key field drifted locally, keyed by id
        $term_index_map = []; // Maps lookup_key to index in $terms for later matching

        // First pass: identify existing terms and collect missing ones
        foreach ($terms as $index => $term) {
            $term_name = $term['name'] ?? '';
            $term_slug = $term['slug'] ?? sanitize_title($term_name);

            if (empty($term_name)) {
                continue;
            }

            // Look up in cached map
            $lookup_key = ($match_by === 'name') ? mb_strtolower($term_name) : strtolower($term_slug);
            $map = ($match_by === 'name') ? $name_map : $slug_map;

            if (isset($map[$lookup_key])) {
                // Term exists, use its ID
                $remote_term = $map[$lookup_key];
                $result_terms[$index] = ['id' => $remote_term['id']];

                // Whichever field ISN'T the match key can silently drift — e.g.
                // slug-match mode will never notice a pure display-name rename,
                // since that's not what found the term. Queue a rename so the
                // remote label doesn't stay stale forever.
                if ($match_by === 'name') {
                    if (strtolower($remote_term['slug'] ?? '') !== strtolower($term_slug)) {
                        $terms_to_rename[$remote_term['id']] = ['id' => $remote_term['id'], 'slug' => $term_slug];
                    }
                } elseif (($remote_term['name'] ?? '') !== $term_name) {
                    $terms_to_rename[$remote_term['id']] = ['id' => $remote_term['id'], 'name' => $term_name];
                }
            } else {
                // Term doesn't exist, queue for batch creation
                $terms_to_create[] = [
                    'name' => $term_name,
                    'slug' => $term_slug,
                ];
                $term_index_map[$lookup_key] = $index;
            }
        }

        // Batch create missing terms (single API call instead of N calls)
        if (!empty($terms_to_create)) {
            $newly_created = []; // Collect created terms to update the cache in one shot

            // Build name → requested-slug map for post-creation collision detection.
            // When WooCommerce receives a POST for a slug that already exists it auto-appends
            // "-2", "-3", etc. rather than returning the existing term. This means a parallel
            // sync worker already created the same category a few milliseconds earlier.
            $req_slug_by_name = [];
            foreach ($terms_to_create as $t) {
                $req_slug_by_name[mb_strtolower($t['name'])] = strtolower($t['slug']);
            }

            // Detect and repair a slug collision: fetch the real existing term, update maps,
            // delete the accidentally-created duplicate. Returns true when the collision was
            // handled so the caller can skip the normal integration logic.
            $fix_collision = function (
                array $created, string $returned_slug, string $name_key
            ) use (
                $api, $type, $match_by, $req_slug_by_name, $term_index_map,
                &$result_terms, &$name_map, &$slug_map, &$newly_created
            ): bool {
                $req_slug = $req_slug_by_name[$name_key] ?? $returned_slug;
                if ($returned_slug === $req_slug) {
                    return false; // no collision
                }
                $existing = ($type === 'categories')
                    ? $api->get_categories('', ['slug' => $req_slug, 'per_page' => 1])
                    : $api->get_tags('', ['slug' => $req_slug, 'per_page' => 1]);

                if (is_wp_error($existing) || empty($existing)) {
                    return false; // can't resolve — caller will use the "-2" version
                }
                $real     = $existing[0];
                $real_key = ($match_by === 'name') ? $name_key : $req_slug;

                if (isset($term_index_map[$real_key])) {
                    $result_terms[$term_index_map[$real_key]] = ['id' => $real['id']];
                }
                $name_map[$name_key] = $real;
                $slug_map[$req_slug] = $real;
                $newly_created[]     = $real;

                if ($type === 'categories') {
                    $api->delete_category($created['id'], true);
                } else {
                    $api->delete_tag($created['id'], true);
                }

                WC_Multi_Store_Logger::write(sprintf(
                    'Slug collision on %s "%s" (got "%s", expected "%s"): using existing ID %d, deleted duplicate ID %d.',
                    rtrim($type, 's'),
                    $created['name'],
                    $returned_slug,
                    $req_slug,
                    $real['id'],
                    $created['id']
                ), 'warning');

                return true;
            };

            $batch_result = ($type === 'categories')
                ? $api->batch_categories(['create' => $terms_to_create])
                : $api->batch_tags(['create' => $terms_to_create]);

            if (!is_wp_error($batch_result) && isset($batch_result['create'])) {
                foreach ($batch_result['create'] as $created) {
                    if (isset($created['id']) && isset($created['name'])) {
                        $returned_slug = strtolower($created['slug'] ?? '');
                        $name_key      = mb_strtolower($created['name']);

                        if ($fix_collision($created, $returned_slug, $name_key)) {
                            continue;
                        }

                        $lookup_key = ($match_by === 'name') ? $name_key : $returned_slug;
                        if (!isset($term_index_map[$lookup_key])) {
                            // Returned slug was altered (e.g. "-2") and collision could not be resolved —
                            // fall back to the originally requested slug so the term still maps correctly.
                            $lookup_key = $req_slug_by_name[$name_key] ?? $name_key;
                        }
                        if (isset($term_index_map[$lookup_key])) {
                            $result_terms[$term_index_map[$lookup_key]] = ['id' => $created['id']];
                        }
                        $name_map[$name_key]      = $created;
                        $slug_map[$returned_slug] = $created;
                        $newly_created[]          = $created;
                    }
                }

                if (!empty($newly_created)) {
                    WC_Multi_Store_Logger::write(sprintf(
                        'Batch created %d %s on remote store',
                        count($newly_created),
                        $type
                    ));
                }
            } else {
                $error_msg = is_wp_error($batch_result) ? $batch_result->get_error_message() : 'Unknown error';
                WC_Multi_Store_Logger::write(sprintf(
                    'Batch %s creation failed: %s. Trying chunked batches before per-item fallback.',
                    $type,
                    $error_msg
                ), 'warning');

                // Closure: integrate one created term into the result/cache maps.
                $integrate_created = function (array $created) use (
                    &$result_terms, &$name_map, &$slug_map, &$newly_created, $term_index_map, $match_by,
                    $fix_collision, $req_slug_by_name
                ): bool {
                    if (!isset($created['id']) || !isset($created['name']) || !isset($created['slug'])) {
                        return false;
                    }
                    $returned_slug = strtolower($created['slug']);
                    $name_key      = mb_strtolower($created['name']);

                    if ($fix_collision($created, $returned_slug, $name_key)) {
                        return true;
                    }

                    $lookup_key = ($match_by === 'name') ? $name_key : $returned_slug;
                    if (!isset($term_index_map[$lookup_key])) {
                        $lookup_key = $req_slug_by_name[$name_key] ?? $name_key;
                    }
                    if (isset($term_index_map[$lookup_key])) {
                        $result_terms[$term_index_map[$lookup_key]] = ['id' => $created['id']];
                    }
                    $name_map[$name_key]      = $created;
                    $slug_map[$returned_slug] = $created;
                    $newly_created[]          = $created;
                    return true;
                };

                // Pass 1: smaller chunked batches. Skipped if we're already at
                // or below chunk size — nothing to gain by re-issuing what
                // just failed.
                $still_needed = $terms_to_create;
                if (count($terms_to_create) > self::TERM_FALLBACK_CHUNK_SIZE) {
                    $still_needed = [];
                    foreach (array_chunk($terms_to_create, self::TERM_FALLBACK_CHUNK_SIZE) as $chunk) {
                        $chunk_result = ($type === 'categories')
                            ? $api->batch_categories(['create' => $chunk])
                            : $api->batch_tags(['create' => $chunk]);

                        if (is_wp_error($chunk_result) || !isset($chunk_result['create']) || !is_array($chunk_result['create'])) {
                            // Chunk failed wholesale — defer to per-item.
                            $still_needed = array_merge($still_needed, $chunk);
                            continue;
                        }

                        $created_names = [];
                        $created_slugs = [];
                        foreach ($chunk_result['create'] as $created) {
                            if ($integrate_created($created)) {
                                $created_names[mb_strtolower($created['name'])] = true;
                                $created_slugs[strtolower($created['slug'])] = true;
                            }
                        }

                        // Re-queue any chunk item that the response didn't acknowledge
                        // (per-item errors inside an otherwise-successful batch).
                        foreach ($chunk as $term_data) {
                            $name_key = mb_strtolower($term_data['name']);
                            $slug_key = strtolower($term_data['slug']);
                            if (!isset($created_names[$name_key]) && !isset($created_slugs[$slug_key])) {
                                $still_needed[] = $term_data;
                            }
                        }
                    }
                }

                // Pass 2: per-item creation for whatever the chunked pass didn't
                // cover — capped after MAX_FAILURES consecutive errors so a
                // misbehaving remote can't pull us into 30+ doomed calls.
                if (!empty($still_needed)) {
                    $consecutive_failures = 0;
                    $aborted              = 0;

                    foreach ($still_needed as $new_term_data) {
                        if ($consecutive_failures >= self::TERM_FALLBACK_MAX_FAILURES) {
                            $aborted++;
                            continue;
                        }

                        $created = ($type === 'categories')
                            ? $api->create_category($new_term_data)
                            : $api->create_tag($new_term_data);

                        if (is_wp_error($created) || !$integrate_created(is_array($created) ? $created : [])) {
                            $consecutive_failures++;
                            continue;
                        }

                        $consecutive_failures = 0;
                    }

                    if ($aborted > 0) {
                        WC_Multi_Store_Logger::write(sprintf(
                            'Aborted per-item %s fallback after %d consecutive failures; %d items skipped.',
                            $type,
                            self::TERM_FALLBACK_MAX_FAILURES,
                            $aborted
                        ), 'error');
                    }
                }
            }

            // Update the transient once with the new terms appended — avoids thrashing
            // where every created term would cause a delete + full API re-fetch on the next product.
            if (!empty($newly_created)) {
                $cache_key = 'wc_mss_remote_' . $type . '_' . md5($api->get_store_url());
                $cached    = get_transient($cache_key);
                if (is_array($cached)) {
                    set_transient($cache_key, array_merge($cached, $newly_created), self::TERM_CACHE_TTL);
                } else {
                    // Cache was already gone (expired or manually cleared); let it rebuild naturally.
                    delete_transient($cache_key);
                }
            }
        }

        // Batch-rename remote terms whose local display value drifted (single
        // API call, mirrors the batch-create path above).
        if (!empty($terms_to_rename)) {
            $rename_batch = array_values($terms_to_rename);
            $rename_result = ($type === 'categories')
                ? $api->batch_categories(['update' => $rename_batch])
                : $api->batch_tags(['update' => $rename_batch]);

            if (is_wp_error($rename_result)) {
                WC_Multi_Store_Logger::write(sprintf(
                    'Batch %s rename failed: %s',
                    $type,
                    $rename_result->get_error_message()
                ), 'warning');
            } else {
                WC_Multi_Store_Logger::write(sprintf(
                    'Renamed %d %s on remote store to match local changes',
                    count($rename_batch),
                    $type
                ));

                // Patch the cached transient in place (same approach as the
                // batch-create path above) so the next product synced in this
                // same 24h window doesn't re-detect the same drift and re-send
                // an identical rename.
                $cache_key = 'wc_mss_remote_' . $type . '_' . md5($api->get_store_url());
                $cached = get_transient($cache_key);
                if (is_array($cached)) {
                    foreach ($cached as &$cached_term) {
                        if (isset($terms_to_rename[$cached_term['id']])) {
                            $cached_term = array_merge($cached_term, $terms_to_rename[$cached_term['id']]);
                        }
                    }
                    unset($cached_term);
                    set_transient($cache_key, $cached, self::TERM_CACHE_TTL);
                }
            }
        }

        // Sort by original index and return values only
        ksort($result_terms);
        return array_values($result_terms);
    }

    /**
     * Filter terms to only include those that exist on remote store
     * Uses cached remote terms (15 min expiration) to avoid repeated API calls
     *
     * @param array $terms Terms array (categories or tags)
     * @param WC_Multi_Store_API_Client $api API client
     * @param string $type 'categories' or 'tags'
     * @param array $settings Plugin settings
     * @return array Filtered terms with IDs
     */
    private function filter_existing_terms($terms, $api, $type, $settings): array {
        $match_by = $settings['category_match_by'] ?? 'slug';

        // Get cached remote terms
        $remote_terms = $this->get_cached_remote_terms($api, $type);

        if (empty($remote_terms)) {
            return [];
        }

        // Build lookup map
        $name_map = [];
        $slug_map = [];
        foreach ($remote_terms as $remote_term) {
            $name_map[mb_strtolower($remote_term['name'])] = $remote_term['id'];
            $slug_map[strtolower($remote_term['slug'])] = $remote_term['id'];
        }

        $existing_terms = [];

        foreach ($terms as $term) {
            $search_value = $term[$match_by] ?? $term['name'] ?? null;

            if (empty($search_value)) {
                continue;
            }

            // Look up in cached map
            $lookup_key = ($match_by === 'name') ? mb_strtolower($search_value) : strtolower($search_value);
            $map = ($match_by === 'name') ? $name_map : $slug_map;

            if (isset($map[$lookup_key])) {
                $existing_terms[] = ['id' => $map[$lookup_key]];
            }
        }

        return $existing_terms;
    }

    /**
     * Get cached remote terms (categories or tags)
     * Cache expires after 15 minutes
     *
     * @param WC_Multi_Store_API_Client $api API client
     * @param string $type 'categories' or 'tags'
     * @return array Remote terms
     */
    private function get_cached_remote_terms($api, $type): array {
        $store_url = $api->get_store_url();

        $cached = WC_Multi_Store_Cache_Manager::get_remote_terms($store_url, $type);
        if ($cached !== false) {
            return $cached;
        }

        $all_terms = self::fetch_all_terms($api, $type);

        // Cache for 24 hours (cleared during daily maintenance and weekly verification)
        WC_Multi_Store_Cache_Manager::set_remote_terms($store_url, $type, $all_terms);

        return $all_terms;
    }

    /**
     * Clear cached remote terms for a specific store or all stores
     *
     * Called during daily maintenance to refresh category/tag data
     * Note: No longer called after weekly verification to avoid "No cache yet" in System Health UI
     *
     * @param string|null $store_url Specific store URL or null to clear all
     */
    public static function clear_term_cache($store_url = null): void {
        WC_Multi_Store_Cache_Manager::clear_remote_terms($store_url);

        WC_Multi_Store_Logger::write($store_url !== null
            ? sprintf('Cleared term cache for store: %s', $store_url)
            : 'Cleared term cache for all stores');
    }

    /**
     * Pre-populate term cache for a store if it doesn't exist.
     *
     * Intentionally static: called from admin views and other contexts where no
     * Sync_Engine instance is available (e.g. System Health page, weekly-verification view).
     * It creates a temporary API client, delegates pagination to fetch_all_terms(), and
     * writes directly to the transient cache — no instance state is needed.
     *
     * @param string $store_url Store URL
     * @param array $store_config Store configuration (must include consumer_key and consumer_secret)
     * @return bool True if cache was populated, false if already exists or failed
     */
    public static function ensure_term_cache($store_url, $store_config): bool {
        // Check if cache already exists
        $cached_cats = WC_Multi_Store_Cache_Manager::get_remote_terms($store_url, 'categories');
        $cached_tags = WC_Multi_Store_Cache_Manager::get_remote_terms($store_url, 'tags');

        // If both caches exist, nothing to do
        if ($cached_cats !== false && $cached_tags !== false) {
            return false;
        }

        // Create API client
        $api = WC_Multi_Store_API_Client::for_store($store_url, $store_config);

        // Fetch and cache categories if needed
        if ($cached_cats === false) {
            $categories = self::fetch_all_terms($api, 'categories');
            if (!empty($categories)) {
                WC_Multi_Store_Cache_Manager::set_remote_terms($store_url, 'categories', $categories);
            }
        }

        // Fetch and cache tags if needed
        if ($cached_tags === false) {
            $tags = self::fetch_all_terms($api, 'tags');
            if (!empty($tags)) {
                WC_Multi_Store_Cache_Manager::set_remote_terms($store_url, 'tags', $tags);
            }
        }

        return true;
    }

    /**
     * Fetch all terms of a type from a remote store via paginated API calls.
     *
     * Intentionally static: called from both ensure_term_cache() (static context, no
     * instance available) and get_cached_remote_terms() (instance context via self::).
     * Contains no instance state — only the injected $api client is used.
     *
     * @param WC_Multi_Store_API_Client $api API client
     * @param string $type 'categories' or 'tags'
     * @return array Terms data
     */
    private static function fetch_all_terms($api, $type): array {
        $all_terms = [];
        $page = 1;
        $per_page = 100;

        do {
            // Use public API methods instead of private get() method
            if ($type === 'categories') {
                $result = $api->get_categories('', [
                    'per_page' => $per_page,
                    'page' => $page,
                ]);
            } else {
                $result = $api->get_tags('', [
                    'per_page' => $per_page,
                    'page' => $page,
                ]);
            }

            if (is_wp_error($result) || empty($result)) {
                break;
            }

            array_push($all_terms, ...$result);
            $page++;

            if ($page > 50) {
                break;
            }
        } while (count($result) === $per_page);

        return $all_terms;
    }

    /**
     * Sync product variations
     *
     * @param WC_Product $product Parent product object
     * @param int $remote_parent_id Remote parent product ID
     * @param WC_Multi_Store_API_Client $api API client
     * @param string $store_url Store URL for caching
     * @param array $store_config Store configuration (optional)
     * @param string $sync_type Sync type
     * @return int Number of API calls made
     */
    private function sync_variations($product, $remote_parent_id, $api, $store_url = '', $store_config = [], $sync_type = 'full_product'): int {
        return $this->variation_sync->sync_variations($product, $remote_parent_id, $api, $store_url, $store_config, $sync_type);
    }


    /**
     * Bulk sync products
     *
     * @param array $product_ids Array of product IDs
     * @param array $stores Store configurations
     * @param string $sync_type Sync type
     * @return array Results
     */
    public function bulk_sync_products($product_ids, $stores, $sync_type = 'full_product'): array {
        $results = [
            'total' => count($product_ids),
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($product_ids as $product_id) {
            $result = $this->sync_product($product_id, $stores, $sync_type);

            $success_count = 0;
            foreach ($result as $store_result) {
                if (is_array($store_result) && isset($store_result['success']) && $store_result['success']) {
                    $success_count++;
                }
            }

            if ($success_count > 0) {
                $results['success']++;
            } else {
                $results['failed']++;
            }

            $results['details'][$product_id] = $result;
        }

        return $results;
    }

    /**
     * Delete product from a single store
     *
     * @param int $product_id Local product ID
     * @param string $product_sku Product SKU
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @param string $sync_source Source of sync (manual, scheduled, hook, etc.)
     * @return array Result array
     */
    public function delete_product_from_store($product_id, $product_sku, $store_url, $store_config, $sync_source = 'manual'): array {
        // Initialize sync context (performance tracking + API client)
        $context = $this->initialize_sync_context($store_url, $store_config);
        ['start_time' => $start_time, 'start_memory' => $start_memory, 'api_calls' => $api_calls, 'api' => $api, 'settings' => $settings] = $context;

        $deletion_mode = $settings['deletion_mode'] ?? 'trash';

        // Find the product on remote store by SKU
        $match_by = $settings['match_products_by'] ?? 'sku';

        // Search for product
        if ($match_by === 'sku') {
            $search_value = $product_sku;
        } else {
            // Slug matching requires the product object, but it may already be deleted.
            // Fall back to SKU so the remote product can still be found.
            WC_Multi_Store_Logger::write(sprintf(
                'delete_product_from_store: match_by=slug is not supported for deletions (product %d, SKU "%s", store %s). Falling back to SKU matching.',
                $product_id,
                $product_sku,
                $store_url
            ), 'warning');
            $search_value = $product_sku;
            $match_by = 'sku';
        }

        if (empty($search_value)) {
            $message = 'Product has no SKU, cannot delete from remote store';
            $this->log_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        // Search for the product
        $result = $api->get_products($search_value, $match_by);
        $api_calls++; // Search call

        if (is_wp_error($result)) {
            $message = 'Failed to search for product: ' . $result->get_error_message();
            $this->log_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $remote_product = !empty($result) ? $result[0] : null;

        if (!$remote_product) {
            $message = 'Product not found on remote store (nothing to delete)';
            $this->log_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'success', $message, null, $start_time, $start_memory, $api_calls);

            $this->logger->info('Product not found on remote store ' . $store_url . ': ' . $product_sku);

            return [
                'success' => true,
                'message' => $message,
                'action' => 'skipped',
            ];
        }

        $remote_product_id = $remote_product['id'];

        // Delete the product
        $force_delete = ($deletion_mode === 'force');
        $delete_result = $api->delete_product($remote_product_id, $force_delete);
        $api_calls++; // Delete call

        if (is_wp_error($delete_result)) {
            $message = 'Failed to delete product: ' . $delete_result->get_error_message();
            $this->log_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

            $this->logger->error('Failed to delete product ' . $product_sku . ' from ' . $store_url . ': ' . $message);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $action = $force_delete ? 'deleted permanently' : 'moved to trash';
        $message = 'Product ' . $action . ' successfully';

        $this->log_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'success', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

        $this->logger->info($message . ': ' . $product_sku . ' from ' . $store_url);

        return [
            'success' => true,
            'action' => $action,
            'remote_id' => $remote_product_id,
        ];
    }

    /**
     * Log deletion operation to history
     *
     * @param int $product_id Product ID
     * @param string $product_sku Product SKU
     * @param string $store_url Store URL
     * @param string $sync_source Sync source
     * @param string $status Status (success/error)
     * @param string $message Message
     * @param int|null $remote_product_id Remote product ID
     * @param float $start_time Start time
     * @param int $start_memory Start memory
     * @param int $api_calls Number of API calls
     */
    private function log_deletion_history($product_id, $product_sku, $store_url, $sync_source, $status, $message, $remote_product_id, $start_time, $start_memory, $api_calls): void {
        $this->log_operation_history([
            'product_id' => $product_id,
            'product_sku' => $product_sku,
            'product_name' => 'Deleted Product',
            'store_url' => $store_url,
            'sync_type' => 'delete_product',
            'sync_source' => $sync_source,
            'status' => $status,
            'message' => $message,
            'remote_product_id' => $remote_product_id,
            'start_time' => $start_time,
            'start_memory' => $start_memory,
            'api_calls' => $api_calls,
        ]);
    }

    /**
     * Delete orphan product from remote store by remote product ID
     *
     * Orphan products exist on the remote store but shouldn't (e.g., excluded by category/tag).
     * Unlike regular deletions, we already know the remote product ID.
     *
     * @param int $product_id Local product ID (may still exist locally)
     * @param string $product_sku Product SKU
     * @param int $remote_product_id Remote product ID to delete
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @param string $sync_source Source of sync (manual, scheduled, hook, etc.)
     * @return array Result array
     */
    public function delete_orphan_product_from_store($product_id, $product_sku, $remote_product_id, $store_url, $store_config, $sync_source = 'orphan_cleanup'): array {
        // Initialize sync context (performance tracking + API client)
        $context = $this->initialize_sync_context($store_url, $store_config);
        ['start_time' => $start_time, 'start_memory' => $start_memory, 'api_calls' => $api_calls, 'api' => $api, 'settings' => $settings] = $context;

        $deletion_mode = $settings['deletion_mode'] ?? 'trash';
        $force_delete = ($deletion_mode === 'force');

        // If we don't have remote_product_id, look up by SKU
        if (empty($remote_product_id) && !empty($product_sku)) {
            $match_by = $settings['match_products_by'] ?? 'sku';
            $search_value = $product_sku;

            $result = $api->get_products($search_value, $match_by);
            $api_calls++; // Search call

            if (is_wp_error($result)) {
                $message = 'Failed to find orphan product: ' . $result->get_error_message();
                $this->log_orphan_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);
                return ['success' => false, 'message' => $message];
            }

            if (empty($result)) {
                $message = 'Orphan product not found on remote store (already deleted?)';
                $this->log_orphan_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'success', $message, null, $start_time, $start_memory, $api_calls);
                return ['success' => true, 'message' => $message, 'action' => 'skipped'];
            }

            $remote_product_id = is_array($result[0]) ? ($result[0]['id'] ?? null) : ($result[0]->id ?? null);
        }

        if (empty($remote_product_id)) {
            $message = 'Cannot delete orphan: No remote product ID available';
            $this->log_orphan_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);
            return ['success' => false, 'message' => $message];
        }

        // Delete the product directly using the known remote ID
        $delete_result = $api->delete_product($remote_product_id, $force_delete);
        $api_calls++; // Delete call

        if (is_wp_error($delete_result)) {
            $message = 'Failed to delete orphan product: ' . $delete_result->get_error_message();
            $this->log_orphan_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $action = $force_delete ? 'deleted permanently' : 'moved to trash';
        $message = 'Orphan product ' . $action . ' successfully';

        $this->log_orphan_deletion_history($product_id, $product_sku, $store_url, $sync_source, 'success', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

        $this->logger->info($message . ': ' . ($product_sku ?: 'Remote ID ' . $remote_product_id) . ' from ' . $store_url);

        return [
            'success' => true,
            'action' => $action,
            'remote_id' => $remote_product_id,
        ];
    }

    /**
     * Log orphan deletion operation to history
     *
     * @param int $product_id Product ID
     * @param string $product_sku Product SKU
     * @param string $store_url Store URL
     * @param string $sync_source Sync source
     * @param string $status Status (success/error)
     * @param string $message Message
     * @param int|null $remote_product_id Remote product ID
     * @param float $start_time Start time
     * @param int $start_memory Start memory
     * @param int $api_calls Number of API calls
     */
    private function log_orphan_deletion_history($product_id, $product_sku, $store_url, $sync_source, $status, $message, $remote_product_id, $start_time, $start_memory, $api_calls): void {
        $this->log_operation_history([
            'product_id' => $product_id,
            'product_sku' => $product_sku,
            'product_name' => 'Orphan Product',
            'store_url' => $store_url,
            'sync_type' => 'delete_orphan',
            'sync_source' => $sync_source,
            'status' => $status,
            'message' => $message,
            'remote_product_id' => $remote_product_id,
            'start_time' => $start_time,
            'start_memory' => $start_memory,
            'api_calls' => $api_calls,
        ]);
    }

    /**
     * Restore product on a single store
     *
     * @param int $product_id Local product ID
     * @param string $product_sku Product SKU
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @param string $sync_source Source of sync (manual, scheduled, hook, etc.)
     * @return array Result array
     */
    public function restore_product_on_store($product_id, $product_sku, $store_url, $store_config, $sync_source = 'manual'): array {
        // Initialize sync context (performance tracking + API client)
        $context = $this->initialize_sync_context($store_url, $store_config);
        ['start_time' => $start_time, 'start_memory' => $start_memory, 'api_calls' => $api_calls, 'api' => $api, 'settings' => $settings] = $context;

        // Find the product on remote store by SKU
        $match_by = $settings['match_products_by'] ?? 'sku';

        // Search for product
        if ($match_by === 'sku') {
            $search_value = $product_sku;
        } else {
            // For slug matching, we need the product object
            $product = wc_get_product($product_id);
            if (!$product) {
                $message = 'Product not found locally, cannot restore';
                $this->log_restoration_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);

                return [
                    'success' => false,
                    'message' => $message,
                ];
            }
            $search_value = $product->get_slug();
        }

        if (empty($search_value)) {
            $message = 'Product has no SKU/slug, cannot restore on remote store';
            $this->log_restoration_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        // Search for the product
        $result = $api->get_products($search_value, $match_by);
        $api_calls++; // Search call

        if (is_wp_error($result)) {
            $message = 'Failed to search for product: ' . $result->get_error_message();
            $this->log_restoration_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $remote_product = !empty($result) ? $result[0] : null;

        if (!$remote_product) {
            $message = 'Product not found on remote store (nothing to restore)';
            $this->log_restoration_history($product_id, $product_sku, $store_url, $sync_source, 'success', $message, null, $start_time, $start_memory, $api_calls);

            $this->logger->info('Product not found on remote store ' . $store_url . ': ' . $product_sku);

            return [
                'success' => true,
                'message' => $message,
                'action' => 'skipped',
            ];
        }

        $remote_product_id = $remote_product['id'];

        // Check if product is already published (not in trash)
        if (isset($remote_product['status']) && $remote_product['status'] !== 'trash') {
            $message = 'Product already published on remote store (not in trash)';
            $this->log_restoration_history($product_id, $product_sku, $store_url, $sync_source, 'success', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

            $this->logger->info('Product already published on remote store ' . $store_url . ': ' . $product_sku);

            return [
                'success' => true,
                'message' => $message,
                'action' => 'skipped',
            ];
        }

        // Update the product status to publish (restore from trash)
        $update_data = [
            'status' => 'publish',
        ];

        $update_result = $api->update_product($remote_product_id, $update_data);
        $api_calls++; // Update call

        if (is_wp_error($update_result)) {
            $message = 'Failed to restore product: ' . $update_result->get_error_message();
            $this->log_restoration_history($product_id, $product_sku, $store_url, $sync_source, 'error', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

            $this->logger->error('Failed to restore product ' . $product_sku . ' on ' . $store_url . ': ' . $message);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $message = 'Product restored successfully';

        $this->log_restoration_history($product_id, $product_sku, $store_url, $sync_source, 'success', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

        $this->logger->info($message . ': ' . $product_sku . ' on ' . $store_url);

        return [
            'success' => true,
            'action' => 'restored',
            'remote_id' => $remote_product_id,
        ];
    }

    /**
     * Log restoration operation to history
     *
     * @param int $product_id Product ID
     * @param string $product_sku Product SKU
     * @param string $store_url Store URL
     * @param string $sync_source Sync source
     * @param string $status Status (success/error)
     * @param string $message Message
     * @param int|null $remote_product_id Remote product ID
     * @param float $start_time Start time
     * @param int $start_memory Start memory
     * @param int $api_calls Number of API calls
     */
    private function log_restoration_history($product_id, $product_sku, $store_url, $sync_source, $status, $message, $remote_product_id, $start_time, $start_memory, $api_calls): void {
        $this->log_operation_history([
            'product_id' => $product_id,
            'product_sku' => $product_sku,
            'product_name' => 'Restored Product',
            'store_url' => $store_url,
            'sync_type' => 'restore_product',
            'sync_source' => $sync_source,
            'status' => $status,
            'message' => $message,
            'remote_product_id' => $remote_product_id,
            'start_time' => $start_time,
            'start_memory' => $start_memory,
            'api_calls' => $api_calls,
        ]);
    }

    /**
     * Delete variation from a single store
     *
     * @param int $variation_id Local variation ID
     * @param string $variation_sku Variation SKU
     * @param int $parent_id Parent product ID
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @param string $sync_source Source of sync (manual, scheduled, hook, etc.)
     * @return array Result array
     */
    public function delete_variation_from_store($variation_id, $variation_sku, $parent_id, $store_url, $store_config, $sync_source = 'manual'): array {
        // Initialize sync context (performance tracking + API client)
        $context = $this->initialize_sync_context($store_url, $store_config);
        ['start_time' => $start_time, 'start_memory' => $start_memory, 'api_calls' => $api_calls, 'api' => $api, 'settings' => $settings] = $context;

        $deletion_mode = $settings['deletion_mode'] ?? 'trash';

        // First, find the parent product on remote store
        $parent_product = wc_get_product($parent_id);
        if (!$parent_product) {
            $message = 'Parent product not found locally';
            $this->log_variation_deletion_history($variation_id, $variation_sku, $store_url, $sync_source, 'error', $message, null, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $match_by = $settings['match_products_by'] ?? 'sku';
        $parent_search_value = ($match_by === 'sku') ? $parent_product->get_sku() : $parent_product->get_slug();

        if (empty($parent_search_value)) {
            $message = 'Parent product has no SKU/slug, cannot find on remote store';
            $this->log_variation_deletion_history($variation_id, $variation_sku, $store_url, $sync_source, 'error', $message, null, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        // Search for the parent product
        $result = $api->get_products($parent_search_value, $match_by);
        $api_calls++; // Search call

        if (is_wp_error($result)) {
            $message = 'Failed to search for parent product: ' . $result->get_error_message();
            $this->log_variation_deletion_history($variation_id, $variation_sku, $store_url, $sync_source, 'error', $message, null, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $remote_parent = !empty($result) ? $result[0] : null;

        if (!$remote_parent) {
            $message = 'Parent product not found on remote store';
            $this->log_variation_deletion_history($variation_id, $variation_sku, $store_url, $sync_source, 'success', $message, null, null, $start_time, $start_memory, $api_calls);

            $this->logger->info('Parent product not found on remote store ' . $store_url . ': ' . $parent_search_value);

            return [
                'success' => true,
                'message' => $message,
                'action' => 'skipped',
            ];
        }

        $remote_parent_id = $remote_parent['id'];

        // Server-side SKU filter avoids fetching every variation for variable products with many variants
        $remote_variations = $api->get_product_variations($remote_parent_id, ['sku' => $variation_sku]);
        $api_calls++; // Get variations call

        if (is_wp_error($remote_variations)) {
            $message = 'Failed to get variations: ' . $remote_variations->get_error_message();
            $this->log_variation_deletion_history($variation_id, $variation_sku, $store_url, $sync_source, 'error', $message, $remote_parent_id, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        // Find the variation by SKU
        $remote_variation_id = null;
        foreach ($remote_variations as $remote_var) {
            if (!empty($remote_var['sku']) && $remote_var['sku'] === $variation_sku) {
                $remote_variation_id = $remote_var['id'];
                break;
            }
        }

        if (!$remote_variation_id) {
            $message = 'Variation not found on remote store (nothing to delete)';
            $this->log_variation_deletion_history($variation_id, $variation_sku, $store_url, $sync_source, 'success', $message, $remote_parent_id, null, $start_time, $start_memory, $api_calls);

            $this->logger->info('Variation not found on remote store ' . $store_url . ': ' . $variation_sku);

            return [
                'success' => true,
                'message' => $message,
                'action' => 'skipped',
            ];
        }

        // Delete the variation
        $force_delete = ($deletion_mode === 'force');
        $delete_result = $api->delete_product_variation($remote_parent_id, $remote_variation_id, $force_delete);
        $api_calls++; // Delete call

        if (is_wp_error($delete_result)) {
            $message = 'Failed to delete variation: ' . $delete_result->get_error_message();
            $this->log_variation_deletion_history($variation_id, $variation_sku, $store_url, $sync_source, 'error', $message, $remote_parent_id, $remote_variation_id, $start_time, $start_memory, $api_calls);

            $this->logger->error('Failed to delete variation ' . $variation_sku . ' from ' . $store_url . ': ' . $message);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $action = $force_delete ? 'deleted permanently' : 'moved to trash';
        $message = 'Variation ' . $action . ' successfully';

        $this->log_variation_deletion_history($variation_id, $variation_sku, $store_url, $sync_source, 'success', $message, $remote_parent_id, $remote_variation_id, $start_time, $start_memory, $api_calls);

        $this->logger->info($message . ': ' . $variation_sku . ' from ' . $store_url);

        return [
            'success' => true,
            'action' => $action,
            'remote_id' => $remote_variation_id,
            'remote_parent_id' => $remote_parent_id,
        ];
    }

    /**
     * Log variation deletion operation to history
     *
     * @param int $variation_id Variation ID
     * @param string $variation_sku Variation SKU
     * @param string $store_url Store URL
     * @param string $sync_source Sync source
     * @param string $status Status (success/error)
     * @param string $message Message
     * @param int|null $remote_parent_id Remote parent product ID
     * @param int|null $remote_variation_id Remote variation ID
     * @param float $start_time Start time
     * @param int $start_memory Start memory
     * @param int $api_calls Number of API calls
     */
    private function log_variation_deletion_history($variation_id, $variation_sku, $store_url, $sync_source, $status, $message, $remote_parent_id, $remote_variation_id, $start_time, $start_memory, $api_calls): void {
        $this->log_operation_history([
            'product_id' => $variation_id,
            'product_sku' => $variation_sku,
            'product_name' => 'Deleted Variation',
            'store_url' => $store_url,
            'sync_type' => 'delete_variation',
            'sync_source' => $sync_source,
            'status' => $status,
            'message' => $message,
            'remote_product_id' => $remote_variation_id,
            'start_time' => $start_time,
            'start_memory' => $start_memory,
            'api_calls' => $api_calls,
        ]);
    }

    /**
     * Update product status on a single store
     *
     * @param int $product_id Local product ID
     * @param string $product_sku Product SKU
     * @param string $new_status New product status
     * @param string $store_url Store URL
     * @param array $store_config Store configuration
     * @param string $sync_source Source of sync (manual, scheduled, hook, etc.)
     * @return array Result array
     */
    public function update_product_status_on_store($product_id, $product_sku, $new_status, $store_url, $store_config, $sync_source = 'manual'): array {
        // Initialize sync context (performance tracking + API client)
        $context = $this->initialize_sync_context($store_url, $store_config);
        ['start_time' => $start_time, 'start_memory' => $start_memory, 'api_calls' => $api_calls, 'api' => $api, 'settings' => $settings] = $context;

        // Find the product on remote store by SKU
        $match_by = $settings['match_products_by'] ?? 'sku';

        // Search for product
        if ($match_by === 'sku') {
            $search_value = $product_sku;
        } else {
            // Slug matching requires the product object, but it may already be deleted.
            // Fall back to SKU so the remote product can still be found.
            WC_Multi_Store_Logger::write(sprintf(
                'update_product_status_on_store: match_by=slug is not supported for status changes on deleted products (product %d, SKU "%s", store %s). Falling back to SKU matching.',
                $product_id,
                $product_sku,
                $store_url
            ), 'warning');
            $search_value = $product_sku;
            $match_by = 'sku';
        }

        if (empty($search_value)) {
            $message = 'Product has no SKU, cannot update status on remote store';
            $this->log_status_change_history($product_id, $product_sku, $new_status, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        // Search for the product
        $result = $api->get_products($search_value, $match_by);
        $api_calls++;

        if (is_wp_error($result)) {
            $message = 'Failed to search for product: ' . $result->get_error_message();
            $this->log_status_change_history($product_id, $product_sku, $new_status, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $remote_product = !empty($result) ? $result[0] : null;

        if (!$remote_product) {
            // Cache the "not found" result to avoid repeated API lookups
            WC_Multi_Store_Cache_Manager::set_product_not_found($store_url, $search_value, $match_by);

            // Check if auto-create missing products is enabled and the new status is 'publish'
            $auto_create_missing = $settings['auto_create_missing_products'] ?? false;

            if ($auto_create_missing && $new_status === 'publish') {
                // Queue a full_product sync to create the product on the remote store
                $queued = WC_Multi_Store_Queue_Table::add(
                    $product_id,
                    $store_url,
                    'full_product',
                    WC_Multi_Store_Queue_Manager::PRIORITY_LOW,
                    'auto_create_missing_' . $sync_source,
                    null,
                    $product_sku
                );

                if ($queued) {
                    $this->logger->info(sprintf(
                        'Queued full sync for missing product: %s (ID %d) to %s with low priority (triggered by status change to %s)',
                        $product_sku,
                        $product_id,
                        $store_url,
                        $new_status
                    ));
                }

                $message = 'Product not found on remote store (queued full sync to create)';
                $this->log_status_change_history($product_id, $product_sku, $new_status, $store_url, $sync_source, 'success', $message, null, $start_time, $start_memory, $api_calls);

                return [
                    'success' => true,
                    'skipped' => true,
                    'queued_full_sync' => true,
                    'message' => $message,
                ];
            }

            $message = 'Product not found on remote store (cannot update status)';
            $this->log_status_change_history($product_id, $product_sku, $new_status, $store_url, $sync_source, 'error', $message, null, $start_time, $start_memory, $api_calls);

            $this->logger->warning('Product not found on remote store ' . $store_url . ': ' . $product_sku);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $remote_product_id = $remote_product['id'];

        // Update the product status
        $update_data = [
            'status' => $new_status,
        ];

        $update_result = $api->update_product($remote_product_id, $update_data);
        $api_calls++;

        if (is_wp_error($update_result)) {
            $message = 'Failed to update product status: ' . $update_result->get_error_message();
            $this->log_status_change_history($product_id, $product_sku, $new_status, $store_url, $sync_source, 'error', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

            $this->logger->error('Failed to update product status for ' . $product_sku . ' on ' . $store_url . ': ' . $message);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $message = 'Product status updated successfully to "' . $new_status . '"';

        $this->log_status_change_history($product_id, $product_sku, $new_status, $store_url, $sync_source, 'success', $message, $remote_product_id, $start_time, $start_memory, $api_calls);

        $this->logger->info($message . ': ' . $product_sku . ' on ' . $store_url);

        return [
            'success' => true,
            'message' => $message,
            'remote_id' => $remote_product_id,
            'new_status' => $new_status,
        ];
    }

    /**
     * Log status change operation to history
     *
     * @param int $product_id Product ID
     * @param string $product_sku Product SKU
     * @param string $new_status New status
     * @param string $store_url Store URL
     * @param string $sync_source Sync source
     * @param string $status Status (success/error)
     * @param string $message Message
     * @param int|null $remote_product_id Remote product ID
     * @param float $start_time Start time
     * @param int $start_memory Start memory
     * @param int $api_calls Number of API calls
     */
    private function log_status_change_history($product_id, $product_sku, $new_status, $store_url, $sync_source, $status, $message, $remote_product_id, $start_time, $start_memory, $api_calls): void {
        $this->log_operation_history([
            'product_id' => $product_id,
            'product_sku' => $product_sku,
            'product_name' => 'Status Change',
            'store_url' => $store_url,
            'sync_type' => 'status_change',
            'sync_source' => $sync_source,
            'status' => $status,
            'message' => $message . ' (to: ' . $new_status . ')',
            'remote_product_id' => $remote_product_id,
            'start_time' => $start_time,
            'start_memory' => $start_memory,
            'api_calls' => $api_calls,
        ]);
    }
}
