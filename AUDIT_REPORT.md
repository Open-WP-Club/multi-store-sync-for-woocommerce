[x] Функционални пропуски ("построено, никога не окабелено") — оправено:
- WC_Multi_Store_Remote_Order_Sync::schedule_sync() — wire-in. Action_Scheduler_Manager::ensure_scheduled() вече reconcile-ва `wc_multi_store_sync_remote_orders` наравно с queue/sync-check/maintenance/weekly-verification/orphan-auto-trash: ако hook-ът не е scheduled, вика schedule_sync() (daily). Няма settings toggle за тази фича (класът се строи безусловно в admin/AJAX/cron контекст), затова reconciliation-ът е безусловен. includes/action-scheduler-manager.php
- WC_Multi_Store_Time_Manager::get_sync_type() — delete. Изместена от паралелна имплементация: `scheduled_sync_type` admin dropdown (use_default/full_product/price_quantity_categories/price_quantity/quantity, wc-settings-integration.php:535) вече е explicit user-facing контрола за scheduled sync type; get_sync_type()'s implicit peak/off-peak избор би я противоречал. get_batch_size()/get_time_period() (същия клас) си остават закачени — те действително се ползват от queue-manager.php при batch sizing. includes/time-manager.php
- Product_Extractor::format_images_smart() — delete. Изместена: sync-engine.php вече има собствена "skip images ако непроменени" логика (редовете ~618-628) с допълнителен safety guard (remote_has_no_images), който format_images_smart() нямаше — wire-in би бил regression risk, не подобрение. includes/product-extractor.php
- Cache_Manager::bulk_refresh_after_verification() / refresh_remote_product_expiration() — delete (и двете, втората стана dead като следствие). Редовете вече се refresh-ват inline и на живо във weekly-verification-remote-data-fetcher.php (get_remote_product() и prefetch_remote_batch_data() викат update_remote_product_after_sync() веднага след fetch) — bulk_refresh би бил чист no-op след факта. includes/cache-manager.php

[x] Conflict Detector — wire-in (includes/conflict-detector.php):
- check_for_conflicts() вече се вика от sync-engine.php при всеки update-sync (преди overwrite), gate-нато на собствения си `wc_mss_conflict_settings.enabled` toggle (default false) — нулев performance ефект докато не се включи explicit. store_hash() вече се вика и след успешен push (с response данните), за да не re-flag-ва един и същ конфликт вечно.
- По пътя се откри и оправи реален латентен бъг: check_for_conflicts() викаше `$client->get(...)`, а `get()` е private в API_Client — реален fatal при истински клиент, маскиран досега само защото тестовете подаваха Mockery mock. Сменено на публичния `get_product()`.
- Съзнателно НЕ имплементирано в тази сесия: `action_on_conflict` ('warn'/друго) и `notify_email` настройките остават непрочетени никъде — detection само логва конфликта, sync продължава (warn поведение). Няма admin страница за преглед на конфликти — AJAX endpoints (ajax_get_conflicts/resolve_conflict/resolve_all/toggle) вече съществуват и сега вече ще имат какво да върнат, но UI за тях е отделна by-design feature-completion задача, не dead-code cleanup.

[x] Изместени от паралелна имплементация — delete:
- Queue_Manager::get_queue_count()/remove_product()/cleanup_old_items() — Queue_Table's еквивалентите се ползват навсякъде другаде. includes/queue-manager.php
- Category_Mapper::add_mapping()/remove_mapping() — set_mappings() bulk save е единственият path от новия UI. includes/category-mapper.php

[x] API client — цял неизползван CRUD слой — delete:
- get_category/update_category/get_tag/update_tag/get_order/get_product_variation/update_product_variation — нула референции, изтрити. includes/api-client.php
- batch_products/batch_update_products/batch_create_products/batch_delete_products — цялото product-level batch API, изтрито (batch_categories/batch_tags/batch_product_variations си остават, те се ползват). includes/api-client.php

[x] По-дребни, единични — delete (освен едно изключение):
- Custom_Field_Mapper::get_available_custom_fields() — delete, заедно с private helper-ите ѝ get_acf_fields() и format_field_label(), които станаха dead като следствие. includes/custom-field-mapper.php
- Product_Transformer::clear_cache() — delete. includes/product-transformer.php
- Webhook_Receiver::get_test_webhook_url() — delete. includes/webhook-receiver.php
- API_Client::get_rate_limit_status() — delete (въпреки обширното тестово покритие в ApiClientRateLimitTest.php — нула production caller, само debugging introspection, никога изложена в admin UI).
- Hooks::clear_settings_cache() — **запазено, не изтрито**. Диагнозата в оригиналния finding ("само test setup я вика") е вярна за production кода, но пропуска, че тя е активна инфраструктура: tests/php/bootstrap.php::tearDown() я вика след ВСЕКИ тест в целия suite, за да не изтича stale Hooks::$cached_settings между тестове (които делят един PHP process). Изтриването ѝ би развалило test isolation за целия suite. В production не е нужна, защото всяка заявка получава свеж static cache естествено — това не е bug за wire-in, а коректно поведение.

---

[x] Дублирана логика — оправено:
- Action_Scheduler_Manager::reschedule_all() — admin "Reschedule Actions" бутонът вече го вика вместо да реимплементира unschedule+reschedule inline (wc-settings-integration.php::handle_reschedule_actions()). Като бонус вече уважава конфигурирания scheduled_sync_interval вместо hardcoded 10 мин.
- Queue_Table::drop_table() — изтрит (не консолидиран). uninstall.php вече дропваше 12 таблици с еднакво суров SQL; тази беше единствената с отделен class method, но нищо друго не я викаше. Reuse-ване на 1 от 12 не си струваше сложността, а drop_table()-специфичното delete_option('wc_mss_queue_db_version') и без друго се покрива от uninstall.php стъпка 3 (blanket delete на wc_mss_% опции).
