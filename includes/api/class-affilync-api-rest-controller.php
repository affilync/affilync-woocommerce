<?php
/**
 * REST API Controller for Affilync endpoints.
 *
 * Registers and handles all REST API endpoints.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync REST API Controller.
 *
 * @since 1.0.0
 */
class Affilync_API_REST_Controller {

    /**
     * Namespace for REST routes.
     *
     * @var string
     */
    const NAMESPACE = 'affilync/v1';

    /**
     * Nonce manager.
     *
     * @var Affilync_Security_Nonce_Manager
     */
    private $nonce_manager;

    /**
     * Rate limiter.
     *
     * @var Affilync_Security_Rate_Limiter
     */
    private $rate_limiter;

    /**
     * HMAC validator.
     *
     * @var Affilync_Security_HMAC_Validator
     */
    private $hmac_validator;

    /**
     * API client.
     *
     * @var Affilync_API_Client
     */
    private $api_client;

    /**
     * Audit logger.
     *
     * @var Affilync_Security_Audit_Logger
     */
    private $audit_logger;

    /**
     * Constructor.
     *
     * @param Affilync_Security_Nonce_Manager  $nonce_manager  Nonce manager.
     * @param Affilync_Security_Rate_Limiter   $rate_limiter   Rate limiter.
     * @param Affilync_Security_HMAC_Validator $hmac_validator HMAC validator.
     * @param Affilync_API_Client              $api_client     API client.
     * @param Affilync_Security_Audit_Logger   $audit_logger   Audit logger.
     */
    public function __construct(
        $nonce_manager,
        $rate_limiter,
        $hmac_validator,
        $api_client,
        $audit_logger
    ) {
        $this->nonce_manager  = $nonce_manager;
        $this->rate_limiter   = $rate_limiter;
        $this->hmac_validator = $hmac_validator;
        $this->api_client     = $api_client;
        $this->audit_logger   = $audit_logger;
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        // OAuth routes.
        register_rest_route(
            self::NAMESPACE,
            '/oauth/initiate',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'oauth_initiate' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/oauth/disconnect',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'oauth_disconnect' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );

        // Webhook endpoint (public with HMAC verification).
        register_rest_route(
            self::NAMESPACE,
            '/webhooks/receive',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'receive_webhook' ),
                'permission_callback' => '__return_true',
            )
        );

