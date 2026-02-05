<?php
/**
 * Integration tests for OAuth flow.
 *
 * @package Affilync_WooCommerce
 */

/**
 * Test class for OAuth integration.
 */
class Test_OAuth_Flow extends WP_UnitTestCase {

    /**
     * OAuth handler instance.
     *
     * @var Affilync_API_OAuth
     */
    private $oauth;

    /**
     * Mock nonce manager.
     *
     * @var Affilync_Security_Nonce_Manager
     */
    private $mock_nonce_manager;

    /**
     * Mock encryption handler.
     *
     * @var Affilync_Security_Encryption
     */
    private $mock_encryption;

    /**
     * Mock API client.
     *
     * @var Affilync_API_Client
     */
    private $mock_api_client;

    /**
     * Set up test fixtures.
     */
    public function set_up() {
        parent::set_up();

        // Create mock objects.
        $this->mock_nonce_manager = $this->createMock( Affilync_Security_Nonce_Manager::class );
        $this->mock_encryption    = $this->createMock( Affilync_Security_Encryption::class );
        $this->mock_api_client    = $this->createMock( Affilync_API_Client::class );

        // Create OAuth instance.
        $this->oauth = new Affilync_API_OAuth(
            $this->mock_nonce_manager,
            $this->mock_encryption,
            $this->mock_api_client
        );

        // Clear any stored OAuth data.
        delete_option( 'affilync_access_token' );
        delete_option( 'affilync_refresh_token' );
        delete_option( 'affilync_brand_id' );
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down() {
        parent::tear_down();

        delete_option( 'affilync_access_token' );
        delete_option( 'affilync_refresh_token' );
        delete_option( 'affilync_brand_id' );
    }

    /**
     * Test OAuth initiation generates PKCE parameters.
     */
    public function test_oauth_initiation_generates_pkce_params() {
        $this->mock_nonce_manager
            ->expects( $this->once() )
            ->method( 'create_nonce' )
            ->willReturn( 'test_state_nonce' );

        // Simulate initiating OAuth.
        $result = $this->oauth->initiate_oauth();

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'authorization_url', $result );
        $this->assertArrayHasKey( 'state', $result );
    }

    /**
     * Test OAuth callback validates state parameter.
     */
    public function test_oauth_callback_validates_state() {
        // Attempt callback without valid state.
        $this->mock_nonce_manager
            ->expects( $this->once() )
            ->method( 'verify_nonce' )
            ->with( 'invalid_state' )
            ->willReturn( false );

        $result = $this->oauth->handle_callback( 'auth_code', 'invalid_state' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'invalid_state', $result->get_error_code() );
    }

    /**
     * Test successful OAuth callback stores tokens.
     */
    public function test_successful_oauth_callback_stores_tokens() {
        $this->mock_nonce_manager
            ->expects( $this->once() )
            ->method( 'verify_nonce' )
            ->with( 'valid_state' )
            ->willReturn( array( 'code_verifier' => 'test_verifier' ) );

        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->with(
                '/api/woocommerce/oauth/token',
                $this->callback( function( $data ) {
                    return isset( $data['code'] ) &&
                           isset( $data['code_verifier'] ) &&
                           $data['grant_type'] === 'authorization_code';
                })
            )
            ->willReturn( array(
                'access_token'  => 'test_access_token',
                'refresh_token' => 'test_refresh_token',
                'brand_id'      => 'brand_123',
                'expires_in'    => 3600,
            ) );

        $this->mock_encryption
            ->expects( $this->exactly( 2 ) )
            ->method( 'encrypt' )
            ->willReturnCallback( function( $value ) {
                return 'encrypted_' . $value;
            });

        $result = $this->oauth->handle_callback( 'auth_code', 'valid_state' );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );

