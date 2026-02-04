<?php
/**
 * OAuth Handler for Affilync API authorization.
 *
 * Implements OAuth 2.0 flow with PKCE for secure authorization.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync OAuth Handler.
 *
 * @since 1.0.0
 */
class Affilync_API_OAuth {

    /**
     * Nonce manager.
     *
     * @var Affilync_Security_Nonce_Manager
     */
    private $nonce_manager;

    /**
     * Encryption handler.
     *
     * @var Affilync_Security_Encryption
     */
    private $encryption;

    /**
     * API client.
     *
     * @var Affilync_API_Client
     */
    private $api_client;

    /**
     * OAuth scopes.
     *
     * @var array
     */
    private $scopes = array(
        'brand:read',
        'brand:write',
        'products:read',
        'products:write',
        'conversions:read',
        'conversions:write',
        'webhooks:manage',
    );

    /**
     * Constructor.
     *
     * @param Affilync_Security_Nonce_Manager $nonce_manager Nonce manager.
     * @param Affilync_Security_Encryption    $encryption    Encryption handler.
     * @param Affilync_API_Client             $api_client    API client.
     */
    public function __construct( $nonce_manager, $encryption, $api_client ) {
        $this->nonce_manager = $nonce_manager;
        $this->encryption    = $encryption;
        $this->api_client    = $api_client;

        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        add_action( 'admin_init', array( $this, 'handle_oauth_redirect' ) );
    }

    /**
     * Get OAuth authorization URL.
     *
     * @return string|WP_Error Authorization URL or error.
     */
    public function get_authorization_url() {
        // Generate PKCE code verifier.
        $code_verifier = $this->generate_code_verifier();

        // Generate code challenge.
        $code_challenge = $this->generate_code_challenge( $code_verifier );

        // Generate state nonce.
        $state = $this->nonce_manager->create(
            array(
                'site_url'      => get_site_url(),
                'code_verifier' => $code_verifier,
                'initiated_at'  => time(),
            ),
            600 // 10 minutes TTL.
        );

        if ( ! $state ) {
            return new WP_Error(
                'nonce_creation_failed',
                __( 'Failed to create OAuth state.', 'affilync-woocommerce' )
            );
        }

        // Build authorization URL.
        $api_url = $this->api_client->get_api_url();
        $params  = array(
            'client_id'             => $this->get_client_id(),
            'redirect_uri'          => $this->get_redirect_uri(),
            'response_type'         => 'code',
            'scope'                 => implode( ' ', $this->scopes ),
            'state'                 => $state,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => 'S256',
            'platform'              => 'woocommerce',
            'site_url'              => get_site_url(),
            'site_name'             => get_bloginfo( 'name' ),
        );

        return $api_url . '/oauth/authorize?' . http_build_query( $params );
    }

