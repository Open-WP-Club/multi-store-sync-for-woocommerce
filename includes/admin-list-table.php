<?php
/**
 * Native WordPress tables for actionable sync records.
 *
 * WordPress owns selection, select-all, bulk controls, headers and pagination.
 * Views only supply their existing, escaped row cells.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WC_Multi_Store_Admin_List_Table extends WP_List_Table {
    private string $list_type;
    private array $list_columns;
    private Closure $render_cells;
    private Closure $selectable;

    public function __construct(string $type, array $columns, array $items, callable $render_cells, callable $selectable, ?int $total = null) {
        parent::__construct(['singular' => 'wc-mss-record', 'plural' => 'wc-mss-' . $type, 'ajax' => false]);
        $this->list_type = $type;
        $this->list_columns = ['cb' => '<input type="checkbox" />'] + $columns;
        $this->items = $items;
        $this->render_cells = Closure::fromCallable($render_cells);
        $this->selectable = Closure::fromCallable($selectable);
        $this->_column_headers = [$this->get_columns(), [], [], array_key_first($columns)];
        if ($total !== null) {
            $this->set_pagination_args(['total_items' => $total, 'per_page' => 50]);
        }
    }

    public function get_columns(): array {
        return $this->list_columns;
    }

    protected function get_bulk_actions(): array {
        return self::actions_for($this->list_type);
    }

    public static function actions_for(string $type): array {
        return match ($type) {
            'conflicts' => [
                'overwrite' => __('Overwrite', 'multi-store-sync-for-woocommerce'),
                'keep_remote' => __('Keep Remote', 'multi-store-sync-for-woocommerce'),
                'merge' => __('Merge', 'multi-store-sync-for-woocommerce'),
            ],
            'discrepancies' => [
                'auto_correct' => __('Auto-Correct', 'multi-store-sync-for-woocommerce'),
                'mark_resolved' => __('Mark Resolved', 'multi-store-sync-for-woocommerce'),
                'mark_ignored' => __('Ignore', 'multi-store-sync-for-woocommerce'),
            ],
            'queue' => ['retry' => __('Retry', 'multi-store-sync-for-woocommerce')],
            'dead-letter-queue' => [
                'retry' => __('Retry', 'multi-store-sync-for-woocommerce'),
                'dismiss' => __('Dismiss', 'multi-store-sync-for-woocommerce'),
            ],
            'category-scan', 'verification' => ['resync' => __('Re-sync to all stores', 'multi-store-sync-for-woocommerce')],
            default => [],
        };
    }

    public function single_row($item): void {
        echo '<tr><th scope="row" class="check-column">';
        if (($this->selectable)($item)) {
            printf(
                '<input type="checkbox" name="wc_mss_ids[]" value="%1$d" aria-label="%2$s" />',
                absint($item['id']),
                /* translators: %d: record ID. */
                esc_attr(sprintf(__('Select item %d', 'multi-store-sync-for-woocommerce'), $item['id']))
            );
        }
        echo '</th>';
        ($this->render_cells)($item);
        echo '</tr>';
    }

    public function display(): void {
        echo '<input type="hidden" name="wc_mss_list" value="' . esc_attr($this->list_type) . '" />';
        // Core uses fixed control IDs. Namespace them when several native tables
        // share a page (stock discrepancies plus per-store category scan results).
        static $instance = 0;
        $suffix = '-' . $this->list_type . '-' . ++$instance;
        ob_start();
        parent::display();
        $html = preg_replace_callback(
            '/\b(id|for|aria-describedby)=([\"\'])(cb-select-all-[12]|bulk-action-selector-(?:top|bottom)|current-page-selector|table-paging|the-list|doaction2?)\2/',
            static fn(array $match): string => $match[1] . '=' . $match[2] . $match[3] . $suffix . $match[2],
            ob_get_clean()
        );
        // Distinguish the two Apply buttons without core's single-table ID-based
        // JavaScript, so a bottom action works independently on multi-table pages.
        $button = 0;
        $html = preg_replace_callback('/name="bulk_action"/', static function () use (&$button): string {
            return 'name="wc_mss_bulk_' . (++$button === 1 ? 'top' : 'bottom') . '"';
        }, $html);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is rendered and escaped by WP_List_Table; only fixed control IDs are suffixed above.
        echo $html;
        echo '<p class="description">' . esc_html__('Select all selects the actionable records on this page.', 'multi-store-sync-for-woocommerce') . '</p>';
    }

    /** Read both pagination links (GET) and the native page input (POST). */
    public static function page_number(?int $total = null): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
        $page = isset($_REQUEST['paged']) && is_scalar($_REQUEST['paged']) ? max(1, absint(wp_unslash($_REQUEST['paged']))) : 1;
        return $total === null ? $page : min($page, max(1, (int) ceil($total / 50)));
    }

    /** Called before fetching rows so the refreshed table reflects completed actions. */
    public static function process_bulk_action(string $section): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only routing here; the type-specific nonce is checked before reading actions or IDs.
        $type = isset($_POST['wc_mss_list']) && is_string($_POST['wc_mss_list']) ? sanitize_key(wp_unslash($_POST['wc_mss_list'])) : '';
        if (!$type || ($type !== $section && !($type === 'category-scan' && $section === 'discrepancies') && !($type === 'verification' && $section === 'weekly-verification'))) {
            return;
        }
        check_admin_referer('bulk-wc-mss-' . $type);
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'multi-store-sync-for-woocommerce'));
        }
        $action_field = isset($_POST['wc_mss_bulk_bottom']) ? 'action2' : 'action';
        $action = isset($_POST[$action_field]) && is_string($_POST[$action_field]) ? sanitize_key(wp_unslash($_POST[$action_field])) : '';
        if (!$action || $action === '-1') {
            $action = isset($_POST['action2']) && is_string($_POST['action2']) ? sanitize_key(wp_unslash($_POST['action2'])) : '';
        }
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every scalar ID is sanitized with absint; nested inputs are discarded.
        $ids = isset($_POST['wc_mss_ids']) && is_array($_POST['wc_mss_ids'])
            ? array_values(array_unique(array_filter(array_map('absint', array_filter(wp_unslash($_POST['wc_mss_ids']), 'is_scalar'))))) : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (isset($_POST['wc_mss_row_action']) && is_string($_POST['wc_mss_row_action'])) {
            [$action, $id] = array_pad(explode(':', sanitize_text_field(wp_unslash($_POST['wc_mss_row_action'])), 2), 2, '');
            $ids = [absint($id)];
        }
        if (!isset(self::actions_for($type)[$action]) || !$ids) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Choose an action and at least one record.', 'multi-store-sync-for-woocommerce') . '</p></div>';
            return;
        }
        $done = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                $result = self::process_item($type, $action, $id);
                if (is_wp_error($result) || !$result) {
                    $errors[] = is_wp_error($result) ? $result->get_error_message() : sprintf(
                        /* translators: %d: record ID. */
                        __('Could not process item %d. It may no longer be actionable.', 'multi-store-sync-for-woocommerce'), $id
                    );
                } else {
                    $done++;
                }
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }
        echo '<div class="notice ' . ($errors ? 'notice-warning' : 'notice-success') . '"><p>';
        /* translators: 1: successfully processed records, 2: failed records. */
        echo esc_html(sprintf(__('Processed: %1$d. Failed: %2$d. Queued syncs are resolved only after successful processing.', 'multi-store-sync-for-woocommerce'), $done, count($errors)));
        echo '</p>';
        foreach (array_unique($errors) as $error) {
            echo '<p>' . esc_html($error) . '</p>';
        }
        echo '</div>';
    }

    public static function process_item(string $type, string $action, int $id): mixed {
        if ($id < 1 || !isset(self::actions_for($type)[$action])) {
            return false;
        }
        if ($type === 'discrepancies') {
            global $wpdb;
            $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}wc_multi_store_stock_discrepancies WHERE id = %d", $id));
            if (!in_array($status, ['pending', 'resolving'], true)) {
                return false;
            }
            return match ($action) {
                'auto_correct' => WC_Multi_Store_Stock_Verifier::auto_correct($id),
                'mark_resolved' => WC_Multi_Store_Stock_Verifier::mark_resolved($id),
                'mark_ignored' => WC_Multi_Store_Stock_Verifier::mark_ignored($id),
                default => false,
            };
        }
        return match ($type) {
            'conflicts' => WC_Multi_Store_Conflict_Detector::resolve_conflict($id, $action),
            'queue' => WC_Multi_Store_Queue_Table::retry_item($id),
            'dead-letter-queue' => $action === 'retry' ? WC_Multi_Store_Dead_Letter_Queue::retry_item($id) : WC_Multi_Store_Dead_Letter_Queue::resolve_item($id),
            'category-scan', 'verification' => WC_MSS()->queue_manager->add_product($id, 'manual_test', WC_Multi_Store_Queue_Manager::PRIORITY_HIGH, 'full_product'),
            default => false,
        };
    }
}
