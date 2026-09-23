# Plugin Check — план за отстраняване на проблемите

Базирано на резултатите от GitHub Actions run `35745146319` (Plugin Check, категории `security,performance,general`).

## Обобщение

| Ниво | Проблем | Брой места | Реален риск |
|---|---|---|---|
| ERROR | Липсваща ABSPATH защита | 1 файл | Нисък (dev-only файл) |
| ERROR | Неескейпнати SQL параметри | ~20 места в ~13 файла | Среден — предимно false positives, но всяко трябва да се потвърди individually |
| WARNING | `wp_redirect()` вместо `wp_safe_redirect()` | 3 места в 2 файла | Нисък-Среден |
| WARNING | Direct DB query / No caching | ~150+ места | Нисък — плъгинът борави с 15+ собствени таблици |
| WARNING | `tax_query` (бавна заявка) | 3 файла | Нисък, информативно |

## Фаза 1 — ABSPATH guard

- [x] `phpstan-constants.php` (корен на плъгина) — dev-only helper за PHPStan, не се зарежда от самия плъгин по време на изпълнение. Добави `if ( ! defined( 'ABSPATH' ) ) exit;` след `<?php`.

## Фаза 2 — Unescaped DB параметри (основната работа)

Типичен pattern (виж `weekly-verification-report-repository.php:119`):

```php
$query = "SELECT * FROM {$table_name} ORDER BY {$orderby} LIMIT {$limit} OFFSET {$offset}";
$results = $wpdb->get_results($query, ARRAY_A);
```

`$table_name` е `$wpdb->prefix . 'фиксиран_низ'`, `$orderby` минава през whitelist + `sanitize_sql_orderby()`, `$limit`/`$offset` са `absint()` — реално безопасни, но Plugin Check не разпознава ръчната валидация, защото низът не минава през `$wpdb->prepare()`.

За всяко от засегнатите места:

1. Числовите части (LIMIT/OFFSET/брой дни и т.н.), които **не** минават вече през `prepare()` → обвий с `$wpdb->prepare("... LIMIT %d OFFSET %d", $limit, $offset)`.
2. Table names (`$wpdb->prefix . 'wc_mss_...'`) — не могат да се параметризират с `prepare()` (third-quote-ва ги като низ, чупи заявката). Добави инлайн `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, not user input.`
3. `$orderby`/`$field_col`, изградени от whitelist масив → same treatment, `phpcs:ignore` с обяснение защо е безопасно.
4. **Важно**: преди `phpcs:ignore`, провери дали стойността реално идва от `$_GET`/`$_POST` без whitelisting надолу по веригата — там е най-вероятно да има истински пропуск, а не false positive.

Работен ред (от най-рисково към най-малко рисково):

- [x] `includes/class-admin-ajax.php` — най-много ERROR-и (line 480, 522, 661, 680, 1032), AJAX endpoint = най-рисково, провери входа внимателно
- [x] `includes/remote-order-table.php` — admin list table, user input за sorting/filtering
- [x] `includes/remote-order-list-table.php` (line 458)
- [x] `includes/weekly-verification-report-repository.php` (line 119/121)
- [x] `includes/api-usage-tracker.php` (line 178/179)
- [x] `includes/sync-history.php` (line 202/207, 213)
- [x] `includes/deletion-audit.php` (line 303, 345)
- [x] `includes/stock-verifier.php` (line 238)
- [x] `includes/webhook-logger.php` (line 427)
- [x] `includes/email-notifications.php` (line 333)
- [x] `includes/logger.php` (line 197)
- [x] `includes/dead-letter-queue.php` (line ~220)
- [x] `includes/conflict-detector.php` (line ~375)

## Фаза 3 — `wp_redirect()` → `wp_safe_redirect()`

И трите строят URL-а с `add_query_arg(..., admin_url('admin.php'))` — вече е admin URL, така че прост replace на функцията е достатъчен, без нужда от `allowed_redirect_hosts` филтър.

- [x] `includes/remote-order-admin.php:100`
- [x] `includes/remote-order-admin.php:121`
- [x] `includes/remote-order-sync.php:371`

## Фаза 4 — Direct DB query / No caching (масовите warning-и)

Не са бъгове — плъгинът има собствени таблици (queue, logger, cache-manager, dead-letter-queue и т.н.), за които WP core функциите (`get_posts`, `WP_Query`) не важат.

- [x] Direct DB/NoCaching е изключено в `phpcs.xml.dist` за custom operational tables, с документирана причина; няма приложим WP core API.
- [x] Прегледано е кеширането: не е добавено механично, тъй като разглежданите queue/log/admin данни са често променяни и кешът би добавил invalidation риск без измерима полза.

