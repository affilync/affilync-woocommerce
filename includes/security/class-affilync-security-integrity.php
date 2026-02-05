<?php
/**
 * Integrity Protection for Affilync WooCommerce Plugin.
 *
 * Detects and prevents tampering with critical plugin files and configuration.
 * Provides runtime verification of API endpoints and code integrity.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Security Integrity Handler.
 *
 * @since 1.0.0
 */
class Affilync_Security_Integrity {

    /**
     * Expected API URL.
     *
     * @var string
     */
    const EXPECTED_API_URL = 'https://api.affilync.com';

    /**
     * Expected App URL.
     *
     * @var string
     */
    const EXPECTED_APP_URL = 'https://app.affilync.com';

    /**
     * Critical files to verify (relative to plugin directory).
     *
     * @var array
     */
    private static $critical_files = array(
        'affilync-woocommerce.php',
        'includes/api/class-affilync-api-client.php',
        'includes/api/class-affilync-api-oauth.php',
        'includes/security/class-affilync-security-encryption.php',
        'includes/licensing/class-affilync-license-manager.php',
    );

    /**
     * Audit logger.
     *
     * @var Affilync_Security_Audit_Logger
     */
    private $audit_logger;

    /**
     * Cached integrity status.
     *
     * @var bool|null
     */
    private static $integrity_verified = null;

    /**
     * Constructor.
     *
     * @param Affilync_Security_Audit_Logger|null $audit_logger Audit logger instance.
     */
    public function __construct( $audit_logger = null ) {
        $this->audit_logger = $audit_logger;
    }

    /**
     * Verify plugin integrity.
     *
     * Checks API URLs, constant definitions, and optionally file hashes.
     *
     * @param bool $check_files Whether to verify file hashes.
     * @return bool True if integrity verified.
     */
    public function verify( $check_files = false ) {
        // Use cached result if available.
        if ( self::$integrity_verified !== null && ! $check_files ) {
            return self::$integrity_verified;
        }

        $issues = array();

        // Verify API URL hasn't been tampered with.
        if ( ! $this->verify_api_url() ) {
            $issues[] = 'api_url_tampered';
        }

        // Verify APP URL hasn't been tampered with.
        if ( ! $this->verify_app_url() ) {
            $issues[] = 'app_url_tampered';
        }

        // Verify critical constants exist.
        if ( ! $this->verify_constants() ) {
            $issues[] = 'constants_missing';
        }

        // Optionally verify file hashes.
        if ( $check_files ) {
            $file_issues = $this->verify_file_hashes();
            if ( ! empty( $file_issues ) ) {
                $issues[] = 'files_modified';
                $issues = array_merge( $issues, $file_issues );
            }
        }

        // Log any issues found.
        if ( ! empty( $issues ) ) {
            $this->log_tampering_attempt( $issues );
            self::$integrity_verified = false;
            return false;
        }

        self::$integrity_verified = true;
        return true;
    }

    /**
     * Verify API URL matches expected value.
     *
     * @return bool True if valid.
     */
    private function verify_api_url() {
        if ( ! defined( 'AFFILYNC_API_URL' ) ) {
            return false;
        }

        // Normalize URLs for comparison.
        $expected = rtrim( self::EXPECTED_API_URL, '/' );
        $actual = rtrim( AFFILYNC_API_URL, '/' );

        return $expected === $actual;
    }

    /**
     * Verify App URL matches expected value.
     *
     * @return bool True if valid.
     */
    private function verify_app_url() {
        if ( ! defined( 'AFFILYNC_APP_URL' ) ) {
            return false;
        }

        $expected = rtrim( self::EXPECTED_APP_URL, '/' );
        $actual = rtrim( AFFILYNC_APP_URL, '/' );

        return $expected === $actual;
    }

