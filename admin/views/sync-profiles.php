<?php
/**
 * Sync Profiles View
 *
 * @package WC_Multi_Store_Sync
 * @var array $profiles  Saved profiles from WC_Multi_Store_Sync_Profiles::get_all()
 * @var array $presets   Built-in presets from WC_Multi_Store_Sync_Profiles::get_presets()
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-mss-sync-profiles">
    <h2><?php esc_html_e('Sync Profiles', 'wc-multi-store-sync'); ?></h2>
    <p class="description">
        <?php esc_html_e('Save your current settings as a named profile to quickly switch between configurations. Built-in presets are always available.', 'wc-multi-store-sync'); ?>
    </p>

    <div id="wc-mss-profiles-notices"></div>

    <!-- Save current settings as profile -->
    <div class="wc-mss-card" style="padding: 20px; margin: 20px 0;">
        <h3 style="margin-top: 0;"><?php esc_html_e('Save Current Settings as Profile', 'wc-multi-store-sync'); ?></h3>
        <table class="form-table" style="max-width: 600px;">
            <tr>
                <th><label for="wc-mss-profile-name"><?php esc_html_e('Profile Name', 'wc-multi-store-sync'); ?></label></th>
                <td><input type="text" id="wc-mss-profile-name" class="regular-text" placeholder="<?php esc_attr_e('e.g. Peak Season Config', 'wc-multi-store-sync'); ?>"></td>
            </tr>
            <tr>
                <th><label for="wc-mss-profile-description"><?php esc_html_e('Description', 'wc-multi-store-sync'); ?></label></th>
                <td><textarea id="wc-mss-profile-description" class="large-text" rows="2" placeholder="<?php esc_attr_e('Optional description', 'wc-multi-store-sync'); ?>"></textarea></td>
            </tr>
        </table>
        <button type="button" id="wc-mss-save-profile-btn" class="button button-primary">
            <?php esc_html_e('Save Profile', 'wc-multi-store-sync'); ?>
        </button>
    </div>

    <!-- Built-in Presets -->
    <div class="wc-mss-card" style="padding: 20px; margin: 20px 0;">
        <h3 style="margin-top: 0;"><?php esc_html_e('Built-in Presets', 'wc-multi-store-sync'); ?></h3>
        <p class="description"><?php esc_html_e('Apply a preset to instantly configure the plugin for a common use case. This will overwrite current settings.', 'wc-multi-store-sync'); ?></p>

        <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Preset', 'wc-multi-store-sync'); ?></th>
                    <th><?php esc_html_e('Description', 'wc-multi-store-sync'); ?></th>
                    <th style="width: 120px;"><?php esc_html_e('Actions', 'wc-multi-store-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($presets as $key => $preset) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($preset['name']); ?></strong></td>
                        <td><?php echo esc_html($preset['description']); ?></td>
                        <td>
                            <button type="button"
                                class="button wc-mss-apply-preset"
                                data-preset="<?php echo esc_attr($key); ?>"
                                data-name="<?php echo esc_attr($preset['name']); ?>">
                                <?php esc_html_e('Apply', 'wc-multi-store-sync'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Saved Profiles -->
    <div class="wc-mss-card" style="padding: 20px; margin: 20px 0;">
        <h3 style="margin-top: 0;"><?php esc_html_e('Saved Profiles', 'wc-multi-store-sync'); ?></h3>

        <?php if (empty($profiles)) : ?>
            <p class="description"><?php esc_html_e('No saved profiles yet. Save your current settings above to create one.', 'wc-multi-store-sync'); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped" id="wc-mss-profiles-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'wc-multi-store-sync'); ?></th>
                        <th><?php esc_html_e('Description', 'wc-multi-store-sync'); ?></th>
                        <th><?php esc_html_e('Saved', 'wc-multi-store-sync'); ?></th>
                        <th style="width: 180px;"><?php esc_html_e('Actions', 'wc-multi-store-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profiles as $id => $profile) : ?>
                        <tr id="wc-mss-profile-row-<?php echo esc_attr($id); ?>">
                            <td><strong><?php echo esc_html($profile['name'] ?? __('Unnamed', 'wc-multi-store-sync')); ?></strong></td>
                            <td><?php echo esc_html($profile['description'] ?? ''); ?></td>
                            <td><?php echo esc_html($profile['updated_at'] ?? $profile['created_at'] ?? '—'); ?></td>
                            <td>
                                <button type="button"
                                    class="button wc-mss-apply-profile"
                                    data-id="<?php echo esc_attr($id); ?>"
                                    data-name="<?php echo esc_attr($profile['name'] ?? ''); ?>">
                                    <?php esc_html_e('Apply', 'wc-multi-store-sync'); ?>
                                </button>
                                <button type="button"
                                    class="button wc-mss-export-profile"
                                    data-id="<?php echo esc_attr($id); ?>">
                                    <?php esc_html_e('Export', 'wc-multi-store-sync'); ?>
                                </button>
                                <button type="button"
                                    class="button button-link-delete wc-mss-delete-profile"
                                    data-id="<?php echo esc_attr($id); ?>"
                                    data-name="<?php echo esc_attr($profile['name'] ?? ''); ?>"
                                    style="color: #d63638;">
                                    <?php esc_html_e('Delete', 'wc-multi-store-sync'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script type="text/javascript">
(function($) {
    'use strict';

    var nonce = <?php echo wp_json_encode(wp_create_nonce('wc_mss_admin')); ?>;

    function showNotice(message, type) {
        type = type || 'success';
        var cls = type === 'error' ? 'notice-error' : 'notice-success';
        $('#wc-mss-profiles-notices').html(
            '<div class="notice ' + cls + ' is-dismissible"><p>' + $('<span>').text(message).html() + '</p></div>'
        );
        $('html, body').animate({ scrollTop: 0 }, 200);
    }

    // Save current settings as profile
    $('#wc-mss-save-profile-btn').on('click', function() {
        var name = $('#wc-mss-profile-name').val().trim();
        if (!name) {
            showNotice(<?php echo wp_json_encode(__('Please enter a profile name.', 'wc-multi-store-sync')); ?>, 'error');
            return;
        }

        var $btn = $(this).prop('disabled', true).text(<?php echo wp_json_encode(__('Saving…', 'wc-multi-store-sync')); ?>);

        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'wc_mss_profile_save',
                nonce: nonce,
                name: name,
                description: $('#wc-mss-profile-description').val()
            }),
        })
            .then(function (res) { return res.json(); })
            .then(function (response) {
                if (response.success) {
                    showNotice(response.data.message);
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showNotice(response.data.message || <?php echo wp_json_encode(__('Error saving profile.', 'wc-multi-store-sync')); ?>, 'error');
                    $btn.prop('disabled', false).text(<?php echo wp_json_encode(__('Save Profile', 'wc-multi-store-sync')); ?>);
                }
            });
    });

    // Apply preset
    $(document).on('click', '.wc-mss-apply-preset', function() {
        var presetKey = $(this).data('preset');
        var presetName = $(this).data('name');

        if (!confirm(<?php echo wp_json_encode(__('Apply preset "%s"? This will overwrite your current settings.', 'wc-multi-store-sync')); ?>.replace('%s', presetName))) {
            return;
        }

        var $btn = $(this).prop('disabled', true);

        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'wc_mss_profile_apply',
                nonce: nonce,
                preset_key: presetKey
            }),
        })
            .then(function (res) { return res.json(); })
            .then(function (response) {
                if (response.success) {
                    showNotice(response.data.message);
                } else {
                    showNotice(response.data.message || <?php echo wp_json_encode(__('Error applying preset.', 'wc-multi-store-sync')); ?>, 'error');
                }
                $btn.prop('disabled', false);
            });
    });

    // Apply saved profile
    $(document).on('click', '.wc-mss-apply-profile', function() {
        var profileId = $(this).data('id');
        var profileName = $(this).data('name');

        if (!confirm(<?php echo wp_json_encode(__('Apply profile "%s"? This will overwrite your current settings.', 'wc-multi-store-sync')); ?>.replace('%s', profileName))) {
            return;
        }

        var $btn = $(this).prop('disabled', true);

        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'wc_mss_profile_apply',
                nonce: nonce,
                profile_id: profileId
            }),
        })
            .then(function (res) { return res.json(); })
            .then(function (response) {
                if (response.success) {
                    showNotice(response.data.message);
                } else {
                    showNotice(response.data.message || <?php echo wp_json_encode(__('Error applying profile.', 'wc-multi-store-sync')); ?>, 'error');
                }
                $btn.prop('disabled', false);
            });
    });

    // Export profile
    $(document).on('click', '.wc-mss-export-profile', function() {
        var profileId = $(this).data('id');
        // Trigger a download via a hidden form (avoids pop-up blockers)
        var url = ajaxurl + '?action=wc_mss_export_config&nonce=' + nonce + '&profile_id=' + encodeURIComponent(profileId);
        window.location.href = url;
    });

    // Delete profile
    $(document).on('click', '.wc-mss-delete-profile', function() {
        var profileId = $(this).data('id');
        var profileName = $(this).data('name');

        if (!confirm(<?php echo wp_json_encode(__('Delete profile "%s"? This cannot be undone.', 'wc-multi-store-sync')); ?>.replace('%s', profileName))) {
            return;
        }

        var $row = $('#wc-mss-profile-row-' + profileId);
        var $btn = $(this).prop('disabled', true);

        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'wc_mss_profile_delete',
                nonce: nonce,
                profile_id: profileId
            }),
        })
            .then(function (res) { return res.json(); })
            .then(function (response) {
                if (response.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    showNotice(response.data.message);
                } else {
                    showNotice(response.data.message || <?php echo wp_json_encode(__('Error deleting profile.', 'wc-multi-store-sync')); ?>, 'error');
                    $btn.prop('disabled', false);
                }
            });
    });

})(jQuery);
</script>
