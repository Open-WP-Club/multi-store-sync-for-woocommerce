# Installation Guide - WooCommerce Multi-Store Sync

## Table of Contents
1. [Requirements](#requirements)
2. [Pre-Installation Checklist](#pre-installation-checklist)
3. [Installation Methods](#installation-methods)
4. [Post-Installation Steps](#post-installation-steps)
5. [Verification](#verification)
6. [Troubleshooting Installation](#troubleshooting-installation)

---

## Requirements

### Minimum Requirements
- **WordPress**: 5.8 or higher
- **PHP**: 8.3 or higher
- **WooCommerce**: 6.0 or higher
- **MySQL**: 5.6 or higher (or MariaDB 10.0+)
- **HTTPS**: Required for secure API communication

### Recommended Requirements
- **WordPress**: Latest stable version
- **PHP**: 8.1 or higher
- **WooCommerce**: Latest stable version
- **Memory Limit**: 256MB or higher
- **Max Execution Time**: 300 seconds or higher

### Server Requirements
- **cURL**: Enabled (for API requests)
- **JSON**: Support enabled
- **WordPress Cron**: Functional (or system cron configured)
- **File Permissions**: Write access to wp-content/plugins directory

---

## Pre-Installation Checklist

Before installing the plugin, ensure you have:

### 1. Main Store Preparation
- [ ] WooCommerce installed and activated
- [ ] Products added to your main store
- [ ] SSL certificate installed (HTTPS)
- [ ] Admin access to WordPress dashboard

### 2. Remote Store Preparation
For **each remote store** you want to sync to:
- [ ] WooCommerce installed and activated
- [ ] SSL certificate installed (HTTPS recommended)
- [ ] REST API enabled (Settings > Permalinks must NOT be set to "Plain")

### 3. API Credentials Generation
For **each remote store**, you need to generate WooCommerce REST API keys:

1. Log in to the remote store's WordPress admin
2. Navigate to **WooCommerce > Settings > Advanced > REST API**
3. Click **"Add Key"**
4. Fill in the details:
   - **Description**: "Main Store Sync" (or similar)
   - **User**: Select an administrator user
   - **Permissions**: **Read/Write**
5. Click **"Generate API Key"**
6. **IMPORTANT**: Copy both the **Consumer Key** and **Consumer Secret** immediately
   - You won't be able to see the Consumer Secret again!
7. Store these credentials securely

**Example Credentials:**
```
Consumer Key: ck_1234567890abcdefghijklmnopqrstuvwxyz12
Consumer Secret: cs_0987654321zyxwvutsrqponmlkjihgfedcba09
```

### 4. Backup Your Site
Before installing any plugin, it's good practice to:
- [ ] Backup your WordPress database
- [ ] Backup your WordPress files
- [ ] Test the backup restoration process (optional but recommended)

---

## Installation Methods

### Method 1: Manual Installation (Recommended)

#### Step 1: Download the Plugin
1. Download the latest version of the plugin ZIP file
2. Extract the ZIP file to your computer
3. The extracted folder should be named `wc-multi-store-sync`

#### Step 2: Upload to WordPress
1. Connect to your server via FTP/SFTP or use your hosting control panel's file manager
2. Navigate to `/wp-content/plugins/`
3. Upload the entire `wc-multi-store-sync` folder
4. Ensure the folder structure looks like this:
   ```
   /wp-content/plugins/wc-multi-store-sync/
   ├── wc-multi-store-sync.php
   ├── includes/
   ├── admin/
   └── ...
   ```

#### Step 3: Set Permissions (if needed)
Ensure proper file permissions:
```bash
# Files should be readable
chmod 644 /wp-content/plugins/wc-multi-store-sync/*.php

# Directories should be readable and executable
chmod 755 /wp-content/plugins/wc-multi-store-sync/

# Logs directory needs write permissions
chmod 755 /wp-content/plugins/wc-multi-store-sync/assets/logs/
```

#### Step 4: Activate the Plugin
1. Log in to your WordPress admin panel
2. Navigate to **Plugins > Installed Plugins**
3. Find **"WooCommerce Multi-Store Sync"**
4. Click **"Activate"**

### Method 2: WordPress Admin Upload

#### Step 1: Prepare the ZIP File
1. Download the plugin ZIP file
2. Ensure it's named `wc-multi-store-sync.zip`

#### Step 2: Upload via Admin
1. Log in to WordPress admin
2. Navigate to **Plugins > Add New**
3. Click **"Upload Plugin"** (top of page)
4. Click **"Choose File"** and select `wc-multi-store-sync.zip`
5. Click **"Install Now"**
6. Wait for installation to complete
7. Click **"Activate Plugin"**

### Method 3: WP-CLI Installation

If you have WP-CLI installed:

```bash
# Navigate to WordPress directory
cd /path/to/wordpress

# Install from ZIP
wp plugin install /path/to/wc-multi-store-sync.zip

# Or install from URL
wp plugin install https://example.com/wc-multi-store-sync.zip

# Activate the plugin
wp plugin activate wc-multi-store-sync

# Verify installation
wp plugin list | grep wc-multi-store-sync
```

---

## Post-Installation Steps

### Step 1: Verify Plugin Activation

After activation, you should see:
- A new **"Multi-Store Sync"** menu item in the WordPress admin sidebar
- A success message: "Plugin activated successfully"
- No error messages or warnings

### Step 2: Check System Status

1. Navigate to **Multi-Store Sync > Dashboard**
2. Review the **System Status** section:
   - ✅ WooCommerce: Active
   - ✅ Cron System: Working
   - ✅ Logs Directory: Writable
   - ✅ Queue System: Ready

### Step 3: Configure Initial Settings

1. Navigate to **Multi-Store Sync > Settings**
2. Review default settings:
   - **Enable Sync**: Toggle to enable/disable plugin
   - **Default Sync Type**: Full Product (recommended for initial setup)
   - **Authentication Method**: Query String (recommended for HTTPS)
   - **Match Products By**: SKU (recommended)
   - **Enable Stock Sync**: Yes (recommended)

3. **IMPORTANT**: Keep the plugin **disabled** until you add at least one store
   - This prevents any automatic sync attempts before configuration

### Step 4: Add Your First Remote Store

1. Navigate to **Multi-Store Sync > Stores**
2. Click **"Add New Store"**
3. Fill in the store details:
   - **Store URL**: Full URL including https:// (e.g., `https://store2.example.com`)
   - **Consumer Key**: Paste from remote store API settings
   - **Consumer Secret**: Paste from remote store API settings
   - **Status**: Active
4. Click **"Test Connection"** to verify
   - ✅ Success: "Connection successful! WooCommerce API is working."
   - ❌ Failure: See [Troubleshooting](#troubleshooting-installation) below
5. If test succeeds, click **"Save Store"**

### Step 5: Enable the Plugin

Once you've added at least one store and tested the connection:

1. Navigate to **Multi-Store Sync > Settings**
2. Enable the **"Enable Sync"** toggle
3. Click **"Save Settings"**
4. You're now ready to start syncing!

---

## Verification

### Verify Installation Checklist

After installation, verify everything is working:

#### 1. Plugin Status
- [ ] Plugin appears in **Plugins** list
- [ ] Plugin status shows "Active"
- [ ] Version number displays correctly (e.g., 2.0.0)
- [ ] No error messages in WordPress admin

#### 2. Menu and Pages
- [ ] "Multi-Store Sync" menu appears in admin sidebar
- [ ] Dashboard page loads without errors
- [ ] Stores page loads without errors
- [ ] Settings page loads without errors
- [ ] Logs page loads without errors

#### 3. Database Tables
Check if database tables were created (optional):

```sql
-- Check for sync history table
SHOW TABLES LIKE '%wc_multi_store_sync_history%';

-- Should return: wp_wc_multi_store_sync_history
```

Or using WP-CLI:
```bash
wp db query "SHOW TABLES LIKE '%wc_multi_store_sync_history%'"
```

#### 4. Cron Jobs
Verify cron jobs are scheduled:

**Using WP-CLI:**
```bash
wp cron event list | grep wc_multi_store
```

**Expected output:**
```
wc_multi_store_sync_process_queue    2024-01-15 10:00:00    5 minutes
wc_multi_store_sync_scheduled        2024-01-15 10:15:00    15 minutes
```

**Using Plugin (Dashboard):**
1. Navigate to **Multi-Store Sync > Dashboard**
2. Check "Cron Status" section:
   - ✅ Queue Processing: Scheduled (every 5 minutes)
   - ✅ Scheduled Sync: Scheduled (every 15 minutes)

#### 5. Test Manual Sync

Test with a single product:

1. Edit a product in WooCommerce
2. Look for the **"Multi-Store Sync"** metabox in the sidebar
3. Click **"🔄 Full Sync"**
4. Check for success message
5. Navigate to **Multi-Store Sync > Logs** to verify sync activity

#### 6. File Permissions

Verify the logs directory is writable:

```bash
ls -la /wp-content/plugins/wc-multi-store-sync/assets/logs/
```

Should show:
```
drwxr-xr-x  2 www-data www-data  4096 Jan 15 10:00 .
-rw-r--r--  1 www-data www-data  1234 Jan 15 10:00 sync-2024-01-15.log
```

---

## Troubleshooting Installation

### Issue: "Plugin could not be activated"

**Possible Causes:**
1. PHP version too old (< 8.3)
2. WooCommerce not installed or inactive
3. File permission issues
4. Conflicting plugins

**Solutions:**

**Check PHP version:**
```bash
php -v
```
Or in WordPress admin: **Tools > Site Health > Info > Server**

**Check WooCommerce status:**
1. Navigate to **Plugins**
2. Verify WooCommerce is active
3. Verify WooCommerce version is 6.0+

**Check file permissions:**
```bash
# Set correct ownership (replace www-data with your web server user)
chown -R www-data:www-data /wp-content/plugins/wc-multi-store-sync

# Set correct permissions
find /wp-content/plugins/wc-multi-store-sync -type d -exec chmod 755 {} \;
find /wp-content/plugins/wc-multi-store-sync -type f -exec chmod 644 {} \;
```

**Disable conflicting plugins:**
Temporarily deactivate other sync-related plugins and try again.

---

### Issue: "Menu Not Appearing"

**Possible Causes:**
1. Insufficient user permissions
2. Plugin not fully activated
3. Theme or plugin conflict

**Solutions:**

**Check user role:**
- Ensure you're logged in as an **Administrator**
- Only administrators can access the Multi-Store Sync menu

**Reactivate plugin:**
1. Deactivate the plugin
2. Clear all caches (if using a caching plugin)
3. Reactivate the plugin

**Test without theme:**
1. Temporarily switch to a default WordPress theme (Twenty Twenty-Four)
2. Check if menu appears
3. If yes, investigate theme compatibility

---

### Issue: "Connection Test Fails"

**Error Message:** "Connection failed: Could not resolve host"

**Possible Causes:**
1. Incorrect Store URL
2. Remote store is offline
3. SSL certificate issues
4. Firewall blocking requests

**Solutions:**

**Verify Store URL:**
- Ensure URL includes protocol: `https://example.com` (not `example.com`)
- Ensure URL doesn't have trailing slash
- Test URL in browser to verify it loads

**Check SSL Certificate:**
```bash
# Test SSL from server
curl -I https://remote-store.com
```

**Check from main store server:**
```bash
# Test API endpoint
curl -I https://remote-store.com/wp-json/wc/v3/products
```

**Disable SSL verification (temporary testing only):**
In Settings, if your remote store has a self-signed certificate, you may need to configure SSL verification (coming in a future update).

---

### Issue: "Connection Test Fails" - Authentication Error

**Error Message:** "Authentication failed: Invalid consumer key or secret"

**Possible Causes:**
1. Incorrect Consumer Key or Secret
2. REST API not enabled on remote store
3. Incorrect permissions for API key
4. Special characters in credentials not properly handled

**Solutions:**

**Re-generate API Keys:**
1. Log in to remote store
2. Navigate to **WooCommerce > Settings > Advanced > REST API**
3. Delete old key
4. Generate new key with **Read/Write** permissions
5. Copy credentials immediately

**Verify REST API is working:**
Visit in your browser:
```
https://remote-store.com/wp-json/wc/v3/system_status
```
This should show a JSON response or prompt for authentication.

**Check Permalinks:**
- Remote store must NOT use "Plain" permalinks
- Navigate to **Settings > Permalinks** on remote store
- Use "Post name" or any other structure except "Plain"

---

### Issue: "Database Table Not Created"

**Possible Causes:**
1. Database user lacks CREATE TABLE permissions
2. Database prefix is non-standard
3. Plugin activation incomplete

**Solutions:**

**Check database permissions:**
```sql
SHOW GRANTS FOR 'your_db_user'@'localhost';
```
Ensure CREATE permission is granted.

**Manually create table (if needed):**
```sql
CREATE TABLE IF NOT EXISTS wp_wc_multi_store_sync_history (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    product_id bigint(20) unsigned NOT NULL,
    product_sku varchar(200) DEFAULT NULL,
    product_name varchar(200) DEFAULT NULL,
    store_url varchar(255) NOT NULL,
    sync_type varchar(50) NOT NULL,
    status varchar(50) NOT NULL,
    message text,
    duration float DEFAULT NULL,
    memory_used int(11) DEFAULT NULL,
    api_calls int(11) DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id),
    KEY product_store (product_id,store_url(191)),
    KEY status_created (status,created_at),
    KEY sync_type_created (sync_type,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Deactivate and reactivate:**
1. Deactivate plugin
2. Verify table is created
3. Reactivate plugin

---

### Issue: "Cron Jobs Not Running"

**Symptoms:**
- Scheduled sync not happening automatically
- Queue not processing
- Last run time never updates

**Possible Causes:**
1. WordPress cron disabled
2. Server cron misconfigured
3. Hosting provider blocks wp-cron.php

**Solutions:**

**Check if WP-Cron is disabled:**
Look in `wp-config.php` for:
```php
define('DISABLE_WP_CRON', true);
```

If found, you need to set up system cron (see below).

**Set up system cron (recommended for high-traffic sites):**

Add to your server's crontab:
```bash
*/5 * * * * wget -q -O - https://yourdomain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Or using wp-cli:
```bash
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

**Test cron manually:**
```bash
# Trigger wp-cron
wp cron event run --all

# Check next scheduled event
wp cron event list | grep wc_multi_store
```

**Alternative: Use a cron monitoring plugin**
Install "WP Crontrol" plugin to view and manage cron events.

---

### Issue: "Memory Limit Errors"

**Error Message:** "Fatal error: Allowed memory size exhausted"

**Solutions:**

**Increase PHP memory limit:**

In `wp-config.php`:
```php
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');
```

In `.htaccess` (if using Apache):
```apache
php_value memory_limit 256M
```

In `php.ini`:
```ini
memory_limit = 256M
```

**Contact your hosting provider** if you don't have access to modify these settings.

---

### Issue: "Logs Directory Not Writable"

**Error Message:** "Cannot write to logs directory"

**Solutions:**

**Fix permissions:**
```bash
# Make logs directory writable
chmod 755 /wp-content/plugins/wc-multi-store-sync/assets/logs/

# Set correct ownership
chown www-data:www-data /wp-content/plugins/wc-multi-store-sync/assets/logs/
```

**Check parent directory:**
```bash
# Parent must be executable
chmod 755 /wp-content/plugins/wc-multi-store-sync/assets/
```

**Verify with:**
```bash
ls -la /wp-content/plugins/wc-multi-store-sync/assets/
```

---

## Getting Help

If you continue to experience issues:

1. **Check Logs**: Navigate to **Multi-Store Sync > Logs** for detailed error messages
2. **Enable Debug Mode**: In Settings, enable debug logging for more detailed information
3. **System Status**: Review **Dashboard > System Status** for configuration issues
4. **Documentation**: Consult the [Troubleshooting Guide](TROUBLESHOOTING.md) for more solutions
5. **Support**: Contact support with:
   - WordPress version
   - WooCommerce version
   - PHP version
   - Plugin version
   - Error messages from logs
   - Steps to reproduce the issue

---

## Next Steps

Once installation is complete and verified:

1. **Configure Settings**: See [Configuration Guide](CONFIGURATION.md)
2. **Add More Stores**: Add all your remote stores
3. **Test Sync**: Sync a few test products
4. **Review Logs**: Check sync logs for any issues
5. **Enable Automation**: Configure scheduled sync and auto-sync features
6. **Read User Manual**: See [User Manual](USER_MANUAL.md) for detailed feature guides

---

**Installation Complete!** 🎉

You're now ready to start syncing products across your WooCommerce stores.
