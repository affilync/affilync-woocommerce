<?php
/**
 * Enhanced Admin Stats Dashboard.
 *
 * Provides detailed analytics and statistics for the admin dashboard.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Admin Stats.
 *
 * @since 1.0.0
 */
class Affilync_Admin_Stats {

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
        // Add stats tab to settings page.
        add_filter( 'affilync_admin_tabs', array( $this, 'add_stats_tab' ) );

        // Register AJAX handlers.
        add_action( 'wp_ajax_affilync_get_chart_data', array( $this, 'ajax_get_chart_data' ) );
        add_action( 'wp_ajax_affilync_get_stats_summary', array( $this, 'ajax_get_stats_summary' ) );
        add_action( 'wp_ajax_affilync_export_stats', array( $this, 'ajax_export_stats' ) );

        // Enqueue scripts.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Add stats tab.
     *
     * @param array $tabs Existing tabs.
     * @return array
     */
    public function add_stats_tab( $tabs ) {
        $tabs['stats'] = __( 'Analytics', 'affilync-woocommerce' );
        return $tabs;
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'affilync' ) === false ) {
            return;
        }

        // Enqueue Chart.js.
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );

        wp_enqueue_script(
            'affilync-admin-stats',
            AFFILYNC_PLUGIN_URL . 'assets/js/admin-stats.js',
            array( 'jquery', 'chartjs' ),
            AFFILYNC_VERSION,
            true
        );

        wp_localize_script(
            'affilync-admin-stats',
            'affilyncStats',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'affilync_stats' ),
                'i18n'    => array(
                    'conversions' => __( 'Conversions', 'affilync-woocommerce' ),
                    'revenue'     => __( 'Revenue', 'affilync-woocommerce' ),
                    'commission'  => __( 'Commission', 'affilync-woocommerce' ),
                    'loading'     => __( 'Loading...', 'affilync-woocommerce' ),
                    'noData'      => __( 'No data available', 'affilync-woocommerce' ),
                ),
            )
        );
    }

    /**
     * Render the stats dashboard.
     */
    public function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '30days';
        $stats = $this->get_stats_summary( $period );
        $usage = affilync()->subscription->get_usage();
        ?>
        <div class="affilync-stats-dashboard">
            <!-- Period Selector -->
            <div class="affilync-stats-header">
                <h2><?php esc_html_e( 'Analytics Dashboard', 'affilync-woocommerce' ); ?></h2>
                <div class="affilync-period-selector">
                    <select id="affilync-period" class="affilync-select">
                        <option value="7days" <?php selected( $period, '7days' ); ?>><?php esc_html_e( 'Last 7 Days', 'affilync-woocommerce' ); ?></option>
                        <option value="30days" <?php selected( $period, '30days' ); ?>><?php esc_html_e( 'Last 30 Days', 'affilync-woocommerce' ); ?></option>
                        <option value="90days" <?php selected( $period, '90days' ); ?>><?php esc_html_e( 'Last 90 Days', 'affilync-woocommerce' ); ?></option>
                        <option value="year" <?php selected( $period, 'year' ); ?>><?php esc_html_e( 'This Year', 'affilync-woocommerce' ); ?></option>
                    </select>
                    <button type="button" class="button affilync-export-btn" data-format="csv">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Export CSV', 'affilync-woocommerce' ); ?>
                    </button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="affilync-stats-cards">
                <div class="affilync-stat-card">
                    <div class="stat-icon conversions">
                        <span class="dashicons dashicons-chart-bar"></span>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value"><?php echo esc_html( number_format( $stats['total_conversions'] ) ); ?></span>
                        <span class="stat-label"><?php esc_html_e( 'Total Conversions', 'affilync-woocommerce' ); ?></span>
                        <?php if ( $stats['conversion_change'] !== 0 ) : ?>
                            <span class="stat-change <?php echo $stats['conversion_change'] >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo $stats['conversion_change'] >= 0 ? '+' : ''; ?><?php echo esc_html( $stats['conversion_change'] ); ?>%
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="affilync-stat-card">
                    <div class="stat-icon revenue">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value"><?php echo wp_kses_post( wc_price( $stats['total_revenue'] ) ); ?></span>
                        <span class="stat-label"><?php esc_html_e( 'Affiliate Revenue', 'affilync-woocommerce' ); ?></span>
                        <?php if ( $stats['revenue_change'] !== 0 ) : ?>
                            <span class="stat-change <?php echo $stats['revenue_change'] >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo $stats['revenue_change'] >= 0 ? '+' : ''; ?><?php echo esc_html( $stats['revenue_change'] ); ?>%
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="affilync-stat-card">
                    <div class="stat-icon commission">
                        <span class="dashicons dashicons-awards"></span>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value"><?php echo wp_kses_post( wc_price( $stats['total_commission'] ) ); ?></span>
                        <span class="stat-label"><?php esc_html_e( 'Total Commission', 'affilync-woocommerce' ); ?></span>
                        <?php if ( $stats['commission_change'] !== 0 ) : ?>
                            <span class="stat-change <?php echo $stats['commission_change'] >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo $stats['commission_change'] >= 0 ? '+' : ''; ?><?php echo esc_html( $stats['commission_change'] ); ?>%
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="affilync-stat-card">
                    <div class="stat-icon average">
                        <span class="dashicons dashicons-performance"></span>
                    </div>
                    <div class="stat-content">
                        <span class="stat-value"><?php echo wp_kses_post( wc_price( $stats['average_order'] ) ); ?></span>
                        <span class="stat-label"><?php esc_html_e( 'Avg Order Value', 'affilync-woocommerce' ); ?></span>
                    </div>
                </div>
            </div>

            <!-- Usage Meter -->
            <div class="affilync-usage-section">
                <h3><?php esc_html_e( 'Plan Usage', 'affilync-woocommerce' ); ?></h3>
                <div class="affilync-usage-meters">
                    <div class="usage-meter">
                        <div class="usage-header">
                            <span class="usage-label"><?php esc_html_e( 'Conversions', 'affilync-woocommerce' ); ?></span>
                            <span class="usage-count">
                                <?php
                                if ( $usage['conversions']['limit'] === -1 ) {
                                    echo esc_html( number_format( $usage['conversions']['used'] ) . ' / ∞' );
                                } else {
                                    echo esc_html( number_format( $usage['conversions']['used'] ) . ' / ' . number_format( $usage['conversions']['limit'] ) );
                                }
                                ?>
                            </span>
                        </div>
                        <div class="usage-bar">
                            <div class="usage-fill <?php echo $usage['conversions']['percentage'] >= 90 ? 'warning' : ''; ?>"
                                 style="width: <?php echo esc_attr( min( $usage['conversions']['percentage'], 100 ) ); ?>%">
                            </div>
                        </div>
                    </div>

                    <div class="usage-meter">
                        <div class="usage-header">
                            <span class="usage-label"><?php esc_html_e( 'Products', 'affilync-woocommerce' ); ?></span>
                            <span class="usage-count">
                                <?php
                                if ( $usage['products']['limit'] === -1 ) {
                                    echo esc_html( number_format( $usage['products']['used'] ) . ' / ∞' );
                                } else {
                                    echo esc_html( number_format( $usage['products']['used'] ) . ' / ' . number_format( $usage['products']['limit'] ) );
                                }
                                ?>
                            </span>
                        </div>
                        <div class="usage-bar">
                            <div class="usage-fill <?php echo $usage['products']['percentage'] >= 90 ? 'warning' : ''; ?>"
                                 style="width: <?php echo esc_attr( min( $usage['products']['percentage'], 100 ) ); ?>%">
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ( $usage['conversions']['percentage'] >= 80 || $usage['products']['percentage'] >= 80 ) : ?>
                    <div class="affilync-upgrade-notice">
                        <p>
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e( "You're approaching your plan limits.", 'affilync-woocommerce' ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=affilync-settings&tab=billing' ) ); ?>">
                                <?php esc_html_e( 'Upgrade Now', 'affilync-woocommerce' ); ?>
                            </a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Charts Section -->
            <div class="affilync-charts-section">
                <div class="affilync-chart-container">
                    <h3><?php esc_html_e( 'Conversions Over Time', 'affilync-woocommerce' ); ?></h3>
                    <canvas id="affilync-conversions-chart"></canvas>
                </div>

                <div class="affilync-chart-container">
                    <h3><?php esc_html_e( 'Revenue Over Time', 'affilync-woocommerce' ); ?></h3>
                    <canvas id="affilync-revenue-chart"></canvas>
                </div>
            </div>

            <!-- Top Affiliates -->
            <div class="affilync-top-affiliates">
                <h3><?php esc_html_e( 'Top Affiliates', 'affilync-woocommerce' ); ?></h3>
                <?php $this->render_top_affiliates_table( $stats['top_affiliates'] ?? array() ); ?>
            </div>

            <!-- Recent Activity -->
            <div class="affilync-recent-activity">
                <h3><?php esc_html_e( 'Recent Conversions', 'affilync-woocommerce' ); ?></h3>
                <?php $this->render_recent_conversions(); ?>
            </div>

            <!-- Status Section -->
            <div class="affilync-status-section">
                <h3><?php esc_html_e( 'Connection Status', 'affilync-woocommerce' ); ?></h3>
                <?php $this->render_status_cards(); ?>
            </div>
        </div>

        <style>
            .affilync-stats-dashboard {
                max-width: 1400px;
            }

            .affilync-stats-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }

            .affilync-period-selector {
                display: flex;
                gap: 10px;
                align-items: center;
            }

            .affilync-stats-cards {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }

            .affilync-stat-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 20px;
                display: flex;
                gap: 15px;
            }

            .stat-icon {
                width: 50px;
                height: 50px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .stat-icon.conversions { background: #e6f0ff; color: #0066e6; }
            .stat-icon.revenue { background: #e6fff2; color: #00b359; }
            .stat-icon.commission { background: #fff0e6; color: #ff6600; }
            .stat-icon.average { background: #f0e6ff; color: #7c3aed; }

            .stat-icon .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
            }

            .stat-value {
                font-size: 28px;
                font-weight: 600;
                color: #1a1a1a;
                display: block;
            }

            .stat-label {
                font-size: 13px;
                color: #646970;
            }

            .stat-change {
                font-size: 12px;
                padding: 2px 6px;
                border-radius: 4px;
                margin-left: 5px;
            }

            .stat-change.positive { background: #e6fff2; color: #00b359; }
            .stat-change.negative { background: #ffe6e6; color: #d63638; }

            .affilync-usage-section {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 30px;
            }

            .affilync-usage-meters {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
            }

            .usage-meter {
                margin-bottom: 10px;
            }

            .usage-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 8px;
            }

            .usage-bar {
                height: 10px;
                background: #f0f0f1;
                border-radius: 5px;
                overflow: hidden;
            }

            .usage-fill {
                height: 100%;
                background: linear-gradient(90deg, #0066e6, #00b359);
                border-radius: 5px;
                transition: width 0.5s ease;
            }

            .usage-fill.warning {
                background: linear-gradient(90deg, #ff6600, #d63638);
            }

            .affilync-upgrade-notice {
                background: #fff8e5;
                border: 1px solid #ffcc00;
                border-radius: 4px;
                padding: 10px 15px;
                margin-top: 15px;
            }

            .affilync-upgrade-notice p {
                margin: 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .affilync-charts-section {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }

            .affilync-chart-container {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 20px;
            }

            .affilync-chart-container h3 {
                margin-top: 0;
            }

            .affilync-top-affiliates,
            .affilync-recent-activity,
            .affilync-status-section {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .affilync-top-affiliates h3,
            .affilync-recent-activity h3,
            .affilync-status-section h3 {
                margin-top: 0;
            }

            .affilync-status-cards {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }

            .status-card {
                padding: 15px;
                border-radius: 6px;
                background: #f8f8f8;
            }

            .status-card.connected {
                background: #e6fff2;
                border: 1px solid #00b359;
            }

            .status-card.warning {
                background: #fff8e5;
                border: 1px solid #ffcc00;
            }

            .status-card.error {
                background: #ffe6e6;
                border: 1px solid #d63638;
            }

            .status-card-label {
                font-size: 12px;
                color: #646970;
                display: block;
            }

            .status-card-value {
                font-size: 16px;
                font-weight: 600;
            }
        </style>
        <?php
    }

    /**
     * Get stats summary for a period.
     *
     * @param string $period Time period.
     * @return array
     */
    public function get_stats_summary( $period = '30days' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'affilync_conversions';
        $date_format = '%Y-%m-%d';

        // Calculate date range.
        $end_date = current_time( 'Y-m-d' );
        switch ( $period ) {
            case '7days':
                $start_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
                $prev_start = gmdate( 'Y-m-d', strtotime( '-14 days' ) );
                break;
            case '90days':
                $start_date = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
                $prev_start = gmdate( 'Y-m-d', strtotime( '-180 days' ) );
                break;
            case 'year':
                $start_date = gmdate( 'Y-01-01' );
                $prev_start = gmdate( 'Y-01-01', strtotime( '-1 year' ) );
                break;
            default: // 30days
                $start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
                $prev_start = gmdate( 'Y-m-d', strtotime( '-60 days' ) );
        }

        // Current period stats.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $current = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total_conversions,
                    COALESCE(SUM(order_total), 0) as total_revenue,
                    COALESCE(SUM(commission_amount), 0) as total_commission,
                    COALESCE(AVG(order_total), 0) as average_order
                FROM {$table}
                WHERE created_at >= %s AND created_at <= %s",
                $start_date,
                $end_date . ' 23:59:59'
            )
        );

        // Previous period stats for comparison.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $previous = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total_conversions,
                    COALESCE(SUM(order_total), 0) as total_revenue,
                    COALESCE(SUM(commission_amount), 0) as total_commission
                FROM {$table}
                WHERE created_at >= %s AND created_at < %s",
                $prev_start,
                $start_date
            )
        );

        // Calculate percentage changes.
        $conversion_change = $this->calculate_change( $current->total_conversions, $previous->total_conversions );
        $revenue_change    = $this->calculate_change( $current->total_revenue, $previous->total_revenue );
        $commission_change = $this->calculate_change( $current->total_commission, $previous->total_commission );

        // Get top affiliates.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_affiliates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    affiliate_id,
                    COUNT(*) as conversions,
                    SUM(order_total) as revenue,
                    SUM(commission_amount) as commission
                FROM {$table}
                WHERE created_at >= %s AND affiliate_id IS NOT NULL
                GROUP BY affiliate_id
                ORDER BY revenue DESC
                LIMIT 10",
                $start_date
            )
        );

        return array(
            'total_conversions'  => (int) $current->total_conversions,
            'total_revenue'      => (float) $current->total_revenue,
            'total_commission'   => (float) $current->total_commission,
            'average_order'      => (float) $current->average_order,
            'conversion_change'  => $conversion_change,
            'revenue_change'     => $revenue_change,
            'commission_change'  => $commission_change,
            'top_affiliates'     => $top_affiliates,
            'period'             => $period,
            'start_date'         => $start_date,
            'end_date'           => $end_date,
        );
    }

    /**
     * Calculate percentage change.
     *
     * @param float $current  Current value.
     * @param float $previous Previous value.
     * @return int
     */
    private function calculate_change( $current, $previous ) {
        if ( $previous == 0 ) {
            return $current > 0 ? 100 : 0;
        }

        return round( ( ( $current - $previous ) / $previous ) * 100 );
    }

    /**
     * Get chart data.
     *
     * @param string $period    Time period.
     * @param string $data_type Data type (conversions, revenue, commission).
     * @return array
     */
    public function get_chart_data( $period = '30days', $data_type = 'conversions' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'affilync_conversions';

        // Calculate date range.
        switch ( $period ) {
            case '7days':
                $start_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
                $group_by   = '%Y-%m-%d';
                break;
            case '90days':
                $start_date = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
                $group_by   = '%Y-%W';
                break;
            case 'year':
                $start_date = gmdate( 'Y-01-01' );
                $group_by   = '%Y-%m';
                break;
            default: // 30days
                $start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
                $group_by   = '%Y-%m-%d';
        }

        $column = $data_type === 'conversions' ? 'COUNT(*)' : "COALESCE(SUM({$data_type === 'revenue' ? 'order_total' : 'commission_amount'}), 0)";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    DATE_FORMAT(created_at, %s) as period,
                    {$column} as value
                FROM {$table}
                WHERE created_at >= %s
                GROUP BY period
                ORDER BY period ASC",
                $group_by,
                $start_date
            )
        );

        $labels = array();
        $values = array();

        foreach ( $results as $row ) {
            $labels[] = $row->period;
            $values[] = (float) $row->value;
        }

        return array(
            'labels' => $labels,
            'values' => $values,
        );
    }

    /**
     * Render top affiliates table.
     *
     * @param array $affiliates Top affiliates data.
     */
    private function render_top_affiliates_table( $affiliates ) {
        if ( empty( $affiliates ) ) {
            echo '<p class="description">' . esc_html__( 'No affiliate data available yet.', 'affilync-woocommerce' ) . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Affiliate', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Conversions', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Revenue', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Commission', 'affilync-woocommerce' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $affiliates as $affiliate ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $affiliate->affiliate_id ); ?></code></td>
                        <td><?php echo esc_html( number_format( $affiliate->conversions ) ); ?></td>
                        <td><?php echo wp_kses_post( wc_price( $affiliate->revenue ) ); ?></td>
                        <td><?php echo wp_kses_post( wc_price( $affiliate->commission ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render recent conversions.
     */
    private function render_recent_conversions() {
        global $wpdb;

        $table = $wpdb->prefix . 'affilync_conversions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $conversions = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 10"
        );

        if ( empty( $conversions ) ) {
            echo '<p class="description">' . esc_html__( 'No conversions recorded yet.', 'affilync-woocommerce' ) . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Affiliate', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Amount', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Commission', 'affilync-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'affilync-woocommerce' ); ?></th>
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
                        <td><code><?php echo esc_html( $conversion->affiliate_id ?: '-' ); ?></code></td>
                        <td><?php echo wp_kses_post( wc_price( $conversion->order_total ) ); ?></td>
                        <td><?php echo wp_kses_post( wc_price( $conversion->commission_amount ) ); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo esc_attr( $conversion->status ); ?>">
                                <?php echo esc_html( ucfirst( $conversion->status ) ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( human_time_diff( strtotime( $conversion->created_at ), current_time( 'timestamp' ) ) . ' ago' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render status cards.
     */
    private function render_status_cards() {
        $connected = affilync()->api_client->is_connected();
        $verified  = affilync()->brand_verification->is_verified();
        $plan      = affilync()->subscription->get_subscription_status();
        $sync      = affilync()->product_sync->get_stats();
        ?>
        <div class="affilync-status-cards">
            <div class="status-card <?php echo $connected ? 'connected' : 'error'; ?>">
                <span class="status-card-label"><?php esc_html_e( 'API Connection', 'affilync-woocommerce' ); ?></span>
                <span class="status-card-value">
                    <?php echo $connected ? '✓ ' . esc_html__( 'Connected', 'affilync-woocommerce' ) : '✗ ' . esc_html__( 'Disconnected', 'affilync-woocommerce' ); ?>
                </span>
            </div>

            <div class="status-card <?php echo $verified ? 'connected' : 'warning'; ?>">
                <span class="status-card-label"><?php esc_html_e( 'Brand Verification', 'affilync-woocommerce' ); ?></span>
                <span class="status-card-value">
                    <?php echo $verified ? '✓ ' . esc_html__( 'Verified', 'affilync-woocommerce' ) : esc_html__( 'Not Verified', 'affilync-woocommerce' ); ?>
                </span>
            </div>

            <div class="status-card connected">
                <span class="status-card-label"><?php esc_html_e( 'Subscription Plan', 'affilync-woocommerce' ); ?></span>
                <span class="status-card-value"><?php echo esc_html( $plan['plan_name'] ); ?></span>
            </div>

            <div class="status-card <?php echo $sync['failed'] > 0 ? 'warning' : 'connected'; ?>">
                <span class="status-card-label"><?php esc_html_e( 'Product Sync', 'affilync-woocommerce' ); ?></span>
                <span class="status-card-value">
                    <?php echo esc_html( sprintf( __( '%d synced', 'affilync-woocommerce' ), $sync['synced'] ) ); ?>
                    <?php if ( $sync['pending'] > 0 ) : ?>
                        <small>(<?php echo esc_html( sprintf( __( '%d pending', 'affilync-woocommerce' ), $sync['pending'] ) ); ?>)</small>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler: Get chart data.
     */
    public function ajax_get_chart_data() {
        check_ajax_referer( 'affilync_stats', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $period    = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : '30days';
        $data_type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'conversions';

        $data = $this->get_chart_data( $period, $data_type );

        wp_send_json_success( $data );
    }

    /**
     * AJAX handler: Get stats summary.
     */
    public function ajax_get_stats_summary() {
        check_ajax_referer( 'affilync_stats', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $period = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : '30days';
        $stats  = $this->get_stats_summary( $period );

        wp_send_json_success( $stats );
    }

    /**
     * AJAX handler: Export stats.
     */
    public function ajax_export_stats() {
        check_ajax_referer( 'affilync_stats', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        global $wpdb;

        $period = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : '30days';
        $format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'csv';

        $table = $wpdb->prefix . 'affilync_conversions';

        // Calculate date range.
        switch ( $period ) {
            case '7days':
                $start_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
                break;
            case '90days':
                $start_date = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
                break;
            case 'year':
                $start_date = gmdate( 'Y-01-01' );
                break;
            default:
                $start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $conversions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    order_id,
                    affiliate_id,
                    campaign_id,
                    order_total,
                    commission_amount,
                    currency,
                    status,
                    created_at
                FROM {$table}
                WHERE created_at >= %s
                ORDER BY created_at DESC",
                $start_date
            ),
            ARRAY_A
        );

        if ( $format === 'csv' ) {
            $csv_data = $this->generate_csv( $conversions );
            wp_send_json_success( array(
                'filename' => 'affilync-conversions-' . $period . '.csv',
                'data'     => $csv_data,
            ) );
        }

        wp_send_json_success( array( 'data' => $conversions ) );
    }

    /**
     * Generate CSV data.
     *
     * @param array $data Data to convert to CSV.
     * @return string
     */
    private function generate_csv( $data ) {
        if ( empty( $data ) ) {
            return '';
        }

        $output = fopen( 'php://temp', 'w' );

        // Header row.
        fputcsv( $output, array_keys( $data[0] ) );

        // Data rows.
        foreach ( $data as $row ) {
            fputcsv( $output, $row );
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        return $csv;
    }
}
