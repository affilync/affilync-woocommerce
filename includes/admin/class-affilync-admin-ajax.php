<?php
/**
 * AJAX Handlers for admin dashboard operations.
 *
 * Provides AJAX endpoints for product sync, connection testing,
 * conversion sync, dashboard stats, and webhook management.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Admin AJAX Handlers.
 *
 * @since 1.0.0
 */
class Affilync_Admin_Ajax {

    /**
     * API client.
     *
     * @var Affilync_API_Client
     */
    private $api_client;

    /**
     * Product sync handler.
     *
     * @var Affilync_Sync_Product_Sync
     */
    private $product_sync;

    /**
     * Conversion tracker.
     *
     * @var Affilync_Tracking_Conversion_Tracker
     */
    private $conversion_tracker;

    /**
     * Webhook handler.
     *
     * @var Affilync_API_Webhook_Handler
     */
    private $webhook_handler;

    /**
     * Audit logger.
     *
     * @var Affilync_Security_Audit_Logger
     */
    private $audit_logger;

    /**
     * HMAC validator.
     *
     * @var Affilync_Security_HMAC_Validator
     */
    private $hmac_validator;

    /**
     * Nonce action name for AJAX requests.
     *
     * @var string
     */
    const NONCE_ACTION = 'affilync_ajax_nonce';

    /**
     * Transient key prefix for cached stats.
     *
     * @var string
     */
    const STATS_CACHE_PREFIX = 'affilync_dash_stats_';

    /**
     * Stats cache duration in seconds (5 minutes).
     *
     * @var int
     */
    const STATS_CACHE_DURATION = 300;

    /**
     * Constructor.
     *
     * @param Affilync_API_Client                  $api_client          API client.
     * @param Affilync_Sync_Product_Sync           $product_sync        Product sync.
     * @param Affilync_Tracking_Conversion_Tracker $conversion_tracker  Conversion tracker.
     * @param Affilync_API_Webhook_Handler         $webhook_handler     Webhook handler.
     * @param Affilync_Security_Audit_Logger       $audit_logger        Audit logger.
     * @param Affilync_Security_HMAC_Validator      $hmac_validator      HMAC validator.
     */
    public function __construct(
        $api_client,
        $product_sync,
        $conversion_tracker,
        $webhook_handler,
        $audit_logger,
        $hmac_validator
    ) {
        $this->api_client         = $api_client;
        $this->product_sync       = $product_sync;
        $this->conversion_tracker = $conversion_tracker;
        $this->webhook_handler    = $webhook_handler;
        $this->audit_logger       = $audit_logger;
        $this->hmac_validator     = $hmac_validator;

        $this->init_hooks();
    }

    /**
     * Initialize AJAX hooks.
     */
    private function init_hooks() {
        add_action( 'wp_ajax_affilync_sync_products', array( $this, 'ajax_sync_products' ) );
        add_action( 'wp_ajax_affilync_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_affilync_sync_conversions', array( $this, 'ajax_sync_conversions' ) );
        add_action( 'wp_ajax_affilync_get_dashboard_stats', array( $this, 'ajax_get_dashboard_stats' ) );
        add_action( 'wp_ajax_affilync_manage_webhooks', array( $this, 'ajax_manage_webhooks' ) );
        add_action( 'wp_ajax_affilync_get_call_logs', array( $this, 'ajax_get_call_logs' ) );

        // Enqueue scripts on Affilync admin pages.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Enqueue admin scripts for AJAX handlers.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'affilync' ) === false ) {
            return;
        }

