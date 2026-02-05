# Affilync for WooCommerce - Configuration Guide

This guide covers all configuration options for the Affilync WooCommerce plugin, including license management, OAuth connection, tracking settings, product sync, and webhook configuration.

## Table of Contents

- [Accessing Settings](#accessing-settings)
- [License Activation](#license-activation)
- [Connecting to Affilync](#connecting-to-affilync)
- [Tracking Settings](#tracking-settings)
- [Product Sync Settings](#product-sync-settings)
- [Webhook Configuration](#webhook-configuration)
- [Advanced Settings](#advanced-settings)
- [Dashboard Widget](#dashboard-widget)

---

## Accessing Settings

The Affilync settings page can be accessed in two ways:

1. **Via WooCommerce Menu**: Navigate to **WooCommerce > Affilync**
2. **Via Plugin Actions**: Go to **Plugins > Installed Plugins**, find "Affilync for WooCommerce", and click **Settings**

The settings page contains five tabs:
- **License** - Manage your license key
- **Settings** - Configure connection, tracking, and sync options
- **Conversions** - View tracked conversions
- **Products** - Monitor product sync status
- **Logs** - View audit logs and security events

---

## License Activation

### Before Connecting

You must activate a valid license before connecting to Affilync. The license enables the OAuth flow and determines your subscription tier.

### License Tab Overview

The License tab displays:
- Current license status with color-coded indicator
- License key (masked for security)
- Subscription plan name
- Expiration date
- Last verification timestamp

### Activating a New License

**Step 1**: Navigate to the License Tab
- Go to **WooCommerce > Affilync**
- Click the **License** tab

**Step 2**: Enter Your License Key
- Locate the "Activate License" section
- Enter your license key in the input field
- Format: `AFFILYNC-XXXX-XXXX-XXXX-XXXX`

**Step 3**: Click Activate
- Click the **Activate** button
- Wait for verification (2-5 seconds)
- The page will reload automatically on success

**Step 4**: Verify Activation
- Status should show "License Active" with a green indicator
- Your plan name and expiration date should be displayed

### Verifying Your License

To manually verify your license status:
1. Go to **WooCommerce > Affilync > License**
2. Click the **Verify License** button
3. The plugin will contact the license server and update the status

### Deactivating Your License

If you need to move the license to another site:
1. Go to **WooCommerce > Affilync > License**
2. Click **Deactivate License**
3. Confirm the deactivation
4. The license can now be activated on a different site

**Note**: Deactivating a license disconnects your store from Affilync. You'll need to reactivate and reconnect to resume tracking.

### License Status Reference

| Status | Meaning | Action Required |
|--------|---------|-----------------|
| License Active | Valid and verified | None |
| License Active (Verification Pending) | Temporarily unable to verify | Check internet connection |
| License Invalid | Key not recognized | Verify key is correct |
| License Expired | Subscription ended | Renew at affilync.com |
| License Suspended | Account issue | Contact support |

---

## Connecting to Affilync

After license activation, connect your store to your Affilync account using OAuth.

### Understanding the OAuth Flow

The plugin uses OAuth 2.0 with PKCE (Proof Key for Code Exchange) for secure authentication:

1. Plugin generates a secure state token and code verifier
2. You're redirected to Affilync to authorize the connection
3. After authorization, you're redirected back with an auth code
4. Plugin exchanges the code for access and refresh tokens
5. Tokens are encrypted and stored in your WordPress database

### Step-by-Step Connection

**Step 1**: Navigate to Settings Tab
- Go to **WooCommerce > Affilync > Settings**
- Locate the "Connection" section

**Step 2**: Initiate Connection
- Click the **Connect to Affilync** button
- You'll be redirected to the Affilync authorization page

**Step 3**: Log In to Affilync
- Log in with your Affilync credentials
- If you don't have an account, click "Sign Up"

**Step 4**: Authorize the Connection
- Review the permissions requested:
  - Read/write brand profile
  - Read/write products
  - Read/write conversions
  - Manage webhooks
- Click **Authorize** to grant access

**Step 5**: Complete Connection
- You'll be redirected back to your WordPress site
- A success message confirms the connection
- Your Brand ID will be displayed

### Connection Status Indicators

**Connected** (Green checkmark):
- Shows "Connected to Affilync"
- Displays your Brand ID
- Shows a "Disconnect" button

**Not Connected** (Red X):
- Shows "Not Connected"
- Displays "Connect to Affilync" button
- Tracking and sync features are disabled

### Disconnecting

To disconnect from Affilync:
1. Go to **WooCommerce > Affilync > Settings**
2. Click the **Disconnect** button
3. Confirm the disconnection
4. Your credentials will be cleared

**Warning**: Disconnecting stops all conversion tracking and product syncing. Existing data remains in your local database but is no longer synced to Affilync.

---

## Tracking Settings

Configure how affiliate conversions are tracked on your store.

### Enable Tracking

**Setting**: Enable Tracking
**Default**: Enabled

When enabled, the plugin:
- Captures affiliate parameters from URLs
- Stores tracking data in cookies
- Records conversions when orders complete
- Syncs conversion data to Affilync

### Cookie Duration

**Setting**: Cookie Duration
**Default**: 30 days
**Range**: 1-365 days

This determines how long tracking cookies persist. If a customer clicks an affiliate link and purchases within this window, the affiliate receives credit.

**Recommendations**:
- Standard e-commerce: 30 days
- High-consideration purchases: 60-90 days
- Low-cost impulse items: 7-14 days

### Conversion Order Statuses

**Setting**: Conversion Order Statuses
**Default**: Completed, Processing

Select which order statuses trigger conversion tracking:

| Status | Recommended For |
|--------|-----------------|
| Processing | Physical products (track at payment) |
| Completed | Digital products (track at delivery) |
| On Hold | Not recommended |
| Pending | Not recommended |

**Best Practice**: Select both "Processing" and "Completed" to capture conversions as early as possible while avoiding false positives from cancelled orders.

### Attribution Model

**Setting**: Attribution Model
**Default**: Last Click
**Options**: Last Click, First Click

| Model | Description | Best For |
|-------|-------------|----------|
| Last Click | Credit goes to the last affiliate link clicked | Standard affiliate programs |
| First Click | Credit goes to the first affiliate link clicked | Brand awareness campaigns |

### URL Parameters

The plugin tracks these URL parameters by default:
- `ref` - Affiliate reference ID
- `aff` - Affiliate ID
- `campaign` - Campaign ID
- `click_id` - Unique click identifier

Example tracked URL:
```
https://your-store.com/product?ref=affiliate123&campaign=summer2024
```

---

## Product Sync Settings

Configure automatic synchronization of your WooCommerce products to the Affilync marketplace.

### Enable Product Sync

**Setting**: Enable Product Sync
**Default**: Enabled

When enabled:
- New products are automatically synced
- Product updates trigger re-sync
- Deleted products are removed from Affilync

### Sync Interval

**Setting**: Sync Interval
**Default**: Hourly
**Options**: Hourly, Twice Daily, Daily

This controls how often the plugin checks for products that need syncing.

| Interval | Best For |
|----------|----------|
| Hourly | Stores with frequent product changes |
| Twice Daily | Medium-activity stores |
| Daily | Stable catalogs with rare changes |

### Product Sync Status

View sync status on the **Products** tab:

| Status | Meaning |
|--------|---------|
| Synced | Product successfully synced to Affilync |
| Pending | Product queued for sync |
| Failed | Sync failed, will retry automatically |

### Manual Sync

**Sync Now Button**: Triggers immediate sync of pending products

**Full Product Sync Button**: Forces re-sync of all products (use sparingly)

### What Gets Synced

For each product, the plugin syncs:
- Product name and description
- SKU
- Price (regular and sale)
- Images (primary and gallery)
- Categories
- Stock status
- Product URL
- Variations (for variable products)

---

## Webhook Configuration

Webhooks enable real-time updates from Affilync to your store.

### Automatic Registration

When you connect to Affilync, webhooks are automatically registered for:
- `conversion.approved` - Conversion approved by brand
- `conversion.rejected` - Conversion rejected
- `conversion.pending` - Conversion pending review
- `payout.processed` - Affiliate payout processed
- `campaign.updated` - Campaign settings changed
- `campaign.paused` - Campaign paused

### Webhook URL

Your webhook endpoint is:
```
https://your-site.com/wp-json/affilync/v1/webhooks/receive
```

This URL is automatically registered with Affilync during the connection process.

### Security

Webhooks are secured with:
- **HMAC Signature Verification**: Each webhook includes a signature header (`X-Affilync-Signature`)
- **Timestamp Validation**: Prevents replay attacks
- **Duplicate Detection**: Same webhook ID won't be processed twice
- **Rate Limiting**: Protects against webhook flooding

### Viewing Webhook Logs

1. Go to **WooCommerce > Affilync > Logs**
2. Review recent webhook deliveries
3. Check processing results and any errors

---

## Advanced Settings

### Remove Data on Uninstall

**Setting**: Remove Data on Uninstall
**Default**: Disabled

When enabled, uninstalling the plugin will:
- Delete all database tables
- Remove all plugin options
- Clear scheduled tasks

**Warning**: This permanently deletes conversion history and tracking data. Export any needed data before enabling this option.

### Debug Mode

To enable debug logging:

1. Add to `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

2. View logs at `wp-content/debug.log`

The plugin logs:
- API requests and responses
- Conversion tracking events
- Sync operations
- Errors and warnings

---

## Dashboard Widget

Affilync adds a dashboard widget showing key metrics at a glance.

### Widget Location

The widget appears on the WordPress admin dashboard (**Dashboard > Home**).

### Displayed Metrics

| Metric | Description |
|--------|-------------|
| Total Conversions | Conversions tracked this month |
| Revenue | Total order value from affiliate sales |
| Commission | Total commission earned by affiliates |
| Connection Status | Quick indicator of API connection |

### Widget Settings

The widget uses data from the last 30 days by default. To customize:

1. Click **Configure** on the widget
2. Select a different time period
3. Click **Save**

---

## Configuration Best Practices

### Initial Setup Checklist

1. [ ] Activate license key
2. [ ] Connect to Affilync account
3. [ ] Set cookie duration appropriate for your products
4. [ ] Select conversion order statuses
5. [ ] Enable product sync
6. [ ] Verify webhook registration
7. [ ] Test with a sample affiliate link

### Performance Optimization

- Use "Twice Daily" or "Daily" sync for stores with 1000+ products
- Keep audit logs for 90 days (default) to manage database size
- Monitor the Logs tab for any recurring errors

### Security Recommendations

- Always define `AFFILYNC_ENCRYPTION_KEY` in `wp-config.php`
- Keep your license key private
- Regularly verify webhook signatures are passing
- Review audit logs for suspicious activity

---

## Next Steps

- [API Reference](API.md) - Learn about available REST endpoints
- [Troubleshooting](TROUBLESHOOTING.md) - Solve common issues

---

## Getting Help

For configuration assistance:

- **Documentation**: https://docs.affilync.com/woocommerce
- **Support Email**: support@affilync.com
- **Support Portal**: https://affilync.com/support
