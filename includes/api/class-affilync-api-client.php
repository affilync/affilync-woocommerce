<?php
/**
 * API Client for Affilync API communication.
 *
 * Handles all HTTP requests to the Affilync API with
 * automatic token refresh, rate limiting, and error handling.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync API Client.
 *
 * @since 1.0.0
 */
class Affilync_API_Client {

    /**
     * API base URL.
     *
     * @var string
     */
    private $api_url;

    /**
     * Encryption handler.
     *
     * @var Affilync_Security_Encryption
     */
    private $encryption;

    /**
     * Rate limiter.
     *
     * @var Affilync_Security_Rate_Limiter
     */
    private $rate_limiter;

    /**
     * Request timeout in seconds.
     *
     * @var int
     */
    const TIMEOUT = 30;

    /**
     * Maximum retries for failed requests.
     *
     * @var int
     */
    const MAX_RETRIES = 3;

    /**
     * Constructor.
     *
     * @param Affilync_Security_Encryption   $encryption   Encryption handler.
     * @param Affilync_Security_Rate_Limiter $rate_limiter Rate limiter.
     */
    public function __construct( $encryption, $rate_limiter ) {
        $this->encryption   = $encryption;
        $this->rate_limiter = $rate_limiter;
        $this->api_url      = defined( 'AFFILYNC_API_URL' ) ? AFFILYNC_API_URL : 'https://api.affilync.com';
    }

    /**
     * Make a GET request.
     *
     * @param string $endpoint API endpoint.
     * @param array  $params   Query parameters.
     * @return array|WP_Error Response data or error.
     */
    public function get( $endpoint, $params = array() ) {
        return $this->request( 'GET', $endpoint, array( 'query' => $params ) );
    }

    /**
     * Make a POST request.
     *
     * @param string $endpoint API endpoint.
     * @param array  $data     Request body.
     * @return array|WP_Error Response data or error.
     */
    public function post( $endpoint, $data = array() ) {
        return $this->request( 'POST', $endpoint, array( 'body' => $data ) );
    }

    /**
     * Make a PUT request.
     *
     * @param string $endpoint API endpoint.
     * @param array  $data     Request body.
     * @return array|WP_Error Response data or error.
     */
    public function put( $endpoint, $data = array() ) {
        return $this->request( 'PUT', $endpoint, array( 'body' => $data ) );
    }

    /**
     * Make a DELETE request.
     *
     * @param string $endpoint API endpoint.
     * @return array|WP_Error Response data or error.
     */
    public function delete( $endpoint ) {
        return $this->request( 'DELETE', $endpoint );
    }

    /**
     * Make a PATCH request.
     *
     * @param string $endpoint API endpoint.
     * @param array  $data     Request body.
     * @return array|WP_Error Response data or error.
     */
    public function patch( $endpoint, $data = array() ) {
        return $this->request( 'PATCH', $endpoint, array( 'body' => $data ) );
    }

