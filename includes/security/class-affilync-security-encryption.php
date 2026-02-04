<?php
/**
 * Encryption class for secure credential storage.
 *
 * Uses AES-256-GCM encryption with PBKDF2 key derivation.
 * Mirrors security patterns from affilync-shopify.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Encryption Handler.
 *
 * Provides secure encryption/decryption for sensitive data like API tokens.
 *
 * @since 1.0.0
 */
class Affilync_Security_Encryption {

    /**
     * Encryption algorithm.
     *
     * @var string
     */
    const CIPHER = 'aes-256-gcm';

    /**
     * PBKDF2 iterations for key derivation.
     *
     * @var int
     */
    const PBKDF2_ITERATIONS = 100000;

    /**
     * Key length in bytes.
     *
     * @var int
     */
    const KEY_LENGTH = 32;

    /**
     * IV length in bytes.
     *
     * @var int
     */
    const IV_LENGTH = 12;

    /**
     * Auth tag length in bytes.
     *
     * @var int
     */
    const TAG_LENGTH = 16;

    /**
     * Version prefix for encrypted data.
     *
     * @var string
     */
    const VERSION = 'v1';

    /**
     * Static salt for key derivation.
     *
     * @var string
     */
    const SALT = 'affilync_woocommerce_salt_v1';

    /**
     * Derived encryption key (cached).
     *
     * @var string|null
     */
    private $derived_key = null;

    /**
     * Constructor.
     */
    public function __construct() {
        // Verify OpenSSL extension is available.
        if ( ! extension_loaded( 'openssl' ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
            trigger_error(
                esc_html__( 'Affilync: OpenSSL extension is required for encryption.', 'affilync-woocommerce' ),
                E_USER_WARNING
            );
        }
    }

    /**
     * Get the master encryption key.
     *
     * Priority:
     * 1. AFFILYNC_ENCRYPTION_KEY constant
     * 2. SECURE_AUTH_KEY WordPress constant
     * 3. Generate and store a new key
     *
     * @return string Master key.
     */
    private function get_master_key() {
        // First, check for dedicated Affilync encryption key.
        if ( defined( 'AFFILYNC_ENCRYPTION_KEY' ) && AFFILYNC_ENCRYPTION_KEY ) {
            return AFFILYNC_ENCRYPTION_KEY;
        }

        // Fall back to WordPress SECURE_AUTH_KEY.
        if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY && strlen( SECURE_AUTH_KEY ) >= 32 ) {
            return SECURE_AUTH_KEY;
        }

        // Generate and store a key if none exists.
        $stored_key = get_option( 'affilync_encryption_key' );
        if ( ! $stored_key ) {
            $stored_key = wp_generate_password( 64, true, true );
            update_option( 'affilync_encryption_key', $stored_key, false );
        }

        return $stored_key;
    }

    /**
     * Derive encryption key from master key using PBKDF2.
     *
     * @return string Derived key.
     */
    private function derive_key() {
        if ( $this->derived_key !== null ) {
            return $this->derived_key;
        }

        $master_key = $this->get_master_key();

        $this->derived_key = hash_pbkdf2(
            'sha256',
            $master_key,
            self::SALT,
            self::PBKDF2_ITERATIONS,
            self::KEY_LENGTH,
            true
        );

        return $this->derived_key;
    }

    /**
     * Encrypt a value.
     *
     * @param string $plaintext The value to encrypt.
     * @return string|false Encrypted value (base64 encoded) or false on failure.
     */
    public function encrypt( $plaintext ) {
        if ( empty( $plaintext ) ) {
            return false;
        }

        // Generate random IV.
        $iv = openssl_random_pseudo_bytes( self::IV_LENGTH );
        if ( $iv === false ) {
            return false;
        }

        $key = $this->derive_key();
        $tag = '';

        // Encrypt with AES-256-GCM.
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ( $ciphertext === false ) {
            return false;
        }

        // Format: version:iv:ciphertext+tag (all base64 encoded).
        $encrypted = self::VERSION . ':' .
            base64_encode( $iv ) . ':' .
            base64_encode( $ciphertext . $tag );

        return $encrypted;
    }

    /**
     * Decrypt a value.
     *
     * @param string $encrypted The encrypted value (from encrypt()).
     * @return string|false Decrypted value or false on failure.
     */
    public function decrypt( $encrypted ) {
        if ( empty( $encrypted ) ) {
            return false;
        }

        // Parse format: version:iv:ciphertext+tag.
        $parts = explode( ':', $encrypted );
        if ( count( $parts ) !== 3 ) {
            return false;
        }

        list( $version, $iv_b64, $data_b64 ) = $parts;

        // Check version.
        if ( $version !== self::VERSION ) {
            return false;
        }

        // Decode components.
        $iv   = base64_decode( $iv_b64, true );
        $data = base64_decode( $data_b64, true );

        if ( $iv === false || $data === false ) {
            return false;
        }

        // Validate lengths.
        if ( strlen( $iv ) !== self::IV_LENGTH ) {
            return false;
        }

        if ( strlen( $data ) < self::TAG_LENGTH ) {
            return false;
        }

        // Split ciphertext and tag.
        $ciphertext = substr( $data, 0, -self::TAG_LENGTH );
        $tag        = substr( $data, -self::TAG_LENGTH );

        $key = $this->derive_key();

        // Decrypt with AES-256-GCM.
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext;
    }

    /**
     * Mask a token for logging purposes.
     *
     * @param string $token       The token to mask.
     * @param int    $visible_end Number of characters to show at end.
     * @return string Masked token (e.g., "****abcd").
     */
    public function mask_token( $token, $visible_end = 4 ) {
        if ( strlen( $token ) <= $visible_end ) {
            return str_repeat( '*', strlen( $token ) );
        }

        return str_repeat( '*', strlen( $token ) - $visible_end ) . substr( $token, -$visible_end );
    }

    /**
     * Securely compare two strings in constant time.
     *
     * @param string $known_string The known string.
     * @param string $user_string  The user-supplied string.
     * @return bool True if strings are equal.
     */
    public function secure_compare( $known_string, $user_string ) {
        return hash_equals( (string) $known_string, (string) $user_string );
    }

    /**
     * Store encrypted credentials in options.
     *
     * @param array $credentials Credentials to store.
     * @return bool True on success.
     */
    public function store_credentials( $credentials ) {
        $encrypted = $this->encrypt( wp_json_encode( $credentials ) );
        if ( $encrypted === false ) {
            return false;
        }

        return update_option( 'affilync_api_credentials', $encrypted, false );
    }

    /**
     * Retrieve and decrypt credentials from options.
     *
     * @return array|false Credentials array or false on failure.
     */
    public function get_credentials() {
        $encrypted = get_option( 'affilync_api_credentials' );
        if ( empty( $encrypted ) ) {
            return false;
        }

        $decrypted = $this->decrypt( $encrypted );
        if ( $decrypted === false ) {
            return false;
        }

        $credentials = json_decode( $decrypted, true );
        if ( ! is_array( $credentials ) ) {
            return false;
        }

        return $credentials;
    }

    /**
     * Clear stored credentials.
     *
     * @return bool True on success.
     */
    public function clear_credentials() {
        return delete_option( 'affilync_api_credentials' );
    }

    /**
     * Check if valid credentials are stored.
     *
     * @return bool True if credentials exist and can be decrypted.
     */
    public function has_credentials() {
        $credentials = $this->get_credentials();
        return is_array( $credentials ) && ! empty( $credentials['access_token'] );
    }
}
