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
     * Option name for the per-installation PBKDF2 salt.
     *
     * @var string
     */
    const SALT_OPTION = 'affilync_pbkdf2_salt';

    /**
     * Derived encryption key (cached).
     *
     * @var string|null
     */
    private $derived_key = null;

    /**
     * Whether insecure key storage is being used.
     *
     * @var bool
     */
    private $using_insecure_storage = false;

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

        // SEC-08: Block startup in production if no secure encryption key is configured.
        // In production, DB-stored keys are unacceptable — they can be extracted via SQL injection.
        if ( defined( 'AFFILYNC_PRODUCTION_MODE' ) && AFFILYNC_PRODUCTION_MODE && ! $this->is_key_secure() ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
            trigger_error(
                esc_html__( 'Affilync: AFFILYNC_ENCRYPTION_KEY must be defined in wp-config.php for production. Encryption is disabled until this is resolved.', 'affilync-woocommerce' ),
                E_USER_ERROR
            );
        }

        // Check key security on admin pages.
        if ( is_admin() ) {
            add_action( 'admin_notices', array( $this, 'display_insecure_key_warning' ) );
        }
    }

    /**
     * Display warning if using insecure key storage.
     */
    public function display_insecure_key_warning() {
        // Only show on Affilync pages.
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'affilync' ) === false ) {
            return;
        }

        // Check if insecure storage is being used.
        if ( ! $this->is_key_secure() ) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( 'Affilync Security Warning:', 'affilync-woocommerce' ); ?></strong>
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: Code example */
                            __( 'Your encryption key is stored insecurely in the database. For production use, add this to your wp-config.php: %s', 'affilync-woocommerce' ),
                            "<code>define( 'AFFILYNC_ENCRYPTION_KEY', '" . esc_html( wp_generate_password( 64, true, true ) ) . "' );</code>"
                        ),
                        array( 'code' => array() )
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Check if the encryption key is stored securely.
     *
     * @return bool True if using secure key storage.
     */
    public function is_key_secure() {
        // Secure if AFFILYNC_ENCRYPTION_KEY is defined.
        if ( defined( 'AFFILYNC_ENCRYPTION_KEY' ) && AFFILYNC_ENCRYPTION_KEY ) {
            return true;
        }

        // Acceptable if SECURE_AUTH_KEY is strong enough.
        if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY && strlen( SECURE_AUTH_KEY ) >= 64 ) {
            return true;
        }

        return false;
    }

    /**
     * Get the master encryption key.
     *
     * Priority:
     * 1. AFFILYNC_ENCRYPTION_KEY constant (most secure)
     * 2. SECURE_AUTH_KEY WordPress constant (acceptable)
     * 3. Generate and store a new key (insecure - database storage)
     *
     * In production mode (AFFILYNC_PRODUCTION_MODE), database fallback is disabled.
     *
     * @return string Master key.
     * @throws Exception If production mode is enabled and no secure key is configured.
     */
    private function get_master_key() {
        // First, check for dedicated Affilync encryption key (most secure).
        if ( defined( 'AFFILYNC_ENCRYPTION_KEY' ) && AFFILYNC_ENCRYPTION_KEY ) {
            $this->using_insecure_storage = false;
            return AFFILYNC_ENCRYPTION_KEY;
        }

        // Fall back to WordPress SECURE_AUTH_KEY (acceptable for most cases).
        if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY && strlen( SECURE_AUTH_KEY ) >= 32 ) {
            // Mark as insecure if key is weak.
            $this->using_insecure_storage = strlen( SECURE_AUTH_KEY ) < 64;
            return SECURE_AUTH_KEY;
        }

        // Production mode enforcement - do not allow database fallback.
        if ( defined( 'AFFILYNC_PRODUCTION_MODE' ) && AFFILYNC_PRODUCTION_MODE ) {
            // Log critical security error.
            if ( class_exists( 'Affilync_Security_Audit_Logger' ) ) {
                $logger = new Affilync_Security_Audit_Logger();
                $logger->critical(
                    'encryption_key_missing',
                    array( 'message' => 'Production mode requires AFFILYNC_ENCRYPTION_KEY constant' )
                );
            }

            // Return empty key - encryption will fail, which is safer than insecure storage.
            wp_die(
                esc_html__( 'Affilync: Production mode requires AFFILYNC_ENCRYPTION_KEY to be defined in wp-config.php', 'affilync-woocommerce' ),
                esc_html__( 'Configuration Error', 'affilync-woocommerce' ),
                array( 'response' => 500 )
            );
        }

        // Generate and store a key if none exists (development/testing only).
        $this->using_insecure_storage = true;
        $stored_key = get_option( 'affilync_encryption_key' );
        if ( ! $stored_key ) {
            $stored_key = wp_generate_password( 64, true, true );
            update_option( 'affilync_encryption_key', $stored_key, false );

            // Log warning about insecure key storage (one-time on generation).
            if ( class_exists( 'Affilync_Security_Audit_Logger' ) ) {
                $logger = new Affilync_Security_Audit_Logger();
                $logger->warning(
                    'encryption_key_insecure',
                    array( 'message' => 'Encryption key stored in database. Define AFFILYNC_ENCRYPTION_KEY for production.' )
                );
            }
        }

        // SEC: emit a recurring loud warning on every key read while the
        // DB fallback is in use. The single one-time warning above only
        // fires on key generation; an operator who installs the plugin
        // without setting AFFILYNC_PRODUCTION_MODE never sees it again
        // and ships to prod with the key sitting next to the ciphertext
        // it protects (any SQL injection or backup compromise yields
        // both). Now PHP_USER_NOTICE on every read so the noise reaches
        // log aggregators; admin notice for WP-admin visibility.
        // (Pre-launch security audit 2026-05-18.)
        if ( function_exists( 'trigger_error' ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
            trigger_error(
                'Affilync WooCommerce: encryption key is read from wp_options. ' .
                'Define AFFILYNC_ENCRYPTION_KEY in wp-config.php and ' .
                'AFFILYNC_PRODUCTION_MODE for production deployments.',
                E_USER_WARNING
            );
        }
        if ( is_admin() && function_exists( 'add_action' ) ) {
            add_action(
                'admin_notices',
                function () {
                    echo '<div class="notice notice-error"><p>';
                    echo '<strong>Affilync WooCommerce — Insecure encryption-key storage.</strong> ';
                    echo 'The plugin is reading its encryption key from the database. ';
                    echo 'Set <code>AFFILYNC_ENCRYPTION_KEY</code> and ';
                    echo '<code>AFFILYNC_PRODUCTION_MODE</code> in wp-config.php before production use.';
                    echo '</p></div>';
                }
            );
        }

        return $stored_key;
    }

    /**
     * Check if using insecure key storage.
     *
     * @return bool True if using database-stored key.
     */
    public function is_using_insecure_storage() {
        // Initialize key check if not done.
        if ( $this->derived_key === null ) {
            $this->derive_key();
        }
        return $this->using_insecure_storage;
    }

    /**
     * Get or generate the per-installation PBKDF2 salt.
     *
     * On first use, generates a cryptographically random 32-byte salt
     * and stores it in wp_options. All subsequent calls return the stored salt.
     *
     * @return string Raw binary salt (32 bytes).
     */
    private function get_salt() {
        $stored_salt = get_option( self::SALT_OPTION );
        if ( $stored_salt && strlen( base64_decode( $stored_salt, true ) ) === 32 ) {
            return base64_decode( $stored_salt );
        }

        // Generate a new cryptographically secure random salt.
        $salt = random_bytes( 32 );
        update_option( self::SALT_OPTION, base64_encode( $salt ), false );

        return $salt;
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
        $salt       = $this->get_salt();

        $this->derived_key = hash_pbkdf2(
            'sha256',
            $master_key,
            $salt,
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
