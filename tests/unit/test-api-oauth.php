<?php
/**
 * Tests for Affilync_API_OAuth
 *
 * Covers authorization URL generation, PKCE challenge, redirect URI,
 * client ID detection, disconnect flow, connection status, and scopes.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * API OAuth test case.
 */
class Test_API_OAuth extends TestCase {

    /**
     * @var Affilync_API_OAuth
     */
    private $oauth;

    /**
     * Mocks.
     */
    private $nonce_manager;
    private $encryption;
    private $api_client;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options'] = array();

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-nonce-manager.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-encryption.php';
        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-api-client.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-audit-logger.php';
        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-api-oauth.php';

        $this->nonce_manager = $this->createMock( Affilync_Security_Nonce_Manager::class );
        $this->encryption    = $this->createMock( Affilync_Security_Encryption::class );
        $this->api_client    = $this->createMock( Affilync_API_Client::class );

        $this->oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            $this->encryption,
            $this->api_client
        );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options'] = array();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // get_redirect_uri
    // ------------------------------------------------------------------

    public function test_redirect_uri_contains_callback_param() {
        $uri = $this->oauth->get_redirect_uri();
        $this->assertStringContainsString( 'affilync_oauth=callback', $uri );
    }

    public function test_redirect_uri_contains_settings_page() {
        $uri = $this->oauth->get_redirect_uri();
        $this->assertStringContainsString( 'page=affilync-settings', $uri );
    }

    public function test_redirect_uri_is_admin_url() {
        $uri = $this->oauth->get_redirect_uri();
        $this->assertStringStartsWith( 'https://example.com/wp-admin/', $uri );
    }

    // ------------------------------------------------------------------
    // has_client_id
    // ------------------------------------------------------------------

    public function test_has_client_id_false_without_config() {
        $this->assertFalse( $this->oauth->has_client_id() );
    }

    public function test_has_client_id_true_with_option() {
        update_option( 'affilync_license_client_id', 'test_client_123' );
        $this->assertTrue( $this->oauth->has_client_id() );
    }

    // ------------------------------------------------------------------
    // get_authorization_url — no client ID
    // ------------------------------------------------------------------

    public function test_get_authorization_url_returns_error_without_client_id() {
        $result = $this->oauth->get_authorization_url();
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'client_id_missing', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // get_authorization_url — with client ID
    // ------------------------------------------------------------------

    public function test_get_authorization_url_returns_url_with_client_id() {
        update_option( 'affilync_license_client_id', 'test_client_123' );

        $this->nonce_manager->method( 'create' )->willReturn( 'test_state_nonce' );
        $this->api_client->method( 'get_api_url' )->willReturn( 'https://api.affilync.com' );

        $oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            $this->encryption,
            $this->api_client
        );

        $url = $oauth->get_authorization_url();

        $this->assertIsString( $url );
        $this->assertStringContainsString( 'oauth/authorize', $url );
        $this->assertStringContainsString( 'client_id=test_client_123', $url );
        $this->assertStringContainsString( 'response_type=code', $url );
        $this->assertStringContainsString( 'state=test_state_nonce', $url );
        $this->assertStringContainsString( 'code_challenge=', $url );
        $this->assertStringContainsString( 'code_challenge_method=S256', $url );
        $this->assertStringContainsString( 'platform=woocommerce', $url );
    }

    public function test_get_authorization_url_error_when_nonce_fails() {
        update_option( 'affilync_license_client_id', 'test_client_123' );
        $this->nonce_manager->method( 'create' )->willReturn( null );

        $oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            $this->encryption,
            $this->api_client
        );

        $result = $oauth->get_authorization_url();
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'nonce_creation_failed', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // disconnect
    // ------------------------------------------------------------------

    public function test_disconnect_returns_true() {
        $this->encryption->method( 'clear_credentials' )->willReturn( true );

        $result = $this->oauth->disconnect();
        $this->assertTrue( $result );
    }

    public function test_disconnect_updates_status() {
        $this->encryption->method( 'clear_credentials' )->willReturn( true );

        $this->oauth->disconnect();
        $this->assertSame( 'disconnected', get_option( 'affilync_connection_status' ) );
    }

    public function test_disconnect_removes_brand_id() {
        update_option( 'affilync_brand_id', 'brand_123' );
        $this->encryption->method( 'clear_credentials' )->willReturn( true );

        $this->oauth->disconnect();
        $this->assertFalse( get_option( 'affilync_brand_id' ) );
    }

    // ------------------------------------------------------------------
    // is_connected
    // ------------------------------------------------------------------

    public function test_is_connected_delegates_to_encryption() {
        $this->encryption->method( 'has_credentials' )->willReturn( true );

        $oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            $this->encryption,
            $this->api_client
        );

        $this->assertTrue( $oauth->is_connected() );
    }

    public function test_is_connected_false_without_credentials() {
        $this->encryption->method( 'has_credentials' )->willReturn( false );

        $oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            $this->encryption,
            $this->api_client
        );

        $this->assertFalse( $oauth->is_connected() );
    }

    // ------------------------------------------------------------------
    // get_status
    // ------------------------------------------------------------------

    public function test_get_status_returns_expected_keys() {
        $this->encryption->method( 'has_credentials' )->willReturn( false );

        $oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            $this->encryption,
            $this->api_client
        );

        $status = $oauth->get_status();

        $this->assertIsArray( $status );
        $this->assertArrayHasKey( 'connected', $status );
        $this->assertArrayHasKey( 'status', $status );
        $this->assertArrayHasKey( 'brand_id', $status );
        $this->assertArrayHasKey( 'last_check', $status );
    }

    public function test_get_status_disconnected() {
        $this->encryption->method( 'has_credentials' )->willReturn( false );

        $oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            $this->encryption,
            $this->api_client
        );

        $status = $oauth->get_status();
        $this->assertFalse( $status['connected'] );
        $this->assertSame( 'disconnected', $status['status'] );
    }

    public function test_get_status_connected_with_brand_id() {
        $this->encryption->method( 'has_credentials' )->willReturn( true );
        update_option( 'affilync_brand_id', 'brand_xyz' );

        $oauth = new Affilync_API_OAuth(
            $this->nonce_manager,
            $this->encryption,
            $this->api_client
        );

        $status = $oauth->get_status();
        $this->assertTrue( $status['connected'] );
        $this->assertSame( 'connected', $status['status'] );
        $this->assertSame( 'brand_xyz', $status['brand_id'] );
    }

    // ------------------------------------------------------------------
    // generate_code_verifier / generate_code_challenge (private via Reflection)
    // ------------------------------------------------------------------

    public function test_code_verifier_is_base64url_safe() {
        $method = new ReflectionMethod( Affilync_API_OAuth::class, 'generate_code_verifier' );
        $method->setAccessible( true );

        $verifier = $method->invoke( $this->oauth );

        $this->assertIsString( $verifier );
        $this->assertGreaterThan( 30, strlen( $verifier ) );
        // Base64url: no +, /, or = padding.
        $this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $verifier );
    }

    public function test_code_verifier_is_unique() {
        $method = new ReflectionMethod( Affilync_API_OAuth::class, 'generate_code_verifier' );
        $method->setAccessible( true );

        $v1 = $method->invoke( $this->oauth );
        $v2 = $method->invoke( $this->oauth );

        $this->assertNotSame( $v1, $v2 );
    }

    public function test_code_challenge_is_deterministic() {
        $method = new ReflectionMethod( Affilync_API_OAuth::class, 'generate_code_challenge' );
        $method->setAccessible( true );

        $challenge1 = $method->invoke( $this->oauth, 'fixed_verifier' );
        $challenge2 = $method->invoke( $this->oauth, 'fixed_verifier' );

        $this->assertSame( $challenge1, $challenge2 );
    }

    public function test_code_challenge_differs_for_different_verifiers() {
        $method = new ReflectionMethod( Affilync_API_OAuth::class, 'generate_code_challenge' );
        $method->setAccessible( true );

        $c1 = $method->invoke( $this->oauth, 'verifier_a' );
        $c2 = $method->invoke( $this->oauth, 'verifier_b' );

        $this->assertNotSame( $c1, $c2 );
    }

    public function test_code_challenge_is_base64url_safe() {
        $method = new ReflectionMethod( Affilync_API_OAuth::class, 'generate_code_challenge' );
        $method->setAccessible( true );

        $challenge = $method->invoke( $this->oauth, 'test_verifier' );

        $this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $challenge );
    }

    // ------------------------------------------------------------------
    // Scopes (private property via Reflection)
    // ------------------------------------------------------------------

    public function test_scopes_include_required_permissions() {
        $prop = new ReflectionProperty( Affilync_API_OAuth::class, 'scopes' );
        $prop->setAccessible( true );

        $scopes = $prop->getValue( $this->oauth );

        $this->assertContains( 'brand:read', $scopes );
        $this->assertContains( 'brand:write', $scopes );
        $this->assertContains( 'products:read', $scopes );
        $this->assertContains( 'conversions:write', $scopes );
        $this->assertContains( 'webhooks:manage', $scopes );
    }

    // ------------------------------------------------------------------
    // handle_oauth_redirect — guard clauses
    // ------------------------------------------------------------------

    public function test_handle_oauth_redirect_noop_without_param() {
        // No $_GET['affilync_oauth'] set, should return early.
        unset( $_GET['affilync_oauth'] );
        $this->oauth->handle_oauth_redirect();
        $this->assertTrue( true ); // No exception means it returned early.
    }

    // ------------------------------------------------------------------
    // get_client_id (private)
    // ------------------------------------------------------------------

    public function test_get_client_id_throws_when_not_configured() {
        $method = new ReflectionMethod( Affilync_API_OAuth::class, 'get_client_id' );
        $method->setAccessible( true );

        $this->expectException( Exception::class );
        $method->invoke( $this->oauth );
    }

    public function test_get_client_id_from_option() {
        update_option( 'affilync_license_client_id', 'my_client_id' );

        $method = new ReflectionMethod( Affilync_API_OAuth::class, 'get_client_id' );
        $method->setAccessible( true );

        $this->assertSame( 'my_client_id', $method->invoke( $this->oauth ) );
    }
}
