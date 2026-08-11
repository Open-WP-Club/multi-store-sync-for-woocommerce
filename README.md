# Multi-Store Sync for WooCommerce

**Professional multi-store product synchronization for WooCommerce**

[![License](https://img.shields.io/badge/license-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![WordPress](https://img.shields.io/badge/wordpress-5.8%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/woocommerce-6.0%2B-purple.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/php-8.4%2B-blue.svg)](https://www.php.net/)
[![Tests](https://github.com/Open-WP-Club/multi-store-sync-for-woocommerce/actions/workflows/functional-tests.yml/badge.svg)](https://github.com/Open-WP-Club/multi-store-sync-for-woocommerce/actions)

> Formerly "WooCommerce API Product Sync with Multiple WooCommerce Stores". The project has moved to [Open-WP-Club/multi-store-sync-for-woocommerce](https://github.com/Open-WP-Club/multi-store-sync-for-woocommerce).

Automatically synchronize products, stock levels, and inventory from your main WooCommerce store to multiple remote WooCommerce stores. Built from the ground up as a standalone solution with enterprise-grade features.

---

## Features

### Core Functionality
- ✅ **One-Way Sync**: Main store → Multiple remote stores
- ✅ **Three Sync Types**: Full Product, Price & Stock, Stock Only
- ✅ **Variable Products**: Full support including variations and attributes
- ✅ **Image Sync**: Featured and gallery images (including API upload for Cloudflare/S3-protected sites)
- ✅ **Category & Tag Sync**: Automatic creation and linking with configurable match modes
- ✅ **SKU/Slug Matching**: Find and update existing products
- ✅ **Deletion Sync**: Products trashed or permanently deleted are removed from child stores
- ✅ **Variation Cleanup**: Orphaned variations on child stores can be automatically deleted

### Automation
- 🔄 **Scheduled Sync**: Automatic background syncing via Action Scheduler
- 🔄 **Auto-Sync on Save**: Real-time sync when products are updated
- 🔄 **Order Sync**: Sync inventory when orders are placed
- 🔄 **Webhook Receiver**: Accept real-time stock deductions from child stores
- 🔄 **Queue System**: Priority-based background batch processing

### Performance
- ⚡ **Caching**: Remote product, variation, and taxonomy caching
- ⚡ **Batch Processing**: Configurable batch sizes with peak/off-peak scheduling
- ⚡ **Performance Monitoring**: Track duration, memory usage, and API call counts
- ⚡ **API Usage Tracker**: Monitor API usage and cost estimates per store

### Management & Tools
- 🎯 **Store Exclusions**: Exclude categories/tags per store
- 🎯 **Bulk Actions**: Sync, delete, or price-update multiple products at once
- 🎯 **High Priority Queue**: Fast-track critical updates (stock changes always critical)
- 🎯 **Force Full Sync by SKU**: Immediately queue one or more products by SKU from the Logs page
- 🎯 **Force Full Sync by Category**: Immediately queue all products in a category from the Logs page
- 🎯 **Sync History**: Track all operations with detailed statistics
- 🎯 **Dead Letter Queue**: Review and retry permanently failed sync items
- 🎯 **Orphan Scanner**: Find and remove products that exist on child stores but not main
- 🎯 **Category Scanner**: Bulk-compare category assignments across stores for 1000+ products
- 🎯 **Stock Verifier**: Detect and auto-correct stock discrepancies between stores
- 🎯 **Weekly Verification**: Scheduled full-catalog audit with email reports, runs via batched async processing
- 🎯 **Deletion Audit**: Log and review every deletion synced to child stores
- 🎯 **Config Import/Export**: Backup and restore plugin configuration

### Advanced Sync Features
- 🔧 **Pricing Rules**: Apply per-store price adjustments (fixed, percentage, multiplier, currency)
- 🔧 **Stock Allocation**: Distribute stock across stores by percentage, fixed amount, or equal split
- 🔧 **Sync Profiles**: Save reusable sync configurations
- 🔧 **Sync Previewer**: Preview what will change before syncing
- 🔧 **Conflict Detector**: Identify data conflicts between main and child stores
- 🔧 **Category Mapper**: Map main store categories to different categories on child stores
- 🔧 **Attribute Remapper**: Map product attributes to different names per store
- 🔧 **Slug Collision Repair**: Automatically detects and deletes duplicate categories/tags created by parallel sync workers, then uses the correct existing term
- 🔧 **Coupon Sync**: Sync coupons to child stores
- 🔧 **Shipping Class Sync**: Sync shipping classes to child stores
- 🔧 **Downloadable Files Sync**: Sync downloadable product files
- 🔧 **Custom Field / ACF Mapper**: Sync custom post meta and Advanced Custom Fields (including repeater fields) with per-field local-to-remote mapping

### Remote Orders
- 🛒 **Remote Order Aggregation**: Pull orders placed on child stores back to the main store on a scheduled basis (via WooCommerce > Remote Orders)
- 🛒 **Remote Order Viewer**: Browse orders, line items, customer, billing, and shipping details from every child store in one place
- 🛒 **Manual & Scheduled Sync**: Trigger an on-demand pull or let it run automatically (default: daily)

### Monitoring & Alerts
- 📊 **Store Health Checks**: Scheduled connectivity and API version checks
- 📊 **Email Notifications**: Alerts for sync failures, API errors, and low stock
- 📊 **Dashboard Widget**: Quick overview from WordPress dashboard
- 📊 **Webhook Logs**: Full log of incoming webhook events with filtering

### Developer-Friendly
- 🛠️ **WP-CLI Commands**: `wp wc-mss sync`, `queue`, `stores`, `verify`, `history`, `config`, `dlq`
- 🛠️ **Hooks & Filters**: Extensive hooks for customization
- 🛠️ **PHPUnit Test Suite**: 2000+ tests with PHPUnit 13 + Brain Monkey
- 🛠️ **WordPress Standards**: Follows WP/WC coding standards
- 🛠️ **HPOS Compatible**: Declared compatible with WooCommerce High-Performance Order Storage

---

## Quick Start

### 1. Install
```bash
cd wp-content/plugins/
wp plugin activate wc-multi-store-sync
```

### 2. Generate API Keys on Remote Stores
1. Log into remote store WordPress admin
2. Navigate to **WooCommerce > Settings > Advanced > REST API**
3. Click **Add Key**
4. **User**: Select an **Administrator** user (required for image sync — see below)
5. **Permissions**: Set to **Read/Write**
6. Click **Generate API Key**
7. Copy **Consumer Key** and **Consumer Secret** immediately (the secret is shown only once)

> **Why Administrator?** If you enable **API Image Transfer** (for sites behind Cloudflare/firewall), images are uploaded to the remote store via the WordPress media API. This requires the `upload_files` capability, which only Administrator users have. Shop Manager keys will work for product sync but image uploads will fail with 403.

### 3. Add Remote Store
1. Navigate to **WooCommerce > Settings > Multi-Store Sync > Stores**
2. Click **Add New Store**
3. Enter store URL and API credentials
4. Click **Test Connection**
5. Click **Save Store**

### 4. Configure & Enable
1. Navigate to **WooCommerce > Settings > Multi-Store Sync > Settings**
2. Configure sync settings
3. Toggle **Enable Sync** to ON
4. Click **Save Settings**

### 5. Sync Products
**Option A: Manual**
- Edit any product
- Click sync button in "Multi-Store Sync" metabox

**Option B: Bulk**
- Select products in Products list
- Choose bulk action: "Sync Full Product"
- Click Apply

**Option C: Force by SKU or Category**
- Navigate to **WooCommerce > Settings > Multi-Store Sync > Logs**
- Use **Force Full Sync by SKU** or **Force Full Sync by Category**
- Products are queued immediately for background processing

**Option D: Automatic**
- Enable scheduled sync in Settings
- Products sync automatically every 10 minutes

---

## Documentation

### User Guides
- 📖 **[Installation Guide](documentation/INSTALLATION.md)** - Step-by-step setup instructions
- ⚙️ **[Configuration Guide](documentation/CONFIGURATION.md)** - Complete configuration reference
- 📘 **[User Manual](documentation/USER_MANUAL.md)** - Feature guide and workflows
- ❓ **[FAQ](documentation/FAQ.md)** - Frequently asked questions
- 🔧 **[Troubleshooting](documentation/TROUBLESHOOTING.md)** - Common issues and solutions

### Developer Documentation
- 👨‍💻 **[Developer Guide](documentation/DEVELOPER.md)** - API reference, hooks, and customization
- 📝 **[Changelog](CHANGELOG.md)** - Version history and changes

---

## Requirements

### Minimum Requirements
- **WordPress**: 5.8 or higher
- **WooCommerce**: 6.0 or higher
- **PHP**: 8.4 or higher
- **HTTPS**: Required for secure API communication

### Recommended
- **WordPress**: Latest stable version
- **WooCommerce**: Latest stable version
- **PHP**: 8.4 or higher
- **Action Scheduler**: Bundled with WooCommerce — no separate install needed
- **Memory**: 256MB+ (512MB+ for large catalogs)

---

## System Architecture

```
Main Store (Your Store)
├── Multi-Store Sync for WooCommerce Plugin ← Install here only
├── Products, Stock, Orders
└── Syncs to ↓

Remote Store 1                Remote Store 2                Remote Store N
├── WooCommerce (only)        ├── WooCommerce (only)        ├── WooCommerce (only)
└── Receives synced products  └── Receives synced products  └── Receives synced products
```

### Key Points
- ✅ Install plugin **only on main store**
- ✅ Remote stores **only need WooCommerce**
- ✅ Product/stock sync is **one-way**: Main → Remote
- ❌ Remote product/stock changes **do NOT sync back** to main
- ℹ️ **Orders** are the one exception: they can be **pulled** from remote stores into a read-only viewer on the main store (see [Remote Orders](#remote-orders)) — this does not create real WooCommerce orders on the main store

---

## Sync Types Explained

### Full Product Sync
**Syncs everything:**
- Product data (name, description, SKU, etc.)
- Prices (regular, sale)
- Stock (quantity, status)
- Images (featured, gallery)
- Categories and tags
- Attributes and variations

**Use for:**
- New products
- Major product updates
- Initial setup

### Price & Stock Sync
**Syncs only:**
- Regular price
- Sale price
- Stock quantity
- Stock status

**Use for:**
- Price changes
- Inventory updates
- Regular maintenance

### Stock Only Sync
**Syncs only:**
- Stock quantity
- Stock status

**Use for:**
- Quick inventory adjustments
- High-frequency updates
- Minimal impact syncing

---

## When Does Sync Happen?

### Automatic Triggers
When these events occur, products are added to the **sync queue**:

| Event | Setting Required | Priority |
|-------|------------------|----------|
| **New product created** | `auto_sync_new_products` | **High (2)** |
| Product saved | `auto_sync_on_save` | Normal (3) |
| **Stock changed** | `stock_sync_enabled` | **Critical (1)** |
| Price changed | `auto_sync_on_save` | High (2) |
| **Product moved to trash** | `auto_sync_deletions` | High (2) |
| **Product permanently deleted** | `auto_sync_deletions` | High (2) |
| Product restored from trash | `auto_sync_restorations` | High (2) |
| Product status changed | `auto_sync_status` | High (2) |
| Variation saved | `auto_sync_on_save` | Normal (3) |
| Variation deleted | `auto_sync_deletions` | High (2) |

**Note:** `auto_sync_new_products` works independently of `auto_sync_on_save` — you can enable auto-sync only for new products without triggering syncs on every save.

### Scheduled Actions (Action Scheduler)
Background tasks that run automatically:

| Action | Interval | Description |
|--------|----------|-------------|
| **Queue Processor** | Every **1 minute** | Processes up to 30 products from the queue and syncs to remote stores |
| **Scheduled Sync** | Every **10 minutes** | Adds modified products to the queue |
| **Weekly Verification** | Once per week | Batched async catalog audit — checks stock, prices, and missing products across all stores |

### Manual Triggers
- **Force Full Sync** button — Syncs all products
- **Force Full Sync by SKU** — Queue specific products by SKU from the Logs page
- **Force Full Sync by Category** — Queue all products in a category from the Logs page
- **Sync Product** button on product edit page
- **Bulk Actions** — Select multiple products and sync

### How It Works

```
Event (stock change, save, etc.)
    ↓
Product added to Queue
    ↓
Queue Processor runs (every 1 min)
    ↓
API call to remote store
    ↓
Sync complete → logged to History
```

### Queue Processing

Products are **not synced immediately** — they are added to a queue and processed in batches:

- **Priority system**: Critical changes (stock) are processed first
- **Batch size**: Default 30 products per batch (configurable in Settings)
- **Processing interval**: Every 1 minute
- **Max throughput**: Up to ~1800 products/hour

### Category & Tag Auto-Creation

When a product is synced, its categories and tags are automatically created on the remote store if they don't already exist. The plugin:

- Detects which terms are missing with a single batch API call
- Creates them in one batch request
- Detects **slug collisions** caused by parallel sync workers (e.g. `baloni-za-bebe-2` instead of `baloni-za-bebe`) — automatically fetches the real existing term, uses its ID, and deletes the duplicate
- Caches the remote term list for 24 hours to minimise API calls

### Category/Tag Exclusions

Products in excluded categories or with excluded tags are **automatically skipped** for that store:

- Exclusions are checked when adding to the queue
- Products won't be queued for stores where they're excluded

---

## Use Cases

### Multi-Location Retail
Run separate stores for different locations, all managed from one central inventory.

### Franchise Management
Centralized product management for multiple franchise locations.

### Specialized Stores
- Main store: All products
- Store A: Only clothing (exclude electronics)
- Store B: Only electronics (exclude clothing)

### Geographic Stores
- Main store: US-based
- Remote stores: EU, Asia, etc.

### White-Label Resellers
Maintain one catalog, push to multiple branded stores.

---

## Performance

### Benchmarks
Tested and optimized for large-scale operations:

| Catalog Size | Initial Sync | Daily Updates | Memory Usage |
|--------------|--------------|---------------|--------------|
| 100 products | 10-15 min    | < 1 min       | 50-100 MB    |
| 500 products | 30-60 min    | 2-5 min       | 100-200 MB   |
| 1000+ products | 1-3 hours  | 5-15 min      | 200-300 MB   |

*Actual performance depends on server resources, network speed, and product complexity.*

### Optimizations
- **Caching**: Reduces API calls by ~50%
- **Batch Processing**: Prevents timeouts and server overload
- **Database Indexes**: Fast query performance
- **Memory Management**: Explicit cleanup in loops

---

## Security

### Features
- ✅ **Nonce Verification**: All forms protected
- ✅ **Capability Checks**: Admin-only access
- ✅ **Input Sanitization**: All user input sanitized
- ✅ **Output Escaping**: All output escaped
- ✅ **SQL Injection Prevention**: Prepared statements
- ✅ **XSS Prevention**: Secure output handling

### Best Practices
- Use HTTPS on all stores
- Generate unique API keys per store
- Read/Write permissions only (not Admin)
- Rotate credentials periodically
- Keep WordPress/WooCommerce updated

---

## FAQ

### Is this plugin free?
**Yes!** Licensed under GPLv3. Free to use, modify, and distribute.

### Does it work with variable products?
**Yes!** Full support for variable products, variations, and attributes.

### How many stores can I sync to?
**Unlimited!** Add as many remote stores as needed.

### Can I sync back from remote to main?
**No.** This is a one-way sync solution (main → remote only).

### Will it slow down my site?
**No, if configured correctly.** Uses background processing and peak-hour management to minimize impact.

### What about images?
**Yes!** Images sync automatically with Full Product sync. If your main store is behind Cloudflare or a firewall that blocks image downloads, enable **API Image Transfer** in Settings.

### Can I exclude certain products?
**Yes!** Use category/tag exclusions per store.

### Does it require installation on remote stores?
**No!** Only install on your main store. Remote stores only need WooCommerce.

### Why are duplicate categories appearing with "-2" in the slug?
This was caused by a race condition when multiple sync workers ran simultaneously and each tried to create the same category. As of v3.6.2 this is automatically detected and repaired — the plugin fetches the real existing category, uses its ID, and deletes the duplicate.

**More questions?** See [FAQ](documentation/FAQ.md)

---

## API Image Transfer (Cloudflare / Firewall)

When product images are synced, WooCommerce on the remote store normally downloads them via HTTP from your main store's URLs. If your main store is behind **Cloudflare**, a WAF, or has hotlink protection, these downloads fail with **403 Forbidden** errors.

**API Image Transfer** solves this by reading image files directly from disk and uploading them through the WordPress REST API to the remote store's Media Library.

### How to enable

**On the main (parent) store:**
1. Go to **WooCommerce > Settings > Multi-Store Sync > Settings**
2. Check **API Image Transfer**
3. Save

**On each remote (child) store:**
1. Ensure the WooCommerce API key was generated from an **Administrator** user
2. Nothing else — no plugin installation, no settings changes

### How it works

```
Main store                          Remote store
   |                                    |
   |  1. Read image from disk           |
   |  2. POST binary to                 |
   |     /wp-json/wp/v2/media --------> |
   |                                    |  3. WordPress saves to Media Library
   |                        <---------- |  4. Returns attachment ID
   |                                    |
   |  5. Sync product with              |
   |     images: [{id: 456}] ---------> |
   |                                    |  6. WooCommerce uses existing
   |                                    |     attachment (no download needed)
```

### Requirements

- Remote store API key must be from an **Administrator** user (needs `upload_files` capability)
- Shop Manager keys will sync products but **image uploads will fail** (403 Forbidden)

---

## Support

### Documentation
- [Installation Guide](documentation/INSTALLATION.md)
- [Configuration Guide](documentation/CONFIGURATION.md)
- [User Manual](documentation/USER_MANUAL.md)
- [Troubleshooting Guide](documentation/TROUBLESHOOTING.md)
- [FAQ](documentation/FAQ.md)
- [Developer Documentation](documentation/DEVELOPER.md)

### Getting Help
- **Issues**: [GitHub Issues](https://github.com/Open-WP-Club/multi-store-sync-for-woocommerce/issues)
- **Email**: support@gkanev.com
- **Website**: [https://gkanev.com](https://gkanev.com)

### Reporting Bugs
When reporting bugs, include:
- WordPress version
- WooCommerce version
- PHP version
- Plugin version
- Error messages from logs
- Steps to reproduce

---

## Contributing

Contributions are welcome!

### Report Bugs
- Use GitHub Issues
- Include detailed steps to reproduce
- Provide system information

### Submit Pull Requests
1. Fork the repository
2. Create a feature branch
3. Follow WordPress coding standards
4. Test thoroughly
5. Submit PR with clear description

See [Developer Documentation](documentation/DEVELOPER.md) for contribution guidelines.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for detailed version history.

---

## License

This plugin is licensed under the **GNU General Public License v3.0 or later**.

```
Multi-Store Sync for WooCommerce
Copyright (C) 2024 Gkanev.com

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
```

