# Changelog

All notable changes to this project are documented in this file.

## 5.3.1 — 2026-10-23

- Fixed: updating a remote product that was deleted on the remote store (stale cached or stored ID, "Invalid ID") now forgets the stale ID, looks the product up again and updates what is there, or re-creates it on a full sync, instead of failing on every retry.
- Improved: a duplicate SKU on the remote store (detected by WooCommerce's error code, so it works whatever language the remote site uses) now produces a clear message that names the remote product holding the SKU, and the queue stops retrying it.
- Added: products blocked by a duplicate SKU are listed as a warning on the plugin dashboard and in the WordPress dashboard widget until they sync successfully.
- Changed: plugin pages no longer draw white boxes, borders and shadows around their sections; they use the plain WordPress look with side padding, like other plugins.

## 5.3.0 — 2026-10-23

- New: **Primary Site Wins** (Settings). When on, the main store is the source of truth: sync conflicts resolve themselves by overwriting the remote edit (closed as `auto_overwrite` after the sync succeeds), stock discrepancies are corrected from the main store (max 3 attempts per product and store per day), and Weekly Verification always auto-corrects. Existing unresolved conflicts are queued when the option is turned on. Removing remote products that no longer exist locally stays a separate Orphan Cleanup setting.
- Fixed: Orphan Cleanup aborts instead of flagging every remote product as an orphan (and auto-trashing them) when the local SKU list is empty or unreadable.
- Fixed: bracketed IPv6 link-local addresses (`[fe80::1]`) are now blocked by the store URL and cache-purge URL checks like `169.254.x.x`.
- Fixed: newly created and renamed remote categories/tags were written to a cache key nothing read, so every following product re-detected them as missing.
- Fixed: stock allocation no longer returns negative quantities for backorder (negative) stock, ignores negative reserves, and caps the extra reserve percentage at 100.
- Fixed: a sale price equal to the regular price after clamping is removed instead of kept as `0`.
- Fixed: the product "delete from stores" box can save "no store selected"; browsers send nothing when every checkbox is unticked.
- Fixed: attribute sync looks through every page of remote attributes instead of only the first 100, so large stores no longer get duplicates; an attribute named `0` no longer returns the whole value-mapping table.
- Fixed: the webhook log export now applies the status and SKU filters that the list shows, and keeps UTF-8 characters in the CSV.
- Fixed: sorting remote orders by the Order column; weekly-verification recipient lists with a trailing comma; a PHP warning for addresses with only a first or last name; price discrepancy messages are escaped; corrupt shipping-class cache entries are ignored.
- Fixed (admin): quotes are escaped in mapping tables, store switches ignore stale responses, failed webhook delete/detail requests show an error, Dead Letter Queue buttons re-enable after a failure, config export tolerates a missing checkbox.
- Fixed (webhooks): the CSV export could be split into extra cells by a crafted SKU/error message, defeating the formula-injection guard; failed deductions no longer block WooCommerce's retry; negative or non-numeric quantities never deduct stock; one failing line no longer aborts the rest of an order or crashes on a product deleted mid-request; log filters accept a literal `0`, a zero page size no longer errors, and an invalid retention setting can no longer wipe every log.
- Fixed (queue): deletions, restores and status changes are no longer swallowed by a pending regular sync of the same product; items for an inactive store are failed instead of synced; claiming an item is atomic so two workers cannot process it twice; an item that keeps crashing the worker is parked as failed after three resets instead of looping forever; a failed database read or an invalid batch size no longer causes a fatal error.
- Fixed (images): local attachments are only read from inside the uploads directory, remote image URLs must be http(s) and not a link-local address, and URLs without a path get a safe filename.
- Fixed (configuration): a redacted export now also hides the application password; importing validates everything before writing anything, restores each redacted credential separately, rejects malformed store entries, keeps unknown or wrongly typed values out of the settings (batch sizes, hours and retention are clamped like the Settings screen).
- Fixed (data safety): deleting a category no longer removes products whose remote deletion could not be queued, and "uncategorize" keeps a product's other categories; deletion-audit cleanup keeps rows it could not archive; sync-history cleanup/deletion no longer wipes everything for a zero or fractional age and only matches the exact store host.
- Fixed (orders and scheduling): remote order sync reports failed inserts/updates and survives malformed API entries, unchanged orders are no longer rewritten on every run, a date-only "to" filter includes that whole day, peak hours that cross midnight work and batch sizes are at least 1.
- Fixed (robustness): sync profile import rejects malformed files and sanitises names, product exclusion lists tolerate corrupt values, empty health-alert recipients fall back to the admin email, and corrupt order/health options no longer cause fatal errors.
- Tests: added coverage for most previously untested classes and the admin scripts; the suite now passes in random order.

## 5.2.0 — 2026-10-20

- Changed: unified actionable sync lists and read-only reports using native WordPress list tables, notices, cards, and the WordPress dialog.
- Changed: shared WordPress AJAX handling and one verification controller for Dashboard and Weekly Verification; removed duplicated scripts and presentation styles.
- Improved: translatable admin messages and consistent pagination that preserves active filters.
- Fixed: Orphan Cleanup processes selected products only from the corresponding store.
- Fixed: late cancellation responses no longer resume verification polling after leaving the page.
- Tests: expanded coverage for asynchronous verification, bulk-action failures, remote-data escaping, and release metadata consistency.
- Fixed: discrepancy queries prepare filters and pagination together and restrict sorting to known columns.
- Improved: documented necessary direct database calls at their use sites and made PHPCS and Plugin Check required CI checks.
- Fixed: distinct SKUs and store URLs no longer collide in cache keys; long URLs produce keys within WordPress's 172-character limit and store invalidation uses the same identity.
- Fixed: remote category/tag invalidation uses the WordPress Transients API for every configured store, including disabled stores and persistent object caches.
- Fixed: daily email summaries use the WordPress calendar day across timezone/DST changes and skip sending when statistics cannot be read.
- Fixed: global/store cache invalidation now rotates persistent WordPress option generations, works with Redis/Memcached transients, and preserves unrelated stores and other plugins' caches.
- Fixed: expired-transient maintenance escapes the exact plugin prefix, rechecks expiration atomically when deleting bounded pairs, and invalidates stale WordPress option-cache entries.
- Fixed: store saves and configuration imports report failed cache invalidation, support retrying identical saved settings, and show admin errors instead of success notices.
- Fixed: invalid cached product/category/tag/taxonomy values are treated as cache misses while valid empty arrays and negative-product markers retain their meaning.
- Fixed: daily summaries skip sending incomplete reports when the per-store statistics query fails.

## 5.0.0

- New: Orphan Cleanup's "Delete Selected" now shows live progress (X of Y products processed) in a persistent status banner that survives page reloads, instead of leaving a "Deleting…" button with no feedback for the minutes a large batch can take in the background
- Change: the Category Mapping, Attribute Mapping, and Conflicts settings tabs are now hidden until their matching feature toggle is turned on in Settings, so the tab bar doesn't show screens that don't apply to a store that never enabled them
- Fix: lowered the declared minimum PHP version from 8.4 to 8.3 — the codebase never actually used any PHP 8.4-only syntax (the two newest features in use, typed class constants and the `#[\Override]` attribute, both shipped in PHP 8.3), and the inflated requirement was blocking activation in PHP 8.3 environments, including the WordPress Plugin Check CI job
- Updated compatibility headers: `WC tested up to` 9.5 → 11.1, added a `Tested up to` header for WordPress core (7.1)
- Fix: added the `Requires Plugins: woocommerce` header (WordPress 6.5+'s native plugin-dependency declaration) — WordPress now refuses activation with a clear message when WooCommerce isn't active, instead of the plugin fataling the moment activation touches a WooCommerce class
- Chore: updated dev dependencies (PHPUnit, PHPStan, WooCommerce stubs, Mockery) to their latest compatible versions

## 4.2.2

- New: "Delete Selected" in Orphan Cleanup now runs as a background Action Scheduler job with status polling instead of blocking the AJAX request, so deleting 100+ orphan products no longer risks a PHP/webserver timeout

## 4.2.1

- Fix: a swapped-argument bug in the weekly verifier's auto-correction called the queue manager with the wrong parameter order, causing a fatal error on every discrepancy auto-correct; queue manager methods are now type-hinted so this class of bug is caught at the call site
- Improved: widened the dead-letter-queue admin notification cooldown from 1 hour to 1 day to stop email spam
- Improved: the Logs admin page now falls back to the most recent log file when today's hasn't been written yet (WooCommerce rotates log files daily)

## 4.2.0

- New: **Product Review Sync** — sync product reviews to child stores
- New: **Global Attribute/Term Sync** — sync global product attributes and terms to child stores

## 4.1.3

- Improved: removed dead code from the logger and hardened the queue manager, hooks, conflict detector, coupon sync, and stock update tracker

## 4.1.2

- Fix: plugin initialization now defers WooCommerce-dependent setup to `plugins_loaded`, since WordPress's active-plugins order doesn't guarantee WooCommerce loads before this plugin — prevents a fatal error on some installs
- Improved: hardened queue locking and handling of sensitive settings

## 4.1.1

- Fix: dashboard statistics calculation and hardened the cache-purge request

## 4.1.0

- New: **Category Mapping** admin screen — map local categories/tags to different names per remote store (WooCommerce > Settings > Multi-Store Sync > Category Mapping)
- New: **Attribute Mapping** admin screen — map local attribute names and values to different names per remote store (WooCommerce > Settings > Multi-Store Sync > Attribute Mapping)
- Fix: category/attribute mapping's store selector failed to load existing mappings (request data was read from the wrong PHP superglobal)
- Improved: removed dead code paths and duplicated logic across the sync engine, pricing rules, and mapping modules

## 4.0.0

- New: **Custom Field / ACF Mapper** — sync custom post meta and Advanced Custom Fields (including repeater fields) to child stores with per-field local-to-remote mapping
- New: **Remote Orders** — pull orders from child stores back to the main store on a schedule and browse them (items, customer, billing, shipping) in a dedicated read-only viewer under WooCommerce > Remote Orders
- Change: plugin admin UI moved from its own top-level menu into **WooCommerce > Settings > Multi-Store Sync**
- Change: project renamed to **Multi-Store Sync for WooCommerce**, repository moved to [Open-WP-Club/multi-store-sync-for-woocommerce](https://github.com/Open-WP-Club/multi-store-sync-for-woocommerce)

## 3.8.0

- Fix: several sync-accuracy issues where fields other than the configured match key (SKU/slug) could silently drift out of sync between stores
- Fix: name/slug drift handling during product matching
- Improved matching logic for more reliable product sync

## 3.7.5

- Security: SSRF guard on outbound requests to remote stores
- Security: consumer key is now hidden/masked instead of shown in plain text
- Reliability: advisory lock around the circuit breaker to prevent race conditions, plus additional warnings
- Performance: batched remote-prefetch cache added to the weekly verifier
- Coupon and shipping class sync are now deferred to reduce impact on the main sync flow

## 3.7.1

- Fix: CSV export sanitization
- Security: added regression tests for security-sensitive code paths
- Security: `ORDER BY` values are now allow-listed to prevent SQL injection via sort parameters
- Change: switched default authentication method
- Fix: settings cache is now cleared for the specific store being updated instead of globally

## 3.7.0

- New: email notifications UI with a daily rate limit (later tightened to 12 hours in 3.7.1)
- New: force-sync AJAX handler with test coverage

## 3.6.5

- New: circuit breaker for remote store requests to avoid hammering a store that's down
- New: verbose per-item output during queue processing
- Fix: `completed_at` timestamp is now recorded when a queue item is marked as failed

## 3.6.2

- Fix: weekly verification now uses async batched processing instead of a single synchronous request — prevents Action Scheduler timeouts on large catalogs
- Fix: slug collision detection for categories and tags — parallel sync workers no longer create duplicate terms; duplicates are auto-deleted and the real existing term is used instead
- New: Force Full Sync by Category — queue all published products in a category from the Logs page

For older versions, see the [Git history](https://github.com/Open-WP-Club/multi-store-sync-for-woocommerce/commits/master).
