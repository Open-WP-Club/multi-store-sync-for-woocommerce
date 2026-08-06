# Configuration Guide - WooCommerce Multi-Store Sync

## Table of Contents
1. [Initial Setup](#initial-setup)
2. [Store Configuration](#store-configuration)
3. [Sync Settings](#sync-settings)
4. [Scheduled Sync Configuration](#scheduled-sync-configuration)
5. [Order Sync Configuration](#order-sync-configuration)
6. [Product-Level Configuration](#product-level-configuration)
7. [Advanced Settings](#advanced-settings)
8. [Configuration Best Practices](#configuration-best-practices)

---

## Initial Setup

### First-Time Configuration Wizard

After installing the plugin, follow these steps for initial setup:

#### Step 1: Access the Plugin
1. Log in to WordPress admin
2. Navigate to **Multi-Store Sync** in the sidebar
3. You'll see the Dashboard overview

#### Step 2: Review System Status
1. Check the **System Status** section on the Dashboard
2. Ensure all requirements are met:
   - ✅ WooCommerce Active
   - ✅ Cron System Working
   - ✅ Logs Directory Writable
   - ✅ Queue System Ready

#### Step 3: Keep Sync Disabled Initially
- Navigate to **Settings**
- Keep **"Enable Sync"** toggled OFF until you've configured at least one store
- This prevents accidental syncing before proper configuration

---

## Store Configuration

### Adding a Remote Store

#### Prerequisites
For each remote store, you need:
- Full store URL (e.g., `https://store2.example.com`)
- WooCommerce REST API Consumer Key
- WooCommerce REST API Consumer Secret

See [Installation Guide](INSTALLATION.md#api-credentials-generation) for generating API credentials.

#### Add Store Steps

1. **Navigate to Stores Page**
   - Go to **Multi-Store Sync > Stores**
   - Click **"Add New Store"** button

2. **Enter Store Details**

   **Store URL**
   - Enter the full URL including `https://`
   - Example: `https://store2.example.com`
   - Do NOT include trailing slash
   - Do NOT include `/wp-admin` or other paths

   **Consumer Key**
   - Paste the Consumer Key from remote store
   - Format: `ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`
   - Ensure no extra spaces

   **Consumer Secret**
   - Paste the Consumer Secret from remote store
   - Format: `cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`
   - Ensure no extra spaces

   **Status**
   - **Active**: Store will receive syncs
   - **Inactive**: Store will be skipped during syncs

3. **Test Connection**
   - Click the **"Test Connection"** button
   - Wait for response
   - Expected results:
     - ✅ **Success**: "Connection successful! WooCommerce API is working."
     - ❌ **Failure**: Error message with details (see [Troubleshooting](TROUBLESHOOTING.md))

4. **Save Store**
   - If connection test succeeds, click **"Save Store"**
   - Store will appear in the stores list
   - You can add multiple stores by repeating these steps

### Editing a Store

1. Navigate to **Multi-Store Sync > Stores**
2. Find the store you want to edit
3. Click **"Edit"** button
4. Update the details
5. Test connection again
6. Click **"Save Changes"**

### Deactivating a Store

To temporarily stop syncing to a store without deleting it:

1. Navigate to **Multi-Store Sync > Stores**
2. Find the store
3. Click **"Edit"**
4. Change **Status** to **Inactive**
5. Click **"Save Changes"**

The store will remain configured but won't receive any syncs.

### Deleting a Store

**⚠️ Warning**: This cannot be undone. You'll need to re-enter credentials if you want to add the store again.

1. Navigate to **Multi-Store Sync > Stores**
2. Find the store to delete
3. Click **"Delete"** button
4. Confirm deletion
5. Store is permanently removed from configuration

### Store Exclusion Rules

Configure which products to exclude from syncing to specific stores.

#### Exclude Categories

1. Edit a store (**Stores** > **Edit**)
2. Scroll to **"Exclude Categories"** section
3. Select categories to exclude:
   - Products in these categories won't sync to this store
   - Multi-select: Hold Ctrl (Windows) or Cmd (Mac) to select multiple
4. Save changes

**Example Use Case:**
- Store A sells all products
- Store B only sells clothing (exclude electronics categories)
- Store C only sells electronics (exclude clothing categories)

#### Exclude Tags

1. Edit a store (**Stores** > **Edit**)
2. Scroll to **"Exclude Tags"** section
3. Select tags to exclude:
   - Products with these tags won't sync to this store
4. Save changes

**Example Use Case:**
- Tag products as "Store-A-Only"
- Configure Store B and Store C to exclude this tag
- Only Store A receives these products

---

## Sync Settings

### General Sync Settings

Navigate to **Multi-Store Sync > Settings** to configure sync behavior.

#### Enable Sync
- **Toggle**: ON/OFF
- **Description**: Master switch for all sync functionality
- **Default**: OFF (enable after configuring stores)
- **When OFF**: No syncing occurs (manual or automatic)
- **When ON**: Syncing is active based on other settings

#### Default Sync Type
- **Options**:
  - **Full Product**: Sync all product data (name, description, price, stock, images, categories, tags, attributes)
  - **Price & Quantity**: Sync only pricing and stock data
  - **Quantity Only**: Sync only stock quantities
- **Default**: Full Product
- **Recommendation**:
  - Use **Full Product** for new products or major updates
  - Use **Price & Quantity** for regular inventory updates
  - Use **Quantity Only** for frequent stock adjustments

#### Authentication Method
- **Options**:
  - **Query String**: Credentials passed as URL parameters
  - **Basic Auth**: Credentials in HTTP Authorization header
- **Default**: Query String
- **Recommendation**:
  - Use **Query String** if remote stores use HTTPS (recommended)
  - Use **Basic Auth** if having issues with Query String or server configuration requires it

#### Match Products By
- **Options**:
  - **SKU**: Match products by SKU field
  - **Slug**: Match products by product slug
- **Default**: SKU
- **Recommendation**: Use **SKU** (more reliable and standard practice)
- **How it works**:
  - When syncing, plugin searches remote store for existing product
  - If found: Updates existing product
  - If not found: Creates new product

#### Enable Stock Sync
- **Toggle**: ON/OFF
- **Description**: Whether to include stock quantities in syncs
- **Default**: ON
- **When OFF**: Stock quantities are never synced (useful if managing stock separately)
- **When ON**: Stock quantities included based on sync type

#### Auto-Sync on Product Save
- **Toggle**: ON/OFF
- **Description**: Automatically sync when a product is saved/updated
- **Default**: OFF
- **When ON**: Any product save triggers a sync to all active stores
- **Recommendation**:
  - Enable for real-time inventory management
  - Disable for large catalogs or frequent bulk edits (use scheduled sync instead)

#### Auto-Sync on Stock Change
- **Toggle**: ON/OFF
- **Description**: Automatically sync when stock quantity changes
- **Default**: OFF
- **When ON**: Stock changes trigger immediate sync (uses Quantity Only sync type)
- **Recommendation**:
  - Enable for real-time stock synchronization
  - Useful for high-volume stores with frequent stock changes

#### Auto-Sync Deletions
- **Toggle**: ON/OFF
- **Description**: When a product is trashed or permanently deleted on the main store, remove it from child stores
- **Default**: OFF
- **Deletion Mode**: **Trash** (move to trash on child) or **Delete** (permanently delete)
- **Note**: Both "Move to Trash" and permanent deletion are detected and synced

#### Delete Orphan Variations
- **Toggle**: ON/OFF
- **Description**: When a variable product is synced, automatically delete any variations on the child store that no longer exist on the main store
- **Default**: OFF
- **Use case**: Prevents ghost variations building up when you remove attribute options over time

#### API Image Transfer
- **Toggle**: ON/OFF
- **Description**: Upload images via the WordPress REST API instead of relying on the remote store downloading them over HTTP
- **Default**: OFF
- **When to enable**: Main store is behind Cloudflare, a WAF, or hotlink protection that blocks remote servers from downloading images
- **Requirement**: Remote store API keys must be from an **Administrator** user (needs `upload_files` capability)
- **Cloud/S3 support**: If images are stored on S3 or another CDN (not on local disk), the plugin automatically falls back to URL-based upload

---

## Scheduled Sync Configuration

Configure automatic, scheduled product synchronization.

### Accessing Scheduled Sync Settings

Navigate to **Multi-Store Sync > Settings** and scroll to **"Scheduled Sync"** section.

### Enable Scheduled Sync
- **Toggle**: ON/OFF
- **Description**: Enable automatic background syncing via Action Scheduler
- **Default**: OFF
- **How it works**:
  - **Queue Processor** runs every **1 minute** — processes up to 30 products per batch
  - **Scheduled Sync** runs every **10 minutes** — adds recently-modified products to the queue
  - Action Scheduler (bundled with WooCommerce) manages retries and failure handling automatically

### Peak Hours Configuration

Define when your store receives the most traffic to reduce sync load during busy times.

#### Peak Start Hour
- **Type**: Dropdown (0-23)
- **Default**: 6 (6:00 AM)
- **Description**: Hour when peak traffic begins
- **Example**: If your store is busiest from 9 AM to 9 PM, set to 9

#### Peak End Hour
- **Type**: Dropdown (0-23)
- **Default**: 23 (11:00 PM)
- **Description**: Hour when peak traffic ends
- **Example**: If your store is busiest from 9 AM to 9 PM, set to 21 (9 PM)

### Batch Size Configuration

Control how many products sync at once to balance performance and speed.

#### Batch Size (Peak Hours)
- **Type**: Number
- **Default**: 5
- **Range**: 1-20 (recommended)
- **Description**: Number of products to sync at once during peak hours
- **Recommendation**:
  - Lower number (3-5) for high-traffic stores
  - Higher number (10-15) if peak traffic is moderate

#### Batch Size (Off-Peak Hours)
- **Type**: Number
- **Default**: 20
- **Range**: 10-100 (recommended)
- **Description**: Number of products to sync at once during off-peak hours
- **Recommendation**:
  - Higher number (20-50) for faster overnight syncing
  - Lower number if experiencing server resource issues

### Sync Type Selection

#### Force Full Sync
- **Toggle**: ON/OFF
- **Description**: Always use Full Product sync (ignore Default Sync Type)
- **Default**: OFF
- **When ON**: Every scheduled sync is Full Product (slower but complete)
- **When OFF**: Uses Default Sync Type or intelligent sync type selection
- **Recommendation**:
  - Enable for initial setup or after major changes
  - Disable for normal operation (more efficient)

#### Sync All Products
- **Toggle**: ON/OFF
- **Description**: Sync all products vs. only recently modified
- **Default**: OFF
- **When ON**: Every scheduled sync includes ALL products
- **When OFF**: Only syncs products modified within timeframe (see below)
- **Recommendation**:
  - Enable for initial setup
  - Disable for normal operation (more efficient)

#### Sync Modified Hours
- **Type**: Number
- **Default**: 24
- **Description**: Only sync products modified in the last X hours
- **Example**: 24 = only products changed in last 24 hours
- **Recommendation**:
  - 24 hours for daily inventory updates
  - 1-6 hours for real-time sync without auto-sync enabled
  - 168 hours (1 week) for weekly batch updates

### Example Configurations

#### Configuration 1: Real-Time Store (High Traffic)
```
Enable Scheduled Sync: ON
Peak Hours: 6:00 AM - 11:00 PM
Batch Size (Peak): 3
Batch Size (Off-Peak): 30
Force Full Sync: OFF
Sync All Products: OFF
Sync Modified Hours: 2
```
**Result**: Syncs products modified in last 2 hours, small batches during day, larger at night

#### Configuration 2: Daily Update Store (Low Traffic)
```
Enable Scheduled Sync: ON
Peak Hours: 9:00 AM - 5:00 PM
Batch Size (Peak): 10
Batch Size (Off-Peak): 50
Force Full Sync: OFF
Sync All Products: OFF
Sync Modified Hours: 24
```
**Result**: Syncs daily changes, moderate batches, larger overnight batches

#### Configuration 3: Initial Setup / Full Sync
```
Enable Scheduled Sync: ON
Peak Hours: N/A (will run at off-peak batch size)
Batch Size (Peak): 10
Batch Size (Off-Peak): 20
Force Full Sync: ON
Sync All Products: ON
Sync Modified Hours: N/A (ignored when Sync All Products is ON)
```
**Result**: Syncs ALL products with full data, slower but complete

---

## Order Sync Configuration

Configure automatic order synchronization when orders are placed.

### Accessing Order Sync Settings

Navigate to **Multi-Store Sync > Settings** and scroll to **"Order Sync"** section.

### Enable Order Sync
- **Toggle**: ON/OFF
- **Description**: Automatically sync products when order status changes
- **Default**: OFF
- **How it works**:
  - Monitors order status changes
  - Syncs products in the order to all stores
  - Ensures inventory is updated across stores
- **Use Case**: Keep inventory synchronized when orders are placed on main store

### Sync on New Orders
- **Toggle**: ON/OFF
- **Description**: Sync products when a new order is created
- **Default**: OFF
- **When ON**: Syncs immediately on order creation (any status)
- **When OFF**: Only syncs on specific status changes (see below)
- **Recommendation**: Keep OFF (use status triggers instead)

### Order Status Triggers
- **Type**: Multi-select
- **Options**:
  - Pending Payment
  - Processing
  - On Hold
  - Completed
  - Cancelled
  - Refunded
  - Failed
- **Default**: Processing, Completed
- **Description**: Which order statuses trigger a product sync
- **Recommendation**:
  - **Processing**: Sync when payment confirmed
  - **Completed**: Sync when order fulfilled
  - **Cancelled/Refunded**: Sync to restore stock

### Debounce Timeout
- **Type**: Number (seconds)
- **Default**: 30
- **Range**: 10-300
- **Description**: Delay before syncing large orders (10+ items)
- **Purpose**: Prevents multiple rapid syncs if order is modified
- **How it works**:
  - Order with 10+ items triggers debounce
  - Waits X seconds
  - If order modified again, timer resets
  - After timeout, syncs once
- **Recommendation**:
  - 30 seconds for normal operations
  - 60-120 seconds for very large orders or slow servers

### Example Configuration

#### Standard E-Commerce Store
```
Enable Order Sync: ON
Sync on New Orders: OFF
Order Status Triggers: Processing, Completed, Cancelled
Debounce Timeout: 30 seconds
```
**Result**: Syncs inventory when orders are processed, completed, or cancelled

---

## Product-Level Configuration

### Sync from Product Edit Page

When editing a product, you can manually trigger syncs.

#### Accessing Product Sync Metabox

1. Navigate to **Products** in WordPress admin
2. Edit any product
3. Look for **"Multi-Store Sync"** metabox in the right sidebar

#### Sync Buttons

**🔄 Full Sync**
- Syncs ALL product data to all active stores
- Use for: New products, major updates

**💰 Price & Stock**
- Syncs only pricing and inventory data
- Use for: Price changes, stock updates

**📦 Stock Only**
- Syncs only stock quantities
- Use for: Quick inventory adjustments

#### How It Works
- Click button
- Sync happens immediately via AJAX
- Success/error message appears
- Check **Logs** for detailed results

### Bulk Actions

Sync multiple products at once from the Products list.

#### Accessing Bulk Actions

1. Navigate to **Products** in WordPress admin
2. Select products (checkboxes)
3. Choose from **Bulk Actions** dropdown:
   - **Sync Full Product**
   - **Sync Price & Stock**
   - **Sync Stock Only**
   - **Add to High Priority Queue**
4. Click **Apply**

#### High Priority Queue

Products added to high priority queue:
- Sync before regular queue items
- Useful for urgent inventory updates
- Processed in next cron run (every 5 minutes)

---

## Advanced Settings

### Cache Configuration

Configure caching for better performance.

#### Cache Settings (Future Update)
The following cache settings will be configurable in a future update:

- **Remote Product Cache Duration**: 5 minutes (default)
- **Taxonomy Cache Duration**: 1 hour (default)
- **Store Config Cache Duration**: 30 minutes (default)
- **Enable Cache Warmup**: Preload frequently accessed data

Currently, caching is automatic and uses these defaults.

### Performance Settings

#### Memory Limit
- **Recommended**: 256MB minimum
- **For Large Catalogs**: 512MB or higher
- **Configure**: In `wp-config.php`:
  ```php
  define('WP_MEMORY_LIMIT', '256M');
  define('WP_MAX_MEMORY_LIMIT', '512M');
  ```

#### Execution Time
- **Recommended**: 300 seconds (5 minutes)
- **For Large Syncs**: 600 seconds (10 minutes)
- **Configure**: In `php.ini` or `.htaccess`:
  ```php
  max_execution_time = 300
  ```

### Logging Settings

#### Enable Debug Logging
- **Location**: Settings page
- **Toggle**: ON/OFF
- **Default**: OFF
- **When ON**: Logs detailed sync information
- **When OFF**: Logs only errors and warnings
- **Recommendation**:
  - Enable for troubleshooting
  - Disable for normal operation (reduces disk usage)

#### Log Retention
- **Default**: 30 days
- **Automatic**: Old logs are automatically deleted
- **Manual**: Clear all logs from Logs page

---

## Configuration Best Practices

### Initial Setup

1. **Start Small**
   - Add one store first
   - Test with a few products
   - Verify sync behavior
   - Add more stores gradually

2. **Use Test Products**
   - Create test products
   - Sync to remote store
   - Verify data accuracy
   - Delete test products when satisfied

3. **Monitor Logs**
   - Check logs after each sync
   - Look for errors or warnings
   - Adjust settings if needed

### Production Configuration

1. **Enable Scheduled Sync**
   - After successful manual testing
   - Start with small batch sizes
   - Increase batch sizes gradually
   - Monitor server resources

2. **Configure Peak Hours**
   - Analyze your traffic patterns
   - Set peak hours accordingly
   - Use smaller batch sizes during peak
   - Larger batch sizes during off-peak

3. **Choose Sync Strategy**
   - **Real-Time**: Enable auto-sync on save/stock change
   - **Scheduled**: Enable scheduled sync with hourly modified products
   - **Hybrid**: Both auto-sync and scheduled sync

### Optimization Tips

1. **Batch Sizes**
   - Start with recommended defaults
   - Increase if server resources allow
   - Decrease if experiencing timeouts or high server load

2. **Sync Types**
   - Use **Quantity Only** for frequent stock updates
   - Use **Price & Quantity** for regular updates
   - Use **Full Product** only when necessary (new products, major changes)

3. **Exclusions**
   - Use category/tag exclusions to reduce unnecessary syncs
   - Exclude products that are store-specific
   - Reduces API calls and improves performance

4. **Queue Management**
   - Use high priority queue for urgent updates
   - Let scheduled sync handle routine updates
   - Monitor queue size (Dashboard)

### Security Best Practices

1. **API Credentials**
   - Use unique credentials for each store
   - Generate new keys with Read/Write permissions only
   - Rotate credentials periodically
   - Never share credentials in logs or screenshots

2. **HTTPS**
   - Always use HTTPS on both main and remote stores
   - Verify SSL certificates are valid
   - Don't disable SSL verification in production

3. **Permissions**
   - Only administrators should access plugin settings
   - Remote store API users should have minimal necessary permissions
   - Regularly audit API key usage

### Backup Strategy

1. **Before Major Changes**
   - Backup database before enabling sync
   - Backup before bulk operations
   - Test restore process

2. **Regular Backups**
   - Automated daily backups recommended
   - Include both database and files
   - Store backups off-site

---

## Configuration Checklist

Use this checklist to ensure proper configuration:

### Initial Setup
- [ ] Plugin installed and activated
- [ ] System status shows all green
- [ ] At least one store added and tested
- [ ] Settings configured (sync type, auth method, match by)
- [ ] Sync enabled

### Scheduled Sync
- [ ] Scheduled sync enabled (if desired)
- [ ] Peak hours configured based on traffic
- [ ] Batch sizes set appropriately
- [ ] Sync modified hours configured
- [ ] Cron jobs running (verify in Dashboard)

### Order Sync
- [ ] Order sync enabled (if desired)
- [ ] Order status triggers selected
- [ ] Debounce timeout set

### Testing
- [ ] Manual sync tested on single product
- [ ] Logs reviewed for errors
- [ ] Remote store product verified
- [ ] Variable products tested (if applicable)
- [ ] Images and categories verified

### Monitoring
- [ ] Dashboard checked regularly
- [ ] Queue size monitored
- [ ] Logs reviewed for errors
- [ ] Performance metrics acceptable

---

## Next Steps

Now that configuration is complete:

1. **Test Thoroughly**: See [User Manual](USER_MANUAL.md) for usage guides
2. **Monitor Performance**: Check Dashboard regularly
3. **Review Logs**: Look for any sync errors
4. **Optimize**: Adjust settings based on performance
5. **Scale Up**: Add more stores as needed

---

**Configuration Complete!** 🎉

Your multi-store sync is now configured and ready for production use.
