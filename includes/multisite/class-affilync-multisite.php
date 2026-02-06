<?php
/**
 * Multisite Support for Affilync WooCommerce.
 *
 * Handles WordPress multisite-specific functionality including per-site
 * settings storage, network-wide configuration, and site lifecycle hooks.
 *
 * @package Affilync_WooCommerce
 * @since   1.5.0
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Multisite handler.
 *
 * Provides site-specific settings isolation, network-level administration,
 * and lifecycle management for multisite WordPress installations.
 *
 * @since 1.5.0
 */
class Affilync_Multisite {

    /**
     * Option key for per-site Affilync settings.
     *
     * @var string
     */
    const SITE_SETTINGS_KEY = 'affilync_site_settings';

    /**
     * Option key for per-site API credentials.
     *
     * @var string
     */
    const SITE_CREDENTIALS_KEY = 'affilync_site_credentials';

    /**
     * Network option key for global configuration.
     *
     * @var string
     */
    const NETWORK_SETTINGS_KEY = 'affilync_network_settings';

    /**
     * Default per-site settings.
     *
     * @var array
     */
    private static $default_site_settings = array(
        'enabled'              => false,
        'tracking_enabled'     => true,
        'product_sync_enabled' => true,
        'cookie_duration'      => 30,
        'attribution_model'    => 'last_click',
        'conversion_statuses'  => array( 'wc-completed', 'wc-processing' ),
    );

    /**
     * Default network-wide settings.
     *
     * @var array
     */
    private static $default_network_settings = array(
        'auto_enable_new_sites' => false,
        'shared_license_key'    => '',
        'enforce_network_plan'  => false,
        'default_api_url'       => '',
    );

    /**
     * Singleton instance.
     *
     * @var Affilync_Multisite|null
     */
    private static $instance = null;

    /**
     * The blog ID that was active before a switch_to_site() call.
     *
     * @var int|null
     */
    private $previous_blog_id = null;

    /**
     * Get singleton instance.
     *
     * @return Affilync_Multisite
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor. Registers multisite lifecycle hooks.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register WordPress hooks for multisite lifecycle events.
     */
    private function init_hooks() {
        if ( ! self::is_multisite() ) {
            return;
        }

        // New site created (WP 5.1+).
        add_action( 'wp_initialize_site', array( $this, 'on_new_site_created' ), 10, 2 );

        // Legacy hook for older WP versions.
        add_action( 'wpmu_new_blog', array( $this, 'on_new_blog_legacy' ), 10, 6 );

        // Site deleted (WP 5.1+).
        add_action( 'wp_uninitialize_site', array( $this, 'on_site_deleted' ), 10, 1 );

        // Legacy delete hook.
        add_action( 'delete_blog', array( $this, 'on_blog_deleted_legacy' ), 10, 2 );
    }

    /**
     * Check if the current WordPress installation is multisite.
     *
     * @return bool True if multisite is enabled.
     */
    public static function is_multisite() {
        return function_exists( 'is_multisite' ) && is_multisite();
    }

    /**
     * Get settings for a specific site in the network.
     *
     * Uses get_blog_option() to retrieve site-specific settings, ensuring
     * each site in the network has isolated configuration.
     *
     * @param int|null $blog_id Blog ID. Null for current site.
     * @return array Site settings merged with defaults.
     */
    public function get_site_settings( $blog_id = null ) {
        if ( is_null( $blog_id ) ) {
            $blog_id = get_current_blog_id();
        }

        $blog_id = absint( $blog_id );

        if ( self::is_multisite() ) {
            $settings = get_blog_option( $blog_id, self::SITE_SETTINGS_KEY, array() );
        } else {
            $settings = get_option( self::SITE_SETTINGS_KEY, array() );
        }

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( $settings, self::$default_site_settings );
    }

