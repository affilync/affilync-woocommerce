<?php
/**
 * Webhook Handler for incoming Affilync webhooks.
 *
 * Processes webhooks with signature verification and async processing.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Webhook Handler.
 *
 * @since 1.0.0
 */
class Affilync_API_Webhook_Handler {

    /**
     * HMAC validator.
     *
     * @var Affilync_Security_HMAC_Validator
     */
    private $hmac_validator;

    /**
     * Audit logger.
     *
     * @var Affilync_Security_Audit_Logger
     */
    private $audit_logger;

    /**
     * Table name for webhook logs.
     *
     * @var string
     */
    const TABLE_NAME = 'affilync_webhooks';

    /**
     * Constructor.
     *
     * @param Affilync_Security_HMAC_Validator $hmac_validator HMAC validator.
     * @param Affilync_Security_Audit_Logger   $audit_logger   Audit logger.
     */
    public function __construct( $hmac_validator, $audit_logger ) {
        $this->hmac_validator = $hmac_validator;
        $this->audit_logger   = $audit_logger;

        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Register cron event for async processing.
        add_action( 'affilync_process_webhook', array( $this, 'process_webhook_async' ), 10, 1 );
    }

    /**
     * Handle incoming webhook.
     *
     * Called from REST API endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function handle_webhook( $request ) {
        // Verify signature.
        $result = $this->hmac_validator->verify_request();

        if ( ! $result['valid'] ) {
            $this->audit_logger->warning(
                Affilync_Security_Audit_Logger::EVENT_WEBHOOK_INVALID,
                array(
                    'error'   => $result['error'],
                    'headers' => $this->get_safe_headers(),
                )
            );

            return new WP_Error(
                'invalid_signature',
                $result['error'],
                array( 'status' => 401 )
            );
        }

        // Parse payload.
        $payload = json_decode( $result['payload'], true );
        if ( ! is_array( $payload ) ) {
            return new WP_Error(
                'invalid_payload',
                __( 'Invalid JSON payload', 'affilync-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        // Extract event info.
        $webhook_id = isset( $payload['webhook_id'] ) ? sanitize_text_field( $payload['webhook_id'] ) : wp_generate_uuid4();
        $event_type = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : 'unknown';

        // Check for duplicate.
        if ( $this->is_duplicate( $webhook_id ) ) {
            return rest_ensure_response(
                array(
                    'status'  => 'duplicate',
                    'message' => 'Webhook already processed',
                )
            );
        }

        // Log webhook.
        $log_id = $this->log_webhook( $webhook_id, $event_type, $payload, true );

        // Audit log.
        $this->audit_logger->info(
            Affilync_Security_Audit_Logger::EVENT_WEBHOOK_RECEIVED,
            array(
                'webhook_id' => $webhook_id,
                'event_type' => $event_type,
            )
        );

        // Schedule async processing.
        wp_schedule_single_event( time(), 'affilync_process_webhook', array( $log_id ) );

        // Return 200 OK immediately.
        return rest_ensure_response(
            array(
                'status'     => 'accepted',
                'webhook_id' => $webhook_id,
            )
        );
    }

    /**
     * Process webhook asynchronously.
     *
     * @param int $log_id Webhook log ID.
     */
    public function process_webhook_async( $log_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Get webhook from log.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $webhook = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND processed = 0",
                $log_id
            )
        );

        if ( ! $webhook ) {
            return;
        }

        $payload = json_decode( $webhook->payload, true );
        $event_type = $webhook->event_type;

        try {
            // Route to appropriate handler.
            $result = $this->route_webhook( $event_type, $payload );

            // Update log with result.
            $this->update_webhook_log( $log_id, true, $result );

        } catch ( Exception $e ) {
            // Log failure.
            $this->update_webhook_log( $log_id, false, array( 'error' => $e->getMessage() ) );

            $this->audit_logger->error(
                Affilync_Security_Audit_Logger::EVENT_WEBHOOK_FAILED,
                array(
                    'webhook_id' => $webhook->webhook_id,
                    'event_type' => $event_type,
                    'error'      => $e->getMessage(),
                )
            );
        }
    }

    /**
     * Route webhook to appropriate handler.
     *
     * @param string $event_type Event type.
     * @param array  $payload    Webhook payload.
     * @return array Handler result.
     */
    private function route_webhook( $event_type, $payload ) {
        $handlers = array(
            'conversion.approved'     => array( $this, 'handle_conversion_approved' ),
            'conversion.rejected'     => array( $this, 'handle_conversion_rejected' ),
            'conversion.pending'      => array( $this, 'handle_conversion_pending' ),
            'payout.processed'        => array( $this, 'handle_payout_processed' ),
            'campaign.updated'        => array( $this, 'handle_campaign_updated' ),
            'campaign.paused'         => array( $this, 'handle_campaign_paused' ),
            'affiliate.joined'        => array( $this, 'handle_affiliate_joined' ),
            'link.clicked'            => array( $this, 'handle_link_clicked' ),
        );

        if ( isset( $handlers[ $event_type ] ) ) {
            return call_user_func( $handlers[ $event_type ], $payload );
        }

        return array(
            'status' => 'unhandled',
            'event'  => $event_type,
        );
    }

    /**
     * Handle conversion.approved event.
     *
     * @param array $payload Event payload.
     * @return array Result.
     */
    private function handle_conversion_approved( $payload ) {
        global $wpdb;

        $conversion_id = isset( $payload['conversion_id'] ) ? sanitize_text_field( $payload['conversion_id'] ) : '';
        $order_id      = isset( $payload['order_id'] ) ? intval( $payload['order_id'] ) : 0;

        if ( ! $order_id ) {
            return array( 'status' => 'skipped', 'reason' => 'no_order_id' );
        }

        // Update conversion status in our table.
        $table = $wpdb->prefix . 'affilync_conversions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(
            $table,
            array(
                'status'            => 'approved',
                'api_conversion_id' => $conversion_id,
            ),
            array( 'order_id' => $order_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // Add order note.
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: Conversion ID */
                    __( 'Affilync: Affiliate conversion approved (ID: %s)', 'affilync-woocommerce' ),
                    $conversion_id
                )
            );
        }

        return array(
            'status'   => 'processed',
            'order_id' => $order_id,
            'updated'  => $updated,
        );
    }

    /**
     * Handle conversion.rejected event.
     *
     * @param array $payload Event payload.
     * @return array Result.
     */
    private function handle_conversion_rejected( $payload ) {
        global $wpdb;

        $conversion_id = isset( $payload['conversion_id'] ) ? sanitize_text_field( $payload['conversion_id'] ) : '';
        $order_id      = isset( $payload['order_id'] ) ? intval( $payload['order_id'] ) : 0;
        $reason        = isset( $payload['reason'] ) ? sanitize_text_field( $payload['reason'] ) : '';

        if ( ! $order_id ) {
            return array( 'status' => 'skipped', 'reason' => 'no_order_id' );
        }

        // Update conversion status.
        $table = $wpdb->prefix . 'affilync_conversions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(
            $table,
            array(
                'status'            => 'rejected',
                'api_conversion_id' => $conversion_id,
            ),
            array( 'order_id' => $order_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // Add order note.
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Conversion ID, 2: Reason */
                    __( 'Affilync: Affiliate conversion rejected (ID: %1$s). Reason: %2$s', 'affilync-woocommerce' ),
                    $conversion_id,
                    $reason
                )
            );
        }

        return array(
            'status'   => 'processed',
            'order_id' => $order_id,
            'updated'  => $updated,
        );
    }

    /**
     * Handle conversion.pending event.
     *
     * @param array $payload Event payload.
     * @return array Result.
     */
    private function handle_conversion_pending( $payload ) {
        // Conversion is pending review - no action needed.
        return array( 'status' => 'acknowledged' );
    }

    /**
     * Handle payout.processed event.
     *
     * @param array $payload Event payload.
     * @return array Result.
     */
    private function handle_payout_processed( $payload ) {
        // Payout processed - informational only.
        return array( 'status' => 'acknowledged' );
    }

    /**
     * Handle campaign.updated event.
     *
     * @param array $payload Event payload.
     * @return array Result.
     */
    private function handle_campaign_updated( $payload ) {
        // Campaign updated - could refresh local cache.
        delete_transient( 'affilync_campaigns' );
        return array( 'status' => 'cache_cleared' );
    }

    /**
     * Handle campaign.paused event.
     *
     * @param array $payload Event payload.
     * @return array Result.
     */
    private function handle_campaign_paused( $payload ) {
        delete_transient( 'affilync_campaigns' );
        return array( 'status' => 'cache_cleared' );
    }

    /**
     * Handle affiliate.joined event.
     *
     * @param array $payload Event payload.
     * @return array Result.
     */
    private function handle_affiliate_joined( $payload ) {
        // New affiliate joined campaign - informational.
        return array( 'status' => 'acknowledged' );
    }

    /**
     * Handle link.clicked event.
     *
     * @param array $payload Event payload.
     * @return array Result.
     */
    private function handle_link_clicked( $payload ) {
        // Click event - typically handled by click tracker.
        return array( 'status' => 'acknowledged' );
    }

    /**
     * Log incoming webhook.
     *
     * @param string $webhook_id     Webhook ID.
     * @param string $event_type     Event type.
     * @param array  $payload        Payload.
     * @param bool   $signature_valid Whether signature was valid.
     * @return int|false Log ID or false on failure.
     */
    private function log_webhook( $webhook_id, $event_type, $payload, $signature_valid ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'webhook_id'      => $webhook_id,
                'event_type'      => $event_type,
                'payload'         => wp_json_encode( $payload ),
                'signature'       => isset( $_SERVER['HTTP_X_AFFILYNC_SIGNATURE'] )
                    ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AFFILYNC_SIGNATURE'] ) )
                    : null,
                'signature_valid' => $signature_valid ? 1 : 0,
                'processed'       => 0,
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%d' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update webhook log after processing.
     *
     * @param int   $log_id  Log ID.
     * @param bool  $success Whether processing succeeded.
     * @param array $result  Processing result.
     */
    private function update_webhook_log( $log_id, $success, $result ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            array(
                'processed'         => 1,
                'processing_result' => wp_json_encode( $result ),
                'processed_at'      => current_time( 'mysql', true ),
            ),
            array( 'id' => $log_id ),
            array( '%d', '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Check if webhook is a duplicate.
     *
     * @param string $webhook_id Webhook ID.
     * @return bool True if duplicate.
     */
    private function is_duplicate( $webhook_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE webhook_id = %s",
                $webhook_id
            )
        );

        return intval( $count ) > 0;
    }

    /**
     * Get safe headers for logging (mask sensitive values).
     *
     * @return array Headers.
     */
    private function get_safe_headers() {
        $safe_headers = array();
        $headers_to_log = array(
            'HTTP_X_AFFILYNC_SIGNATURE',
            'HTTP_X_AFFILYNC_TIMESTAMP',
            'HTTP_X_AFFILYNC_EVENT',
            'HTTP_CONTENT_TYPE',
            'HTTP_USER_AGENT',
        );

        foreach ( $headers_to_log as $header ) {
            if ( isset( $_SERVER[ $header ] ) ) {
                $value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

                // Mask signature.
                if ( $header === 'HTTP_X_AFFILYNC_SIGNATURE' ) {
                    $value = substr( $value, 0, 8 ) . '...';
                }

                $safe_headers[ str_replace( 'HTTP_', '', $header ) ] = $value;
            }
        }

        return $safe_headers;
    }

    /**
     * Get recent webhook logs.
     *
     * @param int $limit Number of logs to return.
     * @return array Webhook logs.
     */
    public function get_recent_logs( $limit = 20 ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, webhook_id, event_type, processed, processing_result, created_at, processed_at
                FROM {$table} ORDER BY created_at DESC LIMIT %d",
                $limit
            )
        );

        return $logs;
    }

    /**
     * Retry failed webhook.
     *
     * @param int $log_id Webhook log ID.
     * @return bool True if scheduled.
     */
    public function retry_webhook( $log_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Reset processed status.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            array( 'processed' => 0 ),
            array( 'id' => $log_id ),
            array( '%d' ),
            array( '%d' )
        );

        // Schedule processing.
        wp_schedule_single_event( time(), 'affilync_process_webhook', array( $log_id ) );

        return true;
    }
}
