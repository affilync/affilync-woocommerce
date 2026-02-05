<?php
/**
 * Brand Verification Class.
 *
 * Handles brand/merchant identity verification with the Affilync platform.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Brand Verification.
 *
 * @since 1.0.0
 */
class Affilync_Verification_Brand {

    /**
     * Verification statuses.
     */
    const STATUS_UNVERIFIED = 'unverified';
    const STATUS_PENDING    = 'pending';
    const STATUS_VERIFIED   = 'verified';
    const STATUS_REJECTED   = 'rejected';

    /**
     * Verification methods.
     */
    const METHOD_DNS   = 'dns';
    const METHOD_FILE  = 'file';
    const METHOD_META  = 'meta';
    const METHOD_EMAIL = 'email';

    /**
     * API client instance.
     *
     * @var Affilync_API_Client
     */
    private $api_client;

    /**
     * Audit logger instance.
     *
     * @var Affilync_Security_Audit_Logger
     */
    private $audit_logger;

    /**
     * Constructor.
     *
     * @param Affilync_API_Client            $api_client   API client instance.
     * @param Affilync_Security_Audit_Logger $audit_logger Audit logger instance.
     */
    public function __construct( $api_client, $audit_logger ) {
        $this->api_client   = $api_client;
        $this->audit_logger = $audit_logger;

        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Admin AJAX handlers.
        add_action( 'wp_ajax_affilync_initiate_verification', array( $this, 'ajax_initiate_verification' ) );
        add_action( 'wp_ajax_affilync_check_verification', array( $this, 'ajax_check_verification' ) );
        add_action( 'wp_ajax_affilync_cancel_verification', array( $this, 'ajax_cancel_verification' ) );

        // Webhook handlers.
        add_action( 'affilync_webhook_brand.verified', array( $this, 'handle_verified_webhook' ) );
        add_action( 'affilync_webhook_brand.rejected', array( $this, 'handle_rejected_webhook' ) );

        // REST API endpoints.
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        register_rest_route(
            'affilync/v1',
            '/verification/status',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_status' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );

        register_rest_route(
            'affilync/v1',
            '/verification/initiate',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_initiate_verification' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
                'args'                => array(
                    'method' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => array( self::METHOD_DNS, self::METHOD_FILE, self::METHOD_META, self::METHOD_EMAIL ),
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            )
        );

        register_rest_route(
            'affilync/v1',
            '/verification/verify',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_verify' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );
    }

    /**
     * Check admin permission.
     *
     * @return bool
     */
    public function check_admin_permission() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Get current verification status.
     *
     * @return array
     */
    public function get_status() {
        $verification = get_option( 'affilync_brand_verification', array() );

        return wp_parse_args(
            $verification,
            array(
                'status'            => self::STATUS_UNVERIFIED,
                'method'            => '',
                'verification_code' => '',
                'initiated_at'      => '',
                'verified_at'       => '',
                'rejected_at'       => '',
                'rejection_reason'  => '',
                'brand_info'        => array(),
            )
        );
    }

    /**
     * Check if brand is verified.
     *
     * @return bool
     */
    public function is_verified() {
        $status = $this->get_status();
        return $status['status'] === self::STATUS_VERIFIED;
    }

    /**
     * Check if verification is pending.
     *
     * @return bool
     */
    public function is_pending() {
        $status = $this->get_status();
        return $status['status'] === self::STATUS_PENDING;
    }

    /**
     * Initiate brand verification.
     *
     * @param string $method Verification method.
     * @return array|WP_Error
     */
    public function initiate_verification( $method ) {
        // Validate method.
        $valid_methods = array( self::METHOD_DNS, self::METHOD_FILE, self::METHOD_META, self::METHOD_EMAIL );
        if ( ! in_array( $method, $valid_methods, true ) ) {
            return new WP_Error( 'invalid_method', __( 'Invalid verification method.', 'affilync-woocommerce' ) );
        }

        // Get site information.
        $site_url   = home_url();
        $site_name  = get_bloginfo( 'name' );
        $admin_email = get_option( 'admin_email' );
        $parsed_url = wp_parse_url( $site_url );
        $domain     = $parsed_url['host'];

        // Request verification from API.
        $response = $this->api_client->post(
            '/api/woocommerce/verification/initiate',
            array(
                'method'      => $method,
                'domain'      => $domain,
                'site_url'    => $site_url,
                'site_name'   => $site_name,
                'admin_email' => $admin_email,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->audit_logger->log(
                'verification_initiate_failed',
                array(
                    'method' => $method,
                    'error'  => $response->get_error_message(),
                ),
                'warning'
            );
            return $response;
        }

        // Store verification data.
        $verification_data = array(
            'status'            => self::STATUS_PENDING,
            'method'            => $method,
            'verification_code' => $response['verification_code'] ?? '',
            'verification_id'   => $response['verification_id'] ?? '',
            'initiated_at'      => current_time( 'mysql' ),
            'expires_at'        => $response['expires_at'] ?? '',
            'instructions'      => $response['instructions'] ?? '',
            'brand_info'        => array(
                'domain'     => $domain,
                'site_url'   => $site_url,
                'site_name'  => $site_name,
            ),
        );

        update_option( 'affilync_brand_verification', $verification_data );

        $this->audit_logger->log(
            'verification_initiated',
            array(
                'method' => $method,
                'domain' => $domain,
            ),
            'info'
        );

        return $verification_data;
    }

    /**
     * Check and complete verification.
     *
     * @return array|WP_Error
     */
    public function check_verification() {
        $status = $this->get_status();

        if ( $status['status'] !== self::STATUS_PENDING ) {
            return new WP_Error(
                'invalid_status',
                __( 'No pending verification to check.', 'affilync-woocommerce' )
            );
        }

        // Request verification check from API.
        $response = $this->api_client->post(
            '/api/woocommerce/verification/verify',
            array(
                'verification_id' => $status['verification_id'] ?? '',
                'method'          => $status['method'],
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Update status based on response.
        if ( ! empty( $response['verified'] ) && $response['verified'] === true ) {
            $this->mark_verified( $response );
            return $this->get_status();
        }

        if ( ! empty( $response['rejected'] ) && $response['rejected'] === true ) {
            $this->mark_rejected( $response['reason'] ?? '' );
            return $this->get_status();
        }

        // Still pending.
        return array(
            'status'  => 'pending',
            'message' => $response['message'] ?? __( 'Verification is still pending.', 'affilync-woocommerce' ),
        );
    }

    /**
     * Cancel pending verification.
     *
     * @return bool
     */
    public function cancel_verification() {
        $status = $this->get_status();

        if ( $status['status'] !== self::STATUS_PENDING ) {
            return false;
        }

        // Notify API about cancellation.
        $this->api_client->post(
            '/api/woocommerce/verification/cancel',
            array(
                'verification_id' => $status['verification_id'] ?? '',
            )
        );

        // Reset verification status.
        delete_option( 'affilync_brand_verification' );

        $this->audit_logger->log(
            'verification_cancelled',
            array( 'method' => $status['method'] ),
            'info'
        );

        return true;
    }

    /**
     * Mark brand as verified.
     *
     * @param array $response API response data.
     */
    private function mark_verified( $response ) {
        $current = $this->get_status();

        $verification_data = array_merge(
            $current,
            array(
                'status'      => self::STATUS_VERIFIED,
                'verified_at' => current_time( 'mysql' ),
                'brand_id'    => $response['brand_id'] ?? '',
                'badge_url'   => $response['badge_url'] ?? '',
                'trust_score' => $response['trust_score'] ?? 0,
            )
        );

        update_option( 'affilync_brand_verification', $verification_data );

        // Store brand ID separately for quick access.
        if ( ! empty( $response['brand_id'] ) ) {
            update_option( 'affilync_brand_id', $response['brand_id'] );
        }

        $this->audit_logger->log(
            'brand_verified',
            array(
                'brand_id' => $response['brand_id'] ?? '',
                'method'   => $current['method'],
            ),
            'info'
        );

        /**
         * Fires when brand verification is completed.
         *
         * @since 1.0.0
         * @param array $verification_data Verification data.
         */
        do_action( 'affilync_brand_verified', $verification_data );
    }

    /**
     * Mark brand as rejected.
     *
     * @param string $reason Rejection reason.
     */
    private function mark_rejected( $reason ) {
        $current = $this->get_status();

        $verification_data = array_merge(
            $current,
            array(
                'status'           => self::STATUS_REJECTED,
                'rejected_at'      => current_time( 'mysql' ),
                'rejection_reason' => $reason,
            )
        );

        update_option( 'affilync_brand_verification', $verification_data );

        $this->audit_logger->log(
            'brand_rejected',
            array(
                'reason' => $reason,
                'method' => $current['method'],
            ),
            'warning'
        );

        /**
         * Fires when brand verification is rejected.
         *
         * @since 1.0.0
         * @param string $reason           Rejection reason.
         * @param array  $verification_data Verification data.
         */
        do_action( 'affilync_brand_rejected', $reason, $verification_data );
    }

    /**
     * Get verification instructions for a method.
     *
     * @param string $method Verification method.
     * @return array
     */
    public function get_method_instructions( $method ) {
        $status = $this->get_status();
        $code   = $status['verification_code'] ?? 'VERIFICATION_CODE';
        $domain = $status['brand_info']['domain'] ?? wp_parse_url( home_url(), PHP_URL_HOST );

        $instructions = array(
            self::METHOD_DNS => array(
                'title'       => __( 'DNS Verification', 'affilync-woocommerce' ),
                'description' => __( 'Add a TXT record to your domain DNS settings.', 'affilync-woocommerce' ),
                'steps'       => array(
                    __( 'Log in to your domain registrar or DNS provider.', 'affilync-woocommerce' ),
                    sprintf(
                        /* translators: %s: Domain name */
                        __( 'Add a new TXT record for %s', 'affilync-woocommerce' ),
                        '<code>_affilync.' . esc_html( $domain ) . '</code>'
                    ),
                    sprintf(
                        /* translators: %s: Verification code */
                        __( 'Set the value to: %s', 'affilync-woocommerce' ),
                        '<code>' . esc_html( $code ) . '</code>'
                    ),
                    __( 'Wait for DNS propagation (may take up to 48 hours).', 'affilync-woocommerce' ),
                    __( 'Click "Verify" to complete verification.', 'affilync-woocommerce' ),
                ),
            ),
            self::METHOD_FILE => array(
                'title'       => __( 'File Verification', 'affilync-woocommerce' ),
                'description' => __( 'Upload a verification file to your website root.', 'affilync-woocommerce' ),
                'steps'       => array(
                    sprintf(
                        /* translators: %s: File name */
                        __( 'Create a file named: %s', 'affilync-woocommerce' ),
                        '<code>affilync-verify.txt</code>'
                    ),
                    sprintf(
                        /* translators: %s: Verification code */
                        __( 'Add this content to the file: %s', 'affilync-woocommerce' ),
                        '<code>' . esc_html( $code ) . '</code>'
                    ),
                    sprintf(
                        /* translators: %s: File URL */
                        __( 'Upload to: %s', 'affilync-woocommerce' ),
                        '<code>' . esc_url( home_url( '/affilync-verify.txt' ) ) . '</code>'
                    ),
                    __( 'Click "Verify" to complete verification.', 'affilync-woocommerce' ),
                ),
            ),
            self::METHOD_META => array(
                'title'       => __( 'Meta Tag Verification', 'affilync-woocommerce' ),
                'description' => __( 'Add a meta tag to your website header.', 'affilync-woocommerce' ),
                'steps'       => array(
                    __( 'Add the following meta tag to your homepage &lt;head&gt; section:', 'affilync-woocommerce' ),
                    '<code>&lt;meta name="affilync-verification" content="' . esc_html( $code ) . '"&gt;</code>',
                    __( 'You can add this using your theme customizer or a header script plugin.', 'affilync-woocommerce' ),
                    __( 'Click "Verify" to complete verification.', 'affilync-woocommerce' ),
                ),
                'auto_add'    => true,
            ),
            self::METHOD_EMAIL => array(
                'title'       => __( 'Email Verification', 'affilync-woocommerce' ),
                'description' => __( 'Verify ownership via email to a domain-associated address.', 'affilync-woocommerce' ),
                'steps'       => array(
                    __( 'We will send a verification email to one of these addresses:', 'affilync-woocommerce' ),
                    '<code>admin@' . esc_html( $domain ) . '</code>, <code>webmaster@' . esc_html( $domain ) . '</code>',
                    __( 'Click the verification link in the email.', 'affilync-woocommerce' ),
                    __( 'Your brand will be verified automatically.', 'affilync-woocommerce' ),
                ),
            ),
        );

        return $instructions[ $method ] ?? array();
    }

    /**
     * Auto-add meta tag if meta verification is selected.
     */
    public function maybe_add_verification_meta() {
        $status = $this->get_status();

        if ( $status['status'] !== self::STATUS_PENDING || $status['method'] !== self::METHOD_META ) {
            return;
        }

        add_action( 'wp_head', function() use ( $status ) {
            printf(
                '<meta name="affilync-verification" content="%s">',
                esc_attr( $status['verification_code'] )
            );
        }, 1 );
    }

    /**
     * Handle verified webhook.
     *
     * @param array $payload Webhook payload.
     */
    public function handle_verified_webhook( $payload ) {
        $this->mark_verified( $payload );
    }

    /**
     * Handle rejected webhook.
     *
     * @param array $payload Webhook payload.
     */
    public function handle_rejected_webhook( $payload ) {
        $this->mark_rejected( $payload['reason'] ?? '' );
    }

    /**
     * REST endpoint: Get verification status.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function rest_get_status( $request ) {
        $status = $this->get_status();

        // Add instructions if pending.
        if ( $status['status'] === self::STATUS_PENDING && ! empty( $status['method'] ) ) {
            $status['instructions'] = $this->get_method_instructions( $status['method'] );
        }

        return rest_ensure_response( $status );
    }

    /**
     * REST endpoint: Initiate verification.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_initiate_verification( $request ) {
        $method = $request->get_param( 'method' );
        $result = $this->initiate_verification( $method );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Add instructions to response.
        $result['method_instructions'] = $this->get_method_instructions( $method );

        return rest_ensure_response( $result );
    }

    /**
     * REST endpoint: Verify.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_verify( $request ) {
        return rest_ensure_response( $this->check_verification() );
    }

    /**
     * AJAX: Initiate verification.
     */
    public function ajax_initiate_verification() {
        check_ajax_referer( 'affilync_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $method = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';

        $result = $this->initiate_verification( $method );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $result['method_instructions'] = $this->get_method_instructions( $method );
        wp_send_json_success( $result );
    }

    /**
     * AJAX: Check verification.
     */
    public function ajax_check_verification() {
        check_ajax_referer( 'affilync_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $result = $this->check_verification();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Cancel verification.
     */
    public function ajax_cancel_verification() {
        check_ajax_referer( 'affilync_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'affilync-woocommerce' ) ) );
        }

        $result = $this->cancel_verification();

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'No pending verification to cancel.', 'affilync-woocommerce' ) ) );
        }

        wp_send_json_success( array( 'message' => __( 'Verification cancelled.', 'affilync-woocommerce' ) ) );
    }

    /**
     * Render verification badge.
     *
     * @param array $args Badge display arguments.
     * @return string HTML output.
     */
    public function render_badge( $args = array() ) {
        if ( ! $this->is_verified() ) {
            return '';
        }

        $status   = $this->get_status();
        $defaults = array(
            'size'  => 'medium',
            'style' => 'badge',
        );
        $args = wp_parse_args( $args, $defaults );

        $badge_url = $status['badge_url'] ?? AFFILYNC_PLUGIN_URL . 'assets/images/verified-badge.svg';
        $site_name = $status['brand_info']['site_name'] ?? get_bloginfo( 'name' );

        $sizes = array(
            'small'  => '24px',
            'medium' => '48px',
            'large'  => '96px',
        );
        $size = $sizes[ $args['size'] ] ?? $sizes['medium'];

        ob_start();
        ?>
        <div class="affilync-verified-badge affilync-badge-<?php echo esc_attr( $args['size'] ); ?>">
            <img
                src="<?php echo esc_url( $badge_url ); ?>"
                alt="<?php echo esc_attr( sprintf( __( '%s is Affilync Verified', 'affilync-woocommerce' ), $site_name ) ); ?>"
                width="<?php echo esc_attr( $size ); ?>"
                height="<?php echo esc_attr( $size ); ?>"
            />
            <?php if ( $args['style'] === 'full' ) : ?>
                <span class="affilync-badge-text"><?php esc_html_e( 'Affilync Verified', 'affilync-woocommerce' ); ?></span>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
