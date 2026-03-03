<?php
/**
 * Tests for Affilync_API_REST_Controller
 *
 * Covers route registration, permission checks, health check, settings CRUD,
 * rate limiting guards, and conversion tracking validation.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * REST Controller test case.
 */
class Test_REST_Controller extends TestCase {

    /**
     * @var Affilync_API_REST_Controller
     */
    private $controller;

    /**
     * Mocks.
     */
    private $nonce_manager;
    private $rate_limiter;
    private $hmac_validator;
    private $api_client;
    private $audit_logger;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options']              = array();
        $GLOBALS['affilync_registered_routes'] = array();
        $GLOBALS['affilync_test_user_cap']  = true;

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-nonce-manager.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-rate-limiter.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-hmac-validator.php';
        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-api-client.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-audit-logger.php';
        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-api-rest-controller.php';

        $this->nonce_manager  = $this->createMock( Affilync_Security_Nonce_Manager::class );
        $this->rate_limiter   = $this->createMock( Affilync_Security_Rate_Limiter::class );
        $this->hmac_validator = $this->createMock( Affilync_Security_HMAC_Validator::class );
        $this->api_client     = $this->createMock( Affilync_API_Client::class );
        $this->audit_logger   = $this->createMock( Affilync_Security_Audit_Logger::class );

        $this->audit_logger->method( 'info' )->willReturn( true );
        $this->audit_logger->method( 'warning' )->willReturn( true );
        $this->audit_logger->method( 'error' )->willReturn( true );

        // Default: rate limiter allows requests.
        $this->rate_limiter->method( 'check' )->willReturn( array(
            'allowed'   => true,
            'remaining' => 99,
            'limit'     => 100,
        ) );

        $this->controller = new Affilync_API_REST_Controller(
            $this->nonce_manager,
            $this->rate_limiter,
            $this->hmac_validator,
            $this->api_client,
            $this->audit_logger
        );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options']              = array();
        $GLOBALS['affilync_registered_routes'] = array();
        unset( $GLOBALS['affilync_test_user_cap'] );
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_namespace_constant() {
        $this->assertSame( 'affilync/v1', Affilync_API_REST_Controller::NAMESPACE );
    }

    // ------------------------------------------------------------------
    // register_routes
    // ------------------------------------------------------------------

    public function test_register_routes_registers_expected_routes() {
        $this->controller->register_routes();

        $routes = $GLOBALS['affilync_registered_routes'];
        $this->assertNotEmpty( $routes );

        $route_paths = array_column( $routes, 'route' );
        $this->assertContains( '/oauth/initiate', $route_paths );
        $this->assertContains( '/oauth/disconnect', $route_paths );
        $this->assertContains( '/webhooks/receive', $route_paths );
        $this->assertContains( '/conversions', $route_paths );
        $this->assertContains( '/conversions/sync', $route_paths );
        $this->assertContains( '/products/sync-status', $route_paths );
        $this->assertContains( '/products/sync', $route_paths );
        $this->assertContains( '/settings', $route_paths );
        $this->assertContains( '/conversions/track', $route_paths );
        $this->assertContains( '/health', $route_paths );
        $this->assertContains( '/status', $route_paths );
    }

    // ------------------------------------------------------------------
    // check_admin_permission
    // ------------------------------------------------------------------

    public function test_check_admin_permission_returns_true_for_admin() {
        $GLOBALS['affilync_test_user_cap'] = true;
        $this->assertTrue( $this->controller->check_admin_permission() );
    }

