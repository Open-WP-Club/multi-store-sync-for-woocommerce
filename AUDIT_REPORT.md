Цели unreachable фийчъри:
- delete: Conflict Detector — check_for_conflicts() никога не се вика от sync-engine.php; съществуват AJAX handlers (ajax_get_conflicts/resolve_conflict/resolve_all) и toggle, но няма admin страница да ги викаш, и нищо никога не създава конфликт запис за начало. Цялата фича е инертна от край до край. Изтрий или окабели. includes/conflict-detector.php

Потвърдени bug-класа "построено, никога не окабелено":
- delete: WC_Multi_Store_Remote_Order_Sync::schedule_sync() никога не се вика (само unschedule_sync() при deactivate) — рекламираният "automatic daily" remote order sync никога реално не си създава recurring action-а. includes/remote-order-sync.php:385
- delete: WC_Multi_Store_Time_Manager::get_sync_type() (peak/off-peak sync type адаптация) — само тестове го викат; scheduled sync никъде не го консултира. includes/time-manager.php:57
- delete: Product_Extractor::format_images_smо е непроменено" оптимизацията е построена итествана, но product data build пътят навсякъде вика старото format_images(). includes/product-extractor.php:521
- delete: Cache_Manager::bulk_refresh_after_verification() — казва си от името, но weekly-verification-scheduler.php никога не я вика след run. includes/cache-manager.php:245

Дублирана логика (същия паттерн като по-рано тази сесия):
- delete: Action_Scheduler_Manager::reschedulule() — admin "Reschedule Actions" бутонътреимплементира unschedule+reschedule inline вместо да вика reschedule_all(); ensure_clean_schedule()'s собствен
docblock казва "call on activation", activateudes/action-scheduler-manager.php:768,862
- delete: Queue_Table::drop_table() — uninstall.php пуска суров DROP TABLE SQL directly вместо да я вика.
includes/queue-table.php:72

Изместени от паралелна имплементация:
- delete: Queue_Manager::get_queue_count()/re_items() — admin Queue страницата и всичкоостанало ползва Queue_Table's еквивалентите вместо тях. includes/queue-manager.php
- delete: Category_Mapper::add_mapping()/remove_mapping() — новата UI спестява bulk чрез set_mappings(); тези единични helper-и вече не се викат никъде извън собствените си тестове. includes/category-mapper.php:99,111

API client — цял неизползван CRUD слой:
- delete: get_category/update_category/get_tag/update_tag/get_order/get_product_variation/update_product_variation —
нула референции navsякъде, дори в тестове. in
- delete: batch_products/batch_update_products/batch_create_products/batch_delete_products — цялото product-level
batch API е неизползвано в production (продук по един); самоbatch_categories/batch_tags/batch_product_variations реално се викат. includes/api-client.php

По-дребни, единични:
- yagni: Custom_Field_Mapper::get_available_custom_fields() — няма admin view да я вика. includes/custom-field-mapper.php:96
- yagni: Product_Transformer::clear_cache() — само тест. includes/product-transformer.php:216
- yagni: Hooks::clear_settings_cache() — нищо в production не инвалидира кеша след запис на settings; само test setup я вика. includes/hooks.php:118
- yagni: Webhook_Receiver::get_test_webhook_url(), API_Client::get_rate_limit_status() — построени, тествани, никой
admin view не ги показва.