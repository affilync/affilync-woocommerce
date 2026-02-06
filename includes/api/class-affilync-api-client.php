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
     * Circuit breaker failure threshold before opening.
     *
     * @var int
     */
    const CIRCUIT_BREAKER_THRESHOLD = 5;

    /**
     * Circuit breaker recovery timeout in seconds.
     *
     * @var int
     */
    const CIRCUIT_BREAKER_TIMEOUT = 60;

    /**
     * Maximum queued requests when circuit is open.
     *
     * @var int
     */
    const MAX_QUEUED_REQUESTS = 100;

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
     * ARCH-III-19: Get the current circuit breaker state.
     *
     * States:
     * - 'closed': Normal operation, requests go through.
     * - 'open': API is down, requests are queued locally.
     * - 'half_open': Testing if API has recovered.
     *
     * @return array Circuit state with keys: state, failure_count, last_failure, opened_at.
     */
    private function get_circuit_state() {
        $state = get_transient( 'affilync_circuit_state' );
        if ( ! $state || ! is_array( $state ) ) {
            return array(
                'state'         => 'closed',
                'failure_count' => 0,
                'last_failure'  => 0,
                'opened_at'     => 0,
            );
        }

        // Auto-transition from open to half_open after timeout
        if ( 'open' === $state['state'] ) {
            $elapsed = time() - $state['opened_at'];
            if ( $elapsed >= self::CIRCUIT_BREAKER_TIMEOUT ) {
                $state['state'] = 'half_open';
                set_transient( 'affilync_circuit_state', $state, self::CIRCUIT_BREAKER_TIMEOUT * 3 );
            }
        }

        return $state;
    }

    /**
     * ARCH-III-19: Record a circuit breaker failure.
     *
     * After CIRCUIT_BREAKER_THRESHOLD consecutive failures, the circuit opens
     * and requests are queued locally instead of hitting the API.
     */
    private function record_circuit_failure() {
        $state = $this->get_circuit_state();
        $state['failure_count']++;
        $state['last_failure'] = time();

        if ( $state['failure_count'] >= self::CIRCUIT_BREAKER_THRESHOLD ) {
            $state['state']     = 'open';
            $state['opened_at'] = time();

            // Log the circuit opening event
            if ( function_exists( 'affilync_log_audit_event' ) ) {
                affilync_log_audit_event( 'circuit_breaker_opened', array(
                    'failure_count' => $state['failure_count'],
                    'api_url'       => $this->api_url,
                ) );
            }
        }

        set_transient( 'affilync_circuit_state', $state, self::CIRCUIT_BREAKER_TIMEOUT * 3 );
    }

    /**
     * ARCH-III-19: Record a circuit breaker success (reset to closed).
     */
    private function record_circuit_success() {
        $state = $this->get_circuit_state();

        if ( 'closed' !== $state['state'] ) {
            // Log recovery
            if ( function_exists( 'affilync_log_audit_event' ) ) {
                affilync_log_audit_event( 'circuit_breaker_closed', array(
                    'previous_state'  => $state['state'],
                    'failure_count'   => $state['failure_count'],
                    'recovery_time_s' => time() - $state['opened_at'],
                ) );
            }
        }

        delete_transient( 'affilync_circuit_state' );
    }

    /**
     * ARCH-III-19: Queue a request locally when the circuit is open.
     *
     * Queued requests are stored as a WordPress option and processed
     * when the circuit closes (via the drain_request_queue method).
     *
     * @param string $method   HTTP method.
     * @param string $endpoint API endpoint.
     * @param array  $options  Request options.
     * @return bool True if queued successfully.
     */
    private function queue_request( $method, $endpoint, $options = array() ) {
        $queue = get_option( 'affilync_request_queue', array() );

        if ( count( $queue ) >= self::MAX_QUEUED_REQUESTS ) {
            // Drop oldest request to prevent unbounded queue growth
            array_shift( $queue );
        }

        $queue[] = array(
            'method'    => $method,
            'endpoint'  => $endpoint,
            'options'   => $options,
            'queued_at' => time(),
        );

        update_option( 'affilync_request_queue', $queue, false );
        return true;
    }

    /**
     * ARCH-III-19: Drain the request queue by replaying queued requests.
     *
     * Called after the circuit transitions back to closed. Processes
     * queued requests in FIFO order with a brief delay between each.
     *
     * @return array Summary with 'processed', 'failed', and 'remaining' counts.
     */
    public function drain_request_queue() {
        $queue = get_option( 'affilync_request_queue', array() );
        if ( empty( $queue ) ) {
            return array( 'processed' => 0, 'failed' => 0, 'remaining' => 0 );
        }

        $processed = 0;
        $failed    = 0;
        $remaining = array();

        foreach ( $queue as $item ) {
            // Skip requests older than 1 hour (stale)
            if ( ( time() - $item['queued_at'] ) > 3600 ) {
                continue;
            }

            $result = $this->request( $item['method'], $item['endpoint'], $item['options'] );

            if ( is_wp_error( $result ) ) {
                $failed++;
                // If the API is down again, stop draining and re-queue remaining
                $error_code = $result->get_error_code();
                if ( 'circuit_open' === $error_code || 'request_failed' === $error_code ) {
                    $remaining = array_slice( $queue, $processed + $failed );
                    break;
                }
            } else {
                $processed++;
            }
        }

        // Save any remaining items back to the queue
        update_option( 'affilync_request_queue', $remaining, false );

        return array(
            'processed' => $processed,
            'failed'    => $failed,
            'remaining' => count( $remaining ),
        );
    }

    /**
     * Make an API request.
     *
     * ARCH-III-19: Includes circuit breaker pattern for graceful degradation.
     * When the Affilync API is down, non-critical requests are queued locally
     * and replayed when the API recovers.
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

        // ARCH-III-19: Check circuit breaker state
        $circuit = $this->get_circuit_state();

        if ( 'open' === $circuit['state'] ) {
            // Circuit is open -- queue non-GET requests for later replay
            if ( 'GET' !== $method ) {
                $this->queue_request( $method, $endpoint, $options );
            }

            return new WP_Error(
                'circuit_open',
                __( 'Affilync API is temporarily unavailable. Your request has been queued.', 'affilync-woocommerce' ),
                array(
                    'status'         => 503,
                    'queued'         => ( 'GET' !== $method ),
                    'retry_after'    => max( 0, self::CIRCUIT_BREAKER_TIMEOUT - ( time() - $circuit['opened_at'] ) ),
                )
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
            // ARCH-III-19: Record failure for circuit breaker
            $this->record_circuit_failure();
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

            // ARCH-III-19: 5xx errors count toward circuit breaker
            if ( $status_code >= 500 ) {
                $this->record_circuit_failure();
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

        // ARCH-III-19: Successful response -- reset circuit breaker
        $this->record_circuit_success();

        // If we just recovered, try to drain the request queue
        if ( 'half_open' === $circuit['state'] || 'open' === $circuit['state'] ) {
            // Schedule async queue drain to avoid blocking the current request
            if ( ! wp_next_scheduled( 'affilync_drain_request_queue' ) ) {
                wp_schedule_single_event( time() + 5, 'affilync_drain_request_queue' );
            }
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

    /**
     * ARCH-III-19: Get circuit breaker status for admin display.
     *
     * @return array Circuit breaker status.
     */
    public function get_circuit_status() {
        $circuit = $this->get_circuit_state();
        $queue   = get_option( 'affilync_request_queue', array() );

        return array(
            'state'           => $circuit['state'],
            'failure_count'   => $circuit['failure_count'],
            'last_failure'    => $circuit['last_failure'] > 0
                ? gmdate( 'Y-m-d H:i:s', $circuit['last_failure'] )
                : null,
            'queued_requests' => count( $queue ),
            'recovery_in'     => 'open' === $circuit['state']
                ? max( 0, self::CIRCUIT_BREAKER_TIMEOUT - ( time() - $circuit['opened_at'] ) )
                : 0,
        );
    }

    /**
     * ARCH-III-19: Manually reset the circuit breaker.
     *
     * Use this after confirming the API is back online.
     */
    public function reset_circuit_breaker() {
        delete_transient( 'affilync_circuit_state' );
        $this->drain_request_queue();
    }
}