    public function test_check_admin_permission_returns_error_for_non_admin() {
        $GLOBALS['affilync_test_user_cap'] = false;
        $result = $this->controller->check_admin_permission();
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rest_forbidden', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // health_check
    // ------------------------------------------------------------------

    public function test_health_check_returns_ok() {
        $request  = new WP_REST_Request( 'GET', '/affilync/v1/health' );
        $response = $this->controller->health_check( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertSame( 'ok', $data['status'] );
        $this->assertArrayHasKey( 'version', $data );
        $this->assertArrayHasKey( 'timestamp', $data );
    }

    // ------------------------------------------------------------------
    // get_settings
    // ------------------------------------------------------------------

    public function test_get_settings_returns_defaults() {
        $request  = new WP_REST_Request( 'GET', '/affilync/v1/settings' );
        $response = $this->controller->get_settings( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();

        $this->assertTrue( $data['tracking_enabled'] );
        $this->assertSame( 30, $data['cookie_duration'] );
        $this->assertSame( 'last_click', $data['attribution_model'] );
        $this->assertTrue( $data['product_sync_enabled'] );
    }

    public function test_get_settings_rate_limited() {
        // Override rate limiter to deny.
        $rate_limiter = $this->createMock( Affilync_Security_Rate_Limiter::class );
        $rate_limiter->method( 'check' )->willReturn( array( 'allowed' => false ) );

        $controller = new Affilync_API_REST_Controller(
            $this->nonce_manager,
            $rate_limiter,
            $this->hmac_validator,
            $this->api_client,
            $this->audit_logger
        );

        $request = new WP_REST_Request( 'GET', '/affilync/v1/settings' );
        $result  = $controller->get_settings( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // update_settings
    // ------------------------------------------------------------------

    public function test_update_settings_sanitizes_and_stores() {
        $request = new WP_REST_Request( 'POST', '/affilync/v1/settings' );
        $request->set_json_params( array(
            'tracking_enabled'     => true,
            'cookie_duration'      => 60,
            'attribution_model'    => 'first_click',
            'product_sync_enabled' => false,
        ) );

        $response = $this->controller->update_settings( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertSame( 60, $data['settings']['cookie_duration'] );
        $this->assertSame( 'first_click', $data['settings']['attribution_model'] );
    }

    public function test_update_settings_caps_cookie_duration() {
        $request = new WP_REST_Request( 'POST', '/affilync/v1/settings' );
        $request->set_json_params( array( 'cookie_duration' => 999 ) );

        $response = $this->controller->update_settings( $request );
        $data = $response->get_data();

        // Should be capped at 365.
        $this->assertSame( 365, $data['settings']['cookie_duration'] );
    }

    // ------------------------------------------------------------------
    // get_status
    // ------------------------------------------------------------------

    public function test_get_status_returns_connection_info() {
        $this->api_client->method( 'is_connected' )->willReturn( false );

        $request  = new WP_REST_Request( 'GET', '/affilync/v1/status' );
        $response = $this->controller->get_status( $request );

        $data = $response->get_data();
        $this->assertFalse( $data['connected'] );
        $this->assertFalse( $data['api_healthy'] );
        $this->assertArrayHasKey( 'version', $data );
        $this->assertArrayHasKey( 'php_version', $data );
    }

    // ------------------------------------------------------------------
    // track_conversion — validation
    // ------------------------------------------------------------------

    public function test_track_conversion_requires_signature() {
        $request = new WP_REST_Request( 'POST', '/affilync/v1/conversions/track' );
        // No signature header set.

        $result = $this->controller->track_conversion( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'missing_signature', $result->get_error_code() );
    }

    public function test_track_conversion_rejects_invalid_hmac() {
        $request = new WP_REST_Request( 'POST', '/affilync/v1/conversions/track' );
        $request->set_header( 'X-Affilync-Signature', 'bad_sig' );
        $request->set_body( '{"order_id":1}' );

        $this->hmac_validator->method( 'verify' )->willReturn( false );

        $result = $this->controller->track_conversion( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_signature', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // oauth_initiate — rate limiting
    // ------------------------------------------------------------------

    public function test_oauth_initiate_rate_limited() {
        $rate_limiter = $this->createMock( Affilync_Security_Rate_Limiter::class );
        $rate_limiter->method( 'check' )->willReturn( array( 'allowed' => false ) );

        $controller = new Affilync_API_REST_Controller(
            $this->nonce_manager,
            $rate_limiter,
            $this->hmac_validator,
            $this->api_client,
            $this->audit_logger
        );

        $request = new WP_REST_Request( 'POST', '/affilync/v1/oauth/initiate' );
        $result  = $controller->oauth_initiate( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // get_conversions — rate limiting
    // ------------------------------------------------------------------

    public function test_get_conversions_rate_limited() {
        $rate_limiter = $this->createMock( Affilync_Security_Rate_Limiter::class );
        $rate_limiter->method( 'check' )->willReturn( array( 'allowed' => false ) );

        $controller = new Affilync_API_REST_Controller(
            $this->nonce_manager,
            $rate_limiter,
            $this->hmac_validator,
            $this->api_client,
            $this->audit_logger
        );

        $request = new WP_REST_Request( 'GET', '/affilync/v1/conversions' );
        $result  = $controller->get_conversions( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
    }
}