## Фаза 5 — `tax_query` warnings

3 файла ползват `tax_query` за категорийна синхронизация — по презумпция необходимо.

- [x] `includes/weekly-verification-remote-data-fetcher.php:400`
- [x] `includes/category-sync.php:48`
- [x] `includes/settings.php:441`

Добави `phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query` с обяснение, освен ако не се открие по-евтина алтернатива при преглед.

## Фаза 6 — Открити извън първоначалния обхват input-handling проблеми

Пълният PHPCS отчет след предишните фази откри допълнителни `ValidatedSanitizedInput` и
nonce findings. Те са приоритизирани пред remaining custom-table SQL false positives.

- [x] `includes/product-edit.php` — save handler nonce се unslash-ва и sanitизира преди
  `wp_verify_nonce()`; AJAX `sync_type` се чете само при налично поле и се unslash-ва преди
  sanitization. Съществуващите nonce и capability проверки са запазени.
- [x] `includes/sync-profiles.php` — state-changing AJAX handlers вече проверяват наличие на
  `profile_id`, `name`, `description` и `preset_key` и прилагат `wp_unslash()` преди
  sanitization. Съществуващите `wc_mss_admin` nonce и `manage_woocommerce` проверки са запазени.
- [x] `includes/attribute-remapper.php` и `includes/category-mapper.php` — mapping масивите
  преминават през `wp_unslash()` и `map_deep(..., 'sanitize_text_field')`; scalar inputs се
  валидират за наличие и се sanitизират. И двата handler-а вече имаха nonce/capability checks.
- [x] `includes/dead-letter-queue.php` — pagination, status и item ID inputs вече се проверяват
  за наличие и минават през `wp_unslash()` плюс `absint()`/`sanitize_text_field()`.
- [x] `includes/store-health-check.php` — URL, username и app-password AJAX полетата вече се
  unslash-ват преди URL/text sanitization; nonce, capability и SSRF validation са запазени.
- [x] `includes/webhook-logger.php` — `REMOTE_ADDR` и доверените proxy headers вече се
  unslash-ват и sanitизират преди IP validation.
- [x] `includes/webhook-receiver.php` — добавено е narrowly-scoped PHPCS изключение за
  `$wpdb->postmeta`; това е WordPress core table name, а числовите данни остават `%d`
  placeholders в `$wpdb->prepare()`.
- [x] `includes/wc-settings-integration.php` — всички state-changing form payload-и вече се
  unslash-ват в локален масив и всяко поле се проверява за наличие преди type-specific
  sanitization/allowlist validation. Nonce проверката остава в action-specific `output()`
  dispatcher-а; PHPCS изключението е ограничено до private handler-ите, които този dispatcher
  извиква само след `check_admin_referer()`.
- [x] `includes/class-admin-ajax.php` — history, webhook-log, SKU, category, scan и retry
  inputs вече се unslash-ват преди sanitization/`absint()`. Closure-bound handler bodies имат
  явна nonce проверка, защото PHPCS не проследява shared auth trait-а; capability guard-ът в
  trait-а е запазен.
- [x] `includes/conflict-detector.php` и `includes/remote-order-admin.php` — входовете за
  resolve/delete actions вече се unslash-ват и sanitизират; bulk conflict resolution валидира
  strict allowlist за `resolution`. За remote-order delete nonce-ът се unslash-ва и sanitизира
  преди verification. `get_conflicts()` вече изгражда подготвена заявка по статични branches.
- [x] `includes/queue-table.php` — PreparedSQL findings са потвърдени false positives:
  queue/DLQ table names са `$wpdb->prefix` плюс fixed constants, а ordering fragment е fixed
  literal. Добавени са документирaни PHPCS изключения; външните стойности остават placeholders.
- [x] `includes/remote-order-list-table.php` — list filters, sorting и hidden `page` поле вече
  проверяват наличие и изпълняват `wp_unslash()` преди type-specific sanitization.
- [x] `includes/dashboard-widget.php`, `includes/coupon-sync.php`, `includes/attribute-sync.php`
  и `includes/review-sync.php` — AJAX входовете се unslash-ват и sanitизират, boolean стойностите
  се приемат само в очаквания формат, а state-changing handlers имат явна nonce проверка наред със
  съществуващия capability guard.
- [x] `includes/orphan-cleanup.php`, `includes/remote-order-sync.php`, `includes/bulk-actions.php`
  и засегнатите `admin/views/*` — GET/POST стойностите се проверяват за наличие и минават през
  `wp_unslash()` преди type-specific sanitization. JSON payload-ите се unslash-ват, декодират и
  type-check-ват; narrowly-scoped изключенията запазват валиден JSON вместо да го повреждат с
  text sanitization.
- [x] `includes/config-manager.php` — privileged JSON import payload-ът вече изисква налична
  непразна string стойност, `wp_unslash()`, успешно JSON decoding и array type validation; nonce
  и capability проверки са запазени.
- [x] `includes/queue-manager.php` и `includes/action-scheduler-manager.php` — PreparedSQL
  findings са проверени: динамичните фрагменти са единствено генерирани `%d` placeholder списъци
  от integer IDs, `$wpdb` core table names или fixed custom table names. PHPCS изключенията са
  ограничени до тези SQL блокове.
- [x] `includes/remote-order-table.php` и
  `includes/weekly-verification-remote-data-fetcher.php` — remaining `%d` list warnings са
  false positives: списъците се генерират от database-derived integer IDs, стойностите се подават
  само към `$wpdb->prepare()`, а table names са fixed plugin или `$wpdb` core names. Добавени са
  тесни PHPCS изключения около конкретните заявки.

### Статус след фаза 6

- [x] Всички PHPCS **errors** от този review са отстранени.

## Фаза 7 — NonceVerification.Recommended warning-и

- [x] Прегледани са всички 79 оставащи warning-а от `vendor/bin/phpcs --report=full` по
  endpoint/flow, а не механично по файл.
- [x] `admin/views/api-usage.php`, `deletion-audit.php`, `history.php`, `stores.php`,
  `includes/remote-order-list-table.php` и queue/chart settings контекстът са само read-only
  GET филтри, pagination, sort, route selectors или screen context. Добавени са тесни inline
  `NonceVerification.Recommended` изключения с причина; не е добавян nonce към GET UI.
- [x] `includes/bulk-actions.php` и success notice-ът в `remote-order-admin.php` четат само
  стойности от redirect за presentation. Те не извършват действие; входът се unslash-ва преди
  числовото преобразуване.
- [x] `includes/conflict-detector.php::ajax_get_conflicts()` е read-only и преминава през
  `WC_Multi_Store_Ajax_Auth_Guard::verify_admin_request()`, който реално извиква
  `check_ajax_referer()` и `current_user_can('manage_woocommerce')`. Изключенията са ограничени
  до четирите филтъра, защото PHPCS не следва trait-а.
- [x] `includes/remote-order-admin.php` запазва nonce проверките за single и bulk delete преди
  изтриване и capability проверката. Route/id стойностите се използват само за dispatch, а
  state-changing клоновете не са прикрити с ignore.
- [x] `includes/hooks.php` използва `bulk_edit` единствено като context guard в WooCommerce save
  hook. Native WordPress bulk-edit request вече е nonce-валидирана преди hook-ът да се извика;
  стойността се проверява за наличие, scalar тип, unslash-ва и sanitизира.
- [x] `includes/wc-settings-integration.php` export download-ът остава nonce-верифициран, а
  останалите параметри в тази група са read-only screen context. GET input-ите сега се проверяват
  за наличие и минават през `wp_unslash()` преди sanitization/`absint()`.
- [x] След тесните изключения `vendor/bin/phpcs --report=full` е чист (0 errors / 0 warnings).

## Фаза 8 — Целенасочен endpoint security review

- [x] `includes/webhook-receiver.php` — подписаният REST payload вече изисква array body,
  string `store_url` (следван от existing registered-store validation), положителен scalar order ID, string status и array `line_items`;
  невалидните payload-и се отхвърлят с 400 преди stock processing. Съществуващата HMAC
  permission callback и rate-limit остават непроменени.
- [x] `includes/remote-order-sync.php` — manual admin sync приема `status` само ако е в
  allowlist-а от регистрираните WooCommerce order statuses (с fallback core списък), като
  запазва custom Woo statuses и existing nonce/capability checks.
- [x] `includes/wc-settings-integration.php`, `includes/api-client.php` и `includes/product-edit.php` — select/sync-type
  inputs вече изискват scalar string и strict allowlist; `deletion_mode` не допуска извън
  `trash|force`, а invalid sync type безопасно се връща към `full_product`. Auth method
  allowlist-ът използва API-client constants, за да запази query-string backward compatibility
  без hand-rolled auth fallback.
