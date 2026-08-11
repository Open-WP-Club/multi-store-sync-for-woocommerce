
Три паралелни агента минаха през целия plugin (~32k реда в includes/, admin/views, root файлове). Ето обединения резултат — само over-engineering/bloat находки (без performance/security, това е извън обхвата на този одит):

Най-големи находки

- yagni Category/Attribute Mapping системата (CRUD, AJAX handlers, apply_mappings/apply_tag_mappings/apply_variation_mappings) е напълно недостижима — самият settings.php признава "there is currently no admin screen to enter mappings — enabling this alone has no effect." Изтрий фийчъра или довърши UI-то. includes/category-mapper.php, includes/attribute-remapper.php (~773 реда)

Дублирана логика/мъртви делегати

- delete Remote_Product_Manager::delete()/restore()/update_status() и Variation_Synchronizer::delete_variation() — sync-engine.php реимплементира същата логика inline, вместо да ги вика. includes/remote-product-manager.php:214,273,341, includes/variation-synchronizer.php:325
- shrink pricing-rules.php — 4 метода (apply_fixed/percentage/multiplier_adjustment, apply_currency_conversion) с идентичен 15-редов loop → един apply_adjustment()
- shrink deletion-audit.php get_logs()/get_total_count() строят еднакъв WHERE clause 2 пъти; същото за category-mapper.php (apply_mappings/apply_tag_mappings), remote-order-table.php (3×), sync-history.php (3×), api-client.php batch-size проверки (4×)
- yagni Купчина one-line delegate wrapper-и с единствен caller: webhook-receiver.php::get_client_ip(), remote-order-sync.php::create_api_client(), pricing-rules.php::apply_to_variation(), weekly-verification-comparator.php (2 wrapper-а) — обади се директно на подлежащия метод, изтрий wrapper-а
- shrink coupon-sync.php/shipping-class-sync.php — schedule_async() и get_api_client() дефинирани идентично в двата файла
- native remote-order-list-table.php hand-rolled $status_labels map (дефинирана 2 пъти в файла!) вместо wc_get_order_status_name(); order-sync.php hand-rolled static settings cache вместо директно get_option()


Мъртви config флагове и write-only данни

conflict-detector.php action_on_conflict/notify_email (записват се, никой не ги чете) · coupon-sync.php auto_sync_on_save/auto_sync_deletions · api-usage-tracker.php cost-estimate опции (wc_mss_api_cost_per_1000/gb) — няма settings поле, "Cost Estimates" картата винаги е $0 · pricing-rules.php currency_from/currency_to полета никога не се четат · product-edit.php пише _wc_mss_deletion_stores_map post meta, никой не го чете · dead-letter-queue.php::do_action('wc_mss_dead_letter_added') — "no consumers anywhere" по собствените коментари в кода

Плюс: неизползвани локални променливи (variation-synchronizer.php:249-251, api-usage.php:33, discrepancies.php:68), излишен in-memory+transient двоен cache layer в settings.php::get_active_stores() (файлът сам коментира, че вторият слой "would only add an invalidation path for no real benefit"), излишен cache-manager.php warmup() hook.

net: приблизително -1800 до -2000 реда (от ~32 600 в includes/), 0 deps.

---⚠️ Извън обхвата на тоя одит, но си струва да знаеш — двe находки всъщност бяха бъгове, не bloat. **И двете оправени**:
1. [x] orphan-cleanup.php:197-266 — Delete-Selected бутонът викаше $api_client->make_request(), метод който не съществуваше в WC_Multi_Store_API_Client (реален клик там → fatal error). **Оправено**: сега използва `WC_Multi_Store_API_Client::for_store($store_url, $config)->delete_product($product_id, true)`, същия pattern като останалия sync код. Тествано в `OrphanCleanupTest.php` (реални `wp_remote_request` stub-ове вместо mock на несъществуващия метод).
2. [x] sync-previewer.php:227-250 — apply_pricing_rules()/apply_stock_allocation() викаха apply_store_pricing()/allocate_stock(), методи с други имена/сигнатури в реалния API (preview редовете за pricing/stock никога не работеха). **Оправено**: сега вика `WC_Multi_Store_Pricing_Rules::preview_price()` и `WC_Multi_Store_Stock_Allocator::calculate_allocation()` — реалните static методи. Тествано в `SyncPreviewerTest.php`.

Искаш ли да пристъпя към прилагане на останалите находки (или само на топ 5-те най-безопасни)?