<?php
/**
 * SKU conflicts
 *
 * Remembers products that could not be synced because another product on the remote store
 * already uses their SKU. That is a data problem only a person can fix, so it is kept until the
 * product syncs successfully and shown as a warning instead of being buried in retries.
 *
 * @package WC_Multi_Store_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Store_Sku_Conflicts {

    const string OPTION = 'wc_mss_sku_conflicts';

    /**
     * Oldest entries are dropped beyond this, so a bad import cannot grow the option without bound.
     */
    const int MAX_ENTRIES = 100;

    /**
     * Remember that a product cannot be saved on a store because of a duplicate SKU.
     *
     * @param int    $owner_remote_id Remote product that already holds the SKU, 0 when unknown
     * @param string $owner_name      Name of that remote product, to recognise it without opening it
     */
    public static function record(int $product_id, string $store_url, string $sku, int $target_remote_id, int $owner_remote_id, string $owner_name = ''): void {
        $conflicts = self::get_all();
        $key = self::key($product_id, $store_url);

        unset($conflicts[$key]);
        $conflicts[$key] = [
            'product_id' => $product_id,
            'store_url' => $store_url,
            'sku' => $sku,
            'remote_id' => $target_remote_id,
            'owner_remote_id' => $owner_remote_id,
            'owner_name' => $owner_name,
            'time' => time(),
        ];

        update_option(self::OPTION, array_slice($conflicts, -self::MAX_ENTRIES, null, true), false);
    }

    /**
     * Forget a conflict once the product has synced to the store.
     */
    public static function clear(int $product_id, string $store_url): void {
        $conflicts = self::get_all();
        $key = self::key($product_id, $store_url);

        if (!isset($conflicts[$key])) {
            return;
        }

        unset($conflicts[$key]);
        update_option(self::OPTION, $conflicts, false);
    }

    /**
     * @return array<string, array{product_id: int, store_url: string, sku: string, remote_id: int, owner_remote_id: int, owner_name?: string, time: int}>
     */
    public static function get_all(): array {
        $conflicts = get_option(self::OPTION, []);

        return is_array($conflicts) ? $conflicts : [];
    }

    /**
     * Edit screen of a product in the remote store's admin.
     */
    public static function remote_edit_url(string $store_url, int $remote_id): string {
        return untrailingslashit($store_url) . '/wp-admin/post.php?post=' . $remote_id . '&action=edit';
    }

    public static function count(): int {
        return count(self::get_all());
    }

    private static function key(int $product_id, string $store_url): string {
        return $product_id . ':' . md5(untrailingslashit(strtolower($store_url)));
    }
}
