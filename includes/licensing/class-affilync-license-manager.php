<?php
/**
 * License Manager for Affilync WooCommerce Plugin.
 *
 * Handles license validation, activation, deactivation, and periodic re-validation.
 * Implements domain binding and offline grace period for network issues.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync License Manager.
 *
 * @since 1.0.0
 */
class Affilync_License_Manager {

    /**
     * License API endpoint.
     *
     * @var string
     */
    const LICENSE_API_URL = 'https://api.affilync.com/api/woocommerce/licenses';

    /**
     * Grace period in days for network issues.
     *
     * @var int
     */
    const GRACE_PERIOD_DAYS = 3;

    /**
     * Revalidation interval in hours.
     *
     * @var int
     */
    const REVALIDATION_INTERVAL = 24;

    /**
     * License status constants.
     */
    const STATUS_VALID      = 'valid';
    const STATUS_INVALID    = 'invalid';
    const STATUS_EXPIRED    = 'expired';
    const STATUS_SUSPENDED  = 'suspended';
    const STATUS_GRACE      = 'grace_period';
    const STATUS_UNCHECKED  = 'unchecked';

    /**
     * Encryption handler.
     *
     * @var Affilync_Security_Encryption
     */
    private $encryption;

    /**
     * Audit logger.
     *
     * @var Affilync_Security_Audit_Logger
     */
    private $audit_logger;

    /**
     * Cached license data.
     *
     * @var array|null
     */
    private $cached_license = null;

    /**
     * Constructor.
     *
     * @param Affilync_Security_Encryption   $encryption   Encryption handler.
     * @param Affilync_Security_Audit_Logger $audit_logger Audit logger.
     */
    public function __construct( $encryption = null, $audit_logger = null ) {
        $this->encryption   = $encryption ?: new Affilync_Security_Encryption();
        $this->audit_logger = $audit_logger ?: new Affilync_Security_Audit_Logger();

        // Register cron handler.
        add_action( 'affilync_license_revalidation', array( $this, 'revalidate_license' ) );
    }

    /**
     * Check if license is valid.
     *
     * @return bool True if license is valid or in grace period.
     */
    public function is_valid() {
        $status = $this->get_status();
        return in_array( $status, array( self::STATUS_VALID, self::STATUS_GRACE ), true );
    }

    /**
     * Get current license status.
     *
     * @return string License status constant.
     */
    public function get_status() {
        $license_data = $this->get_license_data();

        if ( empty( $license_data ) || empty( $license_data['license_key'] ) ) {
            return self::STATUS_UNCHECKED;
        }

        // Check cached status.
        if ( ! empty( $license_data['status'] ) ) {
            // Verify status hasn't expired.
            $last_check = isset( $license_data['last_check'] ) ? strtotime( $license_data['last_check'] ) : 0;
            $hours_since_check = ( time() - $last_check ) / 3600;

            // If within revalidation interval, use cached status.
            if ( $hours_since_check < self::REVALIDATION_INTERVAL ) {
                return $license_data['status'];
            }

            // Check grace period.
            if ( $license_data['status'] === self::STATUS_VALID ) {
                $grace_deadline = $last_check + ( self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS );
                if ( time() < $grace_deadline ) {
                    return self::STATUS_GRACE;
                }
            }
        }

        // Need to revalidate.
        return $this->revalidate_license_internal( $license_data['license_key'] );
    }

    /**
     * Get detailed license information.
     *
     * @return array License details.
     */
    public function get_license_info() {
        $license_data = $this->get_license_data();

        if ( empty( $license_data ) ) {
            return array(
                'status'       => self::STATUS_UNCHECKED,
                'license_key'  => '',
                'expires_at'   => null,
                'plan'         => null,
                'features'     => array(),
                'last_check'   => null,
                'domain'       => get_site_url(),
                'domain_hash'  => $this->get_domain_hash(),
            );
        }

        return array(
            'status'       => $this->get_status(),
            'license_key'  => $this->mask_license_key( $license_data['license_key'] ?? '' ),
            'expires_at'   => $license_data['expires_at'] ?? null,
            'plan'         => $license_data['plan'] ?? null,
            'features'     => $license_data['features'] ?? array(),
            'last_check'   => $license_data['last_check'] ?? null,
            'domain'       => get_site_url(),
            'domain_hash'  => $this->get_domain_hash(),
        );
    }

