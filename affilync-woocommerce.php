<?php
/**
 * Affilync WooCommerce Integration
 *
 * @package           Affilync_WooCommerce
 * @author            Affilync
 * @copyright         2024-2026 Affilync. All Rights Reserved.
 * @license           Proprietary
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
 * License:           Proprietary - See LICENSE.txt
 * License URI:       https://affilync.com/legal/plugin-license
 * WC requires at least: 5.0
 * WC tested up to:   8.5
 *
 * PROPRIETARY SOFTWARE LICENSE
 *
 * This software is protected by copyright law and international treaties.
 * Unauthorized reproduction, distribution, modification, or reverse engineering
 * of this software, or any portion of it, is strictly prohibited and may result
 * in severe civil and criminal penalties.
 *
 * This software requires a valid license key for operation. Each license is
 * valid for a single WordPress installation and is bound to the licensed domain.
 *
 * For licensing inquiries: licensing@affilync.com
 * For support: support@affilync.com
 *
 * (c) 2024-2026 Affilync. All Rights Reserved.
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
 * API Configuration (can be overridden in wp-config.php).
 */
if ( ! defined( 'AFFILYNC_API_URL' ) ) {
    define( 'AFFILYNC_API_URL', 'https://api.affilync.com' );
}
if ( ! defined( 'AFFILYNC_APP_URL' ) ) {
    define( 'AFFILYNC_APP_URL', 'https://app.affilync.com' );
}
if ( ! defined( 'AFFILYNC_CALL_TRACKER_URL' ) ) {
    define( 'AFFILYNC_CALL_TRACKER_URL', 'https://call.affilync.com' );
}

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
        'Affilync_Multisite'    => 'multisite',
        'Affilync_Network'      => 'admin',
        'Affilync_Admin'        => 'admin',
        'Affilync_API'          => 'api',
        'Affilync_Security'     => 'security',
        'Affilync_Tracking'     => 'tracking',
        'Affilync_Sync'         => 'sync',
        'Affilync_Helper'       => 'helpers',
        'Affilync_Billing'       => 'billing',
        'Affilync_CallTracking'  => 'calltracking',
        'Affilync_Verification'  => 'verification',
        'Affilync_Notification' => 'notifications',
        'Affilync_License'      => 'licensing',
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
     * Brand verification.
     *
     * @var Affilync_Verification_Brand
     */
    public $brand_verification;

    /**
     * Email notifications.
     *
     * @var Affilync_Notification_Email
     */
    public $email_notifications;

    /**
     * License manager.
     *
     * @var Affilync_License_Manager
     */
    public $license_manager;

    /**
     * AJAX handlers.
     *
     * @var Affilync_Admin_Ajax
     */
    public $ajax;

    /**
     * Webhook handler.
     *
     * @var Affilync_API_Webhook_Handler
     */
    public $webhook_handler;

    /**
     * Webhook queue.
     *
     * @var Affilync_Webhook_Queue
     */
    public $webhook_queue;

    /**
     * Multisite handler.
     *
     * @var Affilync_Multisite|null
     */
    public $multisite;

    /**
     * Call tracker.
     *
     * @var Affilync_CallTracking_Tracker|null
     */
    public $call_tracker;

    /**
     * Integrity checker.
     *
     * @var Affilync_Security_Integrity
     */
    public $integrity;

    /**
     * Whether the license is valid.
     *
     * @var bool
     */
    private $license_valid = false;

    /**
     * Whether the upgrade check has already run this request.
     *
     * @var bool
     */
    private $upgrade_checked = false;

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
        // Security classes (load first for encryption).
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-encryption.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-nonce-manager.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-rate-limiter.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-hmac-validator.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-audit-logger.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-integrity.php';

        // Licensing classes (load early for validation).
        require_once AFFILYNC_PLUGIN_DIR . 'includes/licensing/class-affilync-license-manager.php';

        // API classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-client.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-oauth.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-webhook-handler.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-rest-controller.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-webhook-queue.php';

        // Tracking classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/tracking/class-affilync-tracking-conversion-tracker.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/tracking/class-affilync-tracking-cookie-handler.php';

        // Sync classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/sync/class-affilync-sync-product-sync.php';

        // Billing classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/billing/class-affilync-billing-subscription.php';

        // Call tracking classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/calltracking/class-affilync-calltracking-call-log.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/calltracking/class-affilync-calltracking-stats.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/calltracking/class-affilync-calltracking-dni-injector.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/calltracking/class-affilync-calltracking-tracker.php';

        // Verification classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/verification/class-affilync-verification-brand.php';

        // Notification classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/notifications/class-affilync-notification-email.php';

        // Admin classes.
        if ( is_admin() ) {
            require_once AFFILYNC_PLUGIN_DIR . 'includes/admin/class-affilync-admin-settings.php';
            require_once AFFILYNC_PLUGIN_DIR . 'includes/admin/class-affilync-admin-dashboard-widget.php';
            require_once AFFILYNC_PLUGIN_DIR . 'includes/admin/class-affilync-admin-stats.php';
            require_once AFFILYNC_PLUGIN_DIR . 'includes/admin/class-affilync-admin-ajax.php';
        }

        // Helper classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/helpers/class-affilync-helper-logger.php';

        // Multisite classes.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/multisite/class-affilync-multisite.php';

        if ( is_multisite() && is_network_admin() ) {
            require_once AFFILYNC_PLUGIN_DIR . 'includes/admin/class-affilync-network-admin.php';
        }
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Activation/Deactivation hooks.
        register_activation_hook( AFFILYNC_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( AFFILYNC_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Run version upgrade check early, before full init.
        add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 10 );

        // Initialize plugin.
        add_action( 'plugins_loaded', array( $this, 'init' ), 20 );

        // Declare HPOS compatibility.
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

        // Load text domain.
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Add plugin action links.
        add_filter( 'plugin_action_links_' . AFFILYNC_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );

        // Register cron handlers.
        add_action( 'affilync_cleanup_expired', array( $this, 'cleanup_expired' ) );
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

        // Initialize security components first (required for license manager).
        $this->encryption     = new Affilync_Security_Encryption();
        $this->audit_logger   = new Affilync_Security_Audit_Logger();

        // In dev mode, skip integrity and license checks entirely.
        $is_dev_mode = defined( 'AFFILYNC_DEV_MODE' ) && AFFILYNC_DEV_MODE;

        // Initialize integrity checker and verify plugin hasn't been tampered with.
        if ( ! $is_dev_mode ) {
            $this->integrity = new Affilync_Security_Integrity( $this->audit_logger );
            if ( ! $this->integrity->verify() ) {
                // Plugin integrity compromised - show admin notice but don't block.
                add_action( 'admin_notices', array( $this, 'integrity_violation_notice' ) );

                // In production mode, block execution.
                if ( defined( 'AFFILYNC_PRODUCTION_MODE' ) && AFFILYNC_PRODUCTION_MODE ) {
                    $this->audit_logger->critical( 'integrity_block', array( 'action' => 'plugin_disabled' ) );
                    return;
                }
            }
        }

        // Initialize license manager and check validity.
        $this->license_manager = new Affilync_License_Manager( $this->encryption, $this->audit_logger );
        $this->license_valid   = $is_dev_mode || $this->license_manager->is_valid();

        // Check license status.
        if ( ! $this->license_valid ) {
            // Only show admin notices and settings page - disable functionality.
            add_action( 'admin_notices', array( $this, 'license_invalid_notice' ) );

            // Still initialize admin settings for license management.
            if ( is_admin() ) {
                new Affilync_Admin_Settings();
            }

            // Log license check.
            $this->audit_logger->warning(
                'license_check_failed',
                array( 'status' => $this->license_manager->get_status() )
            );

            return;
        }

        // Show grace period warning if applicable.
        if ( $this->license_manager->is_grace_period() ) {
            add_action( 'admin_notices', array( $this, 'license_grace_period_notice' ) );
        }

        // Initialize remaining security components.
        $this->nonce_manager  = new Affilync_Security_Nonce_Manager();
        $this->rate_limiter   = new Affilync_Security_Rate_Limiter();
        $this->hmac_validator = new Affilync_Security_HMAC_Validator();

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

        // Initialize call tracking (conditionally).
        $settings = get_option( 'affilync_settings', array() );
        if ( ! empty( $settings['call_tracking_enabled'] ) ) {
            $this->call_tracker = new Affilync_CallTracking_Tracker();
        }

        // Initialize brand verification.
        $this->brand_verification = new Affilync_Verification_Brand( $this->api_client, $this->audit_logger );

        // Initialize email notifications.
        $this->email_notifications = new Affilync_Notification_Email( $this->api_client, $this->encryption );

        // Initialize REST API.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Initialize OAuth handler.
        new Affilync_API_OAuth( $this->nonce_manager, $this->encryption, $this->api_client );

        // Initialize webhook handler.
        $this->webhook_handler = new Affilync_API_Webhook_Handler( $this->hmac_validator, $this->audit_logger );

        // Initialize webhook queue for reliable delivery with retries.
        $this->webhook_queue = new Affilync_Webhook_Queue( $this->api_client, $this->audit_logger );

        // Wire the queue to the handler for enqueue-on-receive.
        $this->webhook_handler->set_webhook_queue( $this->webhook_queue );

        // Initialize admin.
        if ( is_admin() ) {
            new Affilync_Admin_Settings();
            new Affilync_Admin_Dashboard_Widget();
            new Affilync_Admin_Stats();
            $this->ajax = new Affilync_Admin_Ajax(
                $this->api_client,
                $this->product_sync,
                $this->conversion_tracker,
                $this->webhook_handler,
                $this->audit_logger,
                $this->hmac_validator
            );
        }

        // Initialize multisite support.
        $this->multisite = Affilync_Multisite::instance();
        if ( Affilync_Multisite::is_multisite() && is_network_admin() ) {
            new Affilync_Network_Admin( $this->multisite );
        }

        /**
         * Fires after Affilync WooCommerce plugin is fully initialized.
         *
         * @since 1.0.0
         */
        do_action( 'affilync_woocommerce_init' );
    }

    /**
     * Display notice when license is invalid.
     */
    public function license_invalid_notice() {
        $status = $this->license_manager->get_status();
        $settings_url = admin_url( 'admin.php?page=affilync-settings&tab=license' );

        $messages = array(
            Affilync_License_Manager::STATUS_UNCHECKED => __( 'Please activate your license to use Affilync.', 'affilync-woocommerce' ),
            Affilync_License_Manager::STATUS_INVALID   => __( 'Your Affilync license is invalid. Please check your license key.', 'affilync-woocommerce' ),
            Affilync_License_Manager::STATUS_EXPIRED   => __( 'Your Affilync license has expired. Please renew to continue using the plugin.', 'affilync-woocommerce' ),
            Affilync_License_Manager::STATUS_SUSPENDED => __( 'Your Affilync license has been suspended. Please contact support.', 'affilync-woocommerce' ),
        );

        $message = isset( $messages[ $status ] ) ? $messages[ $status ] : $messages[ Affilync_License_Manager::STATUS_INVALID ];
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'Affilync for WooCommerce:', 'affilync-woocommerce' ); ?></strong>
                <?php echo esc_html( $message ); ?>
                <a href="<?php echo esc_url( $settings_url ); ?>">
                    <?php esc_html_e( 'Manage License', 'affilync-woocommerce' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Display notice when license is in grace period.
     */
    public function license_grace_period_notice() {
        $days_remaining = $this->license_manager->get_grace_days_remaining();
        $settings_url = admin_url( 'admin.php?page=affilync-settings&tab=license' );
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'Affilync License Warning:', 'affilync-woocommerce' ); ?></strong>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: Days remaining */
                        _n(
                            'Unable to verify your license. Plugin will be disabled in %d day unless connection is restored.',
                            'Unable to verify your license. Plugin will be disabled in %d days unless connection is restored.',
                            $days_remaining,
                            'affilync-woocommerce'
                        ),
                        $days_remaining
                    )
                );
                ?>
                <a href="<?php echo esc_url( $settings_url ); ?>">
                    <?php esc_html_e( 'Check License', 'affilync-woocommerce' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Check if the plugin license is valid.
     *
     * @return bool True if license is valid.
     */
    public function is_licensed() {
        return $this->license_valid;
    }

    /**
     * Display notice when plugin integrity is compromised.
     */
    public function integrity_violation_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'Affilync Security Alert:', 'affilync-woocommerce' ); ?></strong>
                <?php esc_html_e( 'Plugin integrity verification failed. The plugin files may have been modified. Please reinstall from the original source.', 'affilync-woocommerce' ); ?>
            </p>
        </div>
        <?php
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
     *
     * Handles both single-site and network-wide activation. On multisite
     * network activation, initializes tables and settings for every site.
     */
    public function activate() {
        // Create database tables.
        $this->create_tables();

        // Schedule cron events.
        $this->schedule_cron_events();

        // Generate file hashes for integrity checking.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-integrity.php';
        $integrity = new Affilync_Security_Integrity();
        $integrity->generate_file_hashes();

        // Store current plugin version (first install or re-activation).
        update_option( 'affilync_version', AFFILYNC_VERSION );

        // Handle multisite network activation.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/multisite/class-affilync-multisite.php';
        $multisite = Affilync_Multisite::instance();
        if ( Affilync_Multisite::is_multisite() && is_network_admin() ) {
            $multisite->network_activate();
        }

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
     * Check if the plugin was updated and run upgrade routines if needed.
     *
     * Compares the stored plugin version against the current AFFILYNC_VERSION constant.
     * If the stored version is older, runs version-specific upgrade routines then
     * updates the stored version to match.
     *
     * @since 1.0.0
     */
    public function maybe_upgrade() {
        if ( $this->upgrade_checked ) {
            return;
        }
        $this->upgrade_checked = true;

        $stored_version = get_option( 'affilync_version', '0.0.0' );

        if ( version_compare( $stored_version, AFFILYNC_VERSION, '<' ) ) {
            $this->run_upgrades( $stored_version, AFFILYNC_VERSION );
            update_option( 'affilync_version', AFFILYNC_VERSION );
        }
    }

    /**
     * Run version-based upgrade routines.
     *
     * Executes schema changes, data migrations, and any other upgrade logic
     * required when the plugin is updated from one version to another. Each
     * version block is guarded by a version_compare so it only runs once.
     *
     * @since 1.0.0
     *
     * @param string $from_version The previously stored plugin version.
     * @param string $to_version   The current plugin version being upgraded to.
     */
    private function run_upgrades( $from_version, $to_version ) {
        global $wpdb;

        // Re-run dbDelta to ensure all tables have the latest schema.
        // This handles column additions and index changes safely.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $this->create_tables();

        // --- Version-specific migrations ---

        // Upgrade to 1.1.0: Add sub_id column to conversions for sub-affiliate tracking.
        if ( version_compare( $from_version, '1.1.0', '<' ) ) {
            $table = $wpdb->prefix . 'affilync_conversions';
            $column_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'sub_id'",
                    DB_NAME,
                    $table
                )
            );
            if ( empty( $column_exists ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query(
                    "ALTER TABLE {$table} ADD COLUMN sub_id varchar(64) DEFAULT NULL AFTER click_id"
                );
            }
        }

        // Upgrade to 1.2.0: Add retry_count column to conversions for API sync resilience.
        if ( version_compare( $from_version, '1.2.0', '<' ) ) {
            $table = $wpdb->prefix . 'affilync_conversions';
            $column_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'retry_count'",
                    DB_NAME,
                    $table
                )
            );
            if ( empty( $column_exists ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query(
                    "ALTER TABLE {$table} ADD COLUMN retry_count int(11) NOT NULL DEFAULT 0 AFTER synced_to_api"
                );
            }
        }

        // Upgrade to 1.3.0: Add metadata column to webhooks for extended processing info.
        if ( version_compare( $from_version, '1.3.0', '<' ) ) {
            $table = $wpdb->prefix . 'affilync_webhooks';
            $column_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'metadata'",
                    DB_NAME,
                    $table
                )
            );
            if ( empty( $column_exists ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query(
                    "ALTER TABLE {$table} ADD COLUMN metadata longtext DEFAULT NULL AFTER processing_result"
                );
            }
        }

        // Upgrade to 1.4.0: Create webhook queue table for persistent delivery with retries.
        if ( version_compare( $from_version, '1.4.0', '<' ) ) {
            Affilync_Webhook_Queue::create_table();
            Affilync_Webhook_Queue::schedule_cron_events();
        }

        // Re-generate integrity hashes after any upgrade (files may have changed).
        if ( file_exists( AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-integrity.php' ) ) {
            require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-integrity.php';
            $integrity = new Affilync_Security_Integrity();
            $integrity->generate_file_hashes();
        }

        // Log the upgrade event to the audit log table.
        $this->log_upgrade_event( $from_version, $to_version );
    }

    /**
     * Log an upgrade event directly to the audit log table.
     *
     * Inserts a record without depending on the Affilync_Security_Audit_Logger class
     * being fully initialized, since upgrades run very early in the WordPress lifecycle.
     *
     * @since 1.0.0
     *
     * @param string $from_version The version being upgraded from.
     * @param string $to_version   The version being upgraded to.
     */
    private function log_upgrade_event( $from_version, $to_version ) {
        global $wpdb;

        $table = $wpdb->prefix . 'affilync_audit_log';

        // Verify the audit log table exists before writing.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );

        if ( ! $table_exists ) {
            return;
        }

        $details = wp_json_encode(
            array(
                'from_version' => $from_version,
                'to_version'   => $to_version,
                'php_version'  => PHP_VERSION,
                'wp_version'   => get_bloginfo( 'version' ),
                'wc_version'   => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
                'logged_at'    => current_time( 'mysql', true ),
            )
        );

        $ip_address = '0.0.0.0';
        $headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip  = trim( $ips[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    $ip_address = $ip;
                    break;
                }
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table,
            array(
                'event_type' => 'plugin_upgraded',
                'user_id'    => get_current_user_id() ?: null,
                'ip_address' => $ip_address,
                'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
                    ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
                    : null,
                'details'    => $details,
                'severity'   => 'info',
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s' )
        );
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

        // Call log table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}affilync_call_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            call_id varchar(64) NOT NULL,
            tracking_number varchar(32) DEFAULT NULL,
            caller_number varchar(32) DEFAULT NULL,
            affiliate_id varchar(64) DEFAULT NULL,
            campaign_id varchar(64) DEFAULT NULL,
            duration int(11) NOT NULL DEFAULT 0,
            billable_seconds int(11) NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'completed',
            recording_url text DEFAULT NULL,
            call_data longtext DEFAULT NULL,
            synced_to_api tinyint(1) NOT NULL DEFAULT 0,
            api_call_id varchar(64) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY call_id (call_id),
            KEY affiliate_id (affiliate_id),
            KEY campaign_id (campaign_id),
            KEY status (status),
            KEY synced_to_api (synced_to_api),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Webhook queue table for persistent delivery with retries.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}affilync_webhook_queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(64) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            max_attempts int(11) NOT NULL DEFAULT 5,
            next_retry_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY next_retry_at (next_retry_at),
            KEY event_type (event_type),
            KEY status_retry (status, next_retry_at, attempts)
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

        // Schedule webhook queue processing and cleanup.
        Affilync_Webhook_Queue::schedule_cron_events();
    }

    /**
     * Clear cron events.
     */
    private function clear_cron_events() {
        wp_clear_scheduled_hook( 'affilync_sync_products' );
        wp_clear_scheduled_hook( 'affilync_sync_conversions' );
        wp_clear_scheduled_hook( 'affilync_cleanup_expired' );
        wp_clear_scheduled_hook( 'affilync_license_revalidation' );

        // Clear webhook queue cron events.
        Affilync_Webhook_Queue::clear_cron_events();
    }

    /**
     * Cleanup expired data.
     *
     * This method is called by the 'affilync_cleanup_expired' cron event.
     * Removes expired nonces, old rate limit records, and stale audit logs.
     */
    public function cleanup_expired() {
        global $wpdb;

        // Clean expired nonces.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}affilync_nonces WHERE expires_at < %s",
                current_time( 'mysql' )
            )
        );

        // Clean old rate limit records (older than 24 hours).
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}affilync_rate_limits WHERE window_start < %s",
                gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
            )
        );

        // Clean old audit logs (older than 90 days, keep security events longer).
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}affilync_audit_log
                 WHERE created_at < %s
                 AND severity NOT IN ('error', 'critical')",
                gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
            )
        );

        // Clean critical/error logs older than 1 year.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}affilync_audit_log WHERE created_at < %s",
                gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) )
            )
        );

        // Clean processed webhooks older than 30 days.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}affilync_webhooks
                 WHERE processed = 1 AND created_at < %s",
                gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
            )
        );

        // Clean synced call logs older than 90 days.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $call_table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'affilync_call_log' )
        );
        if ( $call_table_exists ) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}affilync_call_log
                     WHERE synced_to_api = 1 AND created_at < %s",
                    gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
                )
            );
        }
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
