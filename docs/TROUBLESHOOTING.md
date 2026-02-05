# Affilync for WooCommerce - Troubleshooting Guide

This guide helps you diagnose and resolve common issues with the Affilync WooCommerce plugin.

## Table of Contents

- [Quick Diagnostics](#quick-diagnostics)
- [License Activation Issues](#license-activation-issues)
- [Connection Problems](#connection-problems)
- [Conversion Tracking Issues](#conversion-tracking-issues)
- [Product Sync Failures](#product-sync-failures)
- [Webhook Issues](#webhook-issues)
- [Performance Issues](#performance-issues)
- [Error Messages Reference](#error-messages-reference)
- [Getting Support](#getting-support)

---

## Quick Diagnostics

Before diving into specific issues, perform these basic checks:

### System Requirements Check

| Requirement | How to Check | Expected |
|-------------|--------------|----------|
| WordPress Version | Dashboard > Updates | 5.8+ |
| WooCommerce Version | Plugins > Installed | 5.0+ |
| PHP Version | Tools > Site Health | 7.4+ |
| HTTPS | Address bar | Must show padlock |

### Plugin Status Check

1. Go to **WooCommerce > Affilync**
2. Verify:
   - License shows "Active" (green indicator)
   - Connection shows "Connected to Affilync"
   - No error notices at top of page

### Test API Connection

Visit this URL in your browser:
```
https://your-site.com/wp-json/affilync/v1/health
```

**Expected Response**:
```json
{
    "status": "ok",
    "version": "1.0.0",
    "timestamp": "2024-01-15T10:30:00+00:00"
}
```

**If you see an error**, check that:
- Pretty permalinks are enabled (Settings > Permalinks)
- REST API is not blocked by security plugins
- `.htaccess` rules are not interfering

---

## License Activation Issues

### Error: "Please enter a license key"

**Cause**: Empty license key field

**Solution**: Enter your complete license key in the format `AFFILYNC-XXXX-XXXX-XXXX-XXXX`

---

### Error: "Invalid license key"

**Cause**: License key format is incorrect or key doesn't exist

**Solutions**:
1. Verify you copied the entire license key
2. Check for extra spaces at the beginning or end
3. Ensure the key follows the pattern: `AFFILYNC-XXXX-XXXX-XXXX-XXXX`
4. Confirm the license was purchased from affilync.com

---

### Error: "License already activated on another site"

**Cause**: License is currently active on a different WordPress installation

**Solutions**:
1. Log in to your Affilync account at https://app.affilync.com
2. Navigate to Account > Licenses
3. Deactivate the license from the other site
4. Return to your WordPress site and activate again

---

### Error: "License expired"

**Cause**: Your subscription has ended

**Solutions**:
1. Log in to your Affilync account
2. Navigate to Account > Billing
3. Renew your subscription
4. Return to WordPress and click "Verify License"

---

### Error: "Unable to verify license"

**Cause**: Cannot reach the license server

**Solutions**:
1. Check your internet connection
2. Verify your server allows outbound HTTPS connections
3. Check if `api.affilync.com` is blocked by firewall
4. Try again in a few minutes (server may be temporarily unavailable)

**Testing Connectivity**:
```bash
# From server command line
curl -I https://api.affilync.com/health
```

---

### License Stuck in "Verification Pending"

**Cause**: Plugin cannot verify the license but is in grace period

**Solutions**:
1. Click "Verify License" to force a check
2. Review server error logs for connection issues
3. Check if SSL certificate is valid
4. Contact support if issue persists after 24 hours

---

## Connection Problems

### Error: "Not connected to Affilync"

**Cause**: OAuth connection was not completed or has been invalidated

**Solutions**:
1. Verify your license is activated
2. Click "Connect to Affilync"
3. Complete the OAuth authorization flow
4. If redirected back with an error, note the message

---

### Error: "Client ID not configured"

**Cause**: No client ID available for OAuth

**Solutions**:
1. Ensure your license is activated (license provides client ID)
2. Or, define `AFFILYNC_CLIENT_ID` in `wp-config.php`

---

### Error: "Invalid or expired state parameter"

**Cause**: OAuth state token expired (10-minute validity)

**Solutions**:
1. Start the connection process again
2. Complete authorization within 10 minutes
3. Don't use back button during OAuth flow
4. Clear browser cookies if issue persists

---

### Error: "State verification failed"

**Cause**: OAuth state doesn't match expected site

**Solutions**:
1. Ensure you're on the correct WordPress site
2. Check if site URL has changed
3. Try connecting again from the beginning

---

### Error: "Failed to connect to Affilync API"

**Cause**: Network error during OAuth token exchange

**Solutions**:
1. Check server's outbound connection capability
2. Verify SSL certificates are valid
3. Check for firewall blocking HTTPS connections
4. Review server error logs

**Debug Command**:
```bash
# Test connection from server
curl -X POST https://api.affilync.com/oauth/token \
  -H "Content-Type: application/json" \
  -d '{"test": true}'
```

---

### Connection Lost After Working

**Cause**: Access token expired and refresh failed

**Solutions**:
1. Click "Connect to Affilync" to reauthorize
2. Check if encryption key in `wp-config.php` changed
3. Review audit logs for token refresh errors

---

## Conversion Tracking Issues

### Conversions Not Being Tracked

**Symptoms**: Orders complete but no conversions appear

**Diagnostic Steps**:

1. **Verify Tracking is Enabled**
   - Go to Settings > Tracking Settings
   - Ensure "Enable Tracking" is checked

2. **Check Order Status Configuration**
   - Go to Settings > Conversion Order Statuses
   - Verify the relevant order statuses are selected

3. **Test with Affiliate Link**
   - Use a test affiliate link: `https://your-site.com/?ref=test123`
   - Complete a test order
   - Check Conversions tab

4. **Check for Cookie Blocking**
   - Ensure cookies aren't blocked by browser
   - Check if privacy plugins block tracking cookies
   - Verify cookie consent (if applicable)

---

### Error: "Not connected" in Conversion Logs

**Cause**: Plugin disconnected from Affilync

**Solution**: Reconnect to Affilync in Settings

---

### Conversions Tracked but Not Synced

**Symptoms**: Conversions appear locally but "Synced" column shows "No"

**Solutions**:
1. Click "Sync Now" on Conversions tab
2. Check for API connection errors in Logs tab
3. Verify you haven't exceeded plan limits
4. Check server cron is running

**Force Sync via WP-CLI**:
```bash
wp eval "affilync()->conversion_tracker->sync_pending_conversions();"
```

---

### Duplicate Conversions

**Cause**: Order status changes multiple times to tracked statuses

**Solution**: The plugin automatically prevents duplicates. If you see duplicates:
1. Check if multiple tracking plugins are installed
2. Review conversion tracking logs
3. Contact support with order IDs

---

### Wrong Affiliate Credited

**Cause**: Attribution model or cookie duration mismatch

**Solutions**:
1. Review Attribution Model setting (Last Click vs First Click)
2. Check Cookie Duration setting
3. Review customer's journey using order meta data

---

## Product Sync Failures

### Products Not Syncing

**Symptoms**: Products stuck in "Pending" status

**Diagnostic Steps**:

1. **Check Connection**
   - Verify connected to Affilync
   - Test API health endpoint

2. **Check Sync Settings**
   - Ensure Product Sync is enabled
   - Verify sync interval is set

3. **Manual Sync Test**
   - Click "Sync Now" on Products tab
   - Check for error messages

4. **Check Plan Limits**
   - Free plan: 100 products max
   - Check usage on License tab

---

### Error: "Product sync failed"

**Cause**: API rejected the product data

**Solutions**:
1. Check product has required fields (title, price)
2. Verify product images are accessible URLs
3. Review error message in sync logs

---

### Sync Taking Too Long

**Cause**: Large catalog or slow server

**Solutions**:
1. Change sync interval to "Daily"
2. Increase PHP memory limit
3. Increase PHP execution time
4. Use WP-CLI for bulk sync

**Increase Memory (wp-config.php)**:
```php
define( 'WP_MEMORY_LIMIT', '256M' );
```

---

### Deleted Products Still in Affilync

**Cause**: Deletion sync failed

**Solution**:
1. Products should sync deletion automatically
2. If not, delete from Affilync dashboard directly
3. Check API connection and logs

---

## Webhook Issues

### Webhooks Not Received

**Symptoms**: Conversion statuses not updating, no entries in webhook logs

**Diagnostic Steps**:

1. **Verify Webhook URL**
   - Your webhook URL: `https://your-site.com/wp-json/affilync/v1/webhooks/receive`
   - Test accessibility in browser (should return 401 without signature)

2. **Check REST API**
   - Ensure WordPress REST API is accessible
   - Check security plugins aren't blocking

3. **Verify SSL Certificate**
   - Webhooks require valid HTTPS
   - Check certificate isn't expired

4. **Check Logs**
   - Go to WooCommerce > Affilync > Logs
   - Look for webhook entries

---

### Error: "Invalid signature" in Webhook Logs

**Cause**: Webhook signature verification failed

**Solutions**:
1. Webhook secret may have changed
2. Disconnect and reconnect to Affilync (registers new secret)
3. Check for proxy/CDN modifying request body

---

### Webhooks Processing but Actions Not Applied

**Cause**: Webhook handler error or missing order

**Solutions**:
1. Check processing_result in webhook logs
2. Verify referenced order exists
3. Check for PHP errors in debug log

---

## Performance Issues

### Slow Admin Pages

**Cause**: Large database tables or excessive API calls

**Solutions**:
1. Clear old audit logs (automatic after 90 days)
2. Optimize database tables
3. Check for conflicting plugins

**Optimize Tables**:
```sql
OPTIMIZE TABLE wp_affilync_conversions;
OPTIMIZE TABLE wp_affilync_product_sync;
OPTIMIZE TABLE wp_affilync_webhooks;
OPTIMIZE TABLE wp_affilync_audit_log;
```

---

### High API Rate Limiting

**Symptoms**: Frequent "Rate limited" errors

**Solutions**:
1. Reduce sync frequency
2. Avoid manual sync spam
3. Upgrade plan for higher limits

---

### Cron Not Running

**Cause**: WordPress cron not triggering

**Solutions**:
1. Add to `wp-config.php`:
```php
define( 'ALTERNATE_WP_CRON', true );
```

2. Or set up server cron:
```bash
*/5 * * * * wget -q -O - https://your-site.com/wp-cron.php?doing_wp_cron
```

---

## Error Messages Reference

| Error Message | Cause | Solution |
|---------------|-------|----------|
| "WooCommerce is required" | WooCommerce not active | Install and activate WooCommerce |
| "Plugin integrity verification failed" | Files modified | Reinstall plugin from original source |
| "Rate limited" | Too many API requests | Wait and retry |
| "Not connected" | OAuth disconnected | Reconnect to Affilync |
| "Token refresh failed" | Refresh token expired | Reconnect to Affilync |
| "Permission denied" | User lacks capability | Log in as admin |
| "Invalid JSON payload" | Malformed webhook | Check sending server |
| "Duplicate webhook" | Same webhook received twice | Safe to ignore |
| "Database error" | Insert/update failed | Check database connection |

---

## Debug Mode

For advanced troubleshooting, enable WordPress debug mode:

**wp-config.php**:
```php
// Enable debugging
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );

// Enable script debugging
define( 'SCRIPT_DEBUG', true );
```

**View Logs**:
```bash
tail -f wp-content/debug.log
```

---

## Getting Support

If you cannot resolve your issue:

### Before Contacting Support

Gather this information:
1. WordPress version
2. WooCommerce version
3. PHP version
4. Plugin version
5. Error messages (exact text)
6. Steps to reproduce
7. Debug log entries (if available)

### Contact Methods

| Method | Best For | Response Time |
|--------|----------|---------------|
| Support Portal | General inquiries | 24-48 hours |
| Email | Technical issues | 24-48 hours |
| Emergency | Production down | 4 hours (Enterprise) |

- **Support Portal**: https://affilync.com/support
- **Email**: support@affilync.com
- **Documentation**: https://docs.affilync.com

### Emergency Support (Enterprise)

Enterprise customers have access to priority support:
- Email: enterprise@affilync.com
- Include "URGENT" in subject line

---

## Frequently Asked Questions

### Can I use the plugin on multiple sites?

Each license is valid for one site. You can purchase additional licenses or upgrade to a multi-site license.

### How do I migrate to a new domain?

1. Deactivate the license on the old site
2. Install the plugin on the new site
3. Activate with the same license key
4. Reconnect to Affilync

### Will I lose data if I deactivate the plugin?

No. Data remains in your WordPress database. However, conversion tracking stops and data won't sync to Affilync.

### How do I completely remove the plugin?

1. Enable "Remove Data on Uninstall" in Advanced settings
2. Deactivate the plugin
3. Delete the plugin

This removes all database tables and stored options.
