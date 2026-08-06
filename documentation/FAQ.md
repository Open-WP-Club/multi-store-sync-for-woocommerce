# Frequently Asked Questions (FAQ)

## Table of Contents
1. [General Questions](#general-questions)
2. [Installation and Setup](#installation-and-setup)
3. [Sync Functionality](#sync-functionality)
4. [Performance and Limits](#performance-and-limits)
5. [Compatibility](#compatibility)
6. [Troubleshooting](#troubleshooting)
7. [Pricing and Licensing](#pricing-and-licensing)

---

## General Questions

### What does this plugin do?

WooCommerce Multi-Store Sync automatically synchronizes products, stock levels, and inventory from your main WooCommerce store to multiple remote WooCommerce stores. It's a one-way sync solution (main store → remote stores only).

### What is "one-way sync"?

One-way sync means changes flow in only one direction:
- **Main Store → Remote Stores**: ✅ Yes
- **Remote Stores → Main Store**: ❌ No

Changes made on remote stores are **not** synced back to the main store.

### Why would I need this plugin?

**Common Use Cases:**
- Running multiple WooCommerce stores with the same or similar inventory
- Managing a main catalog that feeds multiple specialized stores
- Centralized inventory management across multiple domains
- Franchise or multi-location business with individual stores

### Is this plugin free?

Yes! WooCommerce Multi-Store Sync is open-source software licensed under GPLv3. It's completely free to use, modify, and distribute.

### What's the difference between this and other sync plugins?

- **Standalone**: No dependencies on third-party plugins
- **Purpose-built**: Specifically for WooCommerce-to-WooCommerce sync
- **Performance**: Optimized with caching, queue management, and batch processing
- **Feature-rich**: Scheduled sync, order sync, bulk actions, performance monitoring
- **Modern**: Built from scratch following current WordPress/WooCommerce standards

---

## Installation and Setup

### What are the minimum requirements?

- WordPress 5.8 or higher
- WooCommerce 6.0 or higher
- PHP 8.3 or higher
- HTTPS on all stores (required for secure API communication)

### Do I need to install anything on remote stores?

**On Remote Stores:**
- WooCommerce must be installed and active
- You must generate REST API keys (Consumer Key and Secret)

**You do NOT need to:**
- Install this plugin on remote stores
- Install any additional plugins on remote stores

### How long does initial setup take?

**Typical Setup Time:**
- Plugin installation: 2-3 minutes
- Configuring first store: 5-10 minutes
- Initial full sync (100 products): 10-20 minutes
- Initial full sync (1000+ products): 1-3 hours (automatic overnight)

### Can I test before using in production?

**Yes! Recommended approach:**
1. Set up staging/test environment
2. Add one test remote store
3. Sync a few test products
4. Verify results
5. Once confident, deploy to production

---

## Sync Functionality

### What data gets synced?

**Full Product Sync includes:**
- Product name, slug, description
- SKU, prices (regular, sale)
- Stock quantity and status
- Images (featured and gallery)
- Categories and tags
- Attributes (for variable products)
- Variations (for variable products)
- Weight, dimensions
- Tax settings

**Price & Quantity Sync includes:**
- Regular price, sale price
- Stock quantity, stock status

**Stock Only Sync includes:**
- Stock quantity
- Stock status

### Can I choose which products to sync?

**Yes, multiple ways:**

1. **Per-Store Exclusions:**
   - Exclude specific categories per store
   - Exclude specific tags per store

2. **Manual Selection:**
   - Manually sync individual products (edit page)
   - Use bulk actions to sync selected products

3. **Custom Code:**
   - Use filters to implement custom logic
   - See Developer Documentation

### How often does syncing happen?

**Depends on your configuration:**

- **Manual Sync**: Immediate (when you click sync button)
- **Auto-Sync on Save**: Immediate when product saved
- **Auto-Sync on Stock Change**: Immediate when stock changes
- **Scheduled Sync**: Every 15 minutes (configurable timeframe)
- **Order Sync**: When order status changes

### Can I sync to more than one store?

**Yes!** You can add unlimited stores. Each sync operation sends data to all active stores simultaneously.

### Does it work with variable products?

**Yes!** Full support for:
- Variable products (parent products)
- Product variations
- Product attributes
- Custom attributes

**Recommendation:** Always use "Full Sync" for variable products initially to ensure attributes are created.

### What about product images?

**Yes, images sync automatically** (in Full Sync mode):
- Featured image
- Gallery images

Images are downloaded from main store and uploaded to remote stores.

### Can I sync categories and tags?

**Yes!** Categories and tags sync automatically:
- If category/tag exists on remote (matching slug), it's linked
- If it doesn't exist, it's created
- Hierarchy is preserved

### What happens if a product already exists on the remote store?

The plugin checks for existing products by **SKU** (or slug):
- **If found:** Existing product is updated
- **If not found:** New product is created

### Can I undo a sync?

**No, syncing cannot be undone automatically.** Changes made to remote stores are permanent.

**To "undo":**
- Manually edit products on remote stores
- Delete products on remote stores if needed
- Backup remote stores before major sync operations

---

## Performance and Limits

### How many products can I sync?

**No hard limits!** The plugin is designed for large catalogs:
- Successfully tested with 1000+ products
- Batch processing prevents timeouts
- Queue management for efficient processing

**Practical limits depend on:**
- Server resources (memory, CPU)
- Network speed
- Remote store capacity

### How many stores can I sync to?

**No hard limits!** You can add unlimited stores.

**Considerations:**
- More stores = more API calls = longer sync time
- Batch processing ensures efficient syncing
- Monitor server resources with many stores

### Will syncing slow down my site?

**Not significantly if configured correctly:**

**Built-in Optimizations:**
- Queue-based background processing
- Peak/off-peak hour management (smaller batches during peak)
- Cron-based scheduling (doesn't block user requests)
- Automatic caching for performance

**Recommendations:**
- Configure peak hours to match your traffic
- Use smaller batch sizes during business hours
- Most intensive syncing happens during off-peak hours

### What about API rate limits?

**WooCommerce API has no built-in rate limits**, but:
- Server resources are the practical limit
- Plugin uses batch processing to prevent overload
- Caching reduces redundant API calls
- Built-in delays between batch processing

### How much server memory is needed?

**Recommended:**
- **Minimum**: 256MB PHP memory limit
- **Recommended**: 512MB for catalogs with 500+ products
- **Large Catalogs (1000+)**: 512MB-1GB

**Configure in `wp-config.php`:**
```php
define('WP_MEMORY_LIMIT', '512M');
```

---

## Compatibility

### Which WordPress versions are supported?

- **Minimum**: WordPress 5.8
- **Recommended**: Latest stable version
- **Tested up to**: WordPress 6.4

### Which WooCommerce versions are supported?

- **Minimum**: WooCommerce 6.0
- **Recommended**: Latest stable version
- **Tested up to**: WooCommerce 8.5

### Does it work with WordPress Multisite?

**Not officially tested.** The plugin is designed for:
- Single WordPress installations (main store)
- Syncing to other single WordPress installations (remote stores)

Multisite compatibility may work but is not guaranteed or supported.

### Does it work with WooCommerce Subscriptions?

**Subscription products sync like regular products:**
- Product data syncs
- Subscription settings sync
- Remote stores need WooCommerce Subscriptions plugin

**Note:** Subscription orders themselves don't sync (only inventory/products).

### Does it work with WooCommerce Bookings?

**Similar to subscriptions:**
- Booking products sync
- Remote stores need WooCommerce Bookings plugin
- Bookings themselves don't sync (only products)

### Are there any plugin conflicts?

**Generally compatible with most plugins.** Potential conflicts:
- Other sync plugins (disable others)
- Custom inventory management plugins
- Plugins that heavily modify WooCommerce REST API

**If experiencing issues:**
- Temporarily disable other plugins
- Test if issue resolves
- Report compatibility issues

### Does it work with page builders?

**Yes**, page builders don't affect product syncing:
- Elementor ✅
- WPBakery ✅
- Divi ✅
- Beaver Builder ✅

### What about multilingual plugins (WPML)?

**Not officially tested.** Product sync is based on WooCommerce data, not translations.

**Considerations:**
- Product data syncs in main store language
- Remote stores may need separate translation setup
- WPML compatibility not guaranteed

---

## Troubleshooting

### Why isn't my sync working?

**Most common causes:**
1. **Incorrect API credentials** - Regenerate and re-enter
2. **Permalink settings** - Remote store can't use "Plain" permalinks
3. **SSL issues** - Ensure valid SSL certificates on all stores
4. **WooCommerce inactive** - Verify WooCommerce active on remote stores
5. **API permissions** - API keys must have Read/Write permissions

See [Troubleshooting Guide](TROUBLESHOOTING.md) for detailed solutions.

### How do I check if cron is working?

**Dashboard Check:**
- Navigate to Dashboard
- Check "Cron Status" section
- "Next Run" times should be in the near future

**WP-CLI Check:**
```bash
wp cron event list | grep wc_multi_store
```

**Manual Trigger:**
```bash
wp cron event run --due-now
```

### Why are some products not syncing?

**Check:**
1. **Exclusions** - Product category/tag may be excluded for that store
2. **Product status** - Only published products sync (by default)
3. **Store status** - Store must be "Active"
4. **Logs** - Check for specific error messages

### Where can I find error logs?

**In Plugin:**
- Navigate to **Multi-Store Sync > Logs**
- Filter by "Failed" status
- View detailed error messages

**WordPress Debug Log:**
- Enable in `wp-config.php`: `define('WP_DEBUG_LOG', true);`
- Check `wp-content/debug.log`

### Can I get support?

**Yes! Support available through:**
1. **Documentation** - Comprehensive guides included
2. **GitHub Issues** - Report bugs or request features
3. **Email Support** - For licensed/premium support inquiries

---

## Pricing and Licensing

### Is this plugin really free?

**Yes!** Licensed under GPLv3:
- Free to use
- Free to modify
- Free to distribute
- Open source

### Are there any premium features?

**Currently, no.** All features are included in the free version.

**Future Premium Add-ons (Possible):**
- Priority support
- Advanced features
- White-label options

### Can I use this commercially?

**Yes!** The GPLv3 license allows commercial use:
- Use on client websites
- Include in services offered
- No attribution required (but appreciated!)

### Can I modify the code?

**Yes!** Under GPLv3, you can:
- Modify source code
- Distribute modified versions
- Use modified versions privately or publicly

**Requirements:**
- Modified versions must also be GPLv3
- Include original copyright notices

### Do you offer custom development?

**Yes!** Contact for:
- Custom features
- Integration services
- Premium support
- Consultation

---

## Additional Questions

### How is this different from WooCommerce Product CSV Import?

**CSV Import:**
- Manual export/import process
- Requires downloading and uploading files
- One-time operation
- No automation

**Multi-Store Sync:**
- Automated continuous sync
- API-based (no files)
- Scheduled and real-time options
- Set it and forget it

### Can I sync orders back to the main store?

**No.** This plugin is for:
- Product sync (main → remote)
- Inventory sync (main → remote)

**For centralized order management**, consider:
- Use main store for all orders
- Remote stores for display/catalogs only
- Custom development for bidirectional sync

### What about customer data?

**Customer data does NOT sync.** This plugin syncs:
- Products ✅
- Stock levels ✅
- Prices ✅

**Does NOT sync:**
- Customers ❌
- Orders from remote stores ❌
- User accounts ❌

### Can I schedule syncs for specific times?

**Currently:**
- Syncs run every 15 minutes (scheduled)
- Peak/off-peak configuration for batch sizing
- No specific time selection

**Workaround:**
- Disable scheduled sync
- Use system cron for specific times:
```bash
0 2 * * * wp cron event run wc_multi_store_sync_scheduled
```

### How do I uninstall the plugin?

**Complete Uninstallation:**
1. Navigate to **Plugins** in WordPress admin
2. Deactivate "WooCommerce Multi-Store Sync"
3. Click "Delete"
4. Confirm deletion

**What gets deleted:**
- Plugin files
- Database tables (sync history)
- Stored settings and configurations

**What remains:**
- Products on remote stores (unchanged)
- No impact on synced data

### Where can I request features?

**Feature Requests:**
- GitHub Issues (preferred)
- Include detailed use case and expected behavior

### How can I contribute?

**Contributions welcome!**
- Report bugs (GitHub Issues)
- Submit pull requests (GitHub)
- Improve documentation
- Share feedback and suggestions

See [Developer Documentation](DEVELOPER.md) for contribution guidelines.

---

## Still Have Questions?

- **Installation**: See [Installation Guide](INSTALLATION.md)
- **Setup**: See [Configuration Guide](CONFIGURATION.md)
- **Usage**: See [User Manual](USER_MANUAL.md)
- **Problems**: See [Troubleshooting Guide](TROUBLESHOOTING.md)
- **Development**: See [Developer Documentation](DEVELOPER.md)

**Contact:**
- GitHub: https://github.com/MrGKanev/WooCommerce-Multi-Store-Sync
