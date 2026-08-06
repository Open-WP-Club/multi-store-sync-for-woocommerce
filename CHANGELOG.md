# Changelog

All notable changes to this project are documented in this file.

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
