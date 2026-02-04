<?php
/**
 * Dashboard Widget for Affilync stats.
 *
 * Displays conversion stats on the WordPress dashboard.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Dashboard Widget.
 *
 * @since 1.0.0
 */
class Affilync_Admin_Dashboard_Widget {

    /**
     * Widget ID.
     *
     * @var string
     */
    const WIDGET_ID = 'affilync_dashboard_widget';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
    }

    /**
     * Register the dashboard widget.
     */
    public function register_widget() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __( 'Affilync Conversions', 'affilync-woocommerce' ),
            array( $this, 'render_widget' ),
            array( $this, 'configure_widget' )
        );
    }

    /**
     * Render the dashboard widget.
     */
    public function render_widget() {
        // Check connection.
        $connected = affilync()->api_client->is_connected();

        if ( ! $connected ) {
            $this->render_not_connected();
            return;
        }

        // Get stats.
        $period = $this->get_widget_option( 'period', 'month' );
        $stats = affilync()->conversion_tracker->get_stats( $period );

        $this->render_stats( $stats, $period );
    }

    /**
     * Render stats display.
     *
     * @param array  $stats  Conversion statistics.
     * @param string $period Time period.
     */
    private function render_stats( $stats, $period ) {
        $period_labels = array(
            'today' => __( 'Today', 'affilync-woocommerce' ),
            'week'  => __( 'Last 7 Days', 'affilync-woocommerce' ),
            'month' => __( 'Last 30 Days', 'affilync-woocommerce' ),
            'all'   => __( 'All Time', 'affilync-woocommerce' ),
        );
        ?>
        <style>
            .affilync-widget-stats {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 15px;
                margin-bottom: 15px;
            }
            .affilync-stat {
                text-align: center;
                padding: 10px;
                background: #f6f7f7;
                border-radius: 4px;
            }
            .affilync-stat-value {
                font-size: 24px;
                font-weight: 600;
                color: #0066e6;
                display: block;
            }
            .affilync-stat-label {
                font-size: 12px;
                color: #646970;
                text-transform: uppercase;
            }
            .affilync-widget-period {
                font-size: 12px;
                color: #646970;
                margin-bottom: 10px;
            }
            .affilync-widget-status {
                display: flex;
                gap: 10px;
                font-size: 12px;
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px solid #dcdcde;
            }
            .affilync-status-item {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .status-pending { color: #996800; }
            .status-approved { color: #007017; }
            .status-rejected { color: #d63638; }
        </style>

        <div class="affilync-widget-period">
            <?php echo esc_html( $period_labels[ $period ] ?? $period ); ?>
        </div>

        <div class="affilync-widget-stats">
            <div class="affilync-stat">
                <span class="affilync-stat-value"><?php echo esc_html( $stats['total_conversions'] ); ?></span>
                <span class="affilync-stat-label"><?php esc_html_e( 'Conversions', 'affilync-woocommerce' ); ?></span>
            </div>
            <div class="affilync-stat">
                <span class="affilync-stat-value"><?php echo wp_kses_post( wc_price( $stats['total_revenue'] ) ); ?></span>
                <span class="affilync-stat-label"><?php esc_html_e( 'Revenue', 'affilync-woocommerce' ); ?></span>
            </div>
            <div class="affilync-stat">
                <span class="affilync-stat-value"><?php echo wp_kses_post( wc_price( $stats['total_commission'] ) ); ?></span>
                <span class="affilync-stat-label"><?php esc_html_e( 'Commission', 'affilync-woocommerce' ); ?></span>
            </div>
        </div>

        <div class="affilync-widget-status">
            <span class="affilync-status-item status-pending">
                <span class="dashicons dashicons-clock"></span>
                <?php echo esc_html( sprintf( __( '%d Pending', 'affilync-woocommerce' ), $stats['pending'] ) ); ?>
            </span>
            <span class="affilync-status-item status-approved">
                <span class="dashicons dashicons-yes"></span>
                <?php echo esc_html( sprintf( __( '%d Approved', 'affilync-woocommerce' ), $stats['approved'] ) ); ?>
            </span>
            <span class="affilync-status-item status-rejected">
                <span class="dashicons dashicons-no"></span>
                <?php echo esc_html( sprintf( __( '%d Rejected', 'affilync-woocommerce' ), $stats['rejected'] ) ); ?>
            </span>
        </div>

        <p style="margin-top: 15px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=affilync-settings&tab=conversions' ) ); ?>" class="button">
                <?php esc_html_e( 'View All Conversions', 'affilync-woocommerce' ); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Render not connected state.
     */
    private function render_not_connected() {
        ?>
        <style>
            .affilync-not-connected {
                text-align: center;
                padding: 20px;
            }
            .affilync-not-connected .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #c3c4c7;
                margin-bottom: 10px;
            }
        </style>
        <div class="affilync-not-connected">
            <span class="dashicons dashicons-admin-plugins"></span>
            <p><?php esc_html_e( 'Connect your Affilync account to see conversion stats.', 'affilync-woocommerce' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=affilync-settings' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Connect Now', 'affilync-woocommerce' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Configure widget settings.
     */
    public function configure_widget() {
        if ( isset( $_POST['affilync_widget_period'] ) ) {
            check_admin_referer( 'affilync_widget_config' );

            $period = sanitize_key( wp_unslash( $_POST['affilync_widget_period'] ) );
            $this->set_widget_option( 'period', $period );
        }

        $current_period = $this->get_widget_option( 'period', 'month' );
        ?>
        <p>
            <label for="affilync_widget_period">
                <?php esc_html_e( 'Time Period:', 'affilync-woocommerce' ); ?>
            </label>
            <select id="affilync_widget_period" name="affilync_widget_period">
                <option value="today" <?php selected( $current_period, 'today' ); ?>>
                    <?php esc_html_e( 'Today', 'affilync-woocommerce' ); ?>
                </option>
                <option value="week" <?php selected( $current_period, 'week' ); ?>>
                    <?php esc_html_e( 'Last 7 Days', 'affilync-woocommerce' ); ?>
                </option>
                <option value="month" <?php selected( $current_period, 'month' ); ?>>
                    <?php esc_html_e( 'Last 30 Days', 'affilync-woocommerce' ); ?>
                </option>
                <option value="all" <?php selected( $current_period, 'all' ); ?>>
                    <?php esc_html_e( 'All Time', 'affilync-woocommerce' ); ?>
                </option>
            </select>
        </p>
        <?php wp_nonce_field( 'affilync_widget_config' ); ?>
        <?php
    }

    /**
     * Get widget option.
     *
     * @param string $key     Option key.
     * @param mixed  $default Default value.
     * @return mixed Option value.
     */
    private function get_widget_option( $key, $default = null ) {
        $options = get_option( 'affilync_widget_options', array() );
        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }

    /**
     * Set widget option.
     *
     * @param string $key   Option key.
     * @param mixed  $value Option value.
     */
    private function set_widget_option( $key, $value ) {
        $options = get_option( 'affilync_widget_options', array() );
        $options[ $key ] = $value;
        update_option( 'affilync_widget_options', $options );
    }
}
