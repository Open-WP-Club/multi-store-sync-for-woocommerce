# Troubleshooting Guide - WooCommerce Multi-Store Sync

## Table of Contents
1. [Connection Issues](#connection-issues)
2. [Sync Failures](#sync-failures)
3. [Performance Issues](#performance-issues)
4. [Cron and Scheduling Issues](#cron-and-scheduling-issues)
5. [Data Accuracy Issues](#data-accuracy-issues)
6. [Queue Problems](#queue-problems)
7. [Common Error Messages](#common-error-messages)

---

## Connection Issues

### Issue: "Connection Test Failed - Could not resolve host"

**Symptoms:**
- Connection test fails when adding/editing store
- Error message mentions "could not resolve host"

**Possible Causes:**
1. Incorrect store URL
2. Remote store is offline
3. DNS resolution issues
4. Firewall blocking outbound requests

**Solutions:**

**1. Verify Store URL**
```
Correct:   https://store2.example.com
Incorrect: store2.example.com (missing https://)
Incorrect: https://store2.example.com/ (trailing slash)
Incorrect: https://store2.example.com/wp-admin (includes path)
```

**2. Test URL in Browser**
- Open URL in browser
- Verify site loads
- Check for SSL errors

**3. Test from Server**
```bash
# SSH into main store server
curl -I https://remote-store.com

# Test API endpoint
curl https://remote-store.com/wp-json/wc/v3/system_status
```

**4. Check DNS**
```bash
nslookup remote-store.com
# Should return IP address
```

**5. Check Server Firewall**
- Ensure outbound HTTPS (port 443) is allowed
- Contact hosting provider if uncertain
- Whitelist remote store IP if needed

---

### Issue: "Authentication Failed - Invalid Signature"

**Symptoms:**
- Connection test fails with authentication error
- Syncs fail with 401 Unauthorized

**Possible Causes:**
1. Incorrect Consumer Key or Secret
2. Extra spaces in credentials
3. Wrong authentication method
4. API keys don't have proper permissions
5. Permalink structure issue on remote store

**Solutions:**

**1. Regenerate API Keys**
On remote store:
1. WooCommerce > Settings > Advanced > REST API
2. Delete old key
3. Create new key:
   - Permissions: **Read/Write**
   - User: Administrator
4. Copy credentials immediately (Secret shown once only)

**2. Verify No Extra Spaces**
- Check for spaces before/after credentials
- Copy directly from API key page
- Don't manually type

**3. Try Different Auth Method**
- Query String (default, works with HTTPS)
- Basic Auth (try if Query String fails)

**4. Check Permalinks**
On remote store:
1. Settings > Permalinks
2. Must NOT be set to "Plain"
3. Use "Post name" or any other structure
4. Click "Save Changes"

**5. Test with WP-CLI**
```bash
# On remote store
wp rest get /wc/v3/products --user=admin
# Should return product list
```

---

### Issue: "SSL Certificate Verification Failed"

**Symptoms:**
- Connection fails with SSL error
- Error mentions certificate verification

**Possible Causes:**
1. Self-signed certificate
2. Expired certificate
3. Mismatched domain on certificate
4. Missing intermediate certificates

**Solutions:**

**1. Check Certificate**
```bash
# Test certificate
curl -v https://remote-store.com 2>&1 | grep "SSL certificate"
```

**2. Verify Certificate in Browser**
- Open remote store in browser
- Click padlock icon
- Verify certificate is valid

**3. For Self-Signed Certificates (Development Only)**
SSL verification bypass option coming in future update. For now, use valid certificates even in development.

**4. Renew Certificate**
- If expired, renew via hosting provider
- Let's Encrypt provides free SSL

---

## Sync Failures

### Issue: Products Not Syncing

**Symptoms:**
- Manual sync appears to work but products don't appear on remote store
- No error messages
- Logs show success

**Possible Causes:**
1. Products created but not visible (draft status, wrong category)
2. Remote store has visibility settings
3. Cache on remote store
4. Products synced but SKU mismatch preventing updates

**Solutions:**

**1. Check Remote Store**
- Log into remote store admin
- Check Products > All Products (including drafts)
- Search by SKU
- Check product status

**2. Review Sync Logs**
Navigate to **Logs** page:
- Check "Status" column
- Look for remote_id in successful syncs
- Note the remote product ID

**3. Verify on Remote Store**
```
URL: https://remote-store.com/wp-admin/post.php?post=REMOTE_ID&action=edit
```

**4. Check Product Visibility**
On remote store product:
- Status must be "Published"
- Visibility must not be "Hidden"
- Check if categories exist

**5. Clear Remote Cache**
- Clear remote store caching plugin
- Clear CDN cache if using one

---

### Issue: Variable Products Not Syncing Correctly

**Symptoms:**
- Parent product syncs but variations don't
- Variations sync but attributes missing
- "Attribute does not exist" errors

**Possible Causes:**
1. Attributes not synced before variations
2. Attribute slugs don't match
3. Remote store doesn't have attributes defined

**Diagnosis:**

**Check Logs:**
Look for messages like:
- "Creating attribute: color"
- "Attribute slug mismatch"
- "Variation sync failed"

**Solutions:**

**1. Sync Parent First (Full Sync)**
- Always use "Full Sync" for variable products initially
- This ensures attributes are created
- Then variations can sync

**2. Check Attribute Slugs**
On main store:
- Products > Attributes
- Note exact slugs (e.g., "pa_color", "pa_size")

On remote store:
- Verify same slugs exist
- Create manually if missing

**3. Force Re-sync**
- Edit variable product
- Click "Full Sync"
- Check logs for detailed errors

**4. Manual Attribute Creation**
On remote store:
1. Products > Attributes
2. Add new attribute matching main store exactly:
   - Name: Color
   - Slug: pa_color (must match exactly)

---

### Issue: Images Not Syncing

**Symptoms:**
- Products sync but images missing
- Error: "Image upload failed"

**Possible Causes:**
1. Image URLs not accessible from remote server
2. Remote store upload directory not writable
3. Remote server blocking external image downloads
4. Image file too large

**Solutions:**

**1. Verify Image URLs Accessible**
```bash
# From remote server
curl -I https://main-store.com/wp-content/uploads/2024/01/image.jpg
# Should return 200 OK
```

**2. Check Remote Upload Directory**
On remote store:
```bash
# Check permissions
ls -la /path/to/wordpress/wp-content/uploads/
# Should be writable by web server
```

**3. Check PHP Upload Settings**
On remote store, verify in php.ini:
```ini
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 300
```

**4. Firewall / Cloudflare Issues**

If images fail with 403 Forbidden and your main store is behind Cloudflare or another WAF, the remote store's own image download is being blocked before it reaches your server. Whitelisting the remote store's IP is one option, but the plugin has a built-in fix for exactly this case:

- Enable **API Image Transfer** in **Multi-Store Sync > Settings** on the main store. Instead of the remote store downloading the image over HTTP, the main store reads the file from disk and uploads it directly through the remote store's WordPress REST API.
- Requires the remote store's API key to belong to an **Administrator** user (needs the `upload_files` capability) — Shop Manager keys will sync products but image uploads will still fail with 403.
- See [API Image Transfer](../README.md#api-image-transfer-cloudflare--firewall) in the README for details.

---

## Performance Issues

### Issue: Slow Sync Performance

**Symptoms:**
- Syncs take very long time
- Server timeout errors
- High memory usage

**Possible Causes:**
1. Large product catalogs
2. Insufficient server resources
3. Network latency
4. Large images
5. Too many API calls

**Solutions:**

**1. Optimize Batch Sizes**
- Reduce batch size during peak hours
- Settings > Scheduled Sync > Batch Size (Peak): 3-5

**2. Increase PHP Limits**
In `wp-config.php`:
```php
define('WP_MEMORY_LIMIT', '512M');
define('WP_MAX_MEMORY_LIMIT', '512M');
```

In `php.ini`:
```ini
memory_limit = 512M
max_execution_time = 600
```

**3. Use Appropriate Sync Types**
- Don't use Full Sync unnecessarily
- Use Stock Only for inventory updates
- Use Price & Quantity for regular updates

**4. Enable Caching** (automatic)
Cache is automatically enabled:
- Remote products: 5 minutes
- Categories/tags: 1 hour
- No configuration needed

**5. Optimize Images**
- Compress images before upload
- Use appropriate dimensions
- Consider CDN for main store

**6. Database Optimization**
```bash
wp db optimize
```

---

### Issue: High Server Load During Sync

**Symptoms:**
- Server slow during sync operations
- Site becomes unresponsive
- High CPU usage

**Solutions:**

**1. Configure Peak Hours**
- Settings > Scheduled Sync
- Set peak hours to match traffic patterns
- Use smaller batch sizes during peak

**2. Reduce Batch Size**
- Lower batch size during peak: 2-3
- Lower batch size during off-peak: 10-20

**3. Disable Auto-Sync**
If enabled:
- Settings > Auto-Sync on Product Save: OFF
- Settings > Auto-Sync on Stock Change: OFF
- Rely on scheduled sync instead

**4. Schedule During Off-Hours**
- Most syncing happens overnight (off-peak)
- Adjust peak hours to limit daytime syncing

**5. Use Dedicated Cron**
Instead of WordPress cron:
```bash
# Disable WP-Cron in wp-config.php
define('DISABLE_WP_CRON', true);

# Add to system cron (crontab -e)
*/5 * * * * wget -q -O - https://yourdomain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

---

## Cron and Scheduling Issues

### Issue: Scheduled Sync Not Running

**Symptoms:**
- Dashboard shows old "Last Run" time
- Queue not processing
- Products not syncing automatically

**Possible Causes:**
1. WordPress cron not working
2. WP-Cron disabled
3. No traffic to trigger cron
4. Hosting provider disabling cron

**Diagnosis:**

**Check Cron Status:**
```bash
wp cron event list | grep wc_multi_store
```

Expected output:
```
wc_multi_store_sync_process_queue    next run time
wc_multi_store_sync_scheduled        next run time
```

**Solutions:**

**1. Test Cron Manually**
```bash
# Trigger cron
wp cron event run --due-now

# Or via URL
curl https://yourdomain.com/wp-cron.php?doing_wp_cron
```

**2. Check if WP-Cron Disabled**
Look in `wp-config.php`:
```php
define('DISABLE_WP_CRON', true);  // If present, cron is disabled
```

**3. Set Up System Cron** (Recommended)
```bash
# Disable WP-Cron in wp-config.php
define('DISABLE_WP_CRON', true);

# Add to system crontab (crontab -e)
*/5 * * * * wget -q -O - https://yourdomain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

**4. Use Plugin to Monitor Cron**
Install "WP Crontrol" plugin:
- View all scheduled events
- Run events manually
- Debug cron issues

**5. Check with Hosting Provider**
Some hosting providers:
- Block wp-cron.php
- Have special cron configurations
- Require system cron instead

---

### Issue: Queue Not Processing

**Symptoms:**
- Queue size keeps growing
- Products queued but never sync
- "Processing" status stuck

**Solutions:**

**1. Force a Full Sync**
Dashboard > Quick Actions > "Force Full Sync All Products" — queues every product again rather than processing the existing queue immediately (there's no manual "process now" button; the queue runs on its own every minute via Action Scheduler).

**2. Check for Errors in Logs**
Navigate to **Logs** page:
- Look for error messages
- Check if all syncs failing
- Note specific error codes

**3. Clear Stuck Queue**
⚠️ Use with caution:
**Multi-Store Sync > Weekly Verification** page > "Stop All Sync & Clear Queue"

**4. Verify Action Scheduler Is Running**
See [Scheduled Sync Not Running](#issue-scheduled-sync-not-running) above

**5. Check Queue Table**

The queue is a dedicated database table, not a `wp_options` entry:
```bash
wp db query "SELECT * FROM wp_wc_mss_queue ORDER BY created_at DESC LIMIT 20"
```

---

## Data Accuracy Issues

### Issue: Stock Quantities Not Matching

**Symptoms:**
- Main store shows different stock than remote
- Stock syncs but numbers don't match

**Possible Causes:**
1. Remote store has independent sales
2. Sync delay
3. Manual stock adjustments on remote
4. Cache on remote store

**Solutions:**

**1. Remember: One-Way Sync**
- Sync is main → remote only
- Remote sales don't sync back
- Remote stock changes overwritten on next sync

**2. Force Re-sync**
- Edit product
- Click "Stock Only" sync
- Verify in logs

**3. Check Remote for Independent Changes**
- Log into remote store
- Check if orders placed there
- Check if stock manually adjusted

**4. Enable Order Sync**
- Settings > Order Sync > Enable
- Syncs inventory when main store orders placed
- Keeps remote stores updated

**5. Clear Remote Cache**
- Remote store may be caching product data
- Clear cache/CDN on remote store

---

### Issue: Prices Not Updating

**Symptoms:**
- Change price on main store
- Price doesn't update on remote

**Possible Causes:**
1. Using "Stock Only" sync type
2. Remote store has price override
3. Currency conversion plugins interfering
4. Cache on remote store

**Solutions:**

**1. Use Correct Sync Type**
- "Stock Only" doesn't sync prices
- Use "Price & Quantity" or "Full Sync"

**2. Check Sync Type**
- Settings > Default Sync Type
- If set to "Stock Only", change to "Price & Quantity"

**3. Manual Sync**
- Edit product
- Click "💰 Price & Stock"
- Verify in logs

**4. Check Remote for Overrides**
- Some plugins override prices
- Check for dynamic pricing plugins
- Check for currency conversion

---

## Queue Problems

### Issue: Products Keep Re-queuing

**Symptoms:**
- Same products repeatedly added to queue
- Queue never empties
- Syncs complete but items re-appear

**Possible Causes:**
1. Auto-sync triggering on every stock check
2. Hooks firing multiple times
3. Product updates in loop

**Solutions:**

**1. Disable Auto-Sync Temporarily**
- Settings > Auto-Sync on Save: OFF
- Settings > Auto-Sync on Stock Change: OFF
- See if problem stops

**2. Check for Conflicting Plugins**
- Deactivate other stock management plugins
- Deactivate other sync plugins
- Test if issue resolves

**3. Clear Queue and Monitor**
- Dashboard > Clear Queue
- Watch if specific products re-queue
- Check those products for issues

---

## Common Error Messages

### "cURL error 28: Operation timed out"

**Meaning:** Request to remote store took too long

**Solutions:**
- Check remote store is online
- Check network connection
- Increase timeout (coming in future update)
- Contact remote hosting provider

---

### "cURL error 7: Failed to connect to host"

**Meaning:** Cannot establish connection to remote store

**Solutions:**
- Verify store URL is correct
- Check remote store is online
- Check firewall not blocking
- Verify DNS resolution

---

### "401 Unauthorized"

**Meaning:** Authentication failed

**Solutions:**
- Verify Consumer Key and Secret
- Regenerate API keys
- Check permissions are Read/Write
- Verify no extra spaces in credentials

---

### "404 Not Found"

**Meaning:** API endpoint doesn't exist

**Solutions:**
- Check remote store has WooCommerce active
- Verify permalinks not set to "Plain"
- Check WooCommerce version (need 6.0+)
- Test API manually: `https://remote-store.com/wp-json/wc/v3/`

---

### "403 Forbidden"

**Meaning:** Server refusing the request

**Solutions:**
- Check API user has administrator role
- Check server firewall rules
- Check security plugins not blocking API
- Check .htaccess rules

---

### "500 Internal Server Error"

**Meaning:** Remote server error

**Solutions:**
- Check remote store error logs
- Check PHP errors on remote
- Verify remote server resources
- Contact remote hosting provider

---

### "Product not found by SKU"

**Meaning:** Cannot find existing product to update

**Solutions:**

**If SKU exists:**
- Verify SKU exactly matches (case-sensitive)
- Check for extra spaces in SKU
- Try "Match By Slug" instead

**If SKU doesn't exist:**
- Normal on first sync
- Product will be created
- Check logs for creation success

---

### "Attribute does not exist"

**Meaning:** Product attribute not found on remote store

**Solutions:**
- Use "Full Sync" for variable products
- Manually create attributes on remote:
  - Products > Attributes
  - Match slugs exactly (pa_color, pa_size, etc.)
- Re-sync product after creating attributes

---

## Getting Further Help

If issues persist after trying these solutions:

1. **Enable Debug Logging**
   - Settings > Debug Logging: ON
   - Reproduce the issue
   - Check detailed logs

2. **Gather Information**
   - WordPress version
   - WooCommerce version
   - PHP version
   - Plugin version
   - Error messages from logs
   - Steps to reproduce

3. **Check System Status**
   - Dashboard > System Status
   - Note any errors

4. **Contact Support**
   - Provide all gathered information
   - Include relevant log entries
   - Describe expected vs actual behavior

---

## Additional Troubleshooting Scenarios

### Issue: Products Deleted on Main Store Still Appear on Child Store

**Symptoms:**
- Trashed or permanently deleted products remain visible on child stores
- No deletion entry in the sync log

**Cause:**
The **Auto-Sync Deletions** setting is disabled, or the deletion was performed before the `before_delete_post` hook had a chance to cache the product data (e.g., database restore bypassing WordPress hooks).

**Solutions:**

1. **Enable Auto-Sync Deletions**
   - Go to **Multi-Store Sync > Settings > General**
   - Toggle **Auto-Sync Deletions** ON
   - Choose **Deletion Mode**: Trash or Delete

2. **Run Orphan Scanner**
   - Go to **Multi-Store Sync > Settings > Discrepancies**
   - Select the affected store
   - Click **Scan for Orphans**
   - Delete the found orphaned products

---

### Issue: "Delete Orphan Variations" Setting Resets to OFF After Saving

**Cause (resolved in v3.3.0):** The setting was missing from the save handler and was overwritten with the default value on every save.

**Fix:** Update to v3.3.0 or later. The setting now persists correctly.

---

### Issue: Images Fail to Upload (S3/Cloud Storage)

**Symptoms:**
- Products sync but images are missing on child stores
- Logs show image upload errors for S3 or cloud-stored attachments

**Cause:**
When images are stored on S3 or a CDN (via a plugin like WP Offload Media), the file does not exist on local disk. The image proxy previously only tried to read from disk.

**Fix (resolved in v3.3.0):** The image proxy now detects cloud-stored attachments and falls back to downloading the image from its public URL before uploading to the child store.

**Ensure:**
- The S3/CDN URL is publicly accessible (not behind a signed URL that expires)
- API Image Transfer is enabled in Settings

---

### Issue: Category Scan Timeout for Large Catalogs

**Symptoms:**
- Browser times out or shows an error during category scan
- PHP fatal error: maximum execution time

**Cause:**
The scan makes paginated API calls to fetch all remote products. For very large stores (5000+ products), this can take over 30 seconds.

**Solutions:**

1. **Increase PHP execution time** (hosting-dependent):
   ```php
   // In wp-config.php or .htaccess
   set_time_limit(120);
   ```

2. **Run during off-peak hours** when server load is lower

3. The scan is designed to be memory-efficient (bulk SQL queries), so memory is rarely the bottleneck — the bottleneck is network latency to the child store API

---

## Additional Resources

- [Installation Guide](INSTALLATION.md)
- [Configuration Guide](CONFIGURATION.md)
- [User Manual](USER_MANUAL.md)
- [FAQ](FAQ.md)
- [Developer Documentation](DEVELOPER.md)