    /**
     * Activate a license key.
     *
     * @param string $license_key The license key to activate.
     * @return array|WP_Error Activation result or error.
     */
    public function activate( $license_key ) {
        if ( empty( $license_key ) ) {
            return new WP_Error(
                'invalid_license_key',
                __( 'Please enter a valid license key.', 'affilync-woocommerce' )
            );
        }

        // Sanitize license key.
        $license_key = sanitize_text_field( trim( $license_key ) );

        // Validate format (expected: AFFILYNC-XXXX-XXXX-XXXX-XXXX).
        if ( ! preg_match( '/^AFFILYNC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $license_key ) ) {
            return new WP_Error(
                'invalid_license_format',
                __( 'Invalid license key format. Expected: AFFILYNC-XXXX-XXXX-XXXX-XXXX', 'affilync-woocommerce' )
            );
        }

        // Call API to activate.
        $response = $this->api_request( 'activate', array(
            'license_key' => $license_key,
            'domain'      => get_site_url(),
            'domain_hash' => $this->get_domain_hash(),
            'site_name'   => get_bloginfo( 'name' ),
            'plugin_ver'  => AFFILYNC_VERSION,
            'wp_version'  => get_bloginfo( 'version' ),
            'wc_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
            'php_version' => PHP_VERSION,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->audit_logger->error(
                'license_activation_failed',
                array(
                    'error'       => $response->get_error_message(),
                    'license_key' => $this->mask_license_key( $license_key ),
                )
            );
            return $response;
        }

        // Store license data.
        $license_data = array(
            'license_key' => $license_key,
            'status'      => self::STATUS_VALID,
            'client_id'   => $response['client_id'] ?? null,
            'expires_at'  => $response['expires_at'] ?? null,
            'plan'        => $response['plan'] ?? 'starter',
            'features'    => $response['features'] ?? array(),
            'activated_at' => current_time( 'mysql' ),
            'last_check'  => current_time( 'mysql' ),
        );

        $this->store_license_data( $license_data );

        // Store client ID for OAuth.
        if ( ! empty( $response['client_id'] ) ) {
            update_option( 'affilync_license_client_id', $response['client_id'], false );
        }

        // Schedule revalidation.
        $this->schedule_revalidation();

        $this->audit_logger->info(
            'license_activated',
            array(
                'license_key' => $this->mask_license_key( $license_key ),
                'plan'        => $license_data['plan'],
            )
        );

        return array(
            'success' => true,
            'message' => __( 'License activated successfully.', 'affilync-woocommerce' ),
            'data'    => $this->get_license_info(),
        );
    }

    /**
     * Deactivate the current license.
     *
     * @return array|WP_Error Deactivation result or error.
     */
    public function deactivate() {
        $license_data = $this->get_license_data();

        if ( empty( $license_data['license_key'] ) ) {
            return new WP_Error(
                'no_license',
                __( 'No license to deactivate.', 'affilync-woocommerce' )
            );
        }

        // Call API to deactivate.
        $response = $this->api_request( 'deactivate', array(
            'license_key' => $license_data['license_key'],
            'domain_hash' => $this->get_domain_hash(),
        ) );

        // Clear local data regardless of API response.
        $this->clear_license_data();

        // Clear scheduled revalidation.
        wp_clear_scheduled_hook( 'affilync_license_revalidation' );

        if ( is_wp_error( $response ) ) {
            $this->audit_logger->warning(
                'license_deactivation_api_error',
                array( 'error' => $response->get_error_message() )
            );
        }

        $this->audit_logger->info( 'license_deactivated' );

        return array(
            'success' => true,
            'message' => __( 'License deactivated successfully.', 'affilync-woocommerce' ),
        );
    }

    /**
     * Revalidate license (called by cron).
     */
    public function revalidate_license() {
        $license_data = $this->get_license_data();

        if ( empty( $license_data['license_key'] ) ) {
            return;
        }

        $this->revalidate_license_internal( $license_data['license_key'] );
    }

    /**
     * Internal license revalidation.
     *
     * @param string $license_key The license key.
     * @return string New status.
     */
    private function revalidate_license_internal( $license_key ) {
        $response = $this->api_request( 'verify', array(
            'license_key' => $license_key,
            'domain_hash' => $this->get_domain_hash(),
        ) );

        $license_data = $this->get_license_data();

        if ( is_wp_error( $response ) ) {
            // Network error - enter grace period.
            $license_data['last_network_error'] = current_time( 'mysql' );

            // Check if still within grace period.
            $last_valid = isset( $license_data['last_valid_check'] ) ? strtotime( $license_data['last_valid_check'] ) : 0;
            $grace_deadline = $last_valid + ( self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS );

            if ( time() < $grace_deadline && $license_data['status'] === self::STATUS_VALID ) {
                $license_data['status'] = self::STATUS_GRACE;
                $this->store_license_data( $license_data );

                $this->audit_logger->warning(
                    'license_grace_period',
                    array( 'error' => $response->get_error_message() )
                );

                return self::STATUS_GRACE;
            }

            // Grace period expired.
            $license_data['status'] = self::STATUS_INVALID;
            $this->store_license_data( $license_data );

            $this->audit_logger->error(
                'license_validation_failed',
                array( 'error' => $response->get_error_message() )
            );

            return self::STATUS_INVALID;
        }

        // Update license data from server.
        $new_status = $response['status'] ?? self::STATUS_INVALID;

        $license_data['status']           = $new_status;
        $license_data['last_check']       = current_time( 'mysql' );
        $license_data['expires_at']       = $response['expires_at'] ?? $license_data['expires_at'];
        $license_data['plan']             = $response['plan'] ?? $license_data['plan'];
        $license_data['features']         = $response['features'] ?? $license_data['features'];

        if ( $new_status === self::STATUS_VALID ) {
            $license_data['last_valid_check'] = current_time( 'mysql' );
            unset( $license_data['last_network_error'] );
        }

        $this->store_license_data( $license_data );

        return $new_status;
    }

    /**
     * Make API request to license server.
     *
     * @param string $action  API action (verify, activate, deactivate).
     * @param array  $data    Request data.
     * @return array|WP_Error Response data or error.
     */
    private function api_request( $action, $data ) {
        $url = self::LICENSE_API_URL . '/' . $action;

        // Add integrity signature.
        $data['timestamp'] = time();
        $data['signature'] = $this->generate_request_signature( $data );

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type'     => 'application/json',
                    'Accept'           => 'application/json',
                    'X-Plugin-Version' => AFFILYNC_VERSION,
                ),
                'body'    => wp_json_encode( $data ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $result      = json_decode( $body, true );

        if ( $status_code >= 400 ) {
            $error_message = isset( $result['error'] )
                ? $result['error']
                : __( 'License verification failed.', 'affilync-woocommerce' );

            return new WP_Error( 'license_api_error', $error_message );
        }

        return $result;
    }

    /**
     * Get stored license data.
     *
     * @return array|false License data or false.
     */
    private function get_license_data() {
        if ( $this->cached_license !== null ) {
            return $this->cached_license;
        }

        $encrypted = get_option( 'affilync_license_data' );
        if ( empty( $encrypted ) ) {
            return false;
        }

        $decrypted = $this->encryption->decrypt( $encrypted );
        if ( $decrypted === false ) {
            return false;
        }

        $this->cached_license = json_decode( $decrypted, true );
        return $this->cached_license;
    }

    /**
     * Store license data.
     *
     * @param array $data License data.
     * @return bool True on success.
     */
    private function store_license_data( $data ) {
        $this->cached_license = $data;

        $encrypted = $this->encryption->encrypt( wp_json_encode( $data ) );
        if ( $encrypted === false ) {
            return false;
        }

        return update_option( 'affilync_license_data', $encrypted, false );
    }

    /**
     * Clear stored license data.
     *
     * @return bool True on success.
     */
    private function clear_license_data() {
        $this->cached_license = null;
        delete_option( 'affilync_license_data' );
        delete_option( 'affilync_license_client_id' );
        return true;
    }

    /**
     * Get domain hash for binding.
     *
     * @return string SHA256 hash of site URL.
     */
    private function get_domain_hash() {
        $site_url = get_site_url();
        // Normalize URL.
        $normalized = preg_replace( '#^https?://#', '', rtrim( $site_url, '/' ) );
        return hash( 'sha256', $normalized . '_affilync_domain_bind' );
    }

    /**
     * Generate request signature for integrity.
     *
     * @param array $data Request data.
     * @return string HMAC signature.
     */
    private function generate_request_signature( $data ) {
        $payload = wp_json_encode( array(
            'license_key' => $data['license_key'] ?? '',
            'domain_hash' => $data['domain_hash'] ?? '',
            'timestamp'   => $data['timestamp'] ?? time(),
        ) );

        // Use site-specific key.
        $key = defined( 'AFFILYNC_ENCRYPTION_KEY' )
            ? AFFILYNC_ENCRYPTION_KEY
            : wp_salt( 'auth' );

        return hash_hmac( 'sha256', $payload, $key );
    }

    /**
     * Mask license key for display.
     *
     * @param string $license_key Full license key.
     * @return string Masked key.
     */
    private function mask_license_key( $license_key ) {
        if ( strlen( $license_key ) <= 12 ) {
            return str_repeat( '*', strlen( $license_key ) );
        }

        $prefix = substr( $license_key, 0, 8 );
        $suffix = substr( $license_key, -4 );
        $middle_length = strlen( $license_key ) - 12;

        return $prefix . str_repeat( '*', $middle_length ) . $suffix;
    }

    /**
     * Schedule license revalidation cron.
     */
    private function schedule_revalidation() {
        if ( ! wp_next_scheduled( 'affilync_license_revalidation' ) ) {
            wp_schedule_event(
                time() + ( self::REVALIDATION_INTERVAL * HOUR_IN_SECONDS ),
                'daily',
                'affilync_license_revalidation'
            );
        }
    }

    /**
     * Check if a feature is available in current license.
     *
     * @param string $feature Feature slug.
     * @return bool True if feature is available.
     */
    public function has_feature( $feature ) {
        if ( ! $this->is_valid() ) {
            return false;
        }

        $license_data = $this->get_license_data();
        $features = $license_data['features'] ?? array();

        return in_array( $feature, $features, true );
    }

    /**
     * Get the license plan.
     *
     * @return string|null Plan slug or null if no license.
     */
    public function get_plan() {
        $license_data = $this->get_license_data();
        return $license_data['plan'] ?? null;
    }

    /**
     * Check if license is in grace period.
     *
     * @return bool True if in grace period.
     */
    public function is_grace_period() {
        return $this->get_status() === self::STATUS_GRACE;
    }

    /**
     * Get days remaining in grace period.
     *
     * @return int Days remaining (0 if not in grace period).
     */
    public function get_grace_days_remaining() {
        if ( ! $this->is_grace_period() ) {
            return 0;
        }

        $license_data = $this->get_license_data();
        $last_valid = isset( $license_data['last_valid_check'] ) ? strtotime( $license_data['last_valid_check'] ) : 0;
        $grace_deadline = $last_valid + ( self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS );

        $remaining = $grace_deadline - time();
        return max( 0, ceil( $remaining / DAY_IN_SECONDS ) );
    }
}