    /**
     * Verify critical constants are defined.
     *
     * @return bool True if all constants exist.
     */
    private function verify_constants() {
        $required_constants = array(
            'AFFILYNC_VERSION',
            'AFFILYNC_PLUGIN_FILE',
            'AFFILYNC_PLUGIN_DIR',
        );

        foreach ( $required_constants as $constant ) {
            if ( ! defined( $constant ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify file hashes against stored values.
     *
     * @return array List of modified files, empty if all valid.
     */
    private function verify_file_hashes() {
        $modified_files = array();
        $stored_hashes = get_option( 'affilync_file_hashes', array() );

        // If no hashes stored, generate them.
        if ( empty( $stored_hashes ) ) {
            $this->generate_file_hashes();
            return array();
        }

        foreach ( self::$critical_files as $file ) {
            $file_path = AFFILYNC_PLUGIN_DIR . $file;

            if ( ! file_exists( $file_path ) ) {
                $modified_files[] = 'missing:' . $file;
                continue;
            }

            $current_hash = hash_file( 'sha256', $file_path );

            if ( isset( $stored_hashes[ $file ] ) && $stored_hashes[ $file ] !== $current_hash ) {
                $modified_files[] = 'modified:' . $file;
            }
        }

        return $modified_files;
    }

    /**
     * Generate and store file hashes.
     *
     * Should only be called during plugin updates or installation.
     *
     * @return bool True on success.
     */
    public function generate_file_hashes() {
        $hashes = array();

        foreach ( self::$critical_files as $file ) {
            $file_path = AFFILYNC_PLUGIN_DIR . $file;

            if ( file_exists( $file_path ) ) {
                $hashes[ $file ] = hash_file( 'sha256', $file_path );
            }
        }

        return update_option( 'affilync_file_hashes', $hashes, false );
    }

    /**
     * Log tampering attempt.
     *
     * @param array $issues List of integrity issues found.
     */
    private function log_tampering_attempt( $issues ) {
        // Log locally.
        if ( $this->audit_logger ) {
            $this->audit_logger->critical(
                'integrity_violation',
                array(
                    'issues'     => $issues,
                    'ip_address' => $this->get_client_ip(),
                    'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
                )
            );
        }

        // Report to Affilync API (fire and forget).
        $this->report_tampering_to_api( $issues );
    }

    /**
     * Report tampering to Affilync API.
     *
     * @param array $issues List of integrity issues.
     */
    private function report_tampering_to_api( $issues ) {
        // Non-blocking report.
        wp_remote_post(
            self::EXPECTED_API_URL . '/api/security/integrity-alert',
            array(
                'timeout'   => 1,
                'blocking'  => false,
                'headers'   => array(
                    'Content-Type' => 'application/json',
                ),
                'body'      => wp_json_encode(
                    array(
                        'site_url'    => get_site_url(),
                        'site_hash'   => hash( 'sha256', get_site_url() . '_affilync' ),
                        'issues'      => $issues,
                        'timestamp'   => time(),
                        'plugin_ver'  => defined( 'AFFILYNC_VERSION' ) ? AFFILYNC_VERSION : 'unknown',
                    )
                ),
            )
        );
    }

    /**
     * Get client IP address.
     *
     * @return string Client IP.
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare.
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // Handle comma-separated list (X-Forwarded-For).
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Block plugin execution if integrity check fails.
     *
     * @param bool $die Whether to terminate execution.
     */
    public function enforce( $die = true ) {
        if ( ! $this->verify() ) {
            if ( $die ) {
                wp_die(
                    esc_html__( 'Affilync: Plugin integrity verification failed. The plugin may have been modified.', 'affilync-woocommerce' ),
                    esc_html__( 'Security Error', 'affilync-woocommerce' ),
                    array( 'response' => 403 )
                );
            }
            return false;
        }
        return true;
    }

    /**
     * Check if running in debug mode.
     *
     * Returns true if potentially running in debugger.
     *
     * @return bool True if debugging detected.
     */
    public function is_debugging() {
        // Check for Xdebug.
        if ( function_exists( 'xdebug_is_enabled' ) && xdebug_is_enabled() ) {
            return true;
        }

        // Check for common debugging extensions.
        if ( extension_loaded( 'xdebug' ) || extension_loaded( 'Zend Debugger' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Verify request is from expected origin.
     *
     * @return bool True if request appears legitimate.
     */
    public function verify_request_origin() {
        // Skip for CLI requests.
        if ( php_sapi_name() === 'cli' ) {
            return true;
        }

        // Check for suspicious headers that might indicate automated scanning.
        $suspicious_agents = array(
            'curl/',
            'wget/',
            'python-requests/',
            'scrapy/',
            'httpie/',
        );

        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) )
            : '';

        foreach ( $suspicious_agents as $agent ) {
            if ( strpos( $user_agent, $agent ) !== false ) {
                // Log but don't block - could be legitimate automation.
                if ( $this->audit_logger ) {
                    $this->audit_logger->warning(
                        'suspicious_user_agent',
                        array( 'user_agent' => $user_agent )
                    );
                }
            }
        }

        return true;
    }

    /**
     * Get integrity status report.
     *
     * @return array Status information.
     */
    public function get_status() {
        return array(
            'integrity_verified' => $this->verify(),
            'api_url_valid'      => $this->verify_api_url(),
            'app_url_valid'      => $this->verify_app_url(),
            'constants_valid'    => $this->verify_constants(),
            'debugging_detected' => $this->is_debugging(),
            'last_check'         => current_time( 'mysql' ),
        );
    }
}