        wp_localize_script(
            'affilync-admin',
            'affilyncAjax',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
                'i18n'    => array(
                    'syncingProducts'     => __( 'Syncing products...', 'affilync-woocommerce' ),
                    'syncComplete'        => __( 'Product sync complete.', 'affilync-woocommerce' ),
                    'syncFailed'          => __( 'Product sync failed.', 'affilync-woocommerce' ),
                    'testingConnection'   => __( 'Testing connection...', 'affilync-woocommerce' ),
                    'connectionSuccess'   => __( 'Connection successful.', 'affilync-woocommerce' ),
                    'connectionFailed'    => __( 'Connection test failed.', 'affilync-woocommerce' ),
                    'syncingConversions'  => __( 'Syncing conversions...', 'affilync-woocommerce' ),
                    'conversionsSynced'   => __( 'Conversions synced.', 'affilync-woocommerce' ),
                    'loadingStats'        => __( 'Loading stats...', 'affilync-woocommerce' ),
                    'managingWebhooks'    => __( 'Managing webhooks...', 'affilync-woocommerce' ),
                    'webhooksUpdated'     => __( 'Webhooks updated.', 'affilync-woocommerce' ),
                ),
            )
        );
    }

    // ========================================================================
    // 1. Product Sync AJAX
    // ========================================================================

    /**
     * AJAX handler: Sync products to Affilync.
     *
     * Supports batch sync with progress tracking and per-product error handling.
     * Accepts optional parameters:
     *   - product_ids (array): Specific product IDs to sync (empty = all pending)
     *   - batch_size (int): Number of products per batch (default 50, max 200)
     *   - offset (int): Offset for pagination (default 0)
     *   - full_sync (bool): Queue all published products for sync first
     */
    public function ajax_sync_products() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        // Check API connection before starting.
        if ( ! $this->api_client->is_connected() ) {
            wp_send_json_error( array(
                'message' => __( 'Not connected to Affilync. Please connect your account first.', 'affilync-woocommerce' ),
            ) );
        }

        $full_sync  = isset( $_POST['full_sync'] ) && filter_var( wp_unslash( $_POST['full_sync'] ), FILTER_VALIDATE_BOOLEAN );
        $batch_size = isset( $_POST['batch_size'] ) ? min( absint( wp_unslash( $_POST['batch_size'] ) ), 200 ) : 50;
        $offset     = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

        // Handle specific product IDs if provided.
        $product_ids = array();
        if ( isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ) {
            $product_ids = array_map( 'absint', wp_unslash( $_POST['product_ids'] ) );
            $product_ids = array_filter( $product_ids );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'affilync_product_sync';

        // If full sync requested, queue all published products first.
        if ( $full_sync ) {
            $all_product_ids = wc_get_products( array(
                'status' => 'publish',
                'limit'  => -1,
                'return' => 'ids',
            ) );

            foreach ( $all_product_ids as $pid ) {
                $this->product_sync->queue_product_sync( $pid );
            }
        }

        // If specific product IDs were provided, queue them.
        if ( ! empty( $product_ids ) ) {
            foreach ( $product_ids as $pid ) {
                $this->product_sync->queue_product_sync( $pid );
            }
        }

        // Get total pending count for progress tracking.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE sync_status = 'pending' AND retry_count < 5"
        );

        // Fetch the batch of products to sync.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pending = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id FROM {$table}
                WHERE sync_status = 'pending' AND retry_count < 5
                ORDER BY updated_at ASC
                LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            )
        );

        $results = array();
        $synced  = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ( $pending as $row ) {
            $pid     = (int) $row->product_id;
            $product = wc_get_product( $pid );

            if ( ! $product ) {
                $results[] = array(
                    'product_id' => $pid,
                    'status'     => 'failed',
                    'error'      => __( 'Product not found.', 'affilync-woocommerce' ),
                );
                $failed++;
                continue;
            }

            // Skip non-published products.
            if ( $product->get_status() !== 'publish' ) {
                $results[] = array(
                    'product_id' => $pid,
                    'name'       => $product->get_name(),
                    'status'     => 'skipped',
                    'reason'     => __( 'Not published.', 'affilync-woocommerce' ),
                );
                $skipped++;
                continue;
            }

            $success = $this->product_sync->sync_product( $pid );

            if ( $success ) {
                $results[] = array(
                    'product_id' => $pid,
                    'name'       => $product->get_name(),
                    'status'     => 'synced',
                );
                $synced++;
            } else {
                // Fetch the error message from the sync table.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $error_row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT error_message FROM {$table} WHERE product_id = %d",
                        $pid
                    )
                );

                $results[] = array(
                    'product_id' => $pid,
                    'name'       => $product->get_name(),
                    'status'     => 'failed',
                    'error'      => $error_row ? $error_row->error_message : __( 'Unknown error.', 'affilync-woocommerce' ),
                );
                $failed++;
            }
        }

        // Calculate remaining.
        $processed = $synced + $failed + $skipped;
        $remaining = max( 0, $total_pending - $processed );
        $has_more  = $remaining > 0;

        // Log the sync operation.
        $this->audit_logger->info(
            'product_sync_ajax',
            array(
                'synced'  => $synced,
                'failed'  => $failed,
                'skipped' => $skipped,
                'total'   => $total_pending,
            )
        );

        wp_send_json_success( array(
            'synced'     => $synced,
            'failed'     => $failed,
            'skipped'    => $skipped,
            'total'      => $total_pending,
            'remaining'  => $remaining,
            'has_more'   => $has_more,
            'next_offset' => $offset + $batch_size,
            'results'    => $results,
            'stats'      => $this->product_sync->get_stats(),
        ) );
    }

    // ========================================================================
    // 2. Connection Test AJAX
    // ========================================================================

    /**
     * AJAX handler: Test connection to Affilync API.
     *
     * Performs a comprehensive connection test including:
     * - API key verification (brand profile fetch)
     * - API health check (ping the health endpoint)
     * - Webhook status check (whether webhooks are registered)
     * - Plugin version and WordPress environment diagnostics
     */
    public function ajax_test_connection() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $diagnostics = array(
            'api_connected'    => false,
            'api_healthy'      => false,
            'webhooks_active'  => false,
            'brand_info'       => null,
            'plugin_version'   => AFFILYNC_VERSION,
            'wordpress_version' => get_bloginfo( 'version' ),
            'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
            'php_version'      => PHP_VERSION,
            'api_url'          => $this->api_client->get_api_url(),
            'site_url'         => get_site_url(),
            'ssl_enabled'      => is_ssl(),
            'errors'           => array(),
            'timestamp'        => current_time( 'c' ),
        );

        // Test 1: API health check (does not require auth).
        $health = $this->api_client->health_check();
        if ( is_wp_error( $health ) ) {
            $diagnostics['errors'][] = array(
                'test'    => 'api_health',
                'message' => $health->get_error_message(),
            );
        } else {
            $diagnostics['api_healthy'] = ! empty( $health['healthy'] );
        }

        // Test 2: API connection (requires valid credentials).
        if ( $this->api_client->is_connected() ) {
            $connection = $this->api_client->test_connection();
            if ( is_wp_error( $connection ) ) {
                $diagnostics['errors'][] = array(
                    'test'    => 'api_connection',
                    'message' => $connection->get_error_message(),
                    'code'    => $connection->get_error_code(),
                );
            } else {
                $diagnostics['api_connected'] = true;
                $diagnostics['brand_info']    = isset( $connection['brand'] ) ? array(
                    'name'    => isset( $connection['brand']['name'] ) ? sanitize_text_field( $connection['brand']['name'] ) : null,
                    'id'      => isset( $connection['brand']['id'] ) ? sanitize_text_field( $connection['brand']['id'] ) : null,
                    'plan'    => isset( $connection['brand']['plan'] ) ? sanitize_text_field( $connection['brand']['plan'] ) : null,
                ) : null;
            }
        } else {
            $diagnostics['errors'][] = array(
                'test'    => 'api_connection',
                'message' => __( 'Not connected. No API credentials stored.', 'affilync-woocommerce' ),
            );
        }

        // Test 3: Webhook status.
        if ( $diagnostics['api_connected'] ) {
            $webhook_config = $this->api_client->get_webhook_config();
            if ( is_wp_error( $webhook_config ) ) {
                $diagnostics['errors'][] = array(
                    'test'    => 'webhooks',
                    'message' => $webhook_config->get_error_message(),
                );
            } else {
                $diagnostics['webhooks_active'] = ! empty( $webhook_config['active'] );
                $diagnostics['webhook_events']  = isset( $webhook_config['events'] ) ? $webhook_config['events'] : array();
                $diagnostics['webhook_url']     = isset( $webhook_config['callback_url'] ) ? $webhook_config['callback_url'] : null;
            }
        }

        // Test 4: Recent webhook delivery status from local logs.
        $recent_webhooks = $this->webhook_handler->get_recent_logs( 5 );
        $diagnostics['recent_webhook_deliveries'] = array();
        foreach ( $recent_webhooks as $wh ) {
            $diagnostics['recent_webhook_deliveries'][] = array(
                'event_type'  => $wh->event_type,
                'processed'   => (bool) $wh->processed,
                'received_at' => $wh->created_at,
            );
        }

        // Overall status determination.
        $diagnostics['overall_status'] = 'error';
        if ( $diagnostics['api_connected'] && $diagnostics['api_healthy'] ) {
            $diagnostics['overall_status'] = $diagnostics['webhooks_active'] ? 'healthy' : 'warning';
        } elseif ( $diagnostics['api_healthy'] ) {
            $diagnostics['overall_status'] = 'warning';
        }

        // Log the test.
        $this->audit_logger->info(
            'connection_test',
            array(
                'status'        => $diagnostics['overall_status'],
                'api_connected' => $diagnostics['api_connected'],
                'api_healthy'   => $diagnostics['api_healthy'],
            )
        );

        wp_send_json_success( $diagnostics );
    }

    // ========================================================================
    // 3. Manual Conversion Sync AJAX
    // ========================================================================

    /**
     * AJAX handler: Manually sync conversions to Affilync API.
     *
     * Syncs unsynced conversions with optional date range filtering
     * and duplicate detection. Only conversions that have not been
     * previously synced (synced_to_api = 0) are processed.
     *
     * Parameters:
     *   - date_from (string): Start date in Y-m-d format (optional)
     *   - date_to (string): End date in Y-m-d format (optional)
     *   - batch_size (int): Max conversions to process (default 50, max 200)
     */
    public function ajax_sync_conversions() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        if ( ! $this->api_client->is_connected() ) {
            wp_send_json_error( array(
                'message' => __( 'Not connected to Affilync. Please connect your account first.', 'affilync-woocommerce' ),
            ) );
        }

        $date_from  = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
        $date_to    = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
        $batch_size = isset( $_POST['batch_size'] ) ? min( absint( wp_unslash( $_POST['batch_size'] ) ), 200 ) : 50;

        // Validate date formats if provided.
        if ( $date_from && ! $this->validate_date( $date_from ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid start date format. Use Y-m-d.', 'affilync-woocommerce' ),
            ) );
        }
        if ( $date_to && ! $this->validate_date( $date_to ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid end date format. Use Y-m-d.', 'affilync-woocommerce' ),
            ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'affilync_conversions';

        // Build the query with optional date filters.
        $where_clauses = array( 'synced_to_api = 0' );
        $prepare_args  = array();

        if ( $date_from ) {
            $where_clauses[] = 'created_at >= %s';
            $prepare_args[]  = $date_from . ' 00:00:00';
        }

        if ( $date_to ) {
            $where_clauses[] = 'created_at <= %s';
            $prepare_args[]  = $date_to . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where_clauses );

        // Get total unsynced count.
        if ( ! empty( $prepare_args ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total_unsynced = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
                    ...$prepare_args
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total_unsynced = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
            );
        }

        // Fetch the batch.
        $prepare_args_with_limit = $prepare_args;
        $prepare_args_with_limit[] = $batch_size;

        if ( ! empty( $prepare_args ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $pending = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE {$where_sql} ORDER BY created_at ASC LIMIT %d",
                    ...$prepare_args_with_limit
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $pending = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE {$where_sql} ORDER BY created_at ASC LIMIT %d",
                    $batch_size
                )
            );
        }

        $synced  = 0;
        $failed  = 0;
        $results = array();

        foreach ( $pending as $conversion_id ) {
            $conversion_id = (int) $conversion_id;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $conversion = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $conversion_id )
            );

            if ( ! $conversion ) {
                continue;
            }

            // Duplicate detection: check if already synced by api_conversion_id.
            if ( ! empty( $conversion->api_conversion_id ) ) {
                $results[] = array(
                    'conversion_id' => $conversion_id,
                    'order_id'      => $conversion->order_id,
                    'status'        => 'skipped',
                    'reason'        => __( 'Already has API conversion ID (duplicate).', 'affilync-woocommerce' ),
                );
                continue;
            }

            // Get the order for the conversion.
            $order = wc_get_order( $conversion->order_id );
            if ( ! $order ) {
                $results[] = array(
                    'conversion_id' => $conversion_id,
                    'order_id'      => $conversion->order_id,
                    'status'        => 'failed',
                    'error'         => __( 'Order not found.', 'affilync-woocommerce' ),
                );
                $failed++;
                continue;
            }

            // Build and send to API.
            $payload = array(
                'external_id'       => (string) $conversion->order_id,
                'platform'          => 'woocommerce',
                'affiliate_id'      => $conversion->affiliate_id,
                'campaign_id'       => $conversion->campaign_id,
                'link_id'           => $conversion->link_id,
                'click_id'          => $conversion->click_id,
                'order_total'       => floatval( $conversion->order_total ),
                'commission_amount' => floatval( $conversion->commission_amount ),
                'currency'          => $conversion->currency,
                'order_date'        => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
                'customer_email'    => $order->get_billing_email(),
                'customer_id'       => $order->get_customer_id(),
            );

            $api_result = $this->api_client->track_conversion( $payload );

            if ( is_wp_error( $api_result ) ) {
                $results[] = array(
                    'conversion_id' => $conversion_id,
                    'order_id'      => $conversion->order_id,
                    'status'        => 'failed',
                    'error'         => $api_result->get_error_message(),
                );
                $failed++;
            } else {
                $api_conversion_id = isset( $api_result['conversion_id'] ) ? $api_result['conversion_id'] : null;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $table,
                    array(
                        'synced_to_api'     => 1,
                        'api_conversion_id' => $api_conversion_id,
                    ),
                    array( 'id' => $conversion_id ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );

                $results[] = array(
                    'conversion_id'     => $conversion_id,
                    'order_id'          => $conversion->order_id,
                    'status'            => 'synced',
                    'api_conversion_id' => $api_conversion_id,
                );
                $synced++;
            }
        }

        $remaining = max( 0, $total_unsynced - count( $pending ) );

        $this->audit_logger->info(
            'conversion_sync_ajax',
            array(
                'synced'    => $synced,
                'failed'    => $failed,
                'total'     => $total_unsynced,
                'date_from' => $date_from,
                'date_to'   => $date_to,
            )
        );

        wp_send_json_success( array(
            'synced'    => $synced,
            'failed'    => $failed,
            'total'     => $total_unsynced,
            'remaining' => $remaining,
            'has_more'  => $remaining > 0,
            'results'   => $results,
            'filters'   => array(
                'date_from' => $date_from,
                'date_to'   => $date_to,
            ),
        ) );
    }

    // ========================================================================
    // 4. Dashboard Stats AJAX
    // ========================================================================

    /**
     * AJAX handler: Get dashboard statistics.
     *
     * Returns conversion stats, product sync stats, connection status,
     * and plan usage data. Results are cached in a WordPress transient
     * for 5 minutes to reduce database load.
     *
     * Parameters:
     *   - period (string): Time period (today, week, month, all) - default: month
     *   - force_refresh (bool): Bypass cache and fetch fresh data
     */
    public function ajax_get_dashboard_stats() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $period        = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : 'month';
        $force_refresh = isset( $_POST['force_refresh'] ) && filter_var( wp_unslash( $_POST['force_refresh'] ), FILTER_VALIDATE_BOOLEAN );

        // Validate period.
        $valid_periods = array( 'today', 'week', 'month', 'all' );
        if ( ! in_array( $period, $valid_periods, true ) ) {
            $period = 'month';
        }

        // Check transient cache (5-minute TTL).
        $cache_key = self::STATS_CACHE_PREFIX . $period;

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                $cached['from_cache'] = true;
                wp_send_json_success( $cached );
                return; // Explicit return for clarity.
            }
        }

        // Gather fresh stats from multiple sources.
        $stats = array(
            'period'     => $period,
            'from_cache' => false,
            'timestamp'  => current_time( 'c' ),
        );

        // Conversion stats.
        $stats['conversions'] = $this->conversion_tracker->get_stats( $period );

        // Product sync stats.
        $stats['products'] = $this->product_sync->get_stats();

        // Connection status.
        $stats['connection'] = array(
            'connected' => $this->api_client->is_connected(),
            'api_url'   => $this->api_client->get_api_url(),
        );

        // Plan and usage stats.
        $stats['subscription'] = affilync()->subscription->get_subscription_status();
        $stats['usage']        = affilync()->subscription->get_usage();

        // Unsynced counts.
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $unsynced_conversions = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}affilync_conversions WHERE synced_to_api = 0"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pending_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}affilync_product_sync WHERE sync_status = 'pending'"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $failed_webhooks = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}affilync_webhooks WHERE processed = 0"
        );

        $stats['pending'] = array(
            'unsynced_conversions' => $unsynced_conversions,
            'pending_products'     => $pending_products,
            'unprocessed_webhooks' => $failed_webhooks,
        );

        // Recent activity - last 5 conversions.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $recent_conversions = $wpdb->get_results(
            "SELECT order_id, affiliate_id, order_total, commission_amount, status, created_at
            FROM {$wpdb->prefix}affilync_conversions
            ORDER BY created_at DESC LIMIT 5"
        );

        $stats['recent_conversions'] = array();
        foreach ( $recent_conversions as $conv ) {
            $stats['recent_conversions'][] = array(
                'order_id'     => (int) $conv->order_id,
                'affiliate_id' => $conv->affiliate_id,
                'order_total'  => (float) $conv->order_total,
                'commission'   => (float) $conv->commission_amount,
                'status'       => $conv->status,
                'date'         => $conv->created_at,
            );
        }

        // Cache the result for 5 minutes.
        set_transient( $cache_key, $stats, self::STATS_CACHE_DURATION );

        wp_send_json_success( $stats );
    }

    // ========================================================================
    // 5. Webhook Management AJAX
    // ========================================================================

    /**
     * AJAX handler: Manage webhooks (register, deregister, check status, retry).
     *
     * Parameters:
     *   - action_type (string): One of: register, deregister, status, retry
     *   - webhook_id (int): Required for retry action - the webhook log ID
     */
    public function ajax_manage_webhooks() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $action_type = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '';

        if ( empty( $action_type ) ) {
            wp_send_json_error( array(
                'message' => __( 'Missing action_type parameter. Valid values: register, deregister, status, retry.', 'affilync-woocommerce' ),
            ) );
        }

        switch ( $action_type ) {
            case 'register':
                $this->handle_webhook_register();
                break;

            case 'deregister':
                $this->handle_webhook_deregister();
                break;

            case 'status':
                $this->handle_webhook_status();
                break;

            case 'retry':
                $this->handle_webhook_retry();
                break;

            default:
                wp_send_json_error( array(
                    'message' => sprintf(
                        /* translators: %s: The invalid action type */
                        __( 'Invalid action_type: %s. Valid values: register, deregister, status, retry.', 'affilync-woocommerce' ),
                        $action_type
                    ),
                ) );
        }
    }

    /**
     * Register webhooks with Affilync API.
     */
    private function handle_webhook_register() {
        if ( ! $this->api_client->is_connected() ) {
            wp_send_json_error( array(
                'message' => __( 'Not connected to Affilync. Please connect your account first.', 'affilync-woocommerce' ),
            ) );
        }

        // Build the webhook callback URL.
        $callback_url = rest_url( 'affilync/v1/webhooks' );

        $result = $this->api_client->register_webhooks( $callback_url );

        if ( is_wp_error( $result ) ) {
            $this->audit_logger->error(
                'webhook_register_failed',
                array(
                    'error'        => $result->get_error_message(),
                    'callback_url' => $callback_url,
                )
            );

            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %s: Error message */
                    __( 'Failed to register webhooks: %s', 'affilync-woocommerce' ),
                    $result->get_error_message()
                ),
            ) );
        }

        // Store the webhook secret if returned.
        if ( isset( $result['webhook_secret'] ) ) {
            update_option( 'affilync_webhook_secret', sanitize_text_field( $result['webhook_secret'] ) );
        }

        update_option( 'affilync_webhooks_registered', true );
        update_option( 'affilync_webhook_callback_url', $callback_url );

        $this->audit_logger->info(
            'webhook_registered',
            array(
                'callback_url' => $callback_url,
                'events'       => isset( $result['events'] ) ? $result['events'] : array(),
            )
        );

        wp_send_json_success( array(
            'message'      => __( 'Webhooks registered successfully.', 'affilync-woocommerce' ),
            'callback_url' => $callback_url,
            'events'       => isset( $result['events'] ) ? $result['events'] : array(),
        ) );
    }

    /**
     * Deregister webhooks with Affilync API.
     */
    private function handle_webhook_deregister() {
        if ( ! $this->api_client->is_connected() ) {
            wp_send_json_error( array(
                'message' => __( 'Not connected to Affilync.', 'affilync-woocommerce' ),
            ) );
        }

        $result = $this->api_client->post( '/api/webhooks/deregister', array(
            'callback_url' => get_option( 'affilync_webhook_callback_url', '' ),
        ) );

        if ( is_wp_error( $result ) ) {
            $this->audit_logger->error(
                'webhook_deregister_failed',
                array( 'error' => $result->get_error_message() )
            );

            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %s: Error message */
                    __( 'Failed to deregister webhooks: %s', 'affilync-woocommerce' ),
                    $result->get_error_message()
                ),
            ) );
        }

        delete_option( 'affilync_webhooks_registered' );
        delete_option( 'affilync_webhook_callback_url' );
        delete_option( 'affilync_webhook_secret' );

        $this->audit_logger->info( 'webhook_deregistered', array() );

        wp_send_json_success( array(
            'message' => __( 'Webhooks deregistered successfully.', 'affilync-woocommerce' ),
        ) );
    }

    /**
     * Get webhook delivery status and recent logs.
     */
    private function handle_webhook_status() {
        $registered   = (bool) get_option( 'affilync_webhooks_registered', false );
        $callback_url = get_option( 'affilync_webhook_callback_url', '' );
        $recent_logs  = $this->webhook_handler->get_recent_logs( 20 );

        global $wpdb;
        $table = $wpdb->prefix . 'affilync_webhooks';

        // Delivery statistics.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $delivery_stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN processed = 1 THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN processed = 0 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN signature_valid = 1 THEN 1 ELSE 0 END) as valid_signatures,
                SUM(CASE WHEN signature_valid = 0 THEN 1 ELSE 0 END) as invalid_signatures,
                MAX(created_at) as last_received
            FROM {$table}"
        );

        $logs = array();
        foreach ( $recent_logs as $log ) {
            $log_result = null;
            if ( $log->processing_result ) {
                $log_result = json_decode( $log->processing_result, true );
            }

            $logs[] = array(
                'id'           => (int) $log->id,
                'event_type'   => $log->event_type,
                'processed'    => (bool) $log->processed,
                'result'       => $log_result,
                'received_at'  => $log->created_at,
                'processed_at' => $log->processed_at,
            );
        }

        // Signature verification status.
        $hmac_secret_configured = ! empty( get_option( 'affilync_webhook_secret', '' ) );

        wp_send_json_success( array(
            'registered'     => $registered,
            'callback_url'   => $callback_url,
            'hmac_configured' => $hmac_secret_configured,
            'delivery_stats' => array(
                'total'              => $delivery_stats ? (int) $delivery_stats->total : 0,
                'processed'          => $delivery_stats ? (int) $delivery_stats->processed : 0,
                'pending'            => $delivery_stats ? (int) $delivery_stats->pending : 0,
                'valid_signatures'   => $delivery_stats ? (int) $delivery_stats->valid_signatures : 0,
                'invalid_signatures' => $delivery_stats ? (int) $delivery_stats->invalid_signatures : 0,
                'last_received'      => $delivery_stats ? $delivery_stats->last_received : null,
            ),
            'recent_logs' => $logs,
        ) );
    }

    /**
     * Retry a failed webhook.
     */
    private function handle_webhook_retry() {
        $webhook_log_id = isset( $_POST['webhook_id'] ) ? absint( wp_unslash( $_POST['webhook_id'] ) ) : 0;

        if ( ! $webhook_log_id ) {
            wp_send_json_error( array(
                'message' => __( 'Missing webhook_id parameter.', 'affilync-woocommerce' ),
            ) );
        }

        // Verify the webhook log exists.
        global $wpdb;
        $table = $wpdb->prefix . 'affilync_webhooks';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $webhook = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, event_type, processed FROM {$table} WHERE id = %d",
                $webhook_log_id
            )
        );

        if ( ! $webhook ) {
            wp_send_json_error( array(
                'message' => __( 'Webhook log entry not found.', 'affilync-woocommerce' ),
            ) );
        }

        $this->webhook_handler->retry_webhook( $webhook_log_id );

        $this->audit_logger->info(
            'webhook_retry',
            array(
                'webhook_log_id' => $webhook_log_id,
                'event_type'     => $webhook->event_type,
            )
        );

        wp_send_json_success( array(
            'message'    => sprintf(
                /* translators: %d: Webhook log ID */
                __( 'Webhook #%d has been scheduled for reprocessing.', 'affilync-woocommerce' ),
                $webhook_log_id
            ),
            'webhook_id' => $webhook_log_id,
        ) );
    }

    // ========================================================================
    // 6. Call Logs AJAX
    // ========================================================================

    /**
     * AJAX handler: Get call logs with pagination and filtering.
     *
     * Parameters:
     *   - page (int): Page number (default 1)
     *   - per_page (int): Results per page (default 20, max 100)
     *   - affiliate_id (string): Filter by affiliate ID
     *   - date_from (string): Start date in Y-m-d format
     *   - date_to (string): End date in Y-m-d format
     *   - status (string): Filter by call status
     */
    public function ajax_get_call_logs() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        if ( ! affilync()->call_tracker ) {
            wp_send_json_error( array(
                'message' => __( 'Call tracking is not enabled.', 'affilync-woocommerce' ),
            ) );
        }

        $page         = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
        $per_page     = isset( $_POST['per_page'] ) ? min( absint( wp_unslash( $_POST['per_page'] ) ), 100 ) : 20;
        $affiliate_id = isset( $_POST['affiliate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['affiliate_id'] ) ) : '';
        $date_from    = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
        $date_to      = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
        $status       = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

        // Validate dates.
        if ( $date_from && ! $this->validate_date( $date_from ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid start date format. Use Y-m-d.', 'affilync-woocommerce' ),
            ) );
        }
        if ( $date_to && ! $this->validate_date( $date_to ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid end date format. Use Y-m-d.', 'affilync-woocommerce' ),
            ) );
        }

        $result = affilync()->call_tracker->call_log->get_calls( array(
            'per_page'     => $per_page,
            'page'         => $page,
            'affiliate_id' => $affiliate_id,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'status'       => $status,
        ) );

        $calls = array();
        foreach ( $result['calls'] as $call ) {
            $calls[] = array(
                'id'               => (int) $call->id,
                'call_id'          => $call->call_id,
                'tracking_number'  => $call->tracking_number,
                'caller_number'    => $call->caller_number,
                'affiliate_id'     => $call->affiliate_id,
                'campaign_id'      => $call->campaign_id,
                'duration'         => (int) $call->duration,
                'billable_seconds' => (int) $call->billable_seconds,
                'status'           => $call->status,
                'recording_url'    => $call->recording_url,
                'synced_to_api'    => (bool) $call->synced_to_api,
                'created_at'       => $call->created_at,
            );
        }

        // Get stats for the current filter period.
        $period = 'all';
        if ( $date_from || $date_to ) {
            $period = 'all'; // Custom date range — stats use the full query.
        }
        $stats = affilync()->call_tracker->stats->get_stats( $period );

        wp_send_json_success( array(
            'calls'      => $calls,
            'total'      => $result['total'],
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages' => ceil( $result['total'] / $per_page ),
            'stats'      => $stats,
            'filters'    => array(
                'affiliate_id' => $affiliate_id,
                'date_from'    => $date_from,
                'date_to'      => $date_to,
                'status'       => $status,
            ),
        ) );
    }

    // ========================================================================
    // Utility Methods
    // ========================================================================

    /**
     * Validate a date string in Y-m-d format.
     *
     * @param string $date Date string to validate.
     * @return bool True if valid date in Y-m-d format.
     */
    private function validate_date( $date ) {
        $d = DateTime::createFromFormat( 'Y-m-d', $date );
        return $d && $d->format( 'Y-m-d' ) === $date;
    }
}
