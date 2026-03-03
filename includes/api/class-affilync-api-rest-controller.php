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

        // Conversion tracking endpoint (public with HMAC or API key verification).
        register_rest_route(
            self::NAMESPACE,
            '/conversions/track',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'track_conversion' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'order_id'     => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param ) && intval( $param ) > 0;
                        },
                        'sanitize_callback' => 'absint',
                    ),
                    'affiliate_id' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function( $param ) {
                            return ! empty( $param ) && is_string( $param );
                        },
                    ),
                    'amount'       => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param ) && floatval( $param ) >= 0;
                        },
                        'sanitize_callback' => function( $param ) {
                            return round( floatval( $param ), 4 );
                        },
                    ),
                    'currency'     => array(
                        'required'          => false,
                        'default'           => 'USD',
                        'sanitize_callback' => function( $param ) {
                            return strtoupper( sanitize_text_field( $param ) );
                        },
                        'validate_callback' => function( $param ) {
                            return is_string( $param ) && preg_match( '/^[A-Z]{3}$/i', $param );
                        },
                    ),
                    'click_id'     => array(
                        'required'          => false,
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
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
        $limit  = max( 1, min( intval( $request->get_param( 'limit' ) ), 100 ) );
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
     * Track a conversion via REST API.
     *
     * Public endpoint that accepts conversion data from external systems
     * (e.g., the Affilync platform or third-party integrations). Applies
     * rate limiting via the 'conversion_track' config, validates the order
     * exists and belongs to this store, checks for duplicate tracking, and
     * records the conversion in the affilync_conversions table.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object with body params:
     *     order_id (int), affiliate_id (string), amount (float),
     *     currency (string, optional), click_id (string, optional).
     * @return WP_REST_Response|WP_Error 201 with conversion_id on success,
     *     429 if rate-limited, 400/404/409 on validation failures.
     */
    public function track_conversion( $request ) {
        // Apply rate limiting using the 'conversion_track' configuration.
        $rate_check = $this->rate_limiter->check( 'conversion_track' );
        if ( ! $rate_check['allowed'] ) {
            $this->audit_logger->warning(
                Affilync_Security_Audit_Logger::EVENT_RATE_LIMITED,
                array( 'action' => 'conversion_track' )
            );

            return new WP_Error(
                'rate_limited',
                __( 'Too many conversion tracking requests. Please try again later.', 'affilync-woocommerce' ),
                array( 'status' => 429 )
            );
        }

        // Verify HMAC signature (required for all conversion tracking requests).
        $signature = $request->get_header( 'X-Affilync-Signature' );
        if ( ! $signature ) {
            $this->audit_logger->warning(
                Affilync_Security_Audit_Logger::EVENT_WEBHOOK_INVALID,
                array( 'action' => 'conversion_track', 'reason' => 'missing_signature' )
            );

            return new WP_Error(
                'missing_signature',
                __( 'X-Affilync-Signature header is required.', 'affilync-woocommerce' ),
                array( 'status' => 403 )
            );
        }

        $body_raw = $request->get_body();
        if ( ! $this->hmac_validator->verify( $body_raw, $signature ) ) {
            $this->audit_logger->warning(
                Affilync_Security_Audit_Logger::EVENT_WEBHOOK_INVALID,
                array( 'action' => 'conversion_track', 'reason' => 'invalid_hmac' )
            );

            return new WP_Error(
                'invalid_signature',
                __( 'Invalid request signature.', 'affilync-woocommerce' ),
                array( 'status' => 403 )
            );
        }

        // Extract validated and sanitized parameters.
        $order_id     = $request->get_param( 'order_id' );
        $affiliate_id = $request->get_param( 'affiliate_id' );
        $amount       = $request->get_param( 'amount' );
        $currency     = $request->get_param( 'currency' );
        $click_id     = $request->get_param( 'click_id' );

        // Validate the order exists in WooCommerce.
        if ( ! function_exists( 'wc_get_order' ) ) {
            return new WP_Error(
                'woocommerce_unavailable',
                __( 'WooCommerce is not available.', 'affilync-woocommerce' ),
                array( 'status' => 503 )
            );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error(
                'order_not_found',
                /* translators: %d: Order ID */
                sprintf( __( 'Order #%d was not found.', 'affilync-woocommerce' ), $order_id ),
                array( 'status' => 404 )
            );
        }

        // Verify the order belongs to this store by checking it has a valid status.
        $valid_statuses = wc_get_order_statuses();
        $order_status   = 'wc-' . $order->get_status();
        if ( ! isset( $valid_statuses[ $order_status ] ) ) {
            return new WP_Error(
                'invalid_order',
                __( 'The order has an unrecognized status.', 'affilync-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        // Check for duplicate tracking (same order_id already has a conversion).
        global $wpdb;
        $table = $wpdb->prefix . 'affilync_conversions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE order_id = %d LIMIT 1",
                $order_id
            )
        );

        if ( $existing ) {
            return new WP_Error(
                'duplicate_conversion',
                /* translators: %d: Order ID */
                sprintf( __( 'A conversion for order #%d has already been tracked.', 'affilync-woocommerce' ), $order_id ),
                array(
                    'status'        => 409,
                    'conversion_id' => intval( $existing ),
                )
            );
        }

        // Calculate commission using the order subtotal and a default rate.
        $default_commission_rate = 0.10; // 10%.

        /**
         * Filter the commission rate for REST-tracked conversions.
         *
         * @since 1.0.0
         *
         * @param float    $rate         Default commission rate (0.10 = 10%).
         * @param WC_Order $order        The WooCommerce order.
         * @param string   $affiliate_id The affiliate ID.
         */
        $commission_rate = apply_filters(
            'affilync_conversion_track_commission_rate',
            $default_commission_rate,
            $order,
            $affiliate_id
        );

        $commission_amount = round( floatval( $order->get_subtotal() ) * floatval( $commission_rate ), 4 );

        // Insert the conversion record.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert(
            $table,
            array(
                'order_id'          => $order_id,
                'affiliate_id'      => $affiliate_id,
                'campaign_id'       => null,
                'link_id'           => null,
                'click_id'          => ! empty( $click_id ) ? $click_id : null,
                'order_total'       => $amount,
                'commission_amount' => $commission_amount,
                'currency'          => $currency,
                'status'            => 'pending',
                'attribution_data'  => wp_json_encode(
                    array(
                        'source'       => 'rest_api',
                        'affiliate_id' => $affiliate_id,
                        'click_id'     => $click_id,
                        'tracked_at'   => current_time( 'mysql', true ),
                    )
                ),
                'synced_to_api'     => 0,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d' )
        );

        if ( ! $inserted ) {
            $this->audit_logger->error(
                Affilync_Security_Audit_Logger::EVENT_CONVERSION_FAILED,
                array(
                    'order_id'     => $order_id,
                    'affiliate_id' => $affiliate_id,
                    'reason'       => 'database_insert_failed',
                    'db_error'     => $wpdb->last_error,
                )
            );

            return new WP_Error(
                'conversion_failed',
                __( 'Failed to record the conversion. Please try again.', 'affilync-woocommerce' ),
                array( 'status' => 500 )
            );
        }

        $conversion_id = $wpdb->insert_id;

        // Store tracking metadata on the order.
        $order->update_meta_data( '_affilync_conversion_id', $conversion_id );
        $order->update_meta_data( '_affilync_tracked', 'yes' );
        $order->update_meta_data(
            '_affilync_attribution',
            array(
                'affiliate_id' => $affiliate_id,
                'click_id'     => $click_id,
                'source'       => 'rest_api',
            )
        );
        $order->save();

        // Add an order note for visibility.
        $order->add_order_note(
            sprintf(
                /* translators: %s: Affiliate ID */
                __( 'Affilync: Conversion tracked via REST API for affiliate %s', 'affilync-woocommerce' ),
                $affiliate_id
            )
        );

        // Log the successful conversion.
        $this->audit_logger->info(
            Affilync_Security_Audit_Logger::EVENT_CONVERSION_TRACKED,
            array(
                'conversion_id' => $conversion_id,
                'order_id'      => $order_id,
                'affiliate_id'  => $affiliate_id,
                'amount'        => $amount,
                'currency'      => $currency,
                'click_id'      => $click_id,
                'source'        => 'rest_api',
            )
        );

        $response = rest_ensure_response(
            array(
                'success'       => true,
                'conversion_id' => $conversion_id,
                'order_id'      => $order_id,
                'affiliate_id'  => $affiliate_id,
                'amount'        => $amount,
                'currency'      => $currency,
                'commission'    => $commission_amount,
                'status'        => 'pending',
            )
        );

        $response->set_status( 201 );
        $response->header( 'X-RateLimit-Remaining', $rate_check['remaining'] );
        $response->header( 'X-RateLimit-Limit', $rate_check['limit'] );

        return $response;
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
