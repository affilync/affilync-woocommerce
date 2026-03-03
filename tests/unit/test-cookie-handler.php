<?php
/**
 * Tests for Affilync_Tracking_Cookie_Handler
 *
 * Covers cookie signing/verification, URL parameter capture,
 * attribution models (first_click / last_click), session storage,
 * and convenience accessors.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Cookie Handler test case.
 */
class Test_Cookie_Handler extends TestCase {

    /**
     * @var Affilync_Tracking_Cookie_Handler
     */
    private $handler;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options'] = array();
        $_COOKIE = array();
        $_GET    = array();
        $_SERVER['HTTP_REFERER']    = '';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['HTTP_HOST']       = 'example.com';
        $_SERVER['REQUEST_URI']     = '/shop';

        require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-affilync-tracking-cookie-handler.php';

        $this->handler = new Affilync_Tracking_Cookie_Handler();
    }

    protected function tearDown(): void {
        $_COOKIE = array();
        $_GET    = array();
        $GLOBALS['wp_options'] = array();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Cookie signing / verification
    // ------------------------------------------------------------------

    public function test_sign_and_verify_cookie_round_trip() {
        $method_sign = new ReflectionMethod( Affilync_Tracking_Cookie_Handler::class, 'sign_cookie' );
        $method_sign->setAccessible( true );

        $method_verify = new ReflectionMethod( Affilync_Tracking_Cookie_Handler::class, 'verify_cookie_signature' );
        $method_verify->setAccessible( true );

        $data = base64_encode( wp_json_encode( array( 'affiliate_id' => 'aff_1' ) ) );
        $signature = $method_sign->invoke( $this->handler, $data );

        $this->assertTrue( $method_verify->invoke( $this->handler, $data, $signature ) );
    }

    public function test_tampered_signature_rejected() {
        $method_sign = new ReflectionMethod( Affilync_Tracking_Cookie_Handler::class, 'sign_cookie' );
        $method_sign->setAccessible( true );

        $method_verify = new ReflectionMethod( Affilync_Tracking_Cookie_Handler::class, 'verify_cookie_signature' );
        $method_verify->setAccessible( true );

        $data = base64_encode( wp_json_encode( array( 'affiliate_id' => 'aff_1' ) ) );
        $bad_sig = 'deadbeef0000';

        $this->assertFalse( $method_verify->invoke( $this->handler, $data, $bad_sig ) );
    }

    public function test_signing_key_falls_back_to_auth_key() {
        // AUTH_KEY is defined in our mocks.
        $method_key = new ReflectionMethod( Affilync_Tracking_Cookie_Handler::class, 'get_signing_key' );
        $method_key->setAccessible( true );

        $key = $method_key->invoke( $this->handler );
        $this->assertNotEmpty( $key );
    }

    // ------------------------------------------------------------------
    // get_cookie_data / get_tracking_data
    // ------------------------------------------------------------------

    public function test_get_tracking_data_empty_when_no_cookie() {
        $data = $this->handler->get_tracking_data();
        $this->assertEmpty( $data );
    }

    public function test_get_tracking_data_returns_data_from_valid_cookie() {
        // Manually build a signed cookie.
        $tracking = array( 'affiliate_id' => 'aff_42', 'click_id' => 'click_99' );
        $encoded = base64_encode( wp_json_encode( $tracking ) );

        $method_sign = new ReflectionMethod( Affilync_Tracking_Cookie_Handler::class, 'sign_cookie' );
        $method_sign->setAccessible( true );
        $sig = $method_sign->invoke( $this->handler, $encoded );

        $_COOKIE[ Affilync_Tracking_Cookie_Handler::COOKIE_NAME ] = $encoded . '.' . $sig;

        $data = $this->handler->get_tracking_data();
        $this->assertSame( 'aff_42', $data['affiliate_id'] );
        $this->assertSame( 'click_99', $data['click_id'] );
    }

    public function test_get_tracking_data_returns_empty_for_invalid_cookie() {
        $_COOKIE[ Affilync_Tracking_Cookie_Handler::COOKIE_NAME ] = 'garbage_value';
        $data = $this->handler->get_tracking_data();
        $this->assertEmpty( $data );
    }

    public function test_get_tracking_data_returns_empty_for_bad_signature() {
        $encoded = base64_encode( wp_json_encode( array( 'affiliate_id' => 'aff_1' ) ) );
        $_COOKIE[ Affilync_Tracking_Cookie_Handler::COOKIE_NAME ] = $encoded . '.bad_signature';

        $data = $this->handler->get_tracking_data();
        $this->assertEmpty( $data );
    }

    public function test_get_tracking_data_returns_empty_for_non_array_json() {
        $encoded = base64_encode( '"just a string"' );
        $method_sign = new ReflectionMethod( Affilync_Tracking_Cookie_Handler::class, 'sign_cookie' );
        $method_sign->setAccessible( true );
        $sig = $method_sign->invoke( $this->handler, $encoded );

        $_COOKIE[ Affilync_Tracking_Cookie_Handler::COOKIE_NAME ] = $encoded . '.' . $sig;

        $data = $this->handler->get_tracking_data();
        $this->assertEmpty( $data );
    }

    // ------------------------------------------------------------------
    // Attribution models
    // ------------------------------------------------------------------

    public function test_last_click_attribution_model_default() {
        $tracking = array( 'affiliate_id' => 'last_aff' );
        $this->set_signed_cookie( Affilync_Tracking_Cookie_Handler::COOKIE_NAME, $tracking );

        $data = $this->handler->get_tracking_data();
        $this->assertSame( 'last_aff', $data['affiliate_id'] );
    }

    public function test_first_click_attribution_prefers_first_click_cookie() {
        update_option( 'affilync_settings', array( 'attribution_model' => 'first_click' ) );

        $first = array( 'affiliate_id' => 'first_aff' );
        $last  = array( 'affiliate_id' => 'last_aff' );

        $this->set_signed_cookie( Affilync_Tracking_Cookie_Handler::FIRST_CLICK_COOKIE, $first );
        $this->set_signed_cookie( Affilync_Tracking_Cookie_Handler::COOKIE_NAME, $last );

        $data = $this->handler->get_tracking_data();
        $this->assertSame( 'first_aff', $data['affiliate_id'] );
    }

    public function test_first_click_attribution_falls_back_to_last_click() {
        update_option( 'affilync_settings', array( 'attribution_model' => 'first_click' ) );

        $last = array( 'affiliate_id' => 'last_aff' );
        $this->set_signed_cookie( Affilync_Tracking_Cookie_Handler::COOKIE_NAME, $last );

        // No first-click cookie set.
        $data = $this->handler->get_tracking_data();
        $this->assertSame( 'last_aff', $data['affiliate_id'] );
    }

    // ------------------------------------------------------------------
    // Convenience accessors
    // ------------------------------------------------------------------

    public function test_has_tracking_true_with_affiliate_id() {
        $this->set_signed_cookie(
            Affilync_Tracking_Cookie_Handler::COOKIE_NAME,
            array( 'affiliate_id' => 'aff_1' )
        );
        $this->assertTrue( $this->handler->has_tracking() );
    }

    public function test_has_tracking_true_with_click_id() {
        $this->set_signed_cookie(
            Affilync_Tracking_Cookie_Handler::COOKIE_NAME,
            array( 'click_id' => 'clk_1' )
        );
        $this->assertTrue( $this->handler->has_tracking() );
    }

    public function test_has_tracking_false_without_cookies() {
        $this->assertFalse( $this->handler->has_tracking() );
    }

    public function test_get_affiliate_id() {
        $this->set_signed_cookie(
            Affilync_Tracking_Cookie_Handler::COOKIE_NAME,
            array( 'affiliate_id' => 'aff_99' )
        );
        $this->assertSame( 'aff_99', $this->handler->get_affiliate_id() );
    }

    public function test_get_click_id() {
        $this->set_signed_cookie(
            Affilync_Tracking_Cookie_Handler::COOKIE_NAME,
            array( 'click_id' => 'clk_55' )
        );
        $this->assertSame( 'clk_55', $this->handler->get_click_id() );
    }

    public function test_get_campaign_id() {
        $this->set_signed_cookie(
            Affilync_Tracking_Cookie_Handler::COOKIE_NAME,
            array( 'campaign_id' => 'camp_7' )
        );
        $this->assertSame( 'camp_7', $this->handler->get_campaign_id() );
    }

    public function test_get_affiliate_id_null_when_empty() {
        $this->assertNull( $this->handler->get_affiliate_id() );
    }

    // ------------------------------------------------------------------
    // clear_cookies
    // ------------------------------------------------------------------

    /**
     * @runInSeparateProcess
     */
    public function test_clear_cookies_removes_cookie_globals() {
        $_COOKIE[ Affilync_Tracking_Cookie_Handler::COOKIE_NAME ] = 'something';
        $_COOKIE[ Affilync_Tracking_Cookie_Handler::FIRST_CLICK_COOKIE ] = 'something';

        $this->handler->clear_cookies();

        $this->assertArrayNotHasKey( Affilync_Tracking_Cookie_Handler::COOKIE_NAME, $_COOKIE );
        $this->assertArrayNotHasKey( Affilync_Tracking_Cookie_Handler::FIRST_CLICK_COOKIE, $_COOKIE );
    }

    // ------------------------------------------------------------------
    // capture_tracking_params
    // ------------------------------------------------------------------

    public function test_capture_skips_when_tracking_disabled() {
        update_option( 'affilync_settings', array( 'tracking_enabled' => false ) );

        $_GET['ref'] = 'aff_1';
        $this->handler->capture_tracking_params();

        // No cookie should be set.
        $this->assertArrayNotHasKey(
            Affilync_Tracking_Cookie_Handler::COOKIE_NAME,
            $_COOKIE
        );
    }

    public function test_capture_skips_with_no_tracking_params() {
        $_GET['page'] = 'about'; // Not a tracking param.
        $this->handler->capture_tracking_params();

        $this->assertArrayNotHasKey(
            Affilync_Tracking_Cookie_Handler::COOKIE_NAME,
            $_COOKIE
        );
    }

    /**
     * Note: capture_tracking_params calls set_cookie() which uses native
     * setcookie(). In PHPUnit, headers_sent() returns true, so the cookie
     * is not set in $_COOKIE. We test the param parsing and cookie-setting
     * logic indirectly by verifying the signed cookie round-trip above,
     * and by testing the capture method's guard clauses below.
     *
     * For full integration testing of capture, use @runInSeparateProcess.
     */

    /**
     * @runInSeparateProcess
     */
    public function test_capture_sets_cookie_with_ref_param() {
        update_option( 'affilync_settings', array(
            'tracking_enabled'  => true,
            'track_url_params'  => array( 'ref', 'aff', 'campaign', 'click_id', 'link_id' ),
        ) );

        $_GET['ref'] = 'aff_abc';
        $this->handler->capture_tracking_params();

        $this->assertArrayHasKey( Affilync_Tracking_Cookie_Handler::COOKIE_NAME, $_COOKIE );
    }

    /**
     * @runInSeparateProcess
     */
    public function test_capture_maps_aff_to_affiliate_id() {
        update_option( 'affilync_settings', array(
            'tracking_enabled' => true,
            'track_url_params' => array( 'ref', 'aff', 'campaign', 'click_id', 'link_id' ),
        ) );

        $_GET['aff'] = 'my_affiliate';
        $this->handler->capture_tracking_params();

        $data = $this->handler->get_tracking_data();
        $this->assertSame( 'my_affiliate', $data['affiliate_id'] );
    }

    /**
     * @runInSeparateProcess
     */
    public function test_capture_first_click_only_sets_once() {
        update_option( 'affilync_settings', array(
            'tracking_enabled'  => true,
            'attribution_model' => 'first_click',
            'track_url_params'  => array( 'ref' ),
        ) );

        // First visit.
        $_GET['ref'] = 'first_aff';
        $this->handler->capture_tracking_params();

        // Second visit — first-click cookie should NOT be overwritten.
        $_GET['ref'] = 'second_aff';
        $this->handler->capture_tracking_params();

        $data = $this->handler->get_tracking_data();
        $this->assertSame( 'first_aff', $data['affiliate_id'] );
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_cookie_constants() {
        $this->assertSame( 'affilync_tracking', Affilync_Tracking_Cookie_Handler::COOKIE_NAME );
        $this->assertSame( 'affilync_first_click', Affilync_Tracking_Cookie_Handler::FIRST_CLICK_COOKIE );
        $this->assertSame( 30, Affilync_Tracking_Cookie_Handler::DEFAULT_DURATION );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a properly signed cookie and inject into $_COOKIE.
     */
    private function set_signed_cookie( string $name, array $data ): void {
        $encoded = base64_encode( wp_json_encode( $data ) );
        $method_sign = new ReflectionMethod( Affilync_Tracking_Cookie_Handler::class, 'sign_cookie' );
        $method_sign->setAccessible( true );
        $sig = $method_sign->invoke( $this->handler, $encoded );
        $_COOKIE[ $name ] = $encoded . '.' . $sig;
    }
}
