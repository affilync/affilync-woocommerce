<?php
/**
 * Persistent Webhook Queue with Retries.
 *
 * Provides reliable webhook delivery by storing webhooks in a database queue
 * and retrying failed deliveries with exponential backoff. Replaces direct
 * WP-Cron reliance for webhook processing.
 *
 * @package Affilync_WooCommerce
 * @since   1.0.0
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Webhook Queue.
 *
 * Manages a persistent queue of outbound/inbound webhooks with automatic
 * retry logic and exponential backoff for failed deliveries.
 *
 * @since 1.0.0
 */
class Affilync_Webhook_Queue {

    /**
     * Database table name (without prefix).
     *
     * @var string
     */
    const TABLE_NAME = 'affilync_webhook_queue';

    /**
     * Default maximum retry attempts.
     *
     * @var int
     */
    const DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * Webhook status constants.
     *
     * @var string
     */
    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_FAILED     = 'failed';

    /**
     * WP-Cron hook for processing the queue.
     *
     * @var string
     */
    const CRON_PROCESS_HOOK = 'affilync_process_webhook_queue';

    /**
     * WP-Cron hook for cleaning up completed webhooks.
     *
     * @var string
     */
    const CRON_CLEANUP_HOOK = 'affilync_cleanup_webhook_queue';

    /**
     * API client for sending webhooks.
     *
     * @var Affilync_API_Client|null
     */
    private $api_client;

    /**
     * Audit logger.
     *
     * @var Affilync_Security_Audit_Logger|null
     */
    private $audit_logger;

    /**
     * Constructor.
     *
     * @param Affilync_API_Client|null            $api_client   API client for sending webhooks.
     * @param Affilync_Security_Audit_Logger|null $audit_logger Audit logger.
     */
    public function __construct( $api_client = null, $audit_logger = null ) {
        $this->api_client   = $api_client;
        $this->audit_logger = $audit_logger;

        $this->init_hooks();
    }

    /**
     * Initialize cron hooks.
     */
    private function init_hooks() {
        add_action( self::CRON_PROCESS_HOOK, array( $this, 'process_queue' ) );
        add_action( self::CRON_CLEANUP_HOOK, array( $this, 'cleanup_completed' ) );
    }

    /**
     * Schedule the cron events for queue processing and cleanup.
     *
     * Should be called during plugin activation.
     */
    public static function schedule_cron_events() {
        if ( ! wp_next_scheduled( self::CRON_PROCESS_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_PROCESS_HOOK );
        }

        if ( ! wp_next_scheduled( self::CRON_CLEANUP_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_CLEANUP_HOOK );
        }
    }

    /**
     * Clear scheduled cron events.
     *
     * Should be called during plugin deactivation.
     */
    public static function clear_cron_events() {
        wp_clear_scheduled_hook( self::CRON_PROCESS_HOOK );
        wp_clear_scheduled_hook( self::CRON_CLEANUP_HOOK );
    }

    /**
     * Enqueue a webhook for delivery.
     *
     * Stores the webhook in the database queue. The caller can then
     * attempt immediate delivery; if that fails, the queue processor
     * will retry on the next cron run.
     *
     * @param string $event_type   The webhook event type (e.g., 'conversion.created').
     * @param array  $payload      The webhook payload data.
     * @param int    $max_attempts Maximum number of delivery attempts (default 5).
     * @return int|false The queue item ID, or false on failure.
     */
    public function enqueue( $event_type, $payload, $max_attempts = self::DEFAULT_MAX_ATTEMPTS ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'event_type'   => sanitize_text_field( wp_unslash( $event_type ) ),
                'payload'      => wp_json_encode( $payload ),
                'status'       => self::STATUS_PENDING,
                'attempts'     => 0,
                'max_attempts' => intval( $max_attempts ),
                'next_retry_at' => current_time( 'mysql', true ),
                'created_at'   => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
        );

        if ( ! $result ) {
            if ( $this->audit_logger ) {
                $this->audit_logger->error(
                    'webhook_queue_enqueue_failed',
                    array(
                        'event_type' => $event_type,
                        'db_error'   => $wpdb->last_error,
                    )
                );
            }
            return false;
        }

        $queue_id = $wpdb->insert_id;

        if ( $this->audit_logger ) {
            $this->audit_logger->info(
                'webhook_queued',
                array(
                    'queue_id'   => $queue_id,
                    'event_type' => $event_type,
                )
            );
        }

