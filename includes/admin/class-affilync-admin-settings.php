<?php
/**
 * Admin Settings Page.
 *
 * Manages the plugin settings page in WordPress admin.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Admin Settings.
 *
 * @since 1.0.0
 */
class Affilync_Admin_Settings {

    /**
     * Settings page slug.
     *
     * @var string
     */
    const PAGE_SLUG = 'affilync-settings';

    /**
     * Settings group.
     *
     * @var string
     */
    const SETTINGS_GROUP = 'affilync_settings_group';

    /**
     * Constructor.
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_activation_redirect' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_notices', array( $this, 'display_notices' ) );

        // License AJAX handlers.
        add_action( 'wp_ajax_affilync_activate_license', array( $this, 'ajax_activate_license' ) );
        add_action( 'wp_ajax_affilync_deactivate_license', array( $this, 'ajax_deactivate_license' ) );
        add_action( 'wp_ajax_affilync_check_license', array( $this, 'ajax_check_license' ) );
    }

    /**
     * Add menu pages.
     */
    public function add_menu_pages() {
        // Add under WooCommerce menu.
        add_submenu_page(
            'woocommerce',
            __( 'Affilync Settings', 'affilync-woocommerce' ),
            __( 'Affilync', 'affilync-woocommerce' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting(
            self::SETTINGS_GROUP,
            'affilync_settings',
            array(
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
            )
        );

        // Connection section.
        add_settings_section(
            'affilync_connection',
            __( 'Connection', 'affilync-woocommerce' ),
            array( $this, 'render_connection_section' ),
            self::PAGE_SLUG
        );

        // Tracking section.
        add_settings_section(
            'affilync_tracking',
            __( 'Tracking Settings', 'affilync-woocommerce' ),
            array( $this, 'render_tracking_section' ),
            self::PAGE_SLUG
        );

        add_settings_field(
            'tracking_enabled',
            __( 'Enable Tracking', 'affilync-woocommerce' ),
            array( $this, 'render_checkbox_field' ),
            self::PAGE_SLUG,
            'affilync_tracking',
            array(
                'id'          => 'tracking_enabled',
                'description' => __( 'Enable affiliate conversion tracking.', 'affilync-woocommerce' ),
            )
        );

        add_settings_field(
            'cookie_duration',
            __( 'Cookie Duration', 'affilync-woocommerce' ),
            array( $this, 'render_number_field' ),
            self::PAGE_SLUG,
            'affilync_tracking',
            array(
                'id'          => 'cookie_duration',
                'description' => __( 'Days to keep tracking cookies.', 'affilync-woocommerce' ),
                'min'         => 1,
                'max'         => 365,
            )
        );

        add_settings_field(
            'conversion_statuses',
            __( 'Conversion Order Statuses', 'affilync-woocommerce' ),
            array( $this, 'render_multiselect_field' ),
            self::PAGE_SLUG,
            'affilync_tracking',
            array(
                'id'          => 'conversion_statuses',
                'description' => __( 'Order statuses that trigger conversions.', 'affilync-woocommerce' ),
                'options'     => wc_get_order_statuses(),
            )
        );

        add_settings_field(
            'attribution_model',
            __( 'Attribution Model', 'affilync-woocommerce' ),
            array( $this, 'render_select_field' ),
            self::PAGE_SLUG,
            'affilync_tracking',
            array(
                'id'          => 'attribution_model',
                'description' => __( 'How to attribute conversions to affiliates.', 'affilync-woocommerce' ),
                'options'     => array(
                    'last_click'  => __( 'Last Click', 'affilync-woocommerce' ),
                    'first_click' => __( 'First Click', 'affilync-woocommerce' ),
                ),
            )
        );

        // Product sync section.
        add_settings_section(
            'affilync_product_sync',
            __( 'Product Sync', 'affilync-woocommerce' ),
            array( $this, 'render_product_sync_section' ),
            self::PAGE_SLUG
        );

        add_settings_field(
            'product_sync_enabled',
            __( 'Enable Product Sync', 'affilync-woocommerce' ),
            array( $this, 'render_checkbox_field' ),
            self::PAGE_SLUG,
            'affilync_product_sync',
            array(
                'id'          => 'product_sync_enabled',
                'description' => __( 'Automatically sync products to Affilync marketplace.', 'affilync-woocommerce' ),
            )
        );

        add_settings_field(
            'product_sync_interval',
            __( 'Sync Interval', 'affilync-woocommerce' ),
            array( $this, 'render_select_field' ),
            self::PAGE_SLUG,
            'affilync_product_sync',
            array(
                'id'          => 'product_sync_interval',
                'description' => __( 'How often to sync products.', 'affilync-woocommerce' ),
                'options'     => array(
                    'hourly'     => __( 'Hourly', 'affilync-woocommerce' ),
                    'twicedaily' => __( 'Twice Daily', 'affilync-woocommerce' ),
                    'daily'      => __( 'Daily', 'affilync-woocommerce' ),
                ),
            )
        );

        // Advanced section.
        add_settings_section(
            'affilync_advanced',
            __( 'Advanced', 'affilync-woocommerce' ),
            null,
            self::PAGE_SLUG
        );

        add_settings_field(
            'remove_data_on_uninstall',
            __( 'Remove Data on Uninstall', 'affilync-woocommerce' ),
            array( $this, 'render_checkbox_field' ),
            self::PAGE_SLUG,
            'affilync_advanced',
            array(
                'id'          => 'remove_data_on_uninstall',
                'option_name' => 'affilync_remove_data_on_uninstall',
                'description' => __( 'Delete all plugin data when uninstalling.', 'affilync-woocommerce' ),
            )
        );
    }

    /**
     * Sanitize settings.
     *
     * @param array $input Input values.
     * @return array Sanitized values.
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        if ( isset( $input['tracking_enabled'] ) ) {
            $sanitized['tracking_enabled'] = (bool) $input['tracking_enabled'];
        }

        if ( isset( $input['cookie_duration'] ) ) {
            $sanitized['cookie_duration'] = min( absint( $input['cookie_duration'] ), 365 );
        }

        if ( isset( $input['conversion_statuses'] ) && is_array( $input['conversion_statuses'] ) ) {
            $sanitized['conversion_statuses'] = array_map( 'sanitize_key', $input['conversion_statuses'] );
        }

        if ( isset( $input['attribution_model'] ) ) {
            $sanitized['attribution_model'] = sanitize_key( $input['attribution_model'] );
        }

        if ( isset( $input['product_sync_enabled'] ) ) {
            $sanitized['product_sync_enabled'] = (bool) $input['product_sync_enabled'];
        }

        if ( isset( $input['product_sync_interval'] ) ) {
            $sanitized['product_sync_interval'] = sanitize_key( $input['product_sync_interval'] );
        }

        if ( isset( $input['track_url_params'] ) && is_array( $input['track_url_params'] ) ) {
            $sanitized['track_url_params'] = array_map( 'sanitize_key', $input['track_url_params'] );
        }

        return $sanitized;
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
        ?>
        <div class="wrap affilync-settings">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=license"
                   class="nav-tab <?php echo $active_tab === 'license' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'License', 'affilync-woocommerce' ); ?>
                </a>
                <a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=settings"
                   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'affilync-woocommerce' ); ?>
                </a>
                <a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=conversions"
                   class="nav-tab <?php echo $active_tab === 'conversions' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Conversions', 'affilync-woocommerce' ); ?>
                </a>
                <a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=products"
                   class="nav-tab <?php echo $active_tab === 'products' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Products', 'affilync-woocommerce' ); ?>
                </a>
                <a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=logs"
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Logs', 'affilync-woocommerce' ); ?>
                </a>
            </nav>

            <div class="affilync-tab-content">
                <?php
                switch ( $active_tab ) {
                    case 'license':
                        $this->render_license_tab();
                        break;
                    case 'conversions':
                        $this->render_conversions_tab();
                        break;
                    case 'products':
                        $this->render_products_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    default:
                        $this->render_settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings tab.
     */
    private function render_settings_tab() {
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( self::SETTINGS_GROUP );
            do_settings_sections( self::PAGE_SLUG );
            submit_button( __( 'Save Settings', 'affilync-woocommerce' ) );
            ?>
        </form>
        <?php
    }

    /**
     * Render connection section.
     */
    public function render_connection_section() {
        $connected = affilync()->api_client->is_connected();
        $brand_id = get_option( 'affilync_brand_id', '' );
        ?>
        <div class="affilync-connection-status">
            <?php if ( $connected ) : ?>
                <div class="affilync-status connected">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <strong><?php esc_html_e( 'Connected to Affilync', 'affilync-woocommerce' ); ?></strong>
                    <?php if ( $brand_id ) : ?>
                        <span class="brand-id"><?php echo esc_html( sprintf( __( 'Brand ID: %s', 'affilync-woocommerce' ), $brand_id ) ); ?></span>
                    <?php endif; ?>
                </div>
                <button type="button" class="button affilync-disconnect">
                    <?php esc_html_e( 'Disconnect', 'affilync-woocommerce' ); ?>
                </button>
            <?php else : ?>
                <div class="affilync-status disconnected">
                    <span class="dashicons dashicons-no-alt"></span>
                    <strong><?php esc_html_e( 'Not Connected', 'affilync-woocommerce' ); ?></strong>
                </div>
                <button type="button" class="button button-primary affilync-connect">
                    <?php esc_html_e( 'Connect to Affilync', 'affilync-woocommerce' ); ?>
                </button>
                <p class="description">
                    <?php esc_html_e( 'Connect your WooCommerce store to your Affilync account to start tracking affiliate conversions.', 'affilync-woocommerce' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render tracking section.
     */
    public function render_tracking_section() {
        echo '<p>' . esc_html__( 'Configure how affiliate conversions are tracked.', 'affilync-woocommerce' ) . '</p>';
    }

    /**
     * Render product sync section.
     */
    public function render_product_sync_section() {
        $stats = affilync()->product_sync->get_stats();
        ?>
        <p><?php esc_html_e( 'Sync your products to the Affilync marketplace.', 'affilync-woocommerce' ); ?></p>
        <div class="affilync-sync-status">
            <span><strong><?php esc_html_e( 'Synced:', 'affilync-woocommerce' ); ?></strong> <?php echo esc_html( $stats['synced'] ); ?></span>
            <span><strong><?php esc_html_e( 'Pending:', 'affilync-woocommerce' ); ?></strong> <?php echo esc_html( $stats['pending'] ); ?></span>
            <span><strong><?php esc_html_e( 'Failed:', 'affilync-woocommerce' ); ?></strong> <?php echo esc_html( $stats['failed'] ); ?></span>
            <?php if ( $stats['last_sync'] ) : ?>
                <span><strong><?php esc_html_e( 'Last Sync:', 'affilync-woocommerce' ); ?></strong> <?php echo esc_html( $stats['last_sync'] ); ?></span>
            <?php endif; ?>
            <button type="button" class="button affilync-sync-products">
                <?php esc_html_e( 'Sync Now', 'affilync-woocommerce' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render checkbox field.
     *
     * @param array $args Field arguments.
     */
    public function render_checkbox_field( $args ) {
        $option_name = isset( $args['option_name'] ) ? $args['option_name'] : 'affilync_settings';
        $settings = get_option( $option_name, array() );
        $value = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : true;

        $name = $option_name === 'affilync_settings'
            ? "affilync_settings[{$args['id']}]"
            : $option_name;
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>"
                   value="1" <?php checked( $value ); ?> />
            <?php echo esc_html( $args['description'] ); ?>
        </label>
        <?php
    }

    /**
     * Render number field.
     *
     * @param array $args Field arguments.
     */
    public function render_number_field( $args ) {
        $settings = get_option( 'affilync_settings', array() );
        $value = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : 30;
        ?>
        <input type="number" name="affilync_settings[<?php echo esc_attr( $args['id'] ); ?>]"
               value="<?php echo esc_attr( $value ); ?>"
               min="<?php echo esc_attr( $args['min'] ?? 1 ); ?>"
               max="<?php echo esc_attr( $args['max'] ?? 365 ); ?>"
               class="small-text" />
        <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php
    }

    /**
     * Render select field.
     *
     * @param array $args Field arguments.
     */
    public function render_select_field( $args ) {
        $settings = get_option( 'affilync_settings', array() );
        $value = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
        ?>
        <select name="affilync_settings[<?php echo esc_attr( $args['id'] ); ?>]">
            <?php foreach ( $args['options'] as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php
    }

    /**
     * Render multiselect field.
     *
     * @param array $args Field arguments.
     */
    public function render_multiselect_field( $args ) {
        $settings = get_option( 'affilync_settings', array() );
        $values = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : array( 'wc-completed', 'wc-processing' );
        ?>
        <select name="affilync_settings[<?php echo esc_attr( $args['id'] ); ?>][]" multiple>
            <?php foreach ( $args['options'] as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( in_array( $key, $values, true ) ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php
    }

    /**
     * Render conversions tab.
     */
    private function render_conversions_tab() {
        $stats = affilync()->conversion_tracker->get_stats( 'month' );
        ?>
        <div class="affilync-stats-cards">
            <div class="stat-card">
                <h3><?php esc_html_e( 'Total Conversions', 'affilync-woocommerce' ); ?></h3>
                <span class="stat-value"><?php echo esc_html( $stats['total_conversions'] ); ?></span>
            </div>
            <div class="stat-card">
                <h3><?php esc_html_e( 'Revenue', 'affilync-woocommerce' ); ?></h3>
                <span class="stat-value"><?php echo wc_price( $stats['total_revenue'] ); ?></span>
            </div>
            <div class="stat-card">
                <h3><?php esc_html_e( 'Commission', 'affilync-woocommerce' ); ?></h3>
                <span class="stat-value"><?php echo wc_price( $stats['total_commission'] ); ?></span>
            </div>
        </div>

        <h2><?php esc_html_e( 'Recent Conversions', 'affilync-woocommerce' ); ?></h2>
        <?php $this->render_conversions_table(); ?>
        <?php
    }

    /**
     * Render conversions table.
     */
    private function render_conversions_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'affilync_conversions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $conversions = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 25"
        );
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Affiliate', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Total', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Commission', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Synced', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'affilync-woocommerce' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $conversions as $conversion ) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $conversion->order_id . '&action=edit' ) ); ?>">
                                #<?php echo esc_html( $conversion->order_id ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( $conversion->affiliate_id ?: '-' ); ?></td>
                        <td><?php echo wc_price( $conversion->order_total ); ?></td>
                        <td><?php echo wc_price( $conversion->commission_amount ); ?></td>
                        <td><span class="status-<?php echo esc_attr( $conversion->status ); ?>"><?php echo esc_html( ucfirst( $conversion->status ) ); ?></span></td>
                        <td><?php echo $conversion->synced_to_api ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-no"></span>'; ?></td>
                        <td><?php echo esc_html( $conversion->created_at ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render products tab.
     */
    private function render_products_tab() {
        $stats = affilync()->product_sync->get_stats();
        ?>
        <div class="affilync-stats-cards">
            <div class="stat-card">
                <h3><?php esc_html_e( 'Synced', 'affilync-woocommerce' ); ?></h3>
                <span class="stat-value"><?php echo esc_html( $stats['synced'] ); ?></span>
            </div>
            <div class="stat-card">
                <h3><?php esc_html_e( 'Pending', 'affilync-woocommerce' ); ?></h3>
                <span class="stat-value"><?php echo esc_html( $stats['pending'] ); ?></span>
            </div>
            <div class="stat-card">
                <h3><?php esc_html_e( 'Failed', 'affilync-woocommerce' ); ?></h3>
                <span class="stat-value"><?php echo esc_html( $stats['failed'] ); ?></span>
            </div>
        </div>

        <p>
            <button type="button" class="button button-primary affilync-full-sync">
                <?php esc_html_e( 'Full Product Sync', 'affilync-woocommerce' ); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Render logs tab.
     */
    private function render_logs_tab() {
        $audit_logger = affilync()->audit_logger;
        $logs = $audit_logger->get_logs( array( 'limit' => 50 ) );
        ?>
        <h2><?php esc_html_e( 'Audit Log', 'affilync-woocommerce' ); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Event', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Severity', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'IP', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'User', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'affilync-woocommerce' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td><?php echo esc_html( $log->event_type ); ?></td>
                        <td><span class="severity-<?php echo esc_attr( $log->severity ); ?>"><?php echo esc_html( $log->severity ); ?></span></td>
                        <td><?php echo esc_html( $log->ip_address ); ?></td>
                        <td><?php echo esc_html( $log->user_id ?: '-' ); ?></td>
                        <td><?php echo esc_html( $log->created_at ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Handle activation redirect.
     */
    public function handle_activation_redirect() {
        if ( get_transient( 'affilync_activation_redirect' ) ) {
            delete_transient( 'affilync_activation_redirect' );

            if ( ! isset( $_GET['activate-multi'] ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
                exit;
            }
        }
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
            return;
        }

        wp_enqueue_style(
            'affilync-admin',
            AFFILYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AFFILYNC_VERSION
        );

        wp_enqueue_script(
            'affilync-admin',
            AFFILYNC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            AFFILYNC_VERSION,
            true
        );

        wp_localize_script(
            'affilync-admin',
            'affilyncAdmin',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'restUrl'   => rest_url( 'affilync/v1/' ),
                'nonce'     => wp_create_nonce( 'wp_rest' ),
                'connected' => affilync()->api_client ? affilync()->api_client->is_connected() : false,
                'licensed'  => affilync()->is_licensed(),
                'i18n'      => array(
                    'connecting'        => __( 'Connecting...', 'affilync-woocommerce' ),
                    'disconnecting'     => __( 'Disconnecting...', 'affilync-woocommerce' ),
                    'syncing'           => __( 'Syncing...', 'affilync-woocommerce' ),
                    'confirmDisconnect' => __( 'Are you sure you want to disconnect from Affilync?', 'affilync-woocommerce' ),
                    'activating'        => __( 'Activating license...', 'affilync-woocommerce' ),
                    'deactivating'      => __( 'Deactivating license...', 'affilync-woocommerce' ),
                    'checking'          => __( 'Checking license...', 'affilync-woocommerce' ),
                    'enterLicenseKey'   => __( 'Please enter a license key', 'affilync-woocommerce' ),
                    'confirmDeactivate' => __( 'Are you sure you want to deactivate your license? You can reactivate it later.', 'affilync-woocommerce' ),
                    'licenseActivated'  => __( 'License activated successfully! Reloading page...', 'affilync-woocommerce' ),
                    'licenseDeactivated' => __( 'License deactivated. Reloading page...', 'affilync-woocommerce' ),
                ),
            )
        );
    }

    /**
     * Display admin notices.
     */
    public function display_notices() {
        // Check for OAuth message.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['affilync_message'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $message = sanitize_text_field( wp_unslash( $_GET['affilync_message'] ) );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $type = isset( $_GET['affilync_type'] ) ? sanitize_key( wp_unslash( $_GET['affilync_type'] ) ) : 'info';

            $class = $type === 'error' ? 'notice-error' : 'notice-success';
            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                esc_attr( $class ),
                esc_html( $message )
            );
        }
    }

    /**
     * Render license tab.
     */
    private function render_license_tab() {
        $license_manager = affilync()->license_manager;
        $license_info = $license_manager ? $license_manager->get_license_info() : array(
            'status'      => 'unchecked',
            'license_key' => '',
            'expires_at'  => null,
            'plan'        => null,
            'last_check'  => null,
        );

        $status = $license_info['status'];
        $status_class = $this->get_license_status_class( $status );
        $status_label = $this->get_license_status_label( $status );
        ?>
        <div class="affilync-license-section">
            <h2><?php esc_html_e( 'License Management', 'affilync-woocommerce' ); ?></h2>

            <div class="affilync-license-status-card <?php echo esc_attr( $status_class ); ?>">
                <div class="license-status-header">
                    <span class="status-indicator"></span>
                    <span class="status-text"><?php echo esc_html( $status_label ); ?></span>
                </div>

                <?php if ( $license_info['license_key'] ) : ?>
                    <div class="license-details">
                        <p>
                            <strong><?php esc_html_e( 'License Key:', 'affilync-woocommerce' ); ?></strong>
                            <code><?php echo esc_html( $license_info['license_key'] ); ?></code>
                        </p>
                        <?php if ( $license_info['plan'] ) : ?>
                            <p>
                                <strong><?php esc_html_e( 'Plan:', 'affilync-woocommerce' ); ?></strong>
                                <?php echo esc_html( ucfirst( $license_info['plan'] ) ); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ( $license_info['expires_at'] ) : ?>
                            <p>
                                <strong><?php esc_html_e( 'Expires:', 'affilync-woocommerce' ); ?></strong>
                                <?php echo esc_html( $license_info['expires_at'] ); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ( $license_info['last_check'] ) : ?>
                            <p>
                                <strong><?php esc_html_e( 'Last Verified:', 'affilync-woocommerce' ); ?></strong>
                                <?php echo esc_html( $license_info['last_check'] ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( $status === 'valid' || $status === 'grace_period' ) : ?>
                <div class="affilync-license-actions">
                    <button type="button" class="button affilync-check-license">
                        <?php esc_html_e( 'Verify License', 'affilync-woocommerce' ); ?>
                    </button>
                    <button type="button" class="button affilync-deactivate-license">
                        <?php esc_html_e( 'Deactivate License', 'affilync-woocommerce' ); ?>
                    </button>
                </div>
            <?php else : ?>
                <div class="affilync-license-activation">
                    <h3><?php esc_html_e( 'Activate License', 'affilync-woocommerce' ); ?></h3>
                    <p class="description">
                        <?php esc_html_e( 'Enter your license key to activate Affilync for this site.', 'affilync-woocommerce' ); ?>
                    </p>
                    <div class="license-input-group">
                        <input type="text"
                               id="affilync-license-key"
                               class="regular-text"
                               placeholder="AFFILYNC-XXXX-XXXX-XXXX-XXXX"
                               pattern="AFFILYNC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}" />
                        <button type="button" class="button button-primary affilync-activate-license">
                            <?php esc_html_e( 'Activate', 'affilync-woocommerce' ); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php
                        echo wp_kses(
                            sprintf(
                                /* translators: %s: URL to purchase page */
                                __( 'Don\'t have a license? <a href="%s" target="_blank">Purchase one here</a>.', 'affilync-woocommerce' ),
                                'https://affilync.com/pricing'
                            ),
                            array( 'a' => array( 'href' => array(), 'target' => array() ) )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="affilync-license-info-box">
                <h3><?php esc_html_e( 'About Licensing', 'affilync-woocommerce' ); ?></h3>
                <ul>
                    <li><?php esc_html_e( 'Each license is valid for one site.', 'affilync-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'Licenses are verified daily to ensure validity.', 'affilync-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'If verification fails, a 3-day grace period allows continued use while connection issues are resolved.', 'affilync-woocommerce' ); ?></li>
                    <li><?php esc_html_e( 'Deactivating a license allows you to use it on another site.', 'affilync-woocommerce' ); ?></li>
                </ul>
            </div>
        </div>

        <style>
            .affilync-license-section { max-width: 800px; }
            .affilync-license-status-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-left-width: 4px;
                padding: 20px;
                margin: 20px 0;
            }
            .affilync-license-status-card.status-valid { border-left-color: #00a32a; }
            .affilync-license-status-card.status-grace { border-left-color: #dba617; }
            .affilync-license-status-card.status-invalid,
            .affilync-license-status-card.status-expired,
            .affilync-license-status-card.status-suspended { border-left-color: #d63638; }
            .affilync-license-status-card.status-unchecked { border-left-color: #72aee6; }
            .license-status-header {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 15px;
            }
            .status-indicator {
                width: 12px;
                height: 12px;
                border-radius: 50%;
            }
            .status-valid .status-indicator { background: #00a32a; }
            .status-grace .status-indicator { background: #dba617; }
            .status-invalid .status-indicator,
            .status-expired .status-indicator,
            .status-suspended .status-indicator { background: #d63638; }
            .status-unchecked .status-indicator { background: #72aee6; }
            .license-details p { margin: 8px 0; }
            .license-details code { padding: 2px 6px; background: #f0f0f1; }
            .affilync-license-actions { margin: 20px 0; }
            .affilync-license-actions .button { margin-right: 10px; }
            .license-input-group {
                display: flex;
                gap: 10px;
                margin: 15px 0;
            }
            .license-input-group input { width: 350px; }
            .affilync-license-info-box {
                background: #f6f7f7;
                border: 1px solid #c3c4c7;
                padding: 15px 20px;
                margin-top: 30px;
            }
            .affilync-license-info-box h3 { margin-top: 0; }
            .affilync-license-info-box ul { margin: 0; padding-left: 20px; }
            .affilync-license-info-box li { margin: 8px 0; }
        </style>
        <?php
    }

    /**
     * Get CSS class for license status.
     *
     * @param string $status License status.
     * @return string CSS class.
     */
    private function get_license_status_class( $status ) {
        $classes = array(
            'valid'       => 'status-valid',
            'grace_period' => 'status-grace',
            'invalid'     => 'status-invalid',
            'expired'     => 'status-expired',
            'suspended'   => 'status-suspended',
            'unchecked'   => 'status-unchecked',
        );

        return isset( $classes[ $status ] ) ? $classes[ $status ] : 'status-unchecked';
    }

    /**
     * Get human-readable label for license status.
     *
     * @param string $status License status.
     * @return string Status label.
     */
    private function get_license_status_label( $status ) {
        $labels = array(
            'valid'        => __( 'License Active', 'affilync-woocommerce' ),
            'grace_period' => __( 'License Active (Verification Pending)', 'affilync-woocommerce' ),
            'invalid'      => __( 'License Invalid', 'affilync-woocommerce' ),
            'expired'      => __( 'License Expired', 'affilync-woocommerce' ),
            'suspended'    => __( 'License Suspended', 'affilync-woocommerce' ),
            'unchecked'    => __( 'No License Activated', 'affilync-woocommerce' ),
        );

        return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown Status', 'affilync-woocommerce' );
    }

    /**
     * AJAX handler for license activation.
     */
    public function ajax_activate_license() {
        check_ajax_referer( 'wp_rest', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

        if ( empty( $license_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a license key.', 'affilync-woocommerce' ) ) );
        }

        $license_manager = affilync()->license_manager;
        if ( ! $license_manager ) {
            wp_send_json_error( array( 'message' => __( 'License manager not available.', 'affilync-woocommerce' ) ) );
        }

        $result = $license_manager->activate( $license_key );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler for license deactivation.
     */
    public function ajax_deactivate_license() {
        check_ajax_referer( 'wp_rest', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $license_manager = affilync()->license_manager;
        if ( ! $license_manager ) {
            wp_send_json_error( array( 'message' => __( 'License manager not available.', 'affilync-woocommerce' ) ) );
        }

        $result = $license_manager->deactivate();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler for license check.
     */
    public function ajax_check_license() {
        check_ajax_referer( 'wp_rest', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $license_manager = affilync()->license_manager;
        if ( ! $license_manager ) {
            wp_send_json_error( array( 'message' => __( 'License manager not available.', 'affilync-woocommerce' ) ) );
        }

        // Force revalidation.
        $license_manager->revalidate_license();

        wp_send_json_success( array(
            'message' => __( 'License verified.', 'affilync-woocommerce' ),
            'data'    => $license_manager->get_license_info(),
        ) );
    }
}