    /**
     * Update settings for a specific site in the network.
     *
     * Uses update_blog_option() to persist site-specific settings, keeping
     * each site's configuration isolated from other sites.
     *
     * @param array    $settings Settings to save (merged with existing).
     * @param int|null $blog_id  Blog ID. Null for current site.
     * @return bool True on success.
     */
    public function update_site_settings( $settings, $blog_id = null ) {
        if ( is_null( $blog_id ) ) {
            $blog_id = get_current_blog_id();
        }

        $blog_id  = absint( $blog_id );
        $existing = $this->get_site_settings( $blog_id );
        $merged   = wp_parse_args( $settings, $existing );

        // Sanitize before saving.
        $merged['enabled']              = (bool) $merged['enabled'];
        $merged['tracking_enabled']     = (bool) $merged['tracking_enabled'];
        $merged['product_sync_enabled'] = (bool) $merged['product_sync_enabled'];
        $merged['cookie_duration']      = min( absint( $merged['cookie_duration'] ), 365 );
        $merged['attribution_model']    = sanitize_key( $merged['attribution_model'] );

        if ( isset( $merged['conversion_statuses'] ) && is_array( $merged['conversion_statuses'] ) ) {
            $merged['conversion_statuses'] = array_map( 'sanitize_key', $merged['conversion_statuses'] );
        }

        if ( self::is_multisite() ) {
            return update_blog_option( $blog_id, self::SITE_SETTINGS_KEY, $merged );
        }

        return update_option( self::SITE_SETTINGS_KEY, $merged );
    }

    /**
     * Get API credentials for a specific site.
     *
     * Each site in the multisite network has its own API key and secret,
     * stored via get_blog_option() so they are never shared across sites.
     *
     * @param int|null $blog_id Blog ID. Null for current site.
     * @return array Credentials array with 'api_key' and 'api_secret' keys.
     */
    public function get_site_credentials( $blog_id = null ) {
        if ( is_null( $blog_id ) ) {
            $blog_id = get_current_blog_id();
        }

        $blog_id = absint( $blog_id );

        if ( self::is_multisite() ) {
            $credentials = get_blog_option( $blog_id, self::SITE_CREDENTIALS_KEY, array() );
        } else {
            $credentials = get_option( self::SITE_CREDENTIALS_KEY, array() );
        }

        if ( ! is_array( $credentials ) ) {
            $credentials = array();
        }

        return wp_parse_args(
            $credentials,
            array(
                'api_key'    => '',
                'api_secret' => '',
                'brand_id'   => '',
                'connected'  => false,
            )
        );
    }

    /**
     * Update API credentials for a specific site.
     *
     * Stores per-site API credentials using update_blog_option() to ensure
     * each site's credentials remain private and isolated.
     *
     * @param array    $credentials Credentials to store.
     * @param int|null $blog_id     Blog ID. Null for current site.
     * @return bool True on success.
     */
    public function update_site_credentials( $credentials, $blog_id = null ) {
        if ( is_null( $blog_id ) ) {
            $blog_id = get_current_blog_id();
        }

        $blog_id  = absint( $blog_id );
        $existing = $this->get_site_credentials( $blog_id );
        $merged   = wp_parse_args( $credentials, $existing );

        // Sanitize.
        $merged['api_key']   = sanitize_text_field( $merged['api_key'] );
        $merged['api_secret'] = sanitize_text_field( $merged['api_secret'] );
        $merged['brand_id']  = sanitize_text_field( $merged['brand_id'] );
        $merged['connected'] = (bool) $merged['connected'];

        if ( self::is_multisite() ) {
            return update_blog_option( $blog_id, self::SITE_CREDENTIALS_KEY, $merged );
        }

        return update_option( self::SITE_CREDENTIALS_KEY, $merged );
    }

    /**
     * Delete API credentials for a specific site.
     *
     * @param int|null $blog_id Blog ID. Null for current site.
     * @return bool True on success.
     */
    public function delete_site_credentials( $blog_id = null ) {
        if ( is_null( $blog_id ) ) {
            $blog_id = get_current_blog_id();
        }

        $blog_id = absint( $blog_id );

        if ( self::is_multisite() ) {
            return delete_blog_option( $blog_id, self::SITE_CREDENTIALS_KEY );
        }

        return delete_option( self::SITE_CREDENTIALS_KEY );
    }