    /**
     * Make an API request.
     *
     * @param string $method  HTTP method.
     * @param string $endpoint API endpoint.
     * @param array  $options Request options.
     * @return array|WP_Error Response data or error.
     */
    private function request( $method, $endpoint, $options = array() ) {
        // Check rate limit.
        $rate_check = $this->rate_limiter->check( 'api_call' );
        if ( ! $rate_check['allowed'] ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many requests. Please try again later.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        // Get credentials.
        $credentials = $this->encryption->get_credentials();
        if ( ! $credentials || empty( $credentials['access_token'] ) ) {
            return new WP_Error(
                'not_connected',
                __( 'Not connected to Affilync. Please connect your account.', 'affilync-woocommerce' ),
                array( 'status' => 401 )
            );
        }

        // Check if token needs refresh.
        if ( $this->token_needs_refresh( $credentials ) ) {
            $refresh_result = $this->refresh_token( $credentials );
            if ( is_wp_error( $refresh_result ) ) {
                return $refresh_result;
            }
            $credentials = $refresh_result;
        }

        // Build URL.
        $url = $this->api_url . '/' . ltrim( $endpoint, '/' );

        // Add query params.
        if ( ! empty( $options['query'] ) ) {
            $url = add_query_arg( $options['query'], $url );
        }

        // Build request args.
        $args = array(
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => array(
                'Authorization' => 'Bearer ' . $credentials['access_token'],
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'Affilync-WooCommerce/' . AFFILYNC_VERSION,
            ),
        );

        // Add body for POST/PUT/PATCH.
        if ( ! empty( $options['body'] ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $args['body'] = wp_json_encode( $options['body'] );
        }

        // Make request with retry logic.
        $response = $this->request_with_retry( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Parse response.
        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        // Handle errors.
        if ( $status_code >= 400 ) {
            $error_message = isset( $data['message'] ) ? $data['message'] : __( 'API request failed', 'affilync-woocommerce' );

            // Handle token expiration.
            if ( $status_code === 401 ) {
                // Clear credentials and require reconnection.
                $this->encryption->clear_credentials();
                update_option( 'affilync_connection_status', 'disconnected' );
            }

            return new WP_Error(
                'api_error',
                $error_message,
                array(
                    'status'      => $status_code,
                    'response'    => $data,
                )
            );
        }

        return $data;
    }

    /**
     * Make request with retry logic.
     *
     * @param string $url  Request URL.
     * @param array  $args Request arguments.
     * @return array|WP_Error Response or error.
     */
    private function request_with_retry( $url, $args ) {
        $attempts = 0;
        $last_error = null;

        while ( $attempts < self::MAX_RETRIES ) {
            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                $attempts++;

                // Wait before retry (exponential backoff).
                if ( $attempts < self::MAX_RETRIES ) {
                    sleep( pow( 2, $attempts ) );
                }
                continue;
            }

            $status_code = wp_remote_retrieve_response_code( $response );

            // Don't retry client errors (4xx), only server errors (5xx).
            if ( $status_code >= 500 && $attempts < self::MAX_RETRIES - 1 ) {
                $attempts++;
                sleep( pow( 2, $attempts ) );
                continue;
            }

            return $response;
        }

        return $last_error ?: new WP_Error(
            'request_failed',
            __( 'Failed to connect to Affilync API after multiple attempts.', 'affilync-woocommerce' )
        );
    }

    /**
     * Check if token needs refresh.
     *
     * @param array $credentials Current credentials.
     * @return bool True if token should be refreshed.
     */
    private function token_needs_refresh( $credentials ) {
        if ( empty( $credentials['expires_at'] ) ) {
            return false;
        }

        // Refresh if token expires in less than 5 minutes.
        $expires_at = strtotime( $credentials['expires_at'] );
        return $expires_at && ( $expires_at - time() ) < 300;
    }

    /**
     * Refresh the access token.
     *
     * @param array $credentials Current credentials.
     * @return array|WP_Error New credentials or error.
     */
    private function refresh_token( $credentials ) {
        if ( empty( $credentials['refresh_token'] ) ) {
            return new WP_Error(
                'no_refresh_token',
                __( 'No refresh token available. Please reconnect.', 'affilync-woocommerce' )
            );
        }

        $url = $this->api_url . '/oauth/token';

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => self::TIMEOUT,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'grant_type'    => 'refresh_token',
                        'refresh_token' => $credentials['refresh_token'],
                        'client_id'     => $this->get_client_id(),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code !== 200 || empty( $data['access_token'] ) ) {
            // Refresh failed - clear credentials.
            $this->encryption->clear_credentials();
            update_option( 'affilync_connection_status', 'disconnected' );

            return new WP_Error(
                'refresh_failed',
                __( 'Failed to refresh token. Please reconnect.', 'affilync-woocommerce' )
            );
        }

        // Update stored credentials.
        $new_credentials = array(
            'access_token'  => $data['access_token'],
            'refresh_token' => isset( $data['refresh_token'] ) ? $data['refresh_token'] : $credentials['refresh_token'],
            'expires_at'    => isset( $data['expires_in'] ) ? gmdate( 'Y-m-d H:i:s', time() + $data['expires_in'] ) : null,
            'brand_id'      => isset( $data['brand_id'] ) ? $data['brand_id'] : $credentials['brand_id'],
        );

        $this->encryption->store_credentials( $new_credentials );

        return $new_credentials;
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
        return get_option( 'affilync_client_id', '' );
    }

    /**
     * Check API health.
     *
     * @return array|WP_Error Health status or error.
     */
    public function health_check() {
        $url = $this->api_url . '/health';

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        return array(
            'healthy'     => $status_code === 200,
            'status_code' => $status_code,
            'api_url'     => $this->api_url,
        );
    }

    /**
     * Test connection with current credentials.
     *
     * @return array|WP_Error Connection status or error.
     */
    public function test_connection() {
        $result = $this->get( '/api/brand/profile' );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'connected' => true,
            'brand'     => $result,
        );
    }

    /**
     * Get brand profile.
     *
     * @return array|WP_Error Brand data or error.
     */
    public function get_brand_profile() {
        return $this->get( '/api/brand/profile' );
    }

    /**
     * Track a conversion.
     *
     * @param array $conversion Conversion data.
     * @return array|WP_Error Result or error.
     */
    public function track_conversion( $conversion ) {
        return $this->post( '/api/conversions/track', $conversion );
    }

    /**
     * Sync a product.
     *
     * @param array $product Product data.
     * @return array|WP_Error Result or error.
     */
    public function sync_product( $product ) {
        return $this->post( '/api/products/sync', $product );
    }

    /**
     * Delete a synced product.
     *
     * @param string $product_id Affilync product ID.
     * @return array|WP_Error Result or error.
     */
    public function delete_product( $product_id ) {
        return $this->delete( '/api/products/' . $product_id );
    }

    /**
     * Get webhook secret.
     *
     * @return array|WP_Error Webhook configuration or error.
     */
    public function get_webhook_config() {
        return $this->get( '/api/webhooks/config' );
    }

    /**
     * Register webhooks with Affilync.
     *
     * @param string $callback_url Webhook callback URL.
     * @return array|WP_Error Result or error.
     */
    public function register_webhooks( $callback_url ) {
        return $this->post(
            '/api/webhooks/register',
            array(
                'callback_url' => $callback_url,
                'events'       => array(
                    'conversion.approved',
                    'conversion.rejected',
                    'payout.processed',
                    'campaign.updated',
                ),
            )
        );
    }

    /**
     * Check if connected to Affilync.
     *
     * @return bool True if connected.
     */
    public function is_connected() {
        return $this->encryption->has_credentials();
    }

    /**
     * Get the API URL.
     *
     * @return string API URL.
     */
    public function get_api_url() {
        return $this->api_url;
    }
}
