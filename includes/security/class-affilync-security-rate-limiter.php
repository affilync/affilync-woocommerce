<?php
/**
 * Rate Limiter for API protection.
 *
 * Database-backed sliding window rate limiting.
 * Mirrors RateLimiter from affilync-shopify.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Rate Limiter.
 *
 * Implements sliding window rate limiting using database storage.
 * Falls back gracefully if the database is unavailable.
 *
 * @since 1.0.0
 */
class Affilync_Security_Rate_Limiter {

    /**
     * Table name (without prefix).
     *
     * @var string
     */
    const TABLE_NAME = 'affilync_rate_limits';

    /**
     * Rate limit configurations.
     *
     * @var array
     */
    private $limits = array(
        'api_call'       => array(
            'limit'  => 100,
            'window' => 60,
        ),
        'oauth_attempt'  => array(
            'limit'  => 5,
            'window' => 300,
        ),
        'webhook_receive' => array(
            'limit'  => 200,
            'window' => 60,
        ),
        'product_sync'   => array(
            'limit'  => 50,
            'window' => 60,
        ),
        'conversion_track' => array(
            'limit'  => 100,
            'window' => 60,
        ),
    );

    /**
     * Cleanup probability (1 in X requests).
     *
     * @var int
     */
    const CLEANUP_PROBABILITY = 50;

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
     * Get identifier for rate limiting.
     *
     * Combines IP address with user agent hash for better fingerprinting.
     *
     * @return string Identifier string.
     */
    public function get_identifier() {
        $ip = $this->get_client_ip();

        // Hash user agent for additional fingerprinting.
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';
        $ua_hash = substr( md5( $user_agent ), 0, 8 );

        return $ip . ':' . $ua_hash;
    }

    /**
     * Get the client IP address.
     *
     * Handles proxy headers appropriately.
     *
     * @return string IP address.
     */
    public function get_client_ip() {
        $headers = array(
            'HTTP_CF_CONNECTING_IP',  // Cloudflare.
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

                // X-Forwarded-For can contain multiple IPs.
                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip  = trim( $ips[0] );
                }

                // Validate IP address.
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Check if a request is allowed under rate limit.
     *
     * @param string      $action     The action type (e.g., 'api_call', 'webhook_receive').
     * @param string|null $identifier Optional custom identifier.
     * @return array {
     *     @type bool $allowed   Whether the request is allowed.
     *     @type int  $remaining Remaining requests in window.
     *     @type int  $limit     Total limit for this action.
     *     @type int  $reset     Seconds until window resets.
     * }
     */
    public function check( $action, $identifier = null ) {
        // Get limit configuration.
        $config = $this->get_limit_config( $action );
        if ( ! $config ) {
            // Unknown action - allow by default.
            return array(
                'allowed'   => true,
                'remaining' => 999,
                'limit'     => 999,
                'reset'     => 0,
            );
        }

        $limit  = $config['limit'];
        $window = $config['window'];

        if ( $identifier === null ) {
            $identifier = $this->get_identifier();
        }

        // Probabilistic cleanup.
        $this->maybe_cleanup();

        try {
            $count = $this->get_request_count( $identifier, $action, $window );
            $allowed = $count < $limit;
            $remaining = max( 0, $limit - $count - 1 );

            if ( $allowed ) {
                $this->increment_count( $identifier, $action, $window );
            }

            return array(
                'allowed'   => $allowed,
                'remaining' => $allowed ? $remaining : 0,
                'limit'     => $limit,
                'reset'     => $window,
            );
        } catch ( Exception $e ) {
            // If rate limiting fails (e.g., database error), allow request.
            return array(
                'allowed'   => true,
                'remaining' => $limit,
                'limit'     => $limit,
                'reset'     => $window,
            );
        }
    }

    /**
     * Get current request count for an identifier and action.
     *
     * @param string $identifier The identifier.
     * @param string $action     The action type.
     * @param int    $window     Window size in seconds.
     * @return int Request count.
     */
    private function get_request_count( $identifier, $action, $window ) {
        global $wpdb;

        $table = $this->get_table_name();
        $window_start = gmdate( 'Y-m-d H:i:s', time() - $window );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT request_count, window_start FROM {$table}
                WHERE identifier = %s AND action_type = %s AND window_start > %s",
                $identifier,
                $action,
                $window_start
            )
        );