    /**
     * Get network-wide settings.
     *
     * Retrieves settings that apply across all sites in the network,
     * such as shared license keys and default configurations.
     *
     * @return array Network settings merged with defaults.
     */
    public function get_network_settings() {
        if ( ! self::is_multisite() ) {
            return self::$default_network_settings;
        }

        $settings = get_site_option( self::NETWORK_SETTINGS_KEY, array() );

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( $settings, self::$default_network_settings );
    }

    /**
     * Update network-wide settings.
     *
     * @param array $settings Settings to save.
     * @return bool True on success.
     */
    public function update_network_settings( $settings ) {
        if ( ! self::is_multisite() ) {
            return false;
        }

        $existing = $this->get_network_settings();
        $merged   = wp_parse_args( $settings, $existing );

        // Sanitize.
        $merged['auto_enable_new_sites'] = (bool) $merged['auto_enable_new_sites'];
        $merged['shared_license_key']    = sanitize_text_field( $merged['shared_license_key'] );
        $merged['enforce_network_plan']  = (bool) $merged['enforce_network_plan'];
        $merged['default_api_url']       = esc_url_raw( $merged['default_api_url'] );

        return update_site_option( self::NETWORK_SETTINGS_KEY, $merged );
    }

    /**
     * Switch context to a specific site in the network.
     *
     * Wraps switch_to_blog() and tracks the previous blog ID so
     * restore_previous_site() can return to it. Calling code should
     * always use restore_previous_site() when done.
     *
     * @param int $blog_id The blog ID to switch to.
     * @return bool True if the switch occurred. False if not multisite or same site.
     */
    public function switch_to_site( $blog_id ) {
        if ( ! self::is_multisite() ) {
            return false;
        }

        $blog_id = absint( $blog_id );

        if ( $blog_id === get_current_blog_id() ) {
            return false;
        }

        $this->previous_blog_id = get_current_blog_id();
        switch_to_blog( $blog_id );

        return true;
    }

    /**
     * Restore context to the site that was active before switch_to_site().
     *
     * @return bool True if the restore occurred.
     */
    public function restore_previous_site() {
        if ( ! self::is_multisite() ) {
            return false;
        }

        restore_current_blog();
        $this->previous_blog_id = null;

        return true;
    }

    /**
     * Execute a callback in the context of a specific site.
     *
     * Handles switch_to_blog/restore_current_blog automatically, ensuring
     * the original site context is always restored even on exceptions.
     *
     * @param int      $blog_id  The blog ID to run in.
     * @param callable $callback The callback to execute.
     * @param array    $args     Arguments to pass to the callback.
     * @return mixed The callback return value.
     */
    public function run_on_site( $blog_id, $callback, $args = array() ) {
        $switched = $this->switch_to_site( $blog_id );

        try {
            $result = call_user_func_array( $callback, $args );
        } finally {
            if ( $switched ) {
                $this->restore_previous_site();
            }
        }

        return $result;
    }

    /**
     * Check if Affilync is enabled on a specific site.
     *
     * @param int|null $blog_id Blog ID. Null for current site.
     * @return bool True if enabled.
     */
    public function is_site_enabled( $blog_id = null ) {
        $settings = $this->get_site_settings( $blog_id );
        return ! empty( $settings['enabled'] );
    }

    /**
     * Enable Affilync on a specific site.
     *
     * @param int $blog_id Blog ID.
     * @return bool True on success.
     */
    public function enable_site( $blog_id ) {
        return $this->update_site_settings( array( 'enabled' => true ), $blog_id );
    }

    /**
     * Disable Affilync on a specific site.
     *
     * @param int $blog_id Blog ID.
     * @return bool True on success.
     */
    public function disable_site( $blog_id ) {
        return $this->update_site_settings( array( 'enabled' => false ), $blog_id );
    }

    /**
     * Bulk enable Affilync across multiple sites.
     *
     * @param array $blog_ids Array of blog IDs to enable.
     * @return array Associative array of blog_id => success boolean.
     */
    public function bulk_enable( $blog_ids ) {
        $results = array();
        foreach ( $blog_ids as $blog_id ) {
            $results[ $blog_id ] = $this->enable_site( absint( $blog_id ) );
        }
        return $results;
    }