- [x] `includes/toggleable-feature-trait.php` приема само string стойност `"1"` за enable и
  я unslash-ва/санитизира; `admin/views/discrepancies.php` добавя explicit
  `manage_woocommerce` guard към mutation form handlers, type checks за action/ID и unslash
  за cleanup days. Nonce-ът им е запазен; read-only GET филтрите не са променяни.

## Фаза 9 — Mapping AJAX payload validation

- [x] `includes/category-mapper.php` — save handler-ът изисква string store URL, array mapping
  payload и strict `category|tag` allowlist. Nested/non-string key-value entries се пропускат,
  вместо да се записват в option storage.
- [x] `includes/attribute-remapper.php` — същата type validation за name/value mapping arrays;
  `mapping_type` е strict `names|values`, а value mappings вече изискват непразен string
  `attribute_name`. Съществуващите nonce и `manage_woocommerce` guards са запазени.

## Фаза 10 — Remaining admin mutation validation

- [x] `admin/views/discrepancies.php` — mutation action-ът вече отказва ID 0, cleanup days
  се bound-ват до UI-supported 1–365 и read-only GET status filter е strict allowlist
  (`pending|resolving|resolved|ignored|all`), без да се добавя nonce към filter-а.
- [x] `includes/attribute-sync.php`, `coupon-sync.php`, `review-sync.php` и
  `conflict-detector.php` — independent AJAX toggles приемат `enabled` само като scalar string
  `"1"`, след `wp_unslash()` и sanitization; всички existing nonce/capability checks остават.

## Фаза 11 — Delete/retry, health и store-form endpoint validation

- [x] `includes/class-admin-ajax.php` и `includes/dead-letter-queue.php` — destructive history/
  webhook-log actions имат strict delete-type and webhook-log-type allowlists, 1–365 day bounds
  и scalar input checks; DLQ pagination/status и retry/resolve IDs са type/bounds validated.
- [x] `includes/store-health-check.php` и connection-test AJAX в
  `includes/wc-settings-integration.php` — URL/credential inputs вече изискват strings, а
  saved-password flag приема само `"1"`; nonce, capability и SSRF checks са запазени.
- [x] Store add/update/delete form handlers в `includes/wc-settings-integration.php` — всички
  scalar fields вече се type-check-ват след shared unslash, store status е strict
  `active|inactive`, cache-purge method остава strict `GET|POST`, а category/tag ID arrays
  игнорират nested values.
- [x] `includes/sync-profiles.php` — save/apply/delete AJAX inputs вече са scalar-only;
  preset key се normalize-ва с `sanitize_key()` без да се стесняват legacy profile ID formats.

## Фаза 12 — Read-only webhook-log AJAX filters

- [x] `includes/class-admin-ajax.php` — read-only list/export webhook-log filters вече
  приемат само scalar input след `wp_unslash()`: log type се сверява с enum allowlist-а,
  status с `success|failed`, а date filters изискват реална `Y-m-d` дата. Невалидните
  optional filters се игнорират вместо да се предават към query layer; pagination е
  ограничена до 1–100 items и 1–10000 pages, а statistics days до 1–365.
- [x] Не е добавян nonce към read-only endpoints: те продължават да използват existing shared
  `verify_admin_request()` guard. Нормализаторът получава вече unslash-нат payload от handler-а,
  затова не е нужен PHPCS ignore за nonce false positive.
- [x] `tests/php/Unit/AdminAjaxForceSyncTest.php` покрива allowlists, array rejection,
  invalid/leap-date handling и pagination/day boundaries.

## Фаза 13 — Orphan-cleanup AJAX payload validation

- [x] `includes/orphan-cleanup.php` — scan и scheduled-scan приемат само scalar store URL,
  който се resolve-ва до configured store (включително legacy trailing-slash вариант); непознат
  URL вече не може да стартира remote operation. Празният URL запазва contract-а за „all stores“.
- [x] Cleanup JSON payload-ът изисква list от records с registered string `store_url` и
  positive integer product ID. Payload-ът се намалява до тези два нужни полета преди sync или
  Action Scheduler job; malformed/nested/unregistered entries отказват цялата destructive заявка.
- [x] Capability-denied branches вече връщат веднага, а scan exception details следват същото
  `WP_DEBUG` redaction поведение като cleanup handler-а.
- [x] `tests/php/Unit/OrphanCleanupTest.php` покрива non-scalar store input и malformed cleanup
  record regression cases.

## Ред на изпълнение

1. Фаза 1 + Фаза 3 (тривиални, нисък риск) — отделен commit
2. Фаза 2, файл по файл, започвайки с `class-admin-ajax.php` — отделни commit-и по логически групи
3. Фаза 4 + Фаза 5 накрая, в bulk
