<?php
/**
 * HMAC Validator for webhook signature verification.
 *
 * Provides constant-time signature verification.
 * Mirrors security patterns from affilync-shopify.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync HMAC Validator.
 *
 * Verifies webhook signatures using HMAC-SHA256.
 *
 * @since 1.0.0
 */
class Affilync_Security_HMAC_Validator {

    /**
     * Signature header name.
     *
     * @var string
     */
    const SIGNATURE_HEADER = 'X-Affilync-Signature';

    /**
     * Timestamp header name.
     *
     * @var string
     */
    const TIMESTAMP_HEADER = 'X-Affilync-Timestamp';

    /**
     * Maximum age for timestamp validation (5 minutes).
     *
     * @var int
     */
    const MAX_TIMESTAMP_AGE = 300;

    /**
     * Algorithm used for HMAC.
     *
     * @var string
     */
    const ALGORITHM = 'sha256';

    /**
     * Get the webhook secret.
     *
     * @return string|false Secret or false if not set.
     */
    private function get_secret() {
        // First, check for constant.
        if ( defined( 'AFFILYNC_WEBHOOK_SECRET' ) && AFFILYNC_WEBHOOK_SECRET ) {
            return AFFILYNC_WEBHOOK_SECRET;
        }

        // Fall back to stored option.
        $secret = get_option( 'affilync_webhook_secret' );
        return ! empty( $secret ) ? $secret : false;
    }

    /**
     * Set the webhook secret.
     *
     * @param string $secret The secret to store.
     * @return bool True on success.
     */
    public function set_secret( $secret ) {
        return update_option( 'affilync_webhook_secret', $secret, false );
    }

    /**
     * Generate a new webhook secret.
     *
     * @return string The generated secret.
     */
    public function generate_secret() {
        return 'whsec_' . bin2hex( random_bytes( 32 ) );
    }

    /**
     * Verify webhook signature.
     *
     * @param string $payload   The raw request body.
     * @param string $signature The signature from header.
     * @param string $timestamp The timestamp from header.
     * @return array {
     *     @type bool   $valid   Whether signature is valid.
     *     @type string $error   Error message if invalid.
     * }
     */
    public function verify( $payload, $signature, $timestamp = null ) {
        $secret = $this->get_secret();
        if ( ! $secret ) {
            return array(
                'valid' => false,
                'error' => 'Webhook secret not configured',
            );
        }

        // Validate timestamp if provided.
        if ( $timestamp !== null ) {
            $timestamp_result = $this->validate_timestamp( $timestamp );
            if ( ! $timestamp_result['valid'] ) {
                return $timestamp_result;
            }
        }

        // Validate signature.
        if ( empty( $signature ) ) {
            return array(
                'valid' => false,
                'error' => 'Missing signature',
            );
        }

        // Build the signed payload.
        $signed_payload = $timestamp !== null ? "{$timestamp}.{$payload}" : $payload;

        // Calculate expected signature.
        $expected_signature = $this->calculate_signature( $signed_payload, $secret );

        // Constant-time comparison.
        if ( ! hash_equals( $expected_signature, $signature ) ) {
            return array(
                'valid' => false,
                'error' => 'Invalid signature',
            );
        }

        return array(
            'valid' => true,
            'error' => null,
        );
    }

    /**
     * Calculate HMAC signature.
     *
     * @param string $payload The payload to sign.
     * @param string $secret  The secret key.
     * @return string Hex-encoded signature.
     */
    public function calculate_signature( $payload, $secret ) {
        return hash_hmac( self::ALGORITHM, $payload, $secret );
    }

    /**
     * Validate timestamp.
     *
     * @param string|int $timestamp The timestamp to validate.
     * @return array {
     *     @type bool   $valid Whether timestamp is valid.
     *     @type string $error Error message if invalid.
     * }
     */
    public function validate_timestamp( $timestamp ) {
        $timestamp = intval( $timestamp );

        if ( $timestamp <= 0 ) {
            return array(
                'valid' => false,
                'error' => 'Invalid timestamp format',
            );
        }

        $now = time();
        $age = abs( $now - $timestamp );

        if ( $age > self::MAX_TIMESTAMP_AGE ) {
            return array(
                'valid' => false,
                'error' => 'Timestamp too old or in future',
            );
        }

        return array(
            'valid' => true,
            'error' => null,
        );
    }

    /**
     * Verify webhook from request.
     *
     * Convenience method that extracts headers and body from current request.
     *
     * @return array {
     *     @type bool   $valid   Whether request is valid.
     *     @type string $error   Error message if invalid.
     *     @type string $payload The raw request body.
     * }
     */
    public function verify_request() {
        // Get raw body and unslash to reverse WordPress magic quotes.
        $payload = wp_unslash( file_get_contents( 'php://input' ) );
        if ( empty( $payload ) ) {
            return array(
                'valid'   => false,
                'error'   => 'Empty request body',
                'payload' => '',
            );
        }

        // Get signature header.
        $signature = $this->get_header( self::SIGNATURE_HEADER );
        if ( empty( $signature ) ) {
            return array(
                'valid'   => false,
                'error'   => 'Missing signature header',
                'payload' => $payload,
            );
        }

        // Get timestamp header (mandatory for replay attack prevention).
        $timestamp = $this->get_header( self::TIMESTAMP_HEADER );
        if ( empty( $timestamp ) ) {
            return array(
                'valid'   => false,
                'error'   => 'Missing timestamp header',
                'payload' => $payload,
            );
        }

        // Verify.
        $result = $this->verify( $payload, $signature, $timestamp );
        $result['payload'] = $payload;

        return $result;
    }

    /**
     * Get a header value from the request.
     *
     * @param string $name Header name.
     * @return string|null Header value or null if not found.
     */
    private function get_header( $name ) {
        // Convert header name to SERVER key format.
        $server_key = 'HTTP_' . str_replace( '-', '_', strtoupper( $name ) );

        if ( isset( $_SERVER[ $server_key ] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
        }

        // Try getting from getallheaders() if available.
        if ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            foreach ( $headers as $key => $value ) {
                if ( strcasecmp( $key, $name ) === 0 ) {
                    return sanitize_text_field( wp_unslash( $value ) );
                }
            }
        }

        return null;
    }

    /**
     * Sign a payload for sending.
     *
     * @param string $payload The payload to sign.
     * @return array {
     *     @type string $signature The signature.
     *     @type int    $timestamp The timestamp used.
     * }
     */
    public function sign( $payload ) {
        $secret = $this->get_secret();
        if ( ! $secret ) {
            return array(
                'signature' => '',
                'timestamp' => 0,
            );
        }

        $timestamp = time();
        $signed_payload = "{$timestamp}.{$payload}";
        $signature = $this->calculate_signature( $signed_payload, $secret );

        return array(
            'signature' => $signature,
            'timestamp' => $timestamp,
        );
    }

    /**
     * Verify signature using base64-encoded format.
     *
     * Alternative verification method that expects base64-encoded signature.
     *
     * @param string $payload    The raw payload.
     * @param string $signature  Base64-encoded signature.
     * @return bool True if valid.
     */
    public function verify_base64( $payload, $signature ) {
        $secret = $this->get_secret();
        if ( ! $secret ) {
            return false;
        }

        $expected = base64_encode(
            hash_hmac( self::ALGORITHM, $payload, $secret, true )
        );

        return hash_equals( $expected, $signature );
    }
}