    /**
     * Bulk disable Affilync across multiple sites.
     *
     * @param array $blog_ids Array of blog IDs to disable.
     * @return array Associative array of blog_id => success boolean.
     */
    public function bulk_disable( $blog_ids ) {
        $results = array();
        foreach ( $blog_ids as $blog_id ) {
            $results[ $blog_id ] = $this->disable_site( absint( $blog_id ) );
        }
        return $results;
    }

    /**
     * Get a summary of all sites and their Affilync status.
     *
     * @return array Array of site status records.
     */
    public function get_all_sites_status() {
        if ( ! self::is_multisite() ) {
            return array();
        }

        $sites  = get_sites( array( 'number' => 0 ) );
        $result = array();

        foreach ( $sites as $site ) {
            $blog_id     = absint( $site->blog_id );
            $settings    = $this->get_site_settings( $blog_id );
            $credentials = $this->get_site_credentials( $blog_id );

            $result[] = array(
                'blog_id'   => $blog_id,
                'domain'    => $site->domain,
                'path'      => $site->path,
                'site_name' => get_blog_option( $blog_id, 'blogname', '' ),
                'enabled'   => ! empty( $settings['enabled'] ),
                'connected' => ! empty( $credentials['connected'] ),
                'brand_id'  => $credentials['brand_id'],
            );
        }

        return $result;
    }

    /**
     * Handle new site creation (WP 5.1+).
     *
     * Auto-initializes default Affilync settings for the new site and
     * optionally enables the plugin if network settings dictate.
     *
     * @param WP_Site $new_site New site object.
     * @param array   $args     Arguments passed during site initialization.
     */
    public function on_new_site_created( $new_site, $args ) {
        $blog_id          = absint( $new_site->blog_id );
        $network_settings = $this->get_network_settings();

        // Initialize default settings for the new site.
        $site_defaults = self::$default_site_settings;

        if ( ! empty( $network_settings['auto_enable_new_sites'] ) ) {
            $site_defaults['enabled'] = true;
        }

        $this->update_site_settings( $site_defaults, $blog_id );

        // Run database table creation on the new site.
        $this->run_on_site( $blog_id, array( $this, 'create_site_tables' ) );

        /**
         * Fires after a new multisite site is initialized with Affilync defaults.
         *
         * @since 1.5.0
         *
         * @param int   $blog_id          The new site's blog ID.
         * @param array $network_settings Current network settings.
         */
        do_action( 'affilync_multisite_new_site', $blog_id, $network_settings );
    }

    /**
     * Handle new blog creation (legacy hook for WP < 5.1).
     *
     * @param int    $blog_id Blog ID.
     * @param int    $user_id User ID.
     * @param string $domain  Site domain.
     * @param string $path    Site path.
     * @param int    $site_id Network ID.
     * @param array  $meta    Site meta.
     */
    public function on_new_blog_legacy( $blog_id, $user_id, $domain, $path, $site_id, $meta ) {
        $blog_id          = absint( $blog_id );
        $network_settings = $this->get_network_settings();

        $site_defaults = self::$default_site_settings;

        if ( ! empty( $network_settings['auto_enable_new_sites'] ) ) {
            $site_defaults['enabled'] = true;
        }

        $this->update_site_settings( $site_defaults, $blog_id );
        $this->run_on_site( $blog_id, array( $this, 'create_site_tables' ) );

        /** This action is documented in includes/multisite/class-affilync-multisite.php */
        do_action( 'affilync_multisite_new_site', $blog_id, $network_settings );
    }

    /**
     * Handle site deletion (WP 5.1+).
     *
     * Cleans up all Affilync data for the deleted site including settings,
     * credentials, and database tables.
     *
     * @param WP_Site $old_site The site being deleted.
     */
    public function on_site_deleted( $old_site ) {
        $blog_id = absint( $old_site->blog_id );
        $this->cleanup_site_data( $blog_id );
    }

    /**
     * Handle blog deletion (legacy hook for WP < 5.1).
     *
     * @param int  $blog_id Blog ID being deleted.
     * @param bool $drop    Whether to drop the site's database tables.
     */
    public function on_blog_deleted_legacy( $blog_id, $drop ) {
        $this->cleanup_site_data( absint( $blog_id ) );
    }