        // Verify tokens were stored.
        $this->assertEquals( 'encrypted_test_access_token', get_option( 'affilync_access_token' ) );
        $this->assertEquals( 'brand_123', get_option( 'affilync_brand_id' ) );
    }

    /**
     * Test OAuth callback handles API errors.
     */
    public function test_oauth_callback_handles_api_errors() {
        $this->mock_nonce_manager
            ->expects( $this->once() )
            ->method( 'verify_nonce' )
            ->willReturn( array( 'code_verifier' => 'test_verifier' ) );

        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->willReturn( new WP_Error( 'api_error', 'Token exchange failed' ) );

        $result = $this->oauth->handle_callback( 'auth_code', 'valid_state' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'api_error', $result->get_error_code() );
    }

    /**
     * Test disconnect clears stored credentials.
     */
    public function test_disconnect_clears_credentials() {
        // Set up stored credentials.
        update_option( 'affilync_access_token', 'encrypted_token' );
        update_option( 'affilync_refresh_token', 'encrypted_refresh' );
        update_option( 'affilync_brand_id', 'brand_123' );

        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->with( '/api/woocommerce/oauth/revoke' )
            ->willReturn( array( 'success' => true ) );

        $result = $this->oauth->disconnect();

        $this->assertTrue( $result );
        $this->assertFalse( get_option( 'affilync_access_token' ) );
        $this->assertFalse( get_option( 'affilync_refresh_token' ) );
        $this->assertFalse( get_option( 'affilync_brand_id' ) );
    }

    /**
     * Test token refresh updates stored access token.
     */
    public function test_token_refresh_updates_access_token() {
        update_option( 'affilync_refresh_token', 'encrypted_refresh_token' );

        $this->mock_encryption
            ->method( 'decrypt' )
            ->with( 'encrypted_refresh_token' )
            ->willReturn( 'refresh_token' );

        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->with(
                '/api/woocommerce/oauth/token',
                $this->callback( function( $data ) {
                    return $data['grant_type'] === 'refresh_token' &&
                           $data['refresh_token'] === 'refresh_token';
                })
            )
            ->willReturn( array(
                'access_token' => 'new_access_token',
                'expires_in'   => 3600,
            ) );

        $this->mock_encryption
            ->method( 'encrypt' )
            ->with( 'new_access_token' )
            ->willReturn( 'encrypted_new_access_token' );

        $result = $this->oauth->refresh_token();

        $this->assertTrue( $result );
    }

    /**
     * Test PKCE code verifier generation.
     */
    public function test_pkce_code_verifier_generation() {
        $reflection = new ReflectionClass( $this->oauth );
        $method = $reflection->getMethod( 'generate_code_verifier' );
        $method->setAccessible( true );

        $verifier = $method->invoke( $this->oauth );

        // Code verifier should be 43-128 characters.
        $this->assertGreaterThanOrEqual( 43, strlen( $verifier ) );
        $this->assertLessThanOrEqual( 128, strlen( $verifier ) );

        // Should only contain unreserved characters.
        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-._~]+$/', $verifier );
    }

    /**
     * Test PKCE code challenge generation.
     */
    public function test_pkce_code_challenge_generation() {
        $reflection = new ReflectionClass( $this->oauth );
        $method = $reflection->getMethod( 'generate_code_challenge' );
        $method->setAccessible( true );

        $verifier = 'test_code_verifier_1234567890';
        $challenge = $method->invoke( $this->oauth, $verifier );

        // Challenge should be base64url encoded SHA256 hash.
        $this->assertNotEmpty( $challenge );
        $this->assertNotEquals( $verifier, $challenge );

        // Should be URL-safe base64.
        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-_]+$/', $challenge );
    }

    /**
     * Test OAuth redirect URI construction.
     */
    public function test_oauth_redirect_uri_construction() {
        $reflection = new ReflectionClass( $this->oauth );
        $method = $reflection->getMethod( 'get_redirect_uri' );
        $method->setAccessible( true );

        $uri = $method->invoke( $this->oauth );

        $this->assertStringContainsString( 'affilync/v1/oauth/callback', $uri );
        $this->assertStringStartsWith( home_url(), $uri );
    }

    /**
     * Test authorization URL contains required parameters.
     */
    public function test_authorization_url_contains_required_params() {
        $this->mock_nonce_manager
            ->method( 'create_nonce' )
            ->willReturn( 'test_state' );

        $result = $this->oauth->initiate_oauth();
        $url = $result['authorization_url'];

        // Parse URL and check parameters.
        $parsed = wp_parse_url( $url );
        parse_str( $parsed['query'] ?? '', $params );

        $this->assertArrayHasKey( 'client_id', $params );
        $this->assertArrayHasKey( 'redirect_uri', $params );
        $this->assertArrayHasKey( 'response_type', $params );
        $this->assertArrayHasKey( 'scope', $params );
        $this->assertArrayHasKey( 'state', $params );
        $this->assertArrayHasKey( 'code_challenge', $params );
        $this->assertArrayHasKey( 'code_challenge_method', $params );

        $this->assertEquals( 'code', $params['response_type'] );
        $this->assertEquals( 'S256', $params['code_challenge_method'] );
    }

    /**
     * Test connection status check.
     */
    public function test_is_connected_returns_correct_status() {
        // Initially not connected.
        $this->assertFalse( $this->oauth->is_connected() );

        // Simulate connection.
        update_option( 'affilync_access_token', 'encrypted_token' );
        update_option( 'affilync_brand_id', 'brand_123' );

        $this->assertTrue( $this->oauth->is_connected() );
    }
}