        return $queue_id;
    }

    /**
     * Attempt immediate delivery of a queued webhook.
     *
     * Tries to send the webhook right away. If delivery fails, the item
     * stays in the queue for retry by the cron processor.
     *
     * @param int $queue_id The queue item ID.
     * @return bool True if delivery succeeded, false otherwise.
     */
    public function attempt_immediate_delivery( $queue_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND status = %s",
                $queue_id,
                self::STATUS_PENDING
            )
        );

        if ( ! $item ) {
            return false;
        }

        return $this->deliver_webhook( $item );
    }

    /**
     * Process the webhook queue.
     *
     * Called by WP-Cron hourly. Processes pending and retryable webhooks
     * in batches.
     *
     * @param int $batch_size Number of webhooks to process per batch.
     */
    public function process_queue( $batch_size = 10 ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $now   = current_time( 'mysql', true );

        // Fetch pending items that are ready for (re)delivery.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE status IN (%s, %s)
                AND next_retry_at <= %s
                AND attempts < max_attempts
                ORDER BY created_at ASC
                LIMIT %d",
                self::STATUS_PENDING,
                self::STATUS_FAILED,
                $now,
                intval( $batch_size )
            )
        );

        if ( empty( $items ) ) {
            return;
        }

        $processed = 0;
        $succeeded = 0;

        foreach ( $items as $item ) {
            $result = $this->deliver_webhook( $item );
            $processed++;

            if ( $result ) {
                $succeeded++;
            }
        }

        if ( $this->audit_logger && $processed > 0 ) {
            $this->audit_logger->info(
                'webhook_queue_processed',
                array(
                    'processed' => $processed,
                    'succeeded' => $succeeded,
                    'failed'    => $processed - $succeeded,
                )
            );
        }
    }

    /**
     * Retry failed webhooks with exponential backoff.
     *
     * Finds failed webhooks that have not exceeded their max attempts
     * and schedules them for retry with exponential backoff.
     */
    public function retry_failed() {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Find failed items that still have retry attempts remaining.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $failed_items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, attempts, max_attempts FROM {$table}
                WHERE status = %s
                AND attempts < max_attempts
                ORDER BY created_at ASC
                LIMIT 50",
                self::STATUS_FAILED
            )
        );

        if ( empty( $failed_items ) ) {
            return;
        }

        foreach ( $failed_items as $item ) {
            // Calculate exponential backoff: 2^attempts minutes.
            $backoff_minutes = pow( 2, intval( $item->attempts ) );
            $next_retry      = gmdate( 'Y-m-d H:i:s', strtotime( "+{$backoff_minutes} minutes" ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table,
                array(
                    'status'        => self::STATUS_PENDING,
                    'next_retry_at' => $next_retry,
                ),
                array( 'id' => $item->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }
    }

    /**
     * Clean up completed webhook records older than the specified number of days.
     *
     * @param int $days Number of days to keep completed records (default 30).
     * @return int Number of records deleted.
     */
    public function cleanup_completed( $days = 30 ) {
        global $wpdb;

        $table    = $wpdb->prefix . self::TABLE_NAME;
        $cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table}
                WHERE status = %s
                AND completed_at < %s",
                self::STATUS_COMPLETED,
                $cutoff
            )
        );

        // Also clean permanently failed items older than the cutoff.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted_failed = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table}
                WHERE status = %s
                AND attempts >= max_attempts
                AND created_at < %s",
                self::STATUS_FAILED,
                $cutoff
            )
        );

        $total_deleted = intval( $deleted ) + intval( $deleted_failed );

        if ( $this->audit_logger && $total_deleted > 0 ) {
            $this->audit_logger->info(
                'webhook_queue_cleanup',
                array(
                    'deleted_completed' => intval( $deleted ),
                    'deleted_failed'    => intval( $deleted_failed ),
                    'cutoff_date'       => $cutoff,
                )
            );
        }

        return $total_deleted;
    }

    /**
     * Get queue statistics.
     *
     * @return array Queue stats with counts by status.
     */
    public function get_stats() {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
            OBJECT_K
        );

        return array(
            'pending'    => isset( $stats[ self::STATUS_PENDING ] ) ? intval( $stats[ self::STATUS_PENDING ]->count ) : 0,
            'processing' => isset( $stats[ self::STATUS_PROCESSING ] ) ? intval( $stats[ self::STATUS_PROCESSING ]->count ) : 0,
            'completed'  => isset( $stats[ self::STATUS_COMPLETED ] ) ? intval( $stats[ self::STATUS_COMPLETED ]->count ) : 0,
            'failed'     => isset( $stats[ self::STATUS_FAILED ] ) ? intval( $stats[ self::STATUS_FAILED ]->count ) : 0,
        );
    }

    /**
     * Get recent queue items.
     *
     * @param int    $limit  Number of items to return.
     * @param string $status Optional status filter.
     * @return array Queue items.
     */
    public function get_recent_items( $limit = 20, $status = null ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        if ( $status ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, event_type, status, attempts, max_attempts,
                            next_retry_at, created_at, completed_at, error_message
                    FROM {$table}
                    WHERE status = %s
                    ORDER BY created_at DESC
                    LIMIT %d",
                    $status,
                    $limit
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, event_type, status, attempts, max_attempts,
                            next_retry_at, created_at, completed_at, error_message
                    FROM {$table}
                    ORDER BY created_at DESC
                    LIMIT %d",
                    $limit
                )
            );
        }

        return $items;
    }

    /**
     * Deliver a single webhook item.
     *
     * Marks the item as processing, attempts delivery, and updates
     * the status based on the result.
     *
     * @param object $item The queue item from the database.
     * @return bool True if delivery succeeded, false otherwise.
     */
    private function deliver_webhook( $item ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Mark as processing.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            array( 'status' => self::STATUS_PROCESSING ),
            array( 'id' => $item->id ),
            array( '%s' ),
            array( '%d' )
        );

        $payload    = json_decode( $item->payload, true );
        $event_type = $item->event_type;
        $attempts   = intval( $item->attempts ) + 1;

        try {
            // Attempt delivery via the webhook handler's processing logic.
            $result = $this->send_webhook( $event_type, $payload );

            // Mark as completed.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table,
                array(
                    'status'       => self::STATUS_COMPLETED,
                    'attempts'     => $attempts,
                    'completed_at' => current_time( 'mysql', true ),
                    'error_message' => null,
                ),
                array( 'id' => $item->id ),
                array( '%s', '%d', '%s', '%s' ),
                array( '%d' )
            );

            return true;

        } catch ( Exception $e ) {
            // Calculate next retry with exponential backoff: 2^attempts minutes.
            $backoff_minutes = pow( 2, $attempts );
            $next_retry      = gmdate( 'Y-m-d H:i:s', strtotime( "+{$backoff_minutes} minutes" ) );

            // Determine if this is the final attempt.
            $max_attempts = intval( $item->max_attempts );
            $final_status = ( $attempts >= $max_attempts ) ? self::STATUS_FAILED : self::STATUS_FAILED;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table,
                array(
                    'status'        => $final_status,
                    'attempts'      => $attempts,
                    'next_retry_at' => $next_retry,
                    'error_message' => substr( $e->getMessage(), 0, 65535 ),
                ),
                array( 'id' => $item->id ),
                array( '%s', '%d', '%s', '%s' ),
                array( '%d' )
            );

            if ( $this->audit_logger ) {
                $this->audit_logger->warning(
                    'webhook_delivery_failed',
                    array(
                        'queue_id'      => $item->id,
                        'event_type'    => $event_type,
                        'attempt'       => $attempts,
                        'max_attempts'  => $max_attempts,
                        'error'         => $e->getMessage(),
                        'next_retry_at' => $next_retry,
                    )
                );
            }

            return false;
        }
    }

    /**
     * Send a webhook to the Affilync API.
     *
     * @param string $event_type The event type.
     * @param array  $payload    The webhook payload.
     * @return array API response.
     * @throws Exception If delivery fails.
     */
    private function send_webhook( $event_type, $payload ) {
        if ( ! $this->api_client ) {
            throw new Exception( 'API client not configured for webhook delivery' );
        }

        $endpoint = '/api/woocommerce/webhooks/receive';

        $body = array(
            'event'   => $event_type,
            'payload' => $payload,
            'source'  => 'woocommerce',
            'site'    => home_url(),
        );

        $response = $this->api_client->post( $endpoint, $body );

        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }

        $response_code = wp_remote_retrieve_response_code( $response );

        if ( $response_code < 200 || $response_code >= 300 ) {
            $response_body = wp_remote_retrieve_body( $response );
            throw new Exception(
                sprintf(
                    'Webhook delivery failed with HTTP %d: %s',
                    $response_code,
                    substr( $response_body, 0, 500 )
                )
            );
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /**
     * Create the webhook queue database table.
     *
     * Uses dbDelta for safe table creation and schema updates.
     *
     * @return void
     */
    public static function create_table() {
        global $wpdb;

        $table           = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
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
        dbDelta( $sql );
    }
}