    /**
     * Handle OAuth callback/redirect.
     */
    public function handle_oauth_redirect() {
        // Check if this is an OAuth callback.
        if ( ! isset( $_GET['affilync_oauth'] ) || $_GET['affilync_oauth'] !== 'callback' ) {
            return;
        }

        // Verify user has permission.
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'affilync-woocommerce' ) );
        }

        // Get parameters.
        $code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

        // Handle error response.
        if ( $error ) {
            $error_description = isset( $_GET['error_description'] )
                ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) )
                : __( 'Authorization was denied.', 'affilync-woocommerce' );

            $this->redirect_with_message( 'error', $error_description );
            return;
        }

        // Validate required parameters.
        if ( empty( $code ) || empty( $state ) ) {
            $this->redirect_with_message( 'error', __( 'Invalid OAuth response.', 'affilync-woocommerce' ) );
            return;
        }

        // Verify and consume state nonce.
        $state_data = $this->nonce_manager->verify_and_consume( $state );
        if ( ! $state_data ) {
            $this->redirect_with_message( 'error', __( 'Invalid or expired state parameter.', 'affilync-woocommerce' ) );
            return;
        }

        // Verify site URL matches.
        if ( empty( $state_data['site_url'] ) || $state_data['site_url'] !== get_site_url() ) {
            $this->redirect_with_message( 'error', __( 'State verification failed.', 'affilync-woocommerce' ) );
            return;
        }

        // Exchange code for token.
        $result = $this->exchange_code_for_token( $code, $state_data['code_verifier'] );

        if ( is_wp_error( $result ) ) {
            $this->redirect_with_message( 'error', $result->get_error_message() );
            return;
        }

        // Store credentials.
        $credentials = array(
            'access_token'  => $result['access_token'],
            'refresh_token' => isset( $result['refresh_token'] ) ? $result['refresh_token'] : '',
            'expires_at'    => isset( $result['expires_in'] )
                ? gmdate( 'Y-m-d H:i:s', time() + $result['expires_in'] )
                : null,
            'brand_id'      => isset( $result['brand_id'] ) ? $result['brand_id'] : null,
        );

        $this->encryption->store_credentials( $credentials );

        // Update connection status.
        update_option( 'affilync_connection_status', 'connected' );

        if ( ! empty( $result['brand_id'] ) ) {
            update_option( 'affilync_brand_id', $result['brand_id'] );
        }

        // Store webhook secret if provided.
        if ( ! empty( $result['webhook_secret'] ) ) {
            $hmac_validator = new Affilync_Security_HMAC_Validator();
            $hmac_validator->set_secret( $result['webhook_secret'] );
        }

        // Log success.
        $audit_logger = new Affilync_Security_Audit_Logger();
        $audit_logger->info(
            Affilync_Security_Audit_Logger::EVENT_OAUTH_COMPLETED,
            array( 'brand_id' => $result['brand_id'] ?? 'unknown' )
        );

        $this->redirect_with_message( 'success', __( 'Successfully connected to Affilync!', 'affilync-woocommerce' ) );
    }

    /**
     * Exchange authorization code for access token.
     *
     * @param string $code          Authorization code.
     * @param string $code_verifier PKCE code verifier.
     * @return array|WP_Error Token data or error.
     */
    private function exchange_code_for_token( $code, $code_verifier ) {
        $api_url = $this->api_client->get_api_url();

        $response = wp_remote_post(
            $api_url . '/oauth/token',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'grant_type'    => 'authorization_code',
                        'code'          => $code,
                        'redirect_uri'  => $this->get_redirect_uri(),
                        'client_id'     => $this->get_client_id(),
                        'code_verifier' => $code_verifier,
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'token_exchange_failed',
                __( 'Failed to connect to Affilync API.', 'affilync-woocommerce' )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code !== 200 || empty( $data['access_token'] ) ) {
            $error_message = isset( $data['error_description'] )
                ? $data['error_description']
                : __( 'Failed to obtain access token.', 'affilync-woocommerce' );

            return new WP_Error( 'token_error', $error_message );
        }

        return $data;
    }

    /**
     * Disconnect from Affilync.
     *
     * @return bool True on success.
     */
    public function disconnect() {
        // Clear credentials.
        $this->encryption->clear_credentials();

        // Update status.
        update_option( 'affilync_connection_status', 'disconnected' );
        delete_option( 'affilync_brand_id' );

        // Log disconnection.
        $audit_logger = new Affilync_Security_Audit_Logger();
        $audit_logger->info( Affilync_Security_Audit_Logger::EVENT_DISCONNECTED );

        return true;
    }

    /**
     * Get OAuth redirect URI.
     *
     * @return string Redirect URI.
     */
    public function get_redirect_uri() {
        return admin_url( 'admin.php?page=affilync-settings&affilync_oauth=callback' );
    }

    /**
     * Get client ID.
     *
     * @return string Client ID.
     */
    private function get_client_id() {
        if ( defined( 'AFFILYNC_CLIENT_ID' ) ) {
            return AFFILYNC_CLIENT_ID;
        }
        return get_option( 'affilync_client_id', 'woocommerce_plugin' );
    }

    /**
     * Generate PKCE code verifier.
     *
     * @return string Code verifier.
     */
    private function generate_code_verifier() {
        return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
    }

    /**
     * Generate PKCE code challenge.
     *
     * @param string $code_verifier Code verifier.
     * @return string Code challenge.
     */
    private function generate_code_challenge( $code_verifier ) {
        $hash = hash( 'sha256', $code_verifier, true );
        return rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );
    }

    /**
     * Redirect with message.
     *
     * @param string $type    Message type (success/error).
     * @param string $message Message text.
     */
    private function redirect_with_message( $type, $message ) {
        $redirect_url = admin_url( 'admin.php?page=affilync-settings' );
        $redirect_url = add_query_arg(
            array(
                'affilync_message' => rawurlencode( $message ),
                'affilync_type'    => $type,
            ),
            $redirect_url
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Check if currently connected.
     *
     * @return bool True if connected.
     */
    public function is_connected() {
        return $this->encryption->has_credentials();
    }

    /**
     * Get connection status.
     *
     * @return array Status information.
     */
    public function get_status() {
        $connected = $this->is_connected();

        return array(
            'connected'  => $connected,
            'status'     => $connected ? 'connected' : 'disconnected',
            'brand_id'   => get_option( 'affilync_brand_id', '' ),
            'last_check' => get_option( 'affilync_last_connection_check', '' ),
        );
    }
}
