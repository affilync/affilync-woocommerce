<?php
/**
 * Audit Logger for security events.
 *
 * Logs all security-relevant events to the database.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Audit Logger.
 *
 * Provides comprehensive logging for security events, API calls,
 * authentication attempts, and webhook processing.
 *
 * @since 1.0.0
 */
class Affilync_Security_Audit_Logger {

    /**
     * Table name (without prefix).
     *
     * @var string
     */
    const TABLE_NAME = 'affilync_audit_log';

    /**
     * Severity levels.
     */
    const SEVERITY_INFO     = 'info';
    const SEVERITY_WARNING  = 'warning';
    const SEVERITY_ERROR    = 'error';
    const SEVERITY_CRITICAL = 'critical';

    /**
     * Event types.
     */
    const EVENT_AUTH_SUCCESS     = 'auth_success';
    const EVENT_AUTH_FAILURE     = 'auth_failure';
    const EVENT_OAUTH_INITIATED  = 'oauth_initiated';
    const EVENT_OAUTH_COMPLETED  = 'oauth_completed';
    const EVENT_OAUTH_FAILED     = 'oauth_failed';
    const EVENT_DISCONNECTED     = 'disconnected';
    const EVENT_RATE_LIMITED     = 'rate_limited';
    const EVENT_WEBHOOK_RECEIVED = 'webhook_received';
    const EVENT_WEBHOOK_FAILED   = 'webhook_failed';
    const EVENT_WEBHOOK_INVALID  = 'webhook_invalid';
    const EVENT_CONVERSION_TRACKED = 'conversion_tracked';
    const EVENT_CONVERSION_FAILED  = 'conversion_failed';
    const EVENT_PRODUCT_SYNCED   = 'product_synced';
    const EVENT_PRODUCT_SYNC_FAILED = 'product_sync_failed';
    const EVENT_API_ERROR        = 'api_error';
    const EVENT_ENCRYPTION_ERROR = 'encryption_error';
    const EVENT_NONCE_INVALID    = 'nonce_invalid';
    const EVENT_SETTINGS_CHANGED = 'settings_changed';

    /**
     * Maximum log retention in days.
     *
     * @var int
     */
    const RETENTION_DAYS = 90;

    /**
     * Get the full table name.
     *
     * @return string
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Log an event.
     *
     * @param string $event_type The type of event.
     * @param array  $details    Additional details (will be JSON encoded).
     * @param string $severity   Severity level.
     * @return bool True on success.
     */
    public function log( $event_type, $details = array(), $severity = self::SEVERITY_INFO ) {
        global $wpdb;

        $table = $this->get_table_name();

        // Get current user ID.
        $user_id = get_current_user_id();

        // Get client info.
        $ip_address = $this->get_client_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
            : null;

        // Add timestamp to details.
        $details['logged_at'] = current_time( 'mysql', true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'event_type' => $event_type,
                'user_id'    => $user_id ?: null,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'details'    => wp_json_encode( $details ),
                'severity'   => $severity,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s' )
        );

        // Also log to error_log for critical events.
        if ( in_array( $severity, array( self::SEVERITY_ERROR, self::SEVERITY_CRITICAL ), true ) ) {
            $this->log_to_error_log( $event_type, $details, $severity );
        }

        return $result !== false;
    }

    /**
     * Log info event.
     *
     * @param string $event_type Event type.
     * @param array  $details    Details.
     * @return bool
     */
    public function info( $event_type, $details = array() ) {
        return $this->log( $event_type, $details, self::SEVERITY_INFO );
    }

    /**
     * Log warning event.
     *
     * @param string $event_type Event type.
     * @param array  $details    Details.
     * @return bool
     */
    public function warning( $event_type, $details = array() ) {
        return $this->log( $event_type, $details, self::SEVERITY_WARNING );
    }

    /**
     * Log error event.
     *
     * @param string $event_type Event type.
     * @param array  $details    Details.
     * @return bool
     */
    public function error( $event_type, $details = array() ) {
        return $this->log( $event_type, $details, self::SEVERITY_ERROR );
    }

    /**
     * Log critical event.
     *
     * @param string $event_type Event type.
     * @param array  $details    Details.
     * @return bool
     */
    public function critical( $event_type, $details = array() ) {
        return $this->log( $event_type, $details, self::SEVERITY_CRITICAL );
    }

    /**
     * Get the client IP address.
     *
     * @return string IP address.
     */
    private function get_client_ip() {
        $headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip  = trim( $ips[0] );
                }

                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Log to WordPress error log.
     *
     * @param string $event_type Event type.
     * @param array  $details    Details.
     * @param string $severity   Severity.
     * @return void
     */
    private function log_to_error_log( $event_type, $details, $severity ) {
        $message = sprintf(
            '[Affilync %s] %s: %s',
            strtoupper( $severity ),
            $event_type,
            wp_json_encode( $details )
        );

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( $message );
    }

