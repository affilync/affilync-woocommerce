<?php
/**
 * Nonce Manager for OAuth CSRF protection.
 *
 * Database-backed one-time tokens with automatic expiration.
 * Mirrors NonceStorage from affilync-shopify.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Nonce Manager.
 *
 * Provides secure one-time nonces for OAuth flows and CSRF protection.
 * Unlike WordPress nonces, these are true one-time tokens stored in the database.
 *
 * @since 1.0.0
 */
class Affilync_Security_Nonce_Manager {

    /**
     * Table name (without prefix).
     *
     * @var string
     */
    const TABLE_NAME = 'affilync_nonces';

    /**
     * Default TTL in seconds (10 minutes).
     *
     * @var int
     */
    const DEFAULT_TTL = 600;

    /**
     * Nonce length in bytes.
     *
     * @var int
     */
    const NONCE_LENGTH = 32;

    /**
     * Cleanup probability (1 in X requests).
     *
     * @var int
     */
    const CLEANUP_PROBABILITY = 100;

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
     * Generate a secure random nonce.
     *
     * @return string Hex-encoded nonce.
     */
    public function generate() {
        return bin2hex( random_bytes( self::NONCE_LENGTH ) );
    }

    /**
     * Store a nonce with associated data.
     *
     * @param string $nonce     The nonce to store.
     * @param array  $data      Associated data (e.g., ['site_url' => 'example.com']).
     * @param int    $ttl       Time to live in seconds.
     * @return bool True on success.
     */
    public function store( $nonce, $data = array(), $ttl = self::DEFAULT_TTL ) {
        global $wpdb;

        // Probabilistic cleanup.
        $this->maybe_cleanup();

        $expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $this->get_table_name(),
            array(
                'nonce'      => $nonce,
                'nonce_data' => wp_json_encode( $data ),
                'expires_at' => $expires_at,
                'used'       => 0,
            ),
            array( '%s', '%s', '%s', '%d' )
        );

        return $result !== false;
    }

    /**
     * Verify and consume a nonce (one-time use).
     *
     * This is an atomic operation - the nonce is marked as used before
     * returning, preventing replay attacks.
     *
     * @param string $nonce The nonce to verify.
     * @return array|false The associated data if valid, false otherwise.
     */
    public function verify_and_consume( $nonce ) {
        global $wpdb;

        if ( empty( $nonce ) ) {
            return false;
        }

        $table = $this->get_table_name();

        // Use a transaction-like approach with UPDATE ... WHERE.
        // This atomically marks the nonce as used only if it's valid.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET used = 1 WHERE nonce = %s AND used = 0 AND expires_at > %s",
                $nonce,
                current_time( 'mysql', true )
            )
        );

        if ( $updated !== 1 ) {
            // Nonce was invalid, expired, or already used.
            return false;
        }

        // Fetch the associated data.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT nonce_data FROM {$table} WHERE nonce = %s",
                $nonce
            )
        );

        if ( ! $row ) {
            return false;
        }

        $data = json_decode( $row->nonce_data, true );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Check if a nonce exists and is valid (without consuming).
     *
     * @param string $nonce The nonce to check.
     * @return bool True if valid.
     */
    public function exists( $nonce ) {
        global $wpdb;

        if ( empty( $nonce ) ) {
            return false;
        }

        $table = $this->get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE nonce = %s AND used = 0 AND expires_at > %s",
                $nonce,
                current_time( 'mysql', true )
            )
        );

        return intval( $count ) > 0;
    }

    /**
     * Get data associated with a nonce (without consuming).
     *
     * @param string $nonce The nonce to look up.
     * @return array|false The associated data if valid, false otherwise.
     */
    public function get_data( $nonce ) {
        global $wpdb;

        if ( empty( $nonce ) ) {
            return false;
        }

        $table = $this->get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT nonce_data FROM {$table} WHERE nonce = %s AND used = 0 AND expires_at > %s",
                $nonce,
                current_time( 'mysql', true )
            )
        );

        if ( ! $row ) {
            return false;
        }

        $data = json_decode( $row->nonce_data, true );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Invalidate a specific nonce.
     *
     * @param string $nonce The nonce to invalidate.
     * @return bool True on success.
     */
    public function invalidate( $nonce ) {
        global $wpdb;

        if ( empty( $nonce ) ) {
            return false;
        }

        $table = $this->get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete(
            $table,
            array( 'nonce' => $nonce ),
            array( '%s' )
        );

        return $deleted !== false;
    }

    /**
     * Probabilistically run cleanup of expired nonces.
     *
     * @return void
     */
    private function maybe_cleanup() {
        // Run cleanup randomly (1 in 100 requests).
        if ( wp_rand( 1, self::CLEANUP_PROBABILITY ) !== 1 ) {
            return;
        }

        $this->cleanup_expired();
    }

    /**
     * Clean up expired and used nonces.
     *
     * @return int Number of rows deleted.
     */
    public function cleanup_expired() {
        global $wpdb;

        $table = $this->get_table_name();

        // Delete expired nonces and used nonces older than 1 hour.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at < %s OR (used = 1 AND created_at < %s)",
                current_time( 'mysql', true ),
                gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
            )
        );

        return intval( $deleted );
    }

    /**
     * Create and store a new nonce, returning it.
     *
     * Convenience method that combines generate() and store().
     *
     * @param array $data Associated data.
     * @param int   $ttl  Time to live in seconds.
     * @return string|false The nonce if stored successfully, false otherwise.
     */
    public function create( $data = array(), $ttl = self::DEFAULT_TTL ) {
        $nonce = $this->generate();

        if ( $this->store( $nonce, $data, $ttl ) ) {
            return $nonce;
        }

        return false;
    }
}