        return $row ? intval( $row->request_count ) : 0;
    }

    /**
     * Increment request count for an identifier and action.
     *
     * @param string $identifier The identifier.
     * @param string $action     The action type.
     * @param int    $window     Window size in seconds.
     * @return bool True on success.
     */
    private function increment_count( $identifier, $action, $window ) {
        global $wpdb;

        $table = $this->get_table_name();
        $window_start = gmdate( 'Y-m-d H:i:s', time() - $window );

        // Try to update existing record within window.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET request_count = request_count + 1
                WHERE identifier = %s AND action_type = %s AND window_start > %s",
                $identifier,
                $action,
                $window_start
            )
        );

        if ( $updated > 0 ) {
            return true;
        }

        // No existing record - insert new one.
        // Delete any old records first.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            $table,
            array(
                'identifier'  => $identifier,
                'action_type' => $action,
            ),
            array( '%s', '%s' )
        );

        // Insert new record.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'identifier'    => $identifier,
                'action_type'   => $action,
                'request_count' => 1,
                'window_start'  => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%d', '%s' )
        );

        return $result !== false;
    }

    /**
     * Get rate limit configuration for an action.
     *
     * @param string $action The action type.
     * @return array|null Configuration or null if not found.
     */
    private function get_limit_config( $action ) {
        return isset( $this->limits[ $action ] ) ? $this->limits[ $action ] : null;
    }

    /**
     * Set custom rate limit for an action.
     *
     * @param string $action The action type.
     * @param int    $limit  Maximum requests.
     * @param int    $window Window size in seconds.
     * @return void
     */
    public function set_limit( $action, $limit, $window ) {
        $this->limits[ $action ] = array(
            'limit'  => absint( $limit ),
            'window' => absint( $window ),
        );
    }

    /**
     * Get remaining requests for an identifier and action.
     *
     * @param string      $action     The action type.
     * @param string|null $identifier Optional custom identifier.
     * @return int Remaining requests.
     */
    public function get_remaining( $action, $identifier = null ) {
        $config = $this->get_limit_config( $action );
        if ( ! $config ) {
            return 999;
        }

        if ( $identifier === null ) {
            $identifier = $this->get_identifier();
        }

        $count = $this->get_request_count( $identifier, $action, $config['window'] );
        return max( 0, $config['limit'] - $count );
    }

    /**
     * Probabilistically run cleanup.
     *
     * @return void
     */
    private function maybe_cleanup() {
        if ( wp_rand( 1, self::CLEANUP_PROBABILITY ) !== 1 ) {
            return;
        }

        $this->cleanup_expired();
    }

    /**
     * Clean up expired rate limit records.
     *
     * @return int Number of rows deleted.
     */
    public function cleanup_expired() {
        global $wpdb;

        $table = $this->get_table_name();

        // Delete records older than the maximum window (5 minutes default).
        $oldest = gmdate( 'Y-m-d H:i:s', time() - 300 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE window_start < %s",
                $oldest
            )
        );

        return intval( $deleted );
    }

    /**
     * Add rate limit headers to a response.
     *
     * @param string $action The action type.
     * @param array  $result Result from check() method.
     * @return void
     */
    public function add_headers( $action, $result ) {
        if ( headers_sent() ) {
            return;
        }

        header( 'X-RateLimit-Limit: ' . $result['limit'] );
        header( 'X-RateLimit-Remaining: ' . $result['remaining'] );
        header( 'X-RateLimit-Reset: ' . $result['reset'] );

        if ( ! $result['allowed'] ) {
            header( 'Retry-After: ' . $result['reset'] );
        }
    }

    /**
     * Check rate limit and send headers.
     *
     * Convenience method that combines check() and add_headers().
     *
     * @param string      $action     The action type.
     * @param string|null $identifier Optional custom identifier.
     * @return bool True if allowed.
     */
    public function check_and_respond( $action, $identifier = null ) {
        $result = $this->check( $action, $identifier );
        $this->add_headers( $action, $result );

        return $result['allowed'];
    }

    /**
     * Reset rate limit for an identifier and action.
     *
     * @param string $identifier The identifier.
     * @param string $action     The action type.
     * @return bool True on success.
     */
    public function reset( $identifier, $action ) {
        global $wpdb;

        $table = $this->get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete(
            $table,
            array(
                'identifier'  => $identifier,
                'action_type' => $action,
            ),
            array( '%s', '%s' )
        );

        return $deleted !== false;
    }
}
