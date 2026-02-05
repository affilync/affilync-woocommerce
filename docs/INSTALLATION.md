# Affilync for WooCommerce - Installation Guide

This guide covers the complete installation process for the Affilync WooCommerce plugin, including system requirements, installation methods, and initial configuration.

## Table of Contents

- [System Requirements](#system-requirements)
- [Installation Methods](#installation-methods)
  - [Automatic Installation](#automatic-installation)
  - [Manual Installation](#manual-installation)
  - [FTP Installation](#ftp-installation)
- [wp-config.php Configuration](#wp-configphp-configuration)
- [License Activation](#license-activation)
- [Verifying Installation](#verifying-installation)
- [Troubleshooting Installation Issues](#troubleshooting-installation-issues)

---

## System Requirements

Before installing Affilync for WooCommerce, ensure your server meets these minimum requirements:

| Requirement | Minimum Version | Recommended |
|-------------|----------------|-------------|
| WordPress | 5.8+ | 6.4+ |
| WooCommerce | 5.0+ | 8.5+ |
| PHP | 7.4+ | 8.1+ |
| MySQL | 5.7+ | 8.0+ |
| Memory Limit | 128MB | 256MB+ |
| HTTPS | Required | Required |

### PHP Extensions Required

The following PHP extensions must be enabled:

- `openssl` - Required for encryption
- `json` - Required for API communication
- `curl` - Required for HTTP requests
- `mbstring` - Required for string handling

### Server Configuration

- **HTTPS Required**: The plugin requires HTTPS for secure OAuth authentication and API communication
- **Cron Jobs**: WordPress cron must be functional for scheduled tasks (product sync, conversion sync)
- **Outbound Connections**: Server must allow outbound HTTPS connections to `api.affilync.com`

---

## Installation Methods

### Automatic Installation

The easiest way to install Affilync for WooCommerce:

1. Log in to your WordPress admin dashboard
2. Navigate to **Plugins > Add New**
3. Search for "Affilync for WooCommerce"
4. Click **Install Now** on the Affilync plugin
5. Once installed, click **Activate**
6. You will be redirected to the Affilync settings page

### Manual Installation

If you received the plugin as a ZIP file:

1. Log in to your WordPress admin dashboard
2. Navigate to **Plugins > Add New**
3. Click **Upload Plugin** at the top of the page
4. Click **Choose File** and select the `affilync-woocommerce.zip` file
5. Click **Install Now**
6. Once the installation is complete, click **Activate Plugin**

### FTP Installation

For advanced users who prefer FTP:

1. Download and extract the plugin ZIP file
2. Connect to your server via FTP/SFTP
3. Navigate to `/wp-content/plugins/`
4. Upload the entire `affilync-woocommerce` folder
5. Log in to WordPress admin
6. Navigate to **Plugins > Installed Plugins**
7. Find "Affilync for WooCommerce" and click **Activate**

---

## wp-config.php Configuration

For enhanced security and advanced configuration, you can define constants in your `wp-config.php` file. Add these lines **before** the line that says `/* That's all, stop editing! */`:

### Required for Production

```php
/**
 * Affilync Encryption Key
 *
 * Generate a unique 64-character hex string for your site.
 * This key encrypts sensitive data like API credentials.
 *
 * IMPORTANT: Back up this key! If lost, you'll need to reconnect to Affilync.
 */
define( 'AFFILYNC_ENCRYPTION_KEY', 'your-64-character-hex-string-here' );
```

**Generating an Encryption Key:**

You can generate a secure encryption key using one of these methods:

```bash
# Using OpenSSL (Linux/Mac)
openssl rand -hex 32

# Using PHP
php -r "echo bin2hex(random_bytes(32));"
```

Example output: `a7b3c4d5e6f7g8h9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3`

### Optional Configuration

```php
/**
 * Affilync Client ID (Optional)
 *
 * If provided, this will be used instead of the license-provided client ID.
 * Only set this if instructed by Affilync support.
 */
define( 'AFFILYNC_CLIENT_ID', 'your-client-id' );

/**
 * Custom API URL (Optional)
 *
 * Override the default API URL. Only use for development/staging.
 * Default: https://api.affilync.com
 */
define( 'AFFILYNC_API_URL', 'https://api.affilync.com' );

/**
 * Production Mode (Optional)
 *
 * Enable strict security checks. When true, the plugin will disable
 * itself if integrity verification fails.
 */
define( 'AFFILYNC_PRODUCTION_MODE', true );
```

### Complete Example

```php
/**
 * Affilync Configuration
 * Add these constants before "That's all, stop editing!"
 */
define( 'AFFILYNC_ENCRYPTION_KEY', 'a7b3c4d5e6f7g8h9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3' );
define( 'AFFILYNC_PRODUCTION_MODE', true );

/* That's all, stop editing! Happy publishing. */
```

---

## License Activation

After installing the plugin, you must activate your license to enable full functionality.

### Steps to Activate

1. Navigate to **WooCommerce > Affilync** in your WordPress admin
2. Click the **License** tab
3. Enter your license key in the format: `AFFILYNC-XXXX-XXXX-XXXX-XXXX`
4. Click **Activate**
5. Wait for verification (usually 2-5 seconds)
6. Upon success, the page will reload showing "License Active"

### License Key Format

Your license key follows this pattern:
```
AFFILYNC-XXXX-XXXX-XXXX-XXXX
```

Where each `X` is an alphanumeric character (A-Z, 0-9).

### What Happens During Activation

1. The plugin sends your license key and site URL to the Affilync license server
2. The server verifies the license is valid and not already activated on another site
3. If valid, the server returns a client ID for OAuth authentication
4. The plugin stores the encrypted license information locally
5. A verification cron job is scheduled to run daily

### License Status Indicators

| Status | Color | Description |
|--------|-------|-------------|
| License Active | Green | Your license is valid and verified |
| License Active (Verification Pending) | Yellow | Unable to verify, in grace period |
| License Invalid | Red | License key is not recognized |
| License Expired | Red | License has expired, requires renewal |
| License Suspended | Red | License suspended, contact support |
| No License Activated | Blue | No license entered yet |

---

## Verifying Installation

After installation and license activation, verify everything is working correctly:

### 1. Check Plugin Status

Navigate to **WooCommerce > Affilync > Settings**:

- Verify the connection status shows **Connected to Affilync**
- Check that no error notices appear at the top of the page

### 2. Check System Status

The plugin creates several database tables during activation:

| Table | Purpose |
|-------|---------|
| `wp_affilync_conversions` | Stores conversion tracking data |
| `wp_affilync_product_sync` | Tracks product synchronization |
| `wp_affilync_webhooks` | Logs incoming webhook events |
| `wp_affilync_audit_log` | Security audit trail |
| `wp_affilync_rate_limits` | Rate limiting data |
| `wp_affilync_nonces` | OAuth security nonces |

### 3. Test API Connection

1. Go to **WooCommerce > Affilync > Settings**
2. In the Connection section, your Brand ID should be displayed
3. If not connected, click **Connect to Affilync**

### 4. Verify Scheduled Tasks

Navigate to **Tools > Scheduled Actions** (if using WooCommerce) or use a cron viewer plugin to verify these tasks are scheduled:

- `affilync_sync_products` - Hourly product sync
- `affilync_sync_conversions` - Hourly conversion sync
- `affilync_cleanup_expired` - Daily cleanup of expired data

### 5. Check REST API Availability

Test the health endpoint by visiting:
```
https://your-site.com/wp-json/affilync/v1/health
```

Expected response:
```json
{
    "status": "ok",
    "version": "1.0.0",
    "timestamp": "2024-01-15T10:30:00+00:00"
}
```

---

## Troubleshooting Installation Issues

### Plugin Won't Activate

**Error: "Plugin could not be activated because it triggered a fatal error."**

- Ensure PHP version is 7.4 or higher
- Verify WooCommerce is installed and active
- Check error logs in `wp-content/debug.log`

**To enable error logging:**
```php
// Add to wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

### Database Tables Not Created

If database tables weren't created during activation:

1. Deactivate the plugin
2. Delete the plugin
3. Reinstall and reactivate

Or manually trigger table creation via WP-CLI:
```bash
wp eval "affilync()->activate();"
```

### Cannot Connect to Affilync

1. Verify your site uses HTTPS
2. Check that your server can make outbound HTTPS connections
3. Verify your license is activated
4. Check firewall rules allow connections to `api.affilync.com`

### Encryption Key Issues

If you see errors about encryption:

1. Ensure `AFFILYNC_ENCRYPTION_KEY` is defined in `wp-config.php`
2. Verify the key is exactly 64 hexadecimal characters
3. The key should only contain characters 0-9 and a-f

### Permission Issues

Ensure the web server has write permissions to:
- `wp-content/uploads/` (for temporary files)
- WordPress database tables

---

## Next Steps

Once installation is complete:

1. **Configure Settings**: See [CONFIGURATION.md](CONFIGURATION.md) for detailed settings
2. **Connect Account**: Complete the OAuth connection to your Affilync account
3. **Enable Tracking**: Configure conversion tracking settings
4. **Sync Products**: Set up automatic product synchronization

---

## Getting Help

If you encounter issues during installation:

- **Documentation**: https://docs.affilync.com/woocommerce
- **Support Email**: support@affilync.com
- **Support Portal**: https://affilync.com/support

When contacting support, please include:
- WordPress version
- WooCommerce version
- PHP version
- Error messages (from debug log)
- Server environment details
