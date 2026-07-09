# Affilync for WooCommerce

Connect your WooCommerce store to the Affilync affiliate marketing platform for powerful conversion tracking, product synchronization, and marketing automation.

[![WordPress](https://img.shields.io/badge/WordPress-5.8+-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0+-purple.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

## Features

### Core Functionality
- **Automatic Conversion Tracking** - Track affiliate sales with sub-100ms precision
- **Product Synchronization** - Keep your Affilync marketplace in sync with WooCommerce inventory
- **Secure OAuth Connection** - Enterprise-grade encryption for API credentials
- **Real-time Webhooks** - Instant updates for orders, refunds, and product changes
- **Advanced Attribution** - First-click, last-click, and multi-touch attribution models
- **HPOS Compatible** - Works with WooCommerce High-Performance Order Storage

### Security
- AES-256-GCM encryption for all sensitive data
- One-time nonces with automatic expiration
- Rate limiting to prevent abuse
- HMAC signature verification for webhooks
- Full audit logging for security events
- Brand verification for merchant identity

### Subscription Plans

| Plan | Price | Conversions/Month | Products | Trial |
|------|-------|-------------------|----------|-------|
| Free | $0 | 50 | 100 | - |
| Starter | $29 | 500 | 1,000 | 14 days |
| Pro | $99 | 5,000 | 10,000 | 14 days |
| Enterprise | $299 | Unlimited | Unlimited | 30 days |

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- An Affilync account ([sign up free](https://affilync.com))

## Installation

### From WordPress Admin

1. Log in to your WordPress dashboard
2. Navigate to **Plugins > Add New**
3. Search for "Affilync for WooCommerce"
4. Click **Install Now** then **Activate**
5. Go to **WooCommerce > Affilync** to connect your account

### Manual Installation

1. Download the plugin ZIP file
2. Log in to your WordPress dashboard
3. Navigate to **Plugins > Add New > Upload Plugin**
4. Choose the ZIP file and click **Install Now**
5. Activate the plugin
6. Go to **WooCommerce > Affilync** to connect your account

### From Source

```bash
# Clone the repository
git clone https://github.com/affilync/affilync-woocommerce.git

# Navigate to your WordPress plugins directory
cd /path/to/wordpress/wp-content/plugins/

# Copy the plugin
cp -r /path/to/affilync-woocommerce .

# Activate via WP-CLI
wp plugin activate affilync-woocommerce
```

## Configuration

### 1. Connect to Affilync

1. Navigate to **WooCommerce > Affilync** in your admin menu
2. Click **Connect to Affilync**
3. Log in to your Affilync account and authorize the connection
4. You'll be redirected back to your store

### 2. Configure Tracking

| Setting | Description | Default |
|---------|-------------|---------|
| Enable Tracking | Track affiliate conversions | Enabled |
| Cookie Duration | Days to remember affiliate attribution | 30 |
| Attribution Model | First-click or last-click | Last-click |
| Track Statuses | Order statuses that trigger conversions | Completed, Processing |

### 3. Configure Product Sync

| Setting | Description | Default |
|---------|-------------|---------|
| Auto Sync | Automatically sync product changes | Enabled |
| Sync Interval | How often to check for changes | Hourly |
| Include Variants | Sync product variations | Enabled |

## How It Works

### Conversion Tracking

1. Visitor arrives via affiliate link (`?ref=ABC123`)
2. Plugin stores attribution in cookie
3. Customer completes purchase
4. Conversion tracked and synced to Affilync
5. Commission calculated and attributed to affiliate

```
Affiliate Link → Cookie Storage → Purchase → Conversion → Commission
```

### Product Synchronization

Products are automatically synced when:
- Created in WooCommerce
- Updated (price, description, stock)
- Deleted or trashed

The plugin uses hash-based change detection to minimize API calls.

## REST API Endpoints

The plugin registers the following REST endpoints:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/affilync/v1/oauth/initiate` | POST | Start OAuth flow |
| `/affilync/v1/oauth/disconnect` | POST | Revoke connection |
| `/affilync/v1/webhooks/register` | POST | Register webhooks |
| `/affilync/v1/conversions` | GET | List conversions |
| `/affilync/v1/conversions/sync` | POST | Sync conversions |
| `/affilync/v1/products/sync` | GET/POST | Product sync status/trigger |
| `/affilync/v1/settings` | POST | Update settings |
| `/affilync/v1/health` | GET | Health check |
| `/affilync/v1/status` | GET | Plugin status |

## JavaScript API

The tracking script exposes a public API:

```javascript
// Get current tracking data
const data = window.AffilyncTracking.getTrackingData();

// Manually track a conversion
window.AffilyncTracking.trackConversion({
    orderId: '12345',
    total: 99.99,
    currency: 'USD'
});

// Check if affiliate is set
if (window.AffilyncTracking.hasAffiliate()) {
    console.log('Affiliate:', window.AffilyncTracking.getAffiliateId());
}
```

## Database Tables

The plugin creates the following tables:

| Table | Purpose |
|-------|---------|
| `{prefix}_affilync_conversions` | Stores conversion data |
| `{prefix}_affilync_product_sync` | Tracks product sync status |
| `{prefix}_affilync_webhooks` | Logs incoming webhooks |
| `{prefix}_affilync_audit_log` | Security audit trail |
| `{prefix}_affilync_rate_limits` | Rate limiting data |
| `{prefix}_affilync_nonces` | OAuth nonce storage |

## Hooks & Filters

### Actions

```php
// Fired when plugin is initialized
do_action('affilync_woocommerce_init');

// Fired when a conversion is tracked
do_action('affilync_conversion_tracked', $order_id, $conversion_data);

// Fired when a product is synced
do_action('affilync_product_synced', $product_id, $affilync_product_id);

// Fired when connection status changes
do_action('affilync_connection_changed', $status);
```

### Filters

```php
// Modify conversion data before sending
add_filter('affilync_conversion_data', function($data, $order) {
    $data['custom_field'] = 'value';
    return $data;
}, 10, 2);

// Modify product data before syncing
add_filter('affilync_product_sync_data', function($data, $product) {
    $data['extra_info'] = get_post_meta($product->get_id(), 'extra_info', true);
    return $data;
}, 10, 2);

// Customize tracking parameters
add_filter('affilync_tracking_params', function($params) {
    $params[] = 'custom_param';
    return $params;
});

// Modify cookie duration
add_filter('affilync_cookie_duration', function($days) {
    return 60; // 60 days
});
```

## Development

### Setup

```bash
# Clone repository
git clone https://github.com/affilync/affilync-woocommerce.git
cd affilync-woocommerce

# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Check code style
./vendor/bin/phpcs
```

### Project Structure

```
affilync-woocommerce/
├── affilync-woocommerce.php    # Main plugin file
├── uninstall.php               # Cleanup on uninstall
├── includes/
│   ├── admin/                  # Admin settings, dashboard widget
│   ├── api/                    # API client, OAuth, REST, webhooks
│   ├── billing/                # Subscription management
│   ├── notifications/          # Email notifications (Brevo)
│   ├── security/               # Encryption, nonces, rate limiting, HMAC
│   ├── sync/                   # Product synchronization
│   ├── tracking/               # Conversion tracker, cookies
│   ├── verification/           # Brand verification
│   └── helpers/                # Logger, utilities
├── assets/
│   ├── css/                    # Admin styles
│   ├── js/                     # Admin & tracking scripts
│   └── images/                 # Icons and graphics
├── templates/                  # Email templates
├── tests/
│   ├── unit/                   # Unit tests
│   ├── integration/            # Integration tests
│   └── mocks/                  # Test mocks
└── languages/                  # Translations
```

### Running Tests

```bash
# All tests
./vendor/bin/phpunit

# Specific test file
./vendor/bin/phpunit tests/unit/test-billing-subscription.php

# With coverage
./vendor/bin/phpunit --coverage-html coverage/

# Integration tests (requires WordPress test environment)
./vendor/bin/phpunit --testsuite integration
```

### Code Style

The plugin follows WordPress Coding Standards:

```bash
# Check code style
./vendor/bin/phpcs --standard=WordPress .

# Auto-fix issues
./vendor/bin/phpcbf --standard=WordPress .
```

## Troubleshooting

### Connection Issues

**"Failed to connect to Affilync"**
- Check your internet connection
- Verify Affilync API is accessible: `curl https://api.affilync.com/health`
- Check for firewall blocking outbound requests

**"Invalid OAuth state"**
- Clear browser cookies and try again
- Ensure your site uses HTTPS

### Conversion Tracking Issues

**Conversions not tracking**
- Verify tracking is enabled in settings
- Check that affiliate cookies are not blocked
- Ensure order status matches configured statuses
- Check browser console for JavaScript errors

**Duplicate conversions**
- The plugin prevents duplicates automatically
- Check `_affilync_tracked` order meta

### Product Sync Issues

**Products not syncing**
- Check if auto-sync is enabled
- Manually trigger sync from settings
- Check error logs: **WooCommerce > Status > Logs**
- Verify API rate limits not exceeded

### Debug Mode

Enable debug logging:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('AFFILYNC_DEBUG', true);
```

Logs are written to `wp-content/debug.log` and WooCommerce logs.

## Frequently Asked Questions

### Do I need an Affilync account?

Yes, you need a free Affilync account to use this plugin. [Sign up here](https://affilync.com).

### Is my data secure?

Yes. We use AES-256-GCM encryption for sensitive data, HMAC signature verification for webhooks, and all data is transmitted over HTTPS.

### Does this work with WooCommerce Subscriptions?

Yes, the plugin tracks both one-time and recurring subscription orders.

### Can I customize the attribution window?

Yes, you can configure the cookie duration (default 30 days) and attribution model in settings.

### How are commissions calculated?

Commissions are calculated based on the campaign settings in Affilync. The default is a percentage of the order subtotal (excluding tax and shipping).

### Does product sync include variations?

Yes, product variations are synced as separate products with parent-child relationships.

## Support

- **Documentation:** [docs.affilync.com](https://docs.affilync.com)
- **Support Portal:** [support.affilync.com](https://support.affilync.com)
- **Email:** support@affilync.com
- **GitHub Issues:** [Report a bug](https://github.com/affilync/affilync-woocommerce/issues)

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Commit changes: `git commit -m 'Add my feature'`
4. Push to branch: `git push origin feature/my-feature`
5. Open a Pull Request

## Changelog

### 1.0.0 (2026-02-04)
- Initial release
- OAuth 2.0 PKCE authentication
- Automatic conversion tracking
- Product synchronization
- Webhook handling
- Admin dashboard widget
- Full security suite
- HPOS compatibility
- Subscription billing (4 tiers)
- Brand verification
- Email notifications (Brevo)

## License

This plugin is licensed under the GPL v2 or later.

```
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.
```

## Credits

- Built by the [Affilync](https://affilync.com) team
- Uses [WooCommerce](https://woocommerce.com) hooks and APIs
- Email delivery powered by [Brevo](https://brevo.com)

---

**Made with love for WooCommerce merchants worldwide.**