    /**
     * Clean up all Affilync data for a site.
     *
     * Removes settings, credentials, and optionally database tables
     * for the specified blog ID.
     *
     * @param int $blog_id Blog ID to clean up.
     */
    private function cleanup_site_data( $blog_id ) {
        $blog_id = absint( $blog_id );

        // Remove site-specific options.
        if ( self::is_multisite() ) {
            delete_blog_option( $blog_id, self::SITE_SETTINGS_KEY );
            delete_blog_option( $blog_id, self::SITE_CREDENTIALS_KEY );
            delete_blog_option( $blog_id, 'affilync_settings' );
            delete_blog_option( $blog_id, 'affilync_version' );
            delete_blog_option( $blog_id, 'affilync_db_version' );
            delete_blog_option( $blog_id, 'affilync_brand_id' );
            delete_blog_option( $blog_id, 'affilync_connection_status' );
        }

        // Drop database tables for this site.
        $this->run_on_site( $blog_id, array( $this, 'drop_site_tables' ) );

        /**
         * Fires after a site's Affilync data has been cleaned up.
         *
         * @since 1.5.0
         *
         * @param int $blog_id The blog ID that was cleaned up.
         */
        do_action( 'affilync_multisite_site_cleaned', $blog_id );
    }

    /**
     * Create Affilync database tables for the current site context.
     *
     * This should be called within a switch_to_blog() context so $wpdb->prefix
     * points to the correct site's table prefix.
     */
    public function create_site_tables() {
        // Re-use the main plugin's table creation logic.
        if ( function_exists( 'affilync' ) ) {
            // Trigger the activate method which creates tables.
            // Since we cannot call private create_tables() directly,
            // we replicate the table creation here for the switched site.
            $this->do_create_tables();
        }
    }

    /**
     * Drop Affilync database tables for the current site context.
     */
    public function drop_site_tables() {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'affilync_conversions',
            $wpdb->prefix . 'affilync_product_sync',
            $wpdb->prefix . 'affilync_webhooks',
            $wpdb->prefix . 'affilync_audit_log',
            $wpdb->prefix . 'affilync_rate_limits',
            $wpdb->prefix . 'affilync_nonces',
            $wpdb->prefix . 'affilync_webhook_queue',
        );

        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
        }
    }

    /**
     * Create the Affilync database tables for the current blog context.
     *
     * Mirrors the main plugin's create_tables() method but can be invoked
     * independently in a switched-blog context.
     */
    private function do_create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = array();

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
    }

    /**
     * Network-activate the plugin across all existing sites.
     *
     * Called during network activation to initialize tables and settings
     * for every site in the network.
     */
    public function network_activate() {
        if ( ! self::is_multisite() ) {
            return;
        }

        $sites = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );

        foreach ( $sites as $blog_id ) {
            $blog_id = absint( $blog_id );
            $this->update_site_settings( self::$default_site_settings, $blog_id );
            $this->run_on_site( $blog_id, array( $this, 'create_site_tables' ) );
        }
    }

    /**
     * Get the current site's blog_id for inclusion in API calls.
     *
     * API calls from a multisite installation should include the site_id
     * so the Affilync backend can correlate data per sub-site.
     *
     * @return int The current blog ID (1 on non-multisite installs).
     */
    public static function get_current_site_id() {
        if ( self::is_multisite() ) {
            return get_current_blog_id();
        }
        return 1;
    }

    /**
     * Build API request headers that include multisite context.
     *
     * Returns an array of headers to be merged into outgoing API requests
     * so the Affilync backend knows which sub-site the request originates from.
     *
     * @param int|null $blog_id Blog ID. Null for current site.
     * @return array Associative array of header name => value.
     */
    public function get_site_api_headers( $blog_id = null ) {
        if ( is_null( $blog_id ) ) {
            $blog_id = self::get_current_site_id();
        }

        $headers = array(
            'X-Affilync-Site-Id' => (string) absint( $blog_id ),
        );

        if ( self::is_multisite() ) {
            $headers['X-Affilync-Multisite'] = '1';
            $headers['X-Affilync-Network-Id'] = (string) get_current_network_id();
        }

        return $headers;
    }
}
