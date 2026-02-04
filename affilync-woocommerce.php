<?php
/**
 * Affilync WooCommerce Integration
 *
 * @package           Affilync_WooCommerce
 * @author            Affilync
 * @copyright         2024 Affilync
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Affilync for WooCommerce
 * Plugin URI:        https://affilync.com/integrations/woocommerce
 * Description:       Connect your WooCommerce store to Affilync for powerful affiliate tracking, product sync, and marketing automation.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Affilync
 * Author URI:        https://affilync.com
 * Text Domain:       affilync-woocommerce
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 5.0
 * WC tested up to:   8.5
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin constants.
 */
define( 'AFFILYNC_VERSION', '1.0.0' );
define( 'AFFILYNC_PLUGIN_FILE', __FILE__ );
define( 'AFFILYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFFILYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AFFILYNC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * API Configuration.
 */
define( 'AFFILYNC_API_URL', 'https://api.affilync.com' );
define( 'AFFILYNC_APP_URL', 'https://app.affilync.com' );

/**
 * Autoloader for plugin classes.
 */
spl_autoload_register( function( $class ) {
    // Only autoload our classes.
    if ( strpos( $class, 'Affilync_' ) !== 0 ) {
        return;
    }

    // Convert class name to file path.
    $class_file = str_replace( '_', '-', strtolower( $class ) );
    $class_file = 'class-' . $class_file . '.php';

    // Map class prefixes to directories.
    $prefix_map = array(
        'Affilync_Admin'    => 'admin',
        'Affilync_API'      => 'api',
        'Affilync_Security' => 'security',
        'Affilync_Tracking' => 'tracking',
        'Affilync_Sync'     => 'sync',
        'Affilync_Helper'   => 'helpers',
    );

    $file_path = null;
    foreach ( $prefix_map as $prefix => $dir ) {
        if ( strpos( $class, $prefix ) === 0 ) {
            $file_path = AFFILYNC_PLUGIN_DIR . 'includes/' . $dir . '/' . $class_file;
            break;
        }
    }

    // Default to includes directory.
    if ( ! $file_path ) {
        $file_path = AFFILYNC_PLUGIN_DIR . 'includes/' . $class_file;
    }

    if ( file_exists( $file_path ) ) {
        require_once $file_path;
    }
});

/**
 * Main plugin class.
 */
final class Affilync_WooCommerce {

    /**
     * Plugin instance.
     *
     * @var Affilync_WooCommerce
     */
    private static $instance = null;

    /**
     * Encryption handler.
     *
     * @var Affilync_Security_Encryption
     */
    public $encryption;

    /**
     * Nonce manager.
     *
     * @var Affilync_Security_Nonce_Manager
     */
    public $nonce_manager;

    /**
     * Rate limiter.
     *
     * @var Affilync_Security_Rate_Limiter
     */
    public $rate_limiter;

    /**
     * HMAC validator.
     *
     * @var Affilync_Security_HMAC_Validator
     */
    public $hmac_validator;

    /**
     * Audit logger.
     *
     * @var Affilync_Security_Audit_Logger
     */
    public $audit_logger;

    /**
     * API Client.
     *
     * @var Affilync_API_Client
     */
    public $api_client;

    /**
     * Conversion tracker.
     *
     * @var Affilync_Tracking_Conversion_Tracker
     */
    public $conversion_tracker;

    /**
     * Product sync.
     *
     * @var Affilync_Sync_Product_Sync
     */
    public $product_sync;

    /**
     * Subscription billing.
     *
     * @var Affilync_Billing_Subscription
     */
    public $subscription;

    /**
     * Get plugin instance.
     *
     * @return Affilync_WooCommerce
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files.
     */
    private function includes() {
        // Security classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-encryption.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-nonce-manager.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-rate-limiter.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-hmac-validator.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-audit-logger.php';

        // API classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-client.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-oauth.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-webhook-handler.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-rest-controller.php';

        // Tracking classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/tracking/class-affilync-tracking-conversion-tracker.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/tracking/class-affilync-tracking-cookie-handler.php';

        // Sync classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/sync/class-affilync-sync-product-sync.php';

        // Billing classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/billing/class-affilync-billing-subscription.php';

        // Admin classes.
        if ( is_admin() ) {
            require_once AFFILYNC_PLUGIN_DIR . 'includes/admin/class-affilync-admin-settings.php';
            require_once AFFILYNC_PLUGIN_DIR . 'includes/admin/class-affilync-admin-dashboard-widget.php';
        }

        // Helper classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/helpers/class-affilync-helper-logger.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Activation/Deactivation hooks.
        register_activation_hook( AFFILYNC_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( AFFILYNC_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Initialize plugin.
        add_action( 'plugins_loaded', array( $this, 'init' ), 20 );

        // Declare HPOS compatibility.
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

        // Load text domain.
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Add plugin action links.
        add_filter( 'plugin_action_links_' . AFFILYNC_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
    }

    /**
     * Initialize plugin components.
     */
    public function init() {
        // Check WooCommerce is active.
        if ( ! $this->check_woocommerce() ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        // Initialize security components.
        $this->encryption     = new Affilync_Security_Encryption();
        $this->nonce_manager  = new Affilync_Security_Nonce_Manager();
        $this->rate_limiter   = new Affilync_Security_Rate_Limiter();
        $this->hmac_validator = new Affilync_Security_HMAC_Validator();
        $this->audit_logger   = new Affilync_Security_Audit_Logger();

        // Initialize API components.
        $this->api_client = new Affilync_API_Client( $this->encryption, $this->rate_limiter );

        // Initialize tracking.
        $this->conversion_tracker = new Affilync_Tracking_Conversion_Tracker(
            $this->api_client,
            $this->audit_logger
        );

        // Initialize product sync.
        $this->product_sync = new Affilync_Sync_Product_Sync( $this->api_client );

        // Initialize subscription billing.
        $this->subscription = new Affilync_Billing_Subscription( $this->api_client, $this->encryption );

        // Initialize REST API.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Initialize admin.
        if ( is_admin() ) {
            new Affilync_Admin_Settings();
            new Affilync_Admin_Dashboard_Widget();
        }

        // Initialize OAuth handler.
        new Affilync_API_OAuth( $this->nonce_manager, $this->encryption, $this->api_client );

        // Initialize webhook handler.
        new Affilync_API_Webhook_Handler( $this->hmac_validator, $this->audit_logger );

        /**
         * Fires after Affilync WooCommerce plugin is fully initialized.
         *
         * @since 1.0.0
         */
        do_action( 'affilync_woocommerce_init' );
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        $controller = new Affilync_API_REST_Controller(
            $this->nonce_manager,
            $this->rate_limiter,
            $this->hmac_validator,
            $this->api_client,
            $this->audit_logger
        );
        $controller->register_routes();
    }

    /**
     * Check if WooCommerce is active.
     *
     * @return bool
     */
    private function check_woocommerce() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * WooCommerce missing notice.
     */
    public function woocommerce_missing_notice() {
        /* translators: 1: Plugin name, 2: WooCommerce link */
        $message = sprintf(
            esc_html__( '%1$s requires %2$s to be installed and active.', 'affilync-woocommerce' ),
            '<strong>Affilync for WooCommerce</strong>',
            '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
        );
        printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
    }

    /**
     * Declare HPOS compatibility.
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                AFFILYNC_PLUGIN_FILE,
                true
            );
        }
    }

    /**
     * Load plugin text domain.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'affilync-woocommerce',
            false,
            dirname( AFFILYNC_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        // Create database tables.
        $this->create_tables();

        // Schedule cron events.
        $this->schedule_cron_events();

        // Set activation flag for redirect.
        set_transient( 'affilync_activation_redirect', true, 30 );

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        // Clear scheduled events.
        $this->clear_cron_events();

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Create database tables.
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = array();

        // Conversions table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}affilync_conversions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            affiliate_id varchar(64) DEFAULT NULL,
            campaign_id varchar(64) DEFAULT NULL,
            link_id varchar(64) DEFAULT NULL,
            click_id varchar(64) DEFAULT NULL,
            order_total decimal(19,4) NOT NULL DEFAULT 0,
            commission_amount decimal(19,4) NOT NULL DEFAULT 0,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            status varchar(32) NOT NULL DEFAULT 'pending',
            attribution_data longtext DEFAULT NULL,
            synced_to_api tinyint(1) NOT NULL DEFAULT 0,
            api_conversion_id varchar(64) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY affiliate_id (affiliate_id),
            KEY status (status),
            KEY synced_to_api (synced_to_api)
        ) $charset_collate;";

        // Product sync table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}affilync_product_sync (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            affilync_product_id varchar(64) DEFAULT NULL,
            sync_status varchar(32) NOT NULL DEFAULT 'pending',
            last_synced_at datetime DEFAULT NULL,
            sync_hash varchar(64) DEFAULT NULL,
            error_message text DEFAULT NULL,
            retry_count int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_id (product_id),
            KEY sync_status (sync_status),
            KEY affilync_product_id (affilync_product_id)
        ) $charset_collate;";

        // Webhooks table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}affilync_webhooks (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            webhook_id varchar(64) NOT NULL,
            event_type varchar(64) NOT NULL,
            payload longtext NOT NULL,
            signature varchar(255) DEFAULT NULL,
            signature_valid tinyint(1) DEFAULT NULL,
            processed tinyint(1) NOT NULL DEFAULT 0,
            processing_result text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY webhook_id (webhook_id),
            KEY event_type (event_type),
            KEY processed (processed)
        ) $charset_collate;";

        // Audit log table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}affilync_audit_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(64) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            details longtext DEFAULT NULL,
            severity varchar(16) NOT NULL DEFAULT 'info',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY severity (severity),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Rate limits table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}affilync_rate_limits (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            identifier varchar(255) NOT NULL,
            action_type varchar(64) NOT NULL,
            request_count int(11) NOT NULL DEFAULT 0,
            window_start datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY identifier_action (identifier(191), action_type),
            KEY window_start (window_start)
        ) $charset_collate;";

        // OAuth nonces table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}affilync_nonces (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            nonce varchar(64) NOT NULL,
            nonce_data longtext DEFAULT NULL,
            expires_at datetime NOT NULL,
            used tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY nonce (nonce),
            KEY expires_at (expires_at),
            KEY used (used)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ( $sql as $query ) {
            dbDelta( $query );
        }

        // Store database version.
        update_option( 'affilync_db_version', AFFILYNC_VERSION );
    }

    /**
     * Schedule cron events.
     */
    private function schedule_cron_events() {
        if ( ! wp_next_scheduled( 'affilync_sync_products' ) ) {
            wp_schedule_event( time(), 'hourly', 'affilync_sync_products' );
        }

        if ( ! wp_next_scheduled( 'affilync_sync_conversions' ) ) {
            wp_schedule_event( time(), 'hourly', 'affilync_sync_conversions' );
        }

        if ( ! wp_next_scheduled( 'affilync_cleanup_expired' ) ) {
            wp_schedule_event( time(), 'daily', 'affilync_cleanup_expired' );
        }
    }

    /**
     * Clear cron events.
     */
    private function clear_cron_events() {
        wp_clear_scheduled_hook( 'affilync_sync_products' );
        wp_clear_scheduled_hook( 'affilync_sync_conversions' );
        wp_clear_scheduled_hook( 'affilync_cleanup_expired' );
    }

    /**
     * Plugin action links.
     *
     * @param array $links Plugin action links.
     * @return array
     */
    public function plugin_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . esc_url( admin_url( 'admin.php?page=affilync-settings' ) ) . '">' .
            esc_html__( 'Settings', 'affilync-woocommerce' ) . '</a>',
        );
        return array_merge( $plugin_links, $links );
    }
}

/**
 * Get the main plugin instance.
 *
 * @return Affilync_WooCommerce
 */
function affilync() {
    return Affilync_WooCommerce::instance();
}

// Initialize the plugin.
affilync();
