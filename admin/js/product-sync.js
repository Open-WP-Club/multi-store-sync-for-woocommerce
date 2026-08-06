/**
 * WC Multi-Store Sync - Product Sync JavaScript
 *
 * @package WC_Multi_Store_Sync
 */

(function($) {
    'use strict';

    var currentSyncType = 'full_product';

    /**
     * Show result message
     */
    function showResult(element, success, message) {
        var bgColor = success ? '#d4edda' : '#f8d7da';
        var borderColor = success ? '#28a745' : '#dc3545';
        var textColor = success ? '#155724' : '#721c24';
        var icon = success ? '✓' : '✗';

        element.html(
            '<div style="background: ' + bgColor + '; padding: 10px; border-left: 3px solid ' + borderColor + '; color: ' + textColor + ';">' +
            icon + ' ' + message +
            '</div>'
        ).show();
    }

    /**
     * Reset button states
     */
    function resetButtons() {
        $('.wc-mss-sync-btn, .wc-mss-preview-btn').prop('disabled', false);
        $('.wc-mss-sync-btn').each(function() {
            var btn = $(this);
            var type = btn.data('sync-type');
            if (type === 'full_product') {
                btn.text(wcMssProduct.i18n.fullSync);
            } else if (type === 'price_quantity') {
                btn.text(wcMssProduct.i18n.priceStock);
            } else if (type === 'quantity') {
                btn.text(wcMssProduct.i18n.stockOnly);
            }
        });
        $('.wc-mss-preview-btn').text(wcMssProduct.i18n.previewChanges);
    }

    /**
     * Handle sync button click
     */
    function handleSync(e) {
        e.preventDefault();

        var button = $(this);
        var productId = button.data('product-id');
        var syncType = button.data('sync-type');
        var resultDiv = $('.wc-mss-sync-result');

        currentSyncType = syncType;

        // Disable all buttons
        $('.wc-mss-sync-btn, .wc-mss-preview-btn').prop('disabled', true);
        button.text(wcMssProduct.i18n.syncing);

        resultDiv.hide().html('');
        $('.wc-mss-preview-result').hide().html('');

        $.ajax({
            url: wcMssProduct.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wc_mss_sync_product',
                nonce: wcMssProduct.syncNonce,
                product_id: productId,
                sync_type: syncType
            },
            success: function(response) {
                showResult(resultDiv, response.success, response.data.message);
            },
            error: function() {
                showResult(resultDiv, false, wcMssProduct.i18n.errorOccurred);
            },
            complete: resetButtons
        });
    }

    /**
     * Handle preview button click
     */
    function handlePreview(e) {
        e.preventDefault();

        var button = $(this);
        var productId = button.data('product-id');
        var previewDiv = $('.wc-mss-preview-result');

        // Disable buttons
        $('.wc-mss-sync-btn, .wc-mss-preview-btn').prop('disabled', true);
        button.text(wcMssProduct.i18n.loadingPreview);

        previewDiv.hide().html('');
        $('.wc-mss-sync-result').hide().html('');

        $.ajax({
            url: wcMssProduct.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wc_mss_preview_sync',
                nonce: wcMssProduct.previewNonce,
                product_id: productId,
                sync_type: currentSyncType
            },
            success: function(response) {
                if (response.success) {
                    previewDiv.html(response.data.html).show();
                } else {
                    showResult(previewDiv, false, response.data.message);
                }
            },
            error: function() {
                showResult(previewDiv, false, wcMssProduct.i18n.errorOccurred);
            },
            complete: function() {
                $('.wc-mss-sync-btn, .wc-mss-preview-btn').prop('disabled', false);
                button.text(wcMssProduct.i18n.previewChanges);
            }
        });
    }

    /**
     * Handle execute sync from preview
     */
    function handleExecuteSync(e) {
        e.preventDefault();

        var button = $(this);
        var productId = button.data('product-id');
        var syncType = button.data('sync-type');
        var resultDiv = $('.wc-mss-sync-result');

        // Disable buttons
        $('.wc-mss-execute-sync, .wc-mss-cancel-preview').prop('disabled', true);
        button.text(wcMssProduct.i18n.syncing);

        $.ajax({
            url: wcMssProduct.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wc_mss_sync_product',
                nonce: wcMssProduct.syncNonce,
                product_id: productId,
                sync_type: syncType
            },
            success: function(response) {
                $('.wc-mss-preview-result').hide();
                showResult(resultDiv, response.success, response.data.message);
            },
            error: function() {
                showResult(resultDiv, false, wcMssProduct.i18n.errorOccurred);
            }
        });
    }

    /**
     * Handle cancel preview
     */
    function handleCancelPreview(e) {
        e.preventDefault();
        $('.wc-mss-preview-result').hide().html('');
    }

    // Initialize when document is ready
    $(document).ready(function() {
        // Sync buttons
        $('.wc-mss-sync-btn').on('click', handleSync);

        // Preview button
        $('.wc-mss-preview-btn').on('click', handlePreview);

        // Execute sync from preview (delegated event)
        $(document).on('click', '.wc-mss-execute-sync', handleExecuteSync);

        // Cancel preview
        $(document).on('click', '.wc-mss-cancel-preview', handleCancelPreview);
    });

})(jQuery);