        // Conversion routes.
        register_rest_route(
            self::NAMESPACE,
            '/conversions',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_conversions' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
                'args'                => array(
                    'page'   => array(
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ),
                    'limit'  => array(
                        'default'           => 20,
                        'sanitize_callback' => 'absint',
                    ),
                    'status' => array(
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/conversions/sync',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'sync_conversions' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );

        // Product sync routes.
        register_rest_route(
            self::NAMESPACE,
            '/products/sync-status',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_product_sync_status' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/products/sync',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'trigger_product_sync' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );

        // Settings routes.
        register_rest_route(
            self::NAMESPACE,
            '/settings',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_settings' ),
                    'permission_callback' => array( $this, 'check_admin_permission' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'update_settings' ),
                    'permission_callback' => array( $this, 'check_admin_permission' ),
                ),
            )
        );

        // Health check (public).
        register_rest_route(
            self::NAMESPACE,
            '/health',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'health_check' ),
                'permission_callback' => '__return_true',
            )
        );

        // Status route.
        register_rest_route(
            self::NAMESPACE,
            '/status',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_status' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );
    }

    /**
     * Check admin permission.
     *
     * @return bool|WP_Error True if allowed.
     */
    public function check_admin_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to perform this action.', 'affilync-woocommerce' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Initiate OAuth flow.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function oauth_initiate( $request ) {
        // Check rate limit.
        $rate_check = $this->rate_limiter->check( 'oauth_attempt' );
        if ( ! $rate_check['allowed'] ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many attempts. Please try again later.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        $oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            affilync()->encryption,
            $this->api_client
        );

        $auth_url = $oauth->get_authorization_url();

        if ( is_wp_error( $auth_url ) ) {
            return $auth_url;
        }

        $this->audit_logger->info(
            Affilync_Security_Audit_Logger::EVENT_OAUTH_INITIATED
        );

        return rest_ensure_response(
            array(
                'success'  => true,
                'auth_url' => $auth_url,
            )
        );
    }

    /**
     * Disconnect OAuth.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function oauth_disconnect( $request ) {
        // Check rate limit.
        $rate_check = $this->rate_limiter->check( 'oauth_disconnect' );
        if ( ! $rate_check['allowed'] ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many disconnect attempts. Please try again later.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        $oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            affilync()->encryption,
            $this->api_client
        );

        $oauth->disconnect();

        return rest_ensure_response(
            array(
                'success' => true,
                'message' => __( 'Disconnected from Affilync.', 'affilync-woocommerce' ),
            )
        );
    }

    /**
     * Receive webhook.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function receive_webhook( $request ) {
        // Check rate limit.
        $rate_check = $this->rate_limiter->check( 'webhook_receive' );
        if ( ! $rate_check['allowed'] ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many requests.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        $webhook_handler = new Affilync_API_Webhook_Handler(
            $this->hmac_validator,
            $this->audit_logger
        );

        return $webhook_handler->handle_webhook( $request );
    }

    /**
     * Get conversions.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function get_conversions( $request ) {
        // Check rate limit.
        $rate_check = $this->rate_limiter->check( 'conversions_read' );
        if ( ! $rate_check['allowed'] ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many requests. Please try again later.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        global $wpdb;

        $page   = $request->get_param( 'page' );
        $limit  = min( $request->get_param( 'limit' ), 100 );
        $status = $request->get_param( 'status' );
        $offset = ( $page - 1 ) * $limit;

        $table = $wpdb->prefix . 'affilync_conversions';

        $where = '1=1';
        $values = array();

        if ( ! empty( $status ) ) {
            $where .= ' AND status = %s';
            $values[] = $status;
        }

        $values[] = $limit;
        $values[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $conversions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $values
            )
        );

        // Get total count.
        $count_values = array_slice( $values, 0, -2 );
        if ( ! empty( $count_values ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE {$where}",
                    $count_values
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        return rest_ensure_response(
            array(
                'conversions' => $conversions,
                'total'       => intval( $total ),
                'page'        => $page,
                'limit'       => $limit,
                'pages'       => ceil( $total / $limit ),
            )
        );
    }

    /**
     * Sync unsynced conversions.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function sync_conversions( $request ) {
        // Check rate limit (stricter - this is resource intensive).
        $rate_check = $this->rate_limiter->check( 'conversions_sync' );
        if ( ! $rate_check['allowed'] ) {
            return new WP_Error(
                'rate_limited',
                __( 'Sync in progress or too many recent attempts. Please try again later.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        $tracker = affilync()->conversion_tracker;
        $result = $tracker->sync_pending_conversions();

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'synced'  => $result['synced'],
                'failed'  => $result['failed'],
            )
        );
    }

    /**
     * Get product sync status.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_product_sync_status( $request ) {
        global $wpdb;

        $table = $wpdb->prefix . 'affilync_product_sync';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
                SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed,
                MAX(last_synced_at) as last_sync
            FROM {$table}"
        );

        return rest_ensure_response(
            array(
                'total'     => intval( $stats->total ),
                'synced'    => intval( $stats->synced ),
                'pending'   => intval( $stats->pending ),
                'failed'    => intval( $stats->failed ),
                'last_sync' => $stats->last_sync,
            )
        );
    }

    /**
     * Trigger product sync.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function trigger_product_sync( $request ) {
        // Check rate limit.
        $rate_check = $this->rate_limiter->check( 'product_sync' );
        if ( ! $rate_check['allowed'] ) {
            return new WP_Error(
                'rate_limited',
                __( 'Please wait before syncing again.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        $product_sync = affilync()->product_sync;

        $full_sync = $request->get_param( 'full' ) === true;

        if ( $full_sync ) {
            $result = $product_sync->full_sync();
        } else {
            $result = $product_sync->sync_pending();
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'synced'  => $result['synced'],
                'failed'  => $result['failed'],
                'message' => sprintf(
                    /* translators: 1: Synced count, 2: Failed count */
                    __( 'Synced %1$d products, %2$d failed.', 'affilync-woocommerce' ),
                    $result['synced'],
                    $result['failed']
                ),
            )
        );
    }

    /**
     * Get settings.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function get_settings( $request ) {
        // Check rate limit.
        $rate_check = $this->rate_limiter->check( 'settings_read' );
        if ( ! $rate_check['allowed'] ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many requests. Please try again later.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        $settings = get_option( 'affilync_settings', array() );

        $defaults = array(
            'tracking_enabled'      => true,
            'cookie_duration'       => 30,
            'conversion_statuses'   => array( 'completed', 'processing' ),
            'product_sync_enabled'  => true,
            'product_sync_interval' => 'hourly',
            'attribution_model'     => 'last_click',
            'track_url_params'      => array( 'ref', 'aff', 'campaign' ),
        );

        $settings = wp_parse_args( $settings, $defaults );

        return rest_ensure_response( $settings );
    }

    /**
     * Update settings.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function update_settings( $request ) {
        // Check rate limit (stricter for writes).
        $rate_check = $this->rate_limiter->check( 'settings_write' );
        if ( ! $rate_check['allowed'] ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many settings updates. Please try again later.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        $settings = $request->get_json_params();

        // Sanitize settings.
        $sanitized = array();

        if ( isset( $settings['tracking_enabled'] ) ) {
            $sanitized['tracking_enabled'] = (bool) $settings['tracking_enabled'];
        }

        if ( isset( $settings['cookie_duration'] ) ) {
            $sanitized['cookie_duration'] = min( absint( $settings['cookie_duration'] ), 365 );
        }

        if ( isset( $settings['conversion_statuses'] ) && is_array( $settings['conversion_statuses'] ) ) {
            $sanitized['conversion_statuses'] = array_map( 'sanitize_text_field', $settings['conversion_statuses'] );
        }

        if ( isset( $settings['product_sync_enabled'] ) ) {
            $sanitized['product_sync_enabled'] = (bool) $settings['product_sync_enabled'];
        }

        if ( isset( $settings['product_sync_interval'] ) ) {
            $sanitized['product_sync_interval'] = sanitize_text_field( $settings['product_sync_interval'] );
        }

        if ( isset( $settings['attribution_model'] ) ) {
            $sanitized['attribution_model'] = sanitize_text_field( $settings['attribution_model'] );
        }

        if ( isset( $settings['track_url_params'] ) && is_array( $settings['track_url_params'] ) ) {
            $sanitized['track_url_params'] = array_map( 'sanitize_key', $settings['track_url_params'] );
        }

        // Merge with existing.
        $existing = get_option( 'affilync_settings', array() );
        $updated = array_merge( $existing, $sanitized );

        update_option( 'affilync_settings', $updated );

        $this->audit_logger->info(
            Affilync_Security_Audit_Logger::EVENT_SETTINGS_CHANGED,
            array( 'changed_keys' => array_keys( $sanitized ) )
        );

        return rest_ensure_response(
            array(
                'success'  => true,
                'settings' => $updated,
            )
        );
    }

    /**
     * Health check endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function health_check( $request ) {
        return rest_ensure_response(
            array(
                'status'    => 'ok',
                'version'   => AFFILYNC_VERSION,
                'timestamp' => current_time( 'c' ),
            )
        );
    }

    /**
     * Get plugin status.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_status( $request ) {
        $connected = $this->api_client->is_connected();

        // Test API connection if connected.
        $api_healthy = false;
        if ( $connected ) {
            $health = $this->api_client->health_check();
            $api_healthy = ! is_wp_error( $health ) && ! empty( $health['healthy'] );
        }

        return rest_ensure_response(
            array(
                'connected'   => $connected,
                'api_healthy' => $api_healthy,
                'brand_id'    => get_option( 'affilync_brand_id', '' ),
                'version'     => AFFILYNC_VERSION,
                'wc_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
                'php_version' => PHP_VERSION,
            )
        );
    }
}