    /**
     * Get recent log entries.
     *
     * @param array $args {
     *     Query arguments.
     *
     *     @type string $event_type Filter by event type.
     *     @type string $severity   Filter by severity.
     *     @type int    $user_id    Filter by user ID.
     *     @type string $ip_address Filter by IP address.
     *     @type int    $limit      Number of entries.
     *     @type int    $offset     Offset for pagination.
     * }
     * @return array Log entries.
     */
    public function get_logs( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'event_type' => null,
            'severity'   => null,
            'user_id'    => null,
            'ip_address' => null,
            'limit'      => 50,
            'offset'     => 0,
        );

        $args = wp_parse_args( $args, $defaults );
        $table = $this->get_table_name();

        $where = array( '1=1' );
        $values = array();

        if ( $args['event_type'] ) {
            $where[] = 'event_type = %s';
            $values[] = $args['event_type'];
        }

        if ( $args['severity'] ) {
            $where[] = 'severity = %s';
            $values[] = $args['severity'];
        }

        if ( $args['user_id'] ) {
            $where[] = 'user_id = %d';
            $values[] = $args['user_id'];
        }

        if ( $args['ip_address'] ) {
            $where[] = 'ip_address = %s';
            $values[] = $args['ip_address'];
        }

        $where_clause = implode( ' AND ', $where );
        $values[] = absint( $args['limit'] );
        $values[] = absint( $args['offset'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $values
            )
        );

        // Decode JSON details.
        foreach ( $results as &$row ) {
            $row->details = json_decode( $row->details, true );
        }

        return $results;
    }

    /**
     * Get log count.
     *
     * @param string|null $event_type Filter by event type.
     * @param string|null $severity   Filter by severity.
     * @return int Count.
     */
    public function get_count( $event_type = null, $severity = null ) {
        global $wpdb;

        $table = $this->get_table_name();

        $where = array( '1=1' );
        $values = array();

        if ( $event_type ) {
            $where[] = 'event_type = %s';
            $values[] = $event_type;
        }

        if ( $severity ) {
            $where[] = 'severity = %s';
            $values[] = $severity;
        }

        $where_clause = implode( ' AND ', $where );

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}",
                    $values
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        return intval( $count );
    }

    /**
     * Get summary statistics.
     *
     * @param int $days Number of days to summarize.
     * @return array Statistics.
     */
    public function get_stats( $days = 7 ) {
        global $wpdb;

        $table = $this->get_table_name();
        $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $by_type = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, COUNT(*) as count FROM {$table}
                WHERE created_at > %s GROUP BY event_type ORDER BY count DESC",
                $since
            ),
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $by_severity = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT severity, COUNT(*) as count FROM {$table}
                WHERE created_at > %s GROUP BY severity",
                $since
            ),
            ARRAY_A
        );

        return array(
            'by_type'     => $by_type,
            'by_severity' => $by_severity,
            'period_days' => $days,
        );
    }

    /**
     * Clean up old log entries.
     *
     * @param int|null $days Days to retain (default: RETENTION_DAYS).
     * @return int Number of rows deleted.
     */
    public function cleanup( $days = null ) {
        global $wpdb;

        if ( $days === null ) {
            $days = self::RETENTION_DAYS;
        }

        $table = $this->get_table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoff
            )
        );

        return intval( $deleted );
    }

    /**
     * Clear all logs.
     *
     * @return bool True on success.
     */
    public function clear_all() {
        global $wpdb;

        $table = $this->get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query( "TRUNCATE TABLE {$table}" );

        return $result !== false;
    }

    /**
     * Export logs to CSV.
     *
     * @param array $args Query arguments (see get_logs).
     * @return string CSV content.
     */
    public function export_csv( $args = array() ) {
        $args['limit'] = 10000; // Max export.
        $logs = $this->get_logs( $args );

        $output = fopen( 'php://temp', 'r+' );

        // Header row.
        fputcsv( $output, array(
            'ID',
            'Event Type',
            'User ID',
            'IP Address',
            'Severity',
            'Details',
            'Created At',
        ) );

        foreach ( $logs as $log ) {
            fputcsv( $output, array(
                $log->id,
                $log->event_type,
                $log->user_id,
                $log->ip_address,
                $log->severity,
                wp_json_encode( $log->details ),
                $log->created_at,
            ) );
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        return $csv;
    }
}
