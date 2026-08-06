# User Manual - WooCommerce Multi-Store Sync

## Table of Contents
1. [Overview](#overview)
2. [Dashboard](#dashboard)
3. [Managing Stores](#managing-stores)
4. [Syncing Products](#syncing-products)
5. [Scheduled Syncing](#scheduled-syncing)
6. [Order Synchronization](#order-synchronization)
7. [Viewing Logs](#viewing-logs)
8. [Performance Monitoring](#performance-monitoring)
9. [Discrepancies & Scanning](#discrepancies--scanning)
10. [Dead Letter Queue](#dead-letter-queue)
11. [Deletion Audit](#deletion-audit)
12. [Webhook Receiver](#webhook-receiver)
13. [Advanced Features](#advanced-features)
14. [WP-CLI Reference](#wp-cli-reference)
15. [Common Workflows](#common-workflows)

---

## Overview

WooCommerce Multi-Store Sync is a professional plugin that synchronizes products, stock, and orders from your main WooCommerce store to multiple remote stores.

### Key Features
- ✅ One-way sync (main store → remote stores)
- ✅ Three sync types (Full, Price & Stock, Stock Only)
- ✅ Automatic scheduled syncing
- ✅ Order-based synchronization
- ✅ Bulk actions support
- ✅ Queue management
- ✅ Performance monitoring
- ✅ Comprehensive logging

### Use Case
- **Main Store**: Your primary WooCommerce store where you manage inventory
- **Remote Stores**: Secondary stores that receive synced products
- **Direction**: One-way only (main → remote, not remote → main)

---

## Dashboard

The Dashboard provides an overview of your sync system.

### Accessing Dashboard
Navigate to **Multi-Store Sync > Dashboard**

### Dashboard Sections

#### System Status
Displays health check information:
- **WooCommerce Status**: Active/Inactive
- **Cron System**: Working/Not Working
- **Logs Directory**: Writable/Not Writable
- **Queue System**: Ready/Error

**What to do if status shows errors:**
- See [Troubleshooting Guide](TROUBLESHOOTING.md)
- Check plugin installation
- Verify file permissions

#### Sync Statistics
Shows sync activity metrics:
- **Total Syncs**: Number of completed syncs
- **Successful**: Number of successful syncs
- **Failed**: Number of failed syncs
- **Success Rate**: Percentage of successful syncs

**Understanding Statistics:**
- High success rate (>95%) is normal
- Low success rate indicates configuration or connectivity issues
- Click on **"View Logs"** to see detailed error messages

#### Queue Status
Shows current queue information:
- **Queue Size**: Number of products waiting to sync
- **Processing**: Currently syncing products
- **Failed Items**: Items that failed to sync

**Queue Actions:**
- **Process Now**: Manually trigger queue processing (bypasses cron)
- **Clear Queue**: Remove all items from queue (use with caution)

#### Cron Status
Shows scheduled task information:
- **Queue Processing**: Next run time (every 5 minutes)
- **Scheduled Sync**: Next run time (every 15 minutes)

**What to check:**
- Next run times should be in the near future
- If showing old times, cron may not be running (see Troubleshooting)

#### Recent Activity
Shows last 10 sync operations:
- Product name/SKU
- Store URL
- Sync type
- Status (success/failed)
- Timestamp

#### Quick Actions
- **Force Full Sync**: Queue all products for immediate syncing
- **Clear Cache**: Clear all cached data
- **View Logs**: Jump to logs page

---

## Managing Stores

### Store List
Navigate to **Multi-Store Sync > Stores** to see all configured stores.

#### Store Information Displayed
- **Store URL**: Full URL of remote store
- **Status**: Active (green) or Inactive (gray)
- **Last Sync**: Time of last successful sync
- **Actions**: Edit, Delete buttons

### Adding a Store

**Step-by-Step:**

1. Click **"Add New Store"** button
2. Fill in the form:
   - **Store URL**: `https://your-remote-store.com`
   - **Consumer Key**: From remote store WooCommerce API settings
   - **Consumer Secret**: From remote store WooCommerce API settings
   - **Status**: Active
3. Click **"Test Connection"**
4. If successful, click **"Save Store"**

**Tips:**
- Always test connection before saving
- Keep credentials secure (don't share screenshots)
- Use unique API keys for each store

### Testing a Connection

Click **"Test Connection"** to verify:
- URL is reachable
- API credentials are correct
- WooCommerce API is working
- Permissions are sufficient

**Possible Results:**
- ✅ **Success**: "Connection successful! WooCommerce API is working."
- ❌ **Failed**: Error message explaining the issue

### Editing a Store

1. Click **"Edit"** next to the store
2. Update any fields
3. Test connection again
4. Click **"Save Changes"**

### Activating/Deactivating a Store

**To Deactivate:**
- Edit the store
- Change **Status** to **Inactive**
- Save changes

**Effect:**
- Inactive stores are skipped during all syncs
- Useful for temporarily disabling sync without deleting configuration

**To Reactivate:**
- Edit the store
- Change **Status** to **Active**
- Save changes

### Deleting a Store

⚠️ **Warning**: This cannot be undone!

1. Click **"Delete"** next to the store
2. Confirm deletion
3. Store is permanently removed

**Effect:**
- All stored configuration is deleted
- No impact on products already synced to remote store
- You'll need to re-enter credentials to add the store again

### Store Exclusions

Prevent specific products from syncing to a store based on categories or tags.

#### Excluding Categories

**Use Case:** Store B only sells clothing (exclude electronics)

1. Edit the store
2. Find **"Exclude Categories"** section
3. Select categories to exclude (hold Ctrl/Cmd for multiple)
4. Save changes

**Effect:**
- Products in excluded categories won't sync to this store
- Useful for specializing stores

#### Excluding Tags

**Use Case:** Tag products as "Store-A-Only"

1. Edit the store
2. Find **"Exclude Tags"** section
3. Select tags to exclude
4. Save changes

**Effect:**
- Products with excluded tags won't sync to this store

---

## Syncing Products

### Manual Sync Methods

#### Method 1: Product Edit Page

**Best for: Single product updates**

1. Navigate to **Products** in WordPress
2. Edit a product
3. Find **"Multi-Store Sync"** metabox (right sidebar)
4. Click one of:
   - **🔄 Full Sync**: All product data
   - **💰 Price & Stock**: Pricing and inventory only
   - **📦 Stock Only**: Stock quantities only
5. Sync happens immediately via AJAX
6. Success message appears

**When to use each:**
- **Full Sync**: New products, major changes (description, images, categories)
- **Price & Stock**: Price changes, stock updates
- **Stock Only**: Quick inventory adjustments

#### Method 2: Bulk Actions

**Best for: Multiple products at once**

1. Navigate to **Products** in WordPress
2. Select products (checkboxes)
3. Choose **Bulk Actions** dropdown:
   - Sync Full Product
   - Sync Price & Stock
   - Sync Stock Only
   - Add to High Priority Queue
4. Click **Apply**

**Effect:**
- Selected products added to queue
- Synced in next cron run (every 5 minutes)
- Or use Dashboard "Process Now" for immediate sync

#### Method 3: Force Full Sync

**Best for: Initial setup or syncing all products**

1. Navigate to **Dashboard**
2. Click **"Force Full Sync"** button
3. Confirm action
4. All products queued for syncing

**Effect:**
- Every product in your catalog queued
- Can take significant time for large catalogs
- Monitor progress in Queue Status

### Understanding Sync Types

#### Full Product Sync
**Syncs:**
- Name, slug, description, short description
- SKU, regular price, sale price
- Stock quantity, stock status
- Weight, dimensions
- Tax status, tax class
- Categories, tags
- Images (gallery)
- Attributes (for variable products)
- Variations (for variable products)

**Use When:**
- Adding new products
- Major product updates
- Changes to categories, tags, or images
- Initial setup

**Performance:**
- Slowest (most data)
- Most API calls
- Use sparingly for best performance

#### Price & Quantity Sync
**Syncs:**
- Regular price
- Sale price
- Stock quantity
- Stock status

**Use When:**
- Price changes
- Stock level updates
- Regular inventory management

**Performance:**
- Faster than full sync
- Fewer API calls
- Good for routine updates

#### Quantity Only Sync
**Syncs:**
- Stock quantity
- Stock status

**Use When:**
- Stock adjustments only
- High-frequency inventory updates
- No price changes

**Performance:**
- Fastest
- Minimal API calls
- Best for frequent updates

### Sync Status

After syncing, check status:

1. **Success Message**: Product synced successfully
2. **Error Message**: Sync failed (see error details)
3. **Logs**: Detailed information in **Multi-Store Sync > Logs**

---

## Scheduled Syncing

Automatic, cron-based syncing at regular intervals.

### Enabling Scheduled Sync

1. Navigate to **Settings**
2. Scroll to **"Scheduled Sync"** section
3. Toggle **"Enable Scheduled Sync"** to ON
4. Configure settings (see below)
5. Click **"Save Settings"**

### How Scheduled Sync Works

**Every 15 Minutes:**
- Plugin checks for modified products
- Products modified within configured timeframe are queued
- Queue is marked for processing

**Every 5 Minutes:**
- Queue is processed
- Products synced in batches
- Batch size depends on peak/off-peak hours

### Peak Hours

Configure when your store receives most traffic.

**Settings:**
- **Peak Start Hour**: When peak traffic begins (e.g., 6 AM)
- **Peak End Hour**: When peak traffic ends (e.g., 11 PM)

**Effect:**
- **During Peak**: Smaller batch sizes (less server load)
- **During Off-Peak**: Larger batch sizes (faster syncing)

**Example:**
- Peak: 6 AM - 11 PM (business hours)
- Off-Peak: 11 PM - 6 AM (night)

### Batch Sizes

**Batch Size (Peak):**
- Default: 5 products per batch
- Recommendation: 3-10 for high-traffic stores

**Batch Size (Off-Peak):**
- Default: 20 products per batch
- Recommendation: 20-50 for faster overnight syncing

**Effect:**
- Larger batches = faster syncing but more server load
- Smaller batches = slower syncing but less server load

### Sync Strategies

#### Strategy 1: Modified Products Only (Recommended)

**Settings:**
- Sync All Products: OFF
- Sync Modified Hours: 24

**Effect:**
- Only syncs products changed in last 24 hours
- Efficient for daily operations
- Minimal server load

#### Strategy 2: Real-Time Sync

**Settings:**
- Auto-Sync on Save: ON
- Auto-Sync on Stock Change: ON
- Scheduled Sync: OFF (optional)

**Effect:**
- Products sync immediately when changed
- No delay
- Higher server load on product updates

#### Strategy 3: Full Catalog Sync

**Settings:**
- Sync All Products: ON
- Force Full Sync: ON

**Effect:**
- Every scheduled sync includes ALL products
- Use for initial setup or after major changes
- Disable after complete sync to avoid unnecessary load

#### Strategy 4: Hybrid (Best for Most Users)

**Settings:**
- Auto-Sync on Save: OFF
- Auto-Sync on Stock Change: ON
- Scheduled Sync: ON
- Sync Modified Hours: 6

**Effect:**
- Stock changes sync immediately
- Other changes sync within 6 hours
- Balanced performance and speed

### Monitoring Scheduled Sync

**Dashboard > Cron Status:**
- Shows next scheduled run
- Shows last run time
- Verify cron is running

**Dashboard > Queue Status:**
- Shows queued products
- Shows processing status

**Logs:**
- Review sync results
- Check for errors

---

## Order Synchronization

Automatically sync products when orders are placed.

### Enabling Order Sync

1. Navigate to **Settings**
2. Scroll to **"Order Sync"** section
3. Toggle **"Enable Order Sync"** to ON
4. Configure settings
5. Click **"Save Settings"**

### How Order Sync Works

**Trigger:** Order status changes to selected status (e.g., Processing)

**Action:**
1. Plugin detects status change
2. Extracts products from order
3. Queues products for syncing (Quantity Only type)
4. Products synced in next cron run

**Purpose:**
- Keep inventory synchronized across stores
- Prevent overselling on remote stores
- Update stock levels in real-time

### Order Status Triggers

**Common Statuses:**
- **Processing**: Order payment confirmed
- **Completed**: Order fulfilled/shipped
- **Cancelled**: Order cancelled (restore stock)

**Recommendation:**
- Enable **Processing** and **Completed**
- Enable **Cancelled** if you restore stock on cancellation

### Large Order Debouncing

**Purpose:** Prevent multiple rapid syncs for orders with many items

**How it works:**
- Order with 10+ items triggers debounce
- Waits X seconds (configurable)
- If order modified again, timer resets
- After timeout, syncs once

**Settings:**
- **Debounce Timeout**: 30 seconds (default)
- Increase for very large orders or slow servers

---

## Viewing Logs

Navigate to **Multi-Store Sync > Logs** to view sync activity.

### Log Types

**Sync Log (Main Tab):**
- All sync operations
- Success and failure messages
- Timestamps
- Duration and performance metrics

**SKU-Specific Logs:**
- Filter logs by product SKU
- Useful for troubleshooting specific products

**Error Logs:**
- Only failed syncs
- Error messages and codes

### Log Information

Each log entry shows:
- **Timestamp**: When sync occurred
- **Product**: Name and SKU
- **Store**: Remote store URL
- **Sync Type**: Full/Price & Stock/Stock Only
- **Status**: Success/Failed
- **Duration**: Time taken
- **Message**: Details or error message

### Filtering Logs

**By Date:**
- Select date range
- Click **"Filter"**

**By SKU:**
- Enter product SKU
- Click **"Search"**

**By Store:**
- Select store from dropdown
- Click **"Filter"**

**By Status:**
- Select Success or Failed
- Click **"Filter"**

### Understanding Error Messages

**Common Errors:**

**"Product not found"**
- Product doesn't exist on remote store
- Will be created on next full sync

**"Authentication failed"**
- API credentials incorrect
- Check store configuration

**"Network timeout"**
- Remote store not responding
- Check remote store status
- Increase timeout setting

**"Rate limit exceeded"**
- Too many API requests
- Reduce batch size
- Increase time between syncs

### Downloading Logs

1. Click **"Download Logs"** button
2. Select date range
3. Logs downloaded as text file

### Clearing Logs

⚠️ **Warning**: This cannot be undone!

1. Click **"Clear Logs"** button
2. Confirm action
3. All logs deleted

**Note:** Logs older than 30 days are automatically deleted.

---

## Performance Monitoring

Monitor sync performance and optimize settings.

### Accessing Performance Metrics

**Dashboard > Sync Statistics:**
- Total syncs
- Success rate
- Average duration

**Logs > Performance Metrics:**
- Per-sync duration
- Memory usage
- API call counts

### Key Metrics

#### Success Rate
- **Good**: >95%
- **Fair**: 85-95%
- **Poor**: <85%

**If poor:**
- Check logs for errors
- Verify network connectivity
- Check remote store status

#### Average Duration
- **Full Sync**: 5-15 seconds (acceptable)
- **Price & Stock**: 2-5 seconds (acceptable)
- **Stock Only**: 1-3 seconds (acceptable)

**If slow:**
- Check server resources (CPU, memory)
- Check network speed
- Optimize batch sizes
- Enable caching (automatic)

#### Memory Usage
- **Normal**: <50MB per sync
- **High**: 50-100MB per sync
- **Very High**: >100MB per sync

**If high:**
- Reduce batch size
- Increase PHP memory limit
- Check for large images

### Optimization Tips

**1. Choose Appropriate Sync Types**
- Use Stock Only for frequent updates
- Reserve Full Sync for new products

**2. Configure Batch Sizes**
- Balance speed and server load
- Increase during off-peak
- Decrease during peak

**3. Use Exclusions**
- Exclude unnecessary categories/tags
- Reduces API calls
- Improves performance

**4. Monitor Queue Size**
- Large queue indicates slow processing
- Increase batch sizes
- Check for errors causing retries

---

## Common Workflows

### Workflow 1: Adding a New Product

**Steps:**
1. Create product in WooCommerce
2. Edit product
3. Click **"🔄 Full Sync"** in Multi-Store Sync metabox
4. Check logs for success

**Result:** Product created on all active remote stores

---

### Workflow 2: Updating Stock Levels

**Option A: Single Product**
1. Edit product
2. Update stock quantity
3. Click **"📦 Stock Only"** in Multi-Store Sync metabox

**Option B: Multiple Products**
1. Navigate to **Products**
2. Select products
3. Bulk Actions > **Sync Stock Only**
4. Click **Apply**

**Result:** Stock levels updated on remote stores

---

### Workflow 3: Changing Prices

**Steps:**
1. Edit product
2. Update regular price and/or sale price
3. Click **"💰 Price & Stock"** in Multi-Store Sync metabox
4. Verify in logs

**Result:** Prices updated on remote stores

---

### Workflow 4: Initial Store Setup

**Steps:**
1. Install and activate plugin
2. Add remote stores
3. Test connections
4. Configure settings
5. Click **"Force Full Sync"** on Dashboard
6. Monitor queue processing
7. Verify products on remote stores

**Result:** All products synced to new store

---

### Workflow 5: Daily Operations

**Automated Setup:**
1. Enable **Auto-Sync on Stock Change**
2. Enable **Scheduled Sync** (24-hour modified products)
3. Enable **Order Sync** (Processing, Completed)

**Manual Checks:**
- Review Dashboard daily
- Check logs for errors
- Monitor queue size

**Result:** Automatic, hands-off synchronization

---

### Workflow 6: Troubleshooting Failed Syncs

**Steps:**
1. Navigate to **Logs**
2. Filter by **Failed** status
3. Review error messages
4. Identify issue (network, auth, data)
5. Fix issue (see [Troubleshooting Guide](TROUBLESHOOTING.md))
6. Manually re-sync affected products

**Result:** Failed syncs resolved and re-synced

---

### Workflow 7: Managing Large Catalogs

**For 1000+ products:**

**Initial Sync:**
1. Enable **Scheduled Sync**
2. Set **Sync All Products**: ON
3. Set **Force Full Sync**: ON
4. Set **Batch Size (Off-Peak)**: 50
5. Let run overnight

**Ongoing Sync:**
1. Disable **Sync All Products**
2. Disable **Force Full Sync**
3. Set **Sync Modified Hours**: 24
4. Enable **Auto-Sync on Stock Change**

**Result:** Efficient management of large catalog

---

## Tips and Best Practices

### General Tips

1. **Start Small**
   - Add one store first
   - Test thoroughly
   - Add more stores gradually

2. **Monitor Regularly**
   - Check Dashboard daily
   - Review logs weekly
   - Adjust settings as needed

3. **Use Appropriate Sync Types**
   - Don't use Full Sync unnecessarily
   - Use Stock Only for inventory updates
   - Reserve Full Sync for major changes

4. **Leverage Automation**
   - Use scheduled sync for routine updates
   - Use order sync for inventory management
   - Reduce manual syncing

5. **Backup Before Major Changes**
   - Backup before Force Full Sync
   - Backup before bulk operations
   - Test backup restoration

### Performance Tips

1. **Optimize Batch Sizes**
   - Larger batches during off-peak
   - Smaller batches during peak
   - Monitor server resources

2. **Use Exclusions**
   - Exclude store-specific products
   - Reduces unnecessary syncs
   - Improves performance

3. **Cache is Automatic**
   - Remote products cached (5 minutes)
   - Categories/tags cached (1 hour)
   - No configuration needed

4. **Monitor Memory Usage**
   - Check logs for memory warnings
   - Increase PHP memory limit if needed
   - Reduce batch size if experiencing issues

### Security Tips

1. **Protect API Credentials**
   - Use unique keys per store
   - Rotate credentials regularly
   - Never share in screenshots/logs

2. **Use HTTPS**
   - Always use HTTPS on main and remote stores
   - Verify SSL certificates

3. **Limit Access**
   - Only administrators can access plugin
   - Use Read/Write permissions for API keys (not Read)

---

## Getting Help

If you need assistance:

1. **Check Documentation**
   - [Installation Guide](INSTALLATION.md)
   - [Configuration Guide](CONFIGURATION.md)
   - [Troubleshooting Guide](TROUBLESHOOTING.md)
   - [FAQ](FAQ.md)

2. **Check Logs**
   - Detailed error messages
   - Sync history
   - Performance metrics

3. **System Status**
   - Dashboard > System Status
   - Verify all checks pass

4. **Contact Support**
   - Provide WordPress version
   - Provide WooCommerce version
   - Provide plugin version
   - Include error messages
   - Describe steps to reproduce

---

## Discrepancies & Scanning

Navigate to **Multi-Store Sync > Settings > Discrepancies** (or the Discrepancies tab).

### Orphan Scanner

Finds products that exist on a child store but no longer exist on the main store.

1. Select a store from the dropdown
2. Click **Scan for Orphans**
3. Review the list of orphaned products (SKU, remote ID, title)
4. Click **Delete Selected** or **Delete All** to remove them from the child store

**Use case:** After bulk-deleting products from the main store, run the orphan scanner to clean up child stores.

### Category Scanner

Compares category assignments between the main store and a child store for all products simultaneously. Designed for catalogs with 1000+ products — uses a single bulk API call (paginated) and SQL queries instead of per-product requests.

1. Select a store from the dropdown
2. Select match mode: **Slug** (default) or **Name**
3. Click **Scan Categories**
4. A progress bar shows fetching status
5. Results show each product with:
   - **Missing** categories: present on main but absent on child
   - **Extra** categories: present on child but absent on main
6. Click **Re-sync** next to any product to push a full sync

**Tips:**
- Run after bulk category reorganization on the main store
- "Extra" categories often indicate old categories that were removed from main
- Only products with actual mismatches are shown (matched products are hidden)

---

## Dead Letter Queue

Products that fail repeatedly are moved to the **Dead Letter Queue** rather than blocking the main queue forever.

Navigate to **Multi-Store Sync > Settings > Queue** (DLQ section).

### Actions

- **Retry**: Re-queues the item for another attempt
- **Retry All**: Retries every item in the DLQ at once
- **Resolve**: Marks the item as manually resolved (removes from DLQ)

### When items end up in the DLQ

- Exceeded max retry attempts (default: 3)
- Permanent errors (product not found on remote, auth failure)

---

## Deletion Audit

Every deletion synced to child stores is logged in the **Deletion Audit**.

Navigate to **Multi-Store Sync > Settings > Deletions**.

Each entry shows:
- Product name, SKU, and categories at time of deletion
- Which child store the deletion was sent to
- Timestamp and status (completed / failed)
- Variation data for variable products

**Use case:** Compliance, accidental deletion recovery (you can see what was deleted and re-create it).

---

## Webhook Receiver

The plugin can receive webhook events from child stores (e.g., stock deductions from orders placed on child stores) and reflect those changes back to the main store's stock.

### Setup

1. Navigate to **Multi-Store Sync > Settings > Webhooks**
2. Copy the **Webhook URL** and **Webhook Secret**
3. On each child store, go to **WooCommerce > Settings > Advanced > Webhooks**
4. Create a webhook:
   - **Topic**: Order Created (or Order Updated)
   - **Delivery URL**: Paste the URL from step 2
   - **Secret**: Paste the secret from step 2

### Security

All incoming webhooks are verified using HMAC-SHA256 signature. Requests without a valid signature are rejected with HTTP 401. Rate limiting applies: excessive requests from the same IP are blocked.

### Webhook Logs

Navigate to **Multi-Store Sync > Settings > Webhook Logs** to see all incoming events:
- Filter by type, status, store, or date range
- View full payload for debugging
- Export to CSV

---

## Advanced Features

### Pricing Rules

Apply automatic price adjustments when syncing to specific stores.

Navigate to **Multi-Store Sync > Settings > Stores > Edit Store > Pricing Rules**.

**Rule types:**
| Type | Effect |
|------|--------|
| Fixed | Add or subtract a fixed amount from every price |
| Percentage | Increase or decrease prices by a percentage |
| Multiplier | Multiply prices by a factor (e.g., 1.2 for +20%) |
| Currency | Multiply by an exchange rate |

Rules apply to both regular price and sale price. Negative prices are prevented (floor at 0).

### Stock Allocation

Distribute available stock across stores automatically.

Navigate to **Multi-Store Sync > Settings > Stores > Edit Store > Stock Rules**.

**Allocation types:** Percentage, Fixed amount, Reserve (hold back N units), Equal split across stores, Priority-based.

### Sync Profiles

Save frequently used sync configurations as named profiles and apply them via bulk actions.

Navigate to **Multi-Store Sync > Settings > Profiles**.

### Conflict Detector

Detects data conflicts between main and child stores (e.g., price differs, stock mismatch beyond threshold).

Navigate to **Multi-Store Sync > Settings > Conflicts**.

- Review each conflict with before/after values
- **Resolve**: Accept main store value as correct
- **Resolve All**: Accept all main store values

### Category Mapper

Map main store categories to different categories on specific child stores.

Navigate to **Multi-Store Sync > Settings > Category Mapper**.

**Example:** Main store has "Tops" → Child store "EU" maps it to "Oberteil".

### Attribute Remapper

Map product attribute names to different names per store.

Navigate to **Multi-Store Sync > Settings > Attribute Mapper**.

### Coupon Sync

Sync coupons to child stores. Navigate to **Multi-Store Sync > Settings > Coupons**.

### Shipping Class Sync

Sync shipping classes to child stores. Navigate to **Multi-Store Sync > Settings > Shipping Classes**.

### Config Import / Export

Back up and restore the entire plugin configuration.

Navigate to **Multi-Store Sync > Settings > Config**.

- **Export**: Downloads a JSON file with all settings (API keys can be included or redacted)
- **Import**: Uploads a previously exported JSON file

---

## WP-CLI Reference

All commands are under the `wp wc-mss` namespace.

```bash
# Trigger a sync for specific products or all products
wp wc-mss sync [--product-ids=1,2,3] [--type=full_product] [--store=https://store.com]

# Queue management
wp wc-mss queue [--action=status|clear|process] [--status=pending|failed]

# Store management
wp wc-mss stores [--action=list|test] [--url=https://store.com]

# Run weekly verification manually
wp wc-mss verify [--store=https://store.com]

# View sync history
wp wc-mss history [--limit=50] [--status=success|failed]

# Config import/export
wp wc-mss config [--action=export|import] [--file=config.json]

# Dead Letter Queue management
wp wc-mss dlq [--action=list|retry|retry-all|resolve]
```

---

**Happy Syncing!** 🎉
