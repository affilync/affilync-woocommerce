<?php
/**
 * Conversion Tracker for WooCommerce orders.
 *
 * Tracks affiliate conversions when orders are placed.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Conversion Tracker.
 *
 * @since 1.0.0
 */
class Affilync_Tracking_Conversion_Tracker {

    /**
     * API client.
     *
     * @var Affilync_API_Client
     */
    private $api_client;

    /**
     * Audit logger.
     *
     * @var Affilync_Security_Audit_Logger
     */
    private $audit_logger;

    /**
     * Cookie handler.
     *
     * @var Affilync_Tracking_Cookie_Handler
     */
    private $cookie_handler;

    /**
     * Table name.
     *
     * @var string
     */
    const TABLE_NAME = 'affilync_conversions';

    /**
     * Constructor.
     *
     * @param Affilync_API_Client            $api_client   API client.
     * @param Affilync_Security_Audit_Logger $audit_logger Audit logger.
     */
    public function __construct( $api_client, $audit_logger ) {
        $this->api_client     = $api_client;
        $this->audit_logger   = $audit_logger;
        $this->cookie_handler = new Affilync_Tracking_Cookie_Handler();

        $this->init_hooks();
    }

    /**
     * Maximum retry attempts for async sync.
     *
     * @var int
     */
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Action Scheduler group for conversion syncing.
     *
     * @var string
     */
    const ASYNC_GROUP = 'affilync_conversion_sync';

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Track conversions on order status changes.
        add_action( 'woocommerce_order_status_completed', array( $this, 'track_conversion' ), 10, 2 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'track_conversion' ), 10, 2 );

        // Track refunds.
        add_action( 'woocommerce_order_refunded', array( $this, 'track_refund' ), 10, 2 );
        add_action( 'woocommerce_order_status_refunded', array( $this, 'track_full_refund' ), 10, 2 );

        // Conversion pixel on thank you page.
        add_action( 'woocommerce_thankyou', array( $this, 'render_conversion_pixel' ), 10, 1 );

        // Cron hook for syncing.
        add_action( 'affilync_sync_conversions', array( $this, 'sync_pending_conversions' ) );

        // PAY-III-03: Action Scheduler callback for async conversion sync.
        add_action( 'affilync_async_sync_conversion', array( $this, 'async_sync_conversion_callback' ), 10, 2 );

        // PAY-III-03: Action Scheduler callback for async refund API notification.
        add_action( 'affilync_async_notify_refund', array( $this, 'async_notify_refund_callback' ), 10, 3 );
    }

    /**
     * Track conversion for an order.
     *
     * @param int      $order_id Order ID.
     * @param WC_Order $order    Order object (optional).
     */
    public function track_conversion( $order_id, $order = null ) {
        // Check if tracking is enabled.
        $settings = get_option( 'affilync_settings', array() );
        if ( isset( $settings['tracking_enabled'] ) && ! $settings['tracking_enabled'] ) {
            return;
        }

        // Get order.
        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }

        if ( ! $order ) {
            return;
        }

        // Check if conversion already tracked.
        if ( $this->is_conversion_tracked( $order_id ) ) {
            return;
        }

        // Check order status against configured statuses.
        $track_statuses = isset( $settings['conversion_statuses'] )
            ? $settings['conversion_statuses']
            : array( 'completed', 'processing' );

        if ( ! in_array( $order->get_status(), $track_statuses, true ) ) {
            return;
        }

        // Get attribution data.
        $attribution = $this->get_attribution_data( $order );

        // No affiliate attribution - skip.
        if ( empty( $attribution['affiliate_id'] ) && empty( $attribution['click_id'] ) ) {
            return;
        }

        // Calculate commission (if applicable).
        $commission = $this->calculate_commission( $order, $attribution );

        // Store conversion locally.
        $conversion_id = $this->store_conversion( $order, $attribution, $commission );

        if ( ! $conversion_id ) {
            $this->audit_logger->error(
                Affilync_Security_Audit_Logger::EVENT_CONVERSION_FAILED,
                array(
                    'order_id' => $order_id,
                    'reason'   => 'database_error',
                )
            );
            return;
        }

        // Store conversion ID in order meta.
        $order->update_meta_data( '_affilync_conversion_id', $conversion_id );
        $order->update_meta_data( '_affilync_tracked', 'yes' );
        $order->update_meta_data( '_affilync_attribution', $attribution );
        $order->save();

        // PAY-III-03: Schedule async API sync via Action Scheduler instead of
        // blocking the checkout/order-status-change request. This eliminates the
        // 3-5s delay customers experience on order completion.
        $this->schedule_async_sync( $conversion_id );

        // Add order note.
        $order->add_order_note(
            sprintf(
                /* translators: %s: Affiliate ID */
                __( 'Affilync: Conversion tracked for affiliate %s', 'affilync-woocommerce' ),
                $attribution['affiliate_id'] ?? $attribution['click_id'] ?? 'unknown'
            )
        );

        $this->audit_logger->info(
            Affilync_Security_Audit_Logger::EVENT_CONVERSION_TRACKED,
            array(
                'order_id'     => $order_id,
                'affiliate_id' => $attribution['affiliate_id'] ?? null,
                'click_id'     => $attribution['click_id'] ?? null,
                'order_total'  => $order->get_total(),
            )
        );
    }

    /**
     * Get attribution data for an order.
     *
     * @param WC_Order $order Order object.
     * @return array Attribution data.
     */
    private function get_attribution_data( $order ) {
        $order_id = $order->get_id();

        // First, check order meta (from checkout).
        $attribution = $order->get_meta( '_affilync_attribution' );
        if ( ! empty( $attribution ) && is_array( $attribution ) ) {
            return $attribution;
        }

        // Try to get from cookies.
        $cookie_data = $this->cookie_handler->get_tracking_data();
        if ( ! empty( $cookie_data ) ) {
            return $cookie_data;
        }

        // Check session data.
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_data = WC()->session->get( 'affilync_tracking' );
            if ( ! empty( $session_data ) && is_array( $session_data ) ) {
                return $session_data;
            }
        }

        return array();
    }

    /**
     * Calculate commission for an order.
     *
     * @param WC_Order $order       Order object.
     * @param array    $attribution Attribution data.
     * @return float Commission amount.
     */
    private function calculate_commission( $order, $attribution ) {
        // Default commission rate (can be customized).
        $default_rate = 0.10; // 10%

        // Get rate from attribution if available.
        $rate = isset( $attribution['commission_rate'] )
            ? floatval( $attribution['commission_rate'] )
            : $default_rate;

        // Calculate commission on order subtotal (excluding tax and shipping).
        $subtotal = floatval( $order->get_subtotal() );

        return round( $subtotal * $rate, 2 );
    }

    /**
     * Store conversion in database.
     *
     * @param WC_Order $order       Order object.
     * @param array    $attribution Attribution data.
     * @param float    $commission  Commission amount.
     * @return int|false Conversion ID or false on failure.
     */
    private function store_conversion( $order, $attribution, $commission ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'order_id'          => $order->get_id(),
                'affiliate_id'      => isset( $attribution['affiliate_id'] ) ? sanitize_text_field( $attribution['affiliate_id'] ) : null,
                'campaign_id'       => isset( $attribution['campaign_id'] ) ? sanitize_text_field( $attribution['campaign_id'] ) : null,
                'link_id'           => isset( $attribution['link_id'] ) ? sanitize_text_field( $attribution['link_id'] ) : null,
                'click_id'          => isset( $attribution['click_id'] ) ? sanitize_text_field( $attribution['click_id'] ) : null,
                'order_total'       => $order->get_total(),
                'commission_amount' => $commission,
                'currency'          => $order->get_currency(),
                'status'            => 'pending',
                'attribution_data'  => wp_json_encode( $attribution ),
                'synced_to_api'     => 0,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Sync a conversion to the API.
     *
     * @param int $conversion_id Local conversion ID.
     * @return bool True on success.
     */
    private function sync_conversion( $conversion_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $conversion = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $conversion_id )
        );

        if ( ! $conversion ) {
            return false;
        }

        // Get order details.
        $order = wc_get_order( $conversion->order_id );
        if ( ! $order ) {
            return false;
        }

        // Build API payload.
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
            'products'          => $this->get_order_products( $order ),
            'attribution_data'  => json_decode( $conversion->attribution_data, true ),
        );

        // Send to API.
        $result = $this->api_client->track_conversion( $payload );

        if ( is_wp_error( $result ) ) {
            // Update retry count.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET synced_to_api = 0 WHERE id = %d",
                    $conversion_id
                )
            );
            return false;
        }

        // Update as synced.
        $api_conversion_id = isset( $result['conversion_id'] ) ? $result['conversion_id'] : null;

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

        return true;
    }

    /**
     * Schedule async conversion sync via WooCommerce Action Scheduler.
     *
     * PAY-III-03: Replaces synchronous API calls on order completion hooks to
     * eliminate the 3-5s checkout delay. Uses as_schedule_single_action() to
     * queue the sync for immediate background processing with retry support.
     *
     * @param int $conversion_id Local conversion ID.
     * @param int $attempt       Current attempt number (default 1).
     */
    private function schedule_async_sync( $conversion_id, $attempt = 1 ) {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            // Schedule for immediate background processing (timestamp = now).
            as_schedule_single_action(
                time(),
                'affilync_async_sync_conversion',
                array(
                    'conversion_id' => $conversion_id,
                    'attempt'       => $attempt,
                ),
                self::ASYNC_GROUP
            );
        } else {
            // Fallback: Action Scheduler not available (shouldn't happen with
            // WooCommerce 3.5+), fall back to synchronous sync.
            $this->sync_conversion( $conversion_id );
        }
    }

    /**
     * Action Scheduler callback for async conversion sync.
     *
     * PAY-III-03: Called by Action Scheduler in a background process. Performs
     * the actual API call to sync the conversion. On failure, schedules a retry
     * with exponential backoff (10s, 40s, 90s) up to MAX_RETRY_ATTEMPTS.
     *
     * @param int $conversion_id Local conversion ID.
     * @param int $attempt       Current attempt number.
     */
    public function async_sync_conversion_callback( $conversion_id, $attempt = 1 ) {
        $success = $this->sync_conversion( $conversion_id );

        if ( ! $success && $attempt < self::MAX_RETRY_ATTEMPTS ) {
            // Exponential backoff: attempt^2 * 10 seconds (10s, 40s, 90s).
            $delay = pow( $attempt, 2 ) * 10;

            $this->audit_logger->info(
                'conversion_sync_retry',
                array(
                    'conversion_id' => $conversion_id,
                    'attempt'       => $attempt,
                    'next_attempt'  => $attempt + 1,
                    'delay_seconds' => $delay,
                )
            );

            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action(
                    time() + $delay,
                    'affilync_async_sync_conversion',
                    array(
                        'conversion_id' => $conversion_id,
                        'attempt'       => $attempt + 1,
                    ),
                    self::ASYNC_GROUP
                );
            }
        } elseif ( ! $success ) {
            // All retries exhausted; log error. The sync_pending_conversions
            // cron will pick it up later as a final safety net.
            $this->audit_logger->error(
                'conversion_sync_failed_all_retries',
                array(
                    'conversion_id' => $conversion_id,
                    'attempts'      => $attempt,
                )
            );
        }
    }

    /**
     * Action Scheduler callback for async refund API notification.
     *
     * PAY-III-03: Called by Action Scheduler in a background process. Sends
     * the refund notification to the Affilync API without blocking the
     * WordPress admin request that triggered the refund.
     *
     * @param string $api_conversion_id Remote API conversion ID.
     * @param string $status            Refund status (partial_refund or refunded).
     * @param int    $order_id          WooCommerce order ID.
     */
    public function async_notify_refund_callback( $api_conversion_id, $status, $order_id ) {
        $result = $this->api_client->post(
            '/api/conversions/' . $api_conversion_id . '/refund',
            array(
                'status'   => $status,
                'order_id' => $order_id,
            )
        );

        if ( is_wp_error( $result ) ) {
            $this->audit_logger->error(
                'refund_notification_failed',
                array(
                    'api_conversion_id' => $api_conversion_id,
                    'status'            => $status,
                    'order_id'          => $order_id,
                    'error'             => $result->get_error_message(),
                )
            );
        } else {
            $this->audit_logger->info(
                'refund_notification_sent',
                array(
                    'api_conversion_id' => $api_conversion_id,
                    'status'            => $status,
                    'order_id'          => $order_id,
                )
            );
        }
    }

    /**
     * Get products from order for conversion tracking.
     *
     * @param WC_Order $order Order object.
     * @return array Products array.
     */
    private function get_order_products( $order ) {
        $products = array();

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();

            $products[] = array(
                'product_id' => $product ? $product->get_id() : $item->get_product_id(),
                'name'       => $item->get_name(),
                'quantity'   => $item->get_quantity(),
                'total'      => floatval( $item->get_total() ),
                'sku'        => $product ? $product->get_sku() : '',
            );
        }

        return $products;
    }

    /**
     * Check if conversion is already tracked.
     *
     * @param int $order_id Order ID.
     * @return bool True if already tracked.
     */
    private function is_conversion_tracked( $order_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE order_id = %d",
                $order_id
            )
        );

        return intval( $count ) > 0;
    }

    /**
     * Track refund.
     *
     * @param int $order_id Order ID.
     * @param int $refund_id Refund ID.
     */
    public function track_refund( $order_id, $refund_id ) {
        $this->update_conversion_for_refund( $order_id, 'partial_refund' );
    }

    /**
     * Track full refund.
     *
     * @param int      $order_id Order ID.
     * @param WC_Order $order    Order object.
     */
    public function track_full_refund( $order_id, $order = null ) {
        $this->update_conversion_for_refund( $order_id, 'refunded' );
    }

    /**
     * Update conversion status for refund.
     *
     * PAY-III-03: Updates the local database immediately but defers the API
     * notification to Action Scheduler so that refund hooks do not block the
     * admin request for 3-5 seconds waiting on the remote API call.
     *
     * @param int    $order_id Order ID.
     * @param string $status   New status.
     */
    private function update_conversion_for_refund( $order_id, $status ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Update local status immediately (fast DB write).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            array( 'status' => $status ),
            array( 'order_id' => $order_id ),
            array( '%s' ),
            array( '%d' )
        );

        // Get the API conversion ID for async notification.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $conversion = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT api_conversion_id FROM {$table} WHERE order_id = %d",
                $order_id
            )
        );

        // PAY-III-03: Schedule async API notification instead of blocking.
        if ( $conversion && $conversion->api_conversion_id ) {
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action(
                    time(),
                    'affilync_async_notify_refund',
                    array(
                        'api_conversion_id' => $conversion->api_conversion_id,
                        'status'            => $status,
                        'order_id'          => $order_id,
                    ),
                    self::ASYNC_GROUP
                );
            } else {
                // Fallback: Action Scheduler not available, make synchronous call.
                $this->api_client->post(
                    '/api/conversions/' . $conversion->api_conversion_id . '/refund',
                    array(
                        'status'   => $status,
                        'order_id' => $order_id,
                    )
                );
            }
        }
    }

    /**
     * Render conversion pixel on thank you page.
     *
     * @param int $order_id Order ID.
     */
    public function render_conversion_pixel( $order_id ) {
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Check if already rendered.
        $pixel_rendered = $order->get_meta( '_affilync_pixel_rendered' );
        if ( $pixel_rendered === 'yes' ) {
            return;
        }

        // Get attribution data.
        $attribution = $this->get_attribution_data( $order );

        // Only render if there's attribution.
        if ( empty( $attribution['affiliate_id'] ) && empty( $attribution['click_id'] ) ) {
            return;
        }

        // Build pixel data.
        $pixel_data = array(
            'order_id'     => $order_id,
            'order_total'  => $order->get_total(),
            'currency'     => $order->get_currency(),
            'affiliate_id' => $attribution['affiliate_id'] ?? '',
            'campaign_id'  => $attribution['campaign_id'] ?? '',
            'click_id'     => $attribution['click_id'] ?? '',
        );

        // Output conversion pixel script.
        ?>
        <script type="text/javascript">
        (function() {
            var data = <?php echo wp_json_encode( $pixel_data ); ?>;
            var img = new Image();
            img.src = '<?php echo esc_url( AFFILYNC_API_URL ); ?>/api/conversions/pixel?' +
                'order_id=' + encodeURIComponent(data.order_id) +
                '&total=' + encodeURIComponent(data.order_total) +
                '&currency=' + encodeURIComponent(data.currency) +
                '&aff=' + encodeURIComponent(data.affiliate_id) +
                '&campaign=' + encodeURIComponent(data.campaign_id) +
                '&click_id=' + encodeURIComponent(data.click_id) +
                '&t=' + Date.now();
        })();
        </script>
        <?php

        // Mark as rendered.
        $order->update_meta_data( '_affilync_pixel_rendered', 'yes' );
        $order->save();
    }

    /**
     * Sync pending conversions to API.
     *
     * @return array Results.
     */
    public function sync_pending_conversions() {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Get unsynced conversions.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pending = $wpdb->get_col(
            "SELECT id FROM {$table} WHERE synced_to_api = 0 LIMIT 50"
        );

        $synced = 0;
        $failed = 0;

        foreach ( $pending as $conversion_id ) {
            if ( $this->sync_conversion( $conversion_id ) ) {
                $synced++;
            } else {
                $failed++;
            }
        }

        return array(
            'synced' => $synced,
            'failed' => $failed,
        );
    }

    /**
     * Get conversion stats.
     *
     * @param string $period Period (today, week, month, all).
     * @return array Stats.
     */
    public function get_stats( $period = 'month' ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $where = '';
        switch ( $period ) {
            case 'today':
                $where = "AND DATE(created_at) = CURDATE()";
                break;
            case 'week':
                $where = "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $where = "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_conversions,
                SUM(order_total) as total_revenue,
                SUM(commission_amount) as total_commission,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM {$table}
            WHERE 1=1 {$where}"
        );

        return array(
            'total_conversions' => intval( $stats->total_conversions ),
            'total_revenue'     => floatval( $stats->total_revenue ),
            'total_commission'  => floatval( $stats->total_commission ),
            'approved'          => intval( $stats->approved ),
            'pending'           => intval( $stats->pending ),
            'rejected'          => intval( $stats->rejected ),
            'period'            => $period,
        );
    }
}
