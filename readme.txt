=== Affilync for WooCommerce ===
Contributors: affilync
Donate link: https://affilync.com
Tags: affiliate, marketing, tracking, woocommerce, conversions
Requires at least: 5.8
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to Affilync for powerful affiliate tracking, product sync, and marketing automation.

== Description ==

**Affilync for WooCommerce** seamlessly integrates your WooCommerce store with the Affilync affiliate marketing platform. Track conversions, sync products, and grow your affiliate program with enterprise-grade security and real-time analytics.

= Key Features =

* **Automatic Conversion Tracking** - Track affiliate sales with sub-100ms precision
* **Product Synchronization** - Keep your Affilync marketplace in sync with WooCommerce inventory
* **Secure OAuth Connection** - Enterprise-grade encryption for API credentials
* **Real-time Webhooks** - Instant updates for orders, refunds, and product changes
* **Advanced Attribution** - First-click, last-click, and multi-touch attribution models
* **Fraud Prevention** - Built-in bot detection and click validation
* **HPOS Compatible** - Works with WooCommerce High-Performance Order Storage

= Security First =

Built with security as a foundation:

* AES-256-GCM encryption for all sensitive data
* One-time nonces with automatic expiration
* Rate limiting to prevent abuse
* HMAC signature verification for webhooks
* Full audit logging

= How It Works =

1. Install and activate the plugin
2. Connect to your Affilync account via secure OAuth
3. Configure tracking settings
4. Products automatically sync to Affilync
5. Affiliate conversions are tracked in real-time

= Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* An Affilync account (free to create)

== Installation ==

= Automatic Installation =

1. Log in to your WordPress dashboard
2. Navigate to Plugins > Add New
3. Search for "Affilync for WooCommerce"
4. Click "Install Now" then "Activate"
5. Go to Affilync > Settings to connect your account

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress dashboard
3. Navigate to Plugins > Add New > Upload Plugin
4. Choose the ZIP file and click "Install Now"
5. Activate the plugin
6. Go to Affilync > Settings to connect your account

= Configuration =

After activation:

1. Navigate to **WooCommerce > Affilync** in your admin menu
2. Click "Connect to Affilync" to start the OAuth flow
3. Log in to your Affilync account and authorize the connection
4. Configure your tracking preferences
5. Enable product sync if desired

== Frequently Asked Questions ==

= Do I need an Affilync account? =

Yes, you need a free Affilync account to use this plugin. Sign up at [affilync.com](https://affilync.com).

= Is my data secure? =

Absolutely. We use AES-256-GCM encryption for all sensitive data, HMAC signature verification for webhooks, and implement strict rate limiting. All data transmission uses HTTPS.

= Does this work with WooCommerce HPOS? =

Yes! The plugin is fully compatible with WooCommerce High-Performance Order Storage (HPOS).

= How are conversions tracked? =

The plugin captures affiliate tracking parameters (ref, aff, campaign) from URLs and stores them in cookies. When an order is completed, the conversion is attributed to the affiliate and synced to Affilync.

= Can I customize the attribution window? =

Yes, you can configure the cookie duration (default 30 days) and attribution model in the plugin settings.

= Does product sync happen automatically? =

Products sync automatically when created, updated, or deleted in WooCommerce. You can also trigger a manual bulk sync from the settings page.

= What order statuses trigger conversions? =

By default, conversions are tracked when orders reach "completed" or "processing" status. This is configurable in the settings.

== Screenshots ==

1. Dashboard widget showing conversion stats
2. Settings page with connection status
3. Conversion tracking configuration
4. Product sync status overview
5. Audit log for security events

== Changelog ==

= 1.0.0 =
* Initial release
* OAuth integration with Affilync API
* Automatic conversion tracking
* Product synchronization
* Webhook handling
* Admin dashboard widget
* Full security suite (encryption, nonces, rate limiting, HMAC)
* HPOS compatibility

== Upgrade Notice ==

= 1.0.0 =
Initial release of Affilync for WooCommerce.

== Privacy Policy ==

This plugin stores:

* **Affiliate tracking data** - Affiliate IDs, campaign IDs, and click IDs from URL parameters, stored in cookies and order meta
* **Conversion data** - Order totals, commission amounts, and attribution data synced to Affilync
* **Product data** - Product names, descriptions, prices, and images synced to Affilync marketplace
* **Audit logs** - Security events including connection attempts and webhook deliveries

Data is transmitted securely to Affilync servers (api.affilync.com) for processing. See our [Privacy Policy](https://affilync.com/privacy) for details.
