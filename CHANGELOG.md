# Changelog

All notable changes to this project are documented in this file.

## 5.0.0

- New: Orphan Cleanup's "Delete Selected" now shows live progress (X of Y products processed) in a persistent status banner that survives page reloads, instead of leaving a "Deleting…" button with no feedback for the minutes a large batch can take in the background
- Change: the Category Mapping, Attribute Mapping, and Conflicts settings tabs are now hidden until their matching feature toggle is turned on in Settings, so the tab bar doesn't show screens that don't apply to a store that never enabled them
- Fix: lowered the declared minimum PHP version from 8.4 to 8.3 — the codebase never actually used any PHP 8.4-only syntax (the two newest features in use, typed class constants and the `#[\Override]` attribute, both shipped in PHP 8.3), and the inflated requirement was blocking activation in PHP 8.3 environments, including the WordPress Plugin Check CI job
- Updated compatibility headers: `WC tested up to` 9.5 → 11.1, added a `Tested up to` header for WordPress core (7.1)
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
