<?php
/**
 * Integration tests for OAuth flow.
 *
 * Tests against the actual Affilync_API_OAuth public API:
 *   - get_authorization_url()
 *   - disconnect()
 *   - get_redirect_uri()
 *   - has_client_id()
 *   - is_connected()
 *   - get_status()
 *
 * Private methods tested via reflection:
 *   - generate_code_verifier()
 *   - generate_code_challenge()
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
		delete_option( 'affilync_connection_status' );
		delete_option( 'affilync_brand_id' );
		delete_option( 'affilync_api_credentials' );
		delete_option( 'affilync_license_client_id' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		parent::tear_down();

		delete_option( 'affilync_connection_status' );
		delete_option( 'affilync_brand_id' );
		delete_option( 'affilync_api_credentials' );
		delete_option( 'affilync_license_client_id' );
	}

	/**
	 * Test get_authorization_url returns error when client_id not configured.
	 */
	public function test_get_authorization_url_returns_error_without_client_id() {
		$result = $this->oauth->get_authorization_url();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'client_id_missing', $result->get_error_code() );
	}

	/**
	 * Test get_authorization_url generates valid URL with client_id.
	 */
	public function test_get_authorization_url_generates_url_with_client_id() {
		// Set a license-based client ID.
		update_option( 'affilync_license_client_id', 'test_client_id_123' );

		$this->mock_nonce_manager
			->expects( $this->once() )
			->method( 'create' )
			->willReturn( 'test_state_nonce' );

		$this->mock_api_client
			->method( 'get_api_url' )
			->willReturn( 'https://api.affilync.com' );

		$result = $this->oauth->get_authorization_url();

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'https://api.affilync.com/oauth/authorize?', $result );

		// Parse URL and check required parameters.
		$parsed = parse_url( $result );
		parse_str( $parsed['query'] ?? '', $params );

		$this->assertEquals( 'test_client_id_123', $params['client_id'] );
		$this->assertEquals( 'code', $params['response_type'] );
		$this->assertEquals( 'S256', $params['code_challenge_method'] );
		$this->assertEquals( 'test_state_nonce', $params['state'] );
		$this->assertArrayHasKey( 'code_challenge', $params );
		$this->assertArrayHasKey( 'redirect_uri', $params );
		$this->assertArrayHasKey( 'scope', $params );
		$this->assertEquals( 'woocommerce', $params['platform'] );
	}

	/**
	 * Test get_authorization_url returns error when nonce creation fails.
	 */
	public function test_get_authorization_url_returns_error_on_nonce_failure() {
		update_option( 'affilync_license_client_id', 'test_client_id' );

		$this->mock_nonce_manager
			->method( 'create' )
			->willReturn( false );

		$this->mock_api_client
			->method( 'get_api_url' )
			->willReturn( 'https://api.affilync.com' );

		$result = $this->oauth->get_authorization_url();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'nonce_creation_failed', $result->get_error_code() );
	}

	/**
	 * Test disconnect clears stored credentials and updates status.
	 */
	public function test_disconnect_clears_credentials() {
		update_option( 'affilync_connection_status', 'connected' );
		update_option( 'affilync_brand_id', 'brand_123' );

		$this->mock_encryption
			->expects( $this->once() )
			->method( 'clear_credentials' );

		$result = $this->oauth->disconnect();

		$this->assertTrue( $result );
		$this->assertEquals( 'disconnected', get_option( 'affilync_connection_status' ) );
		$this->assertFalse( get_option( 'affilync_brand_id' ) );
	}

	/**
	 * Test get_redirect_uri returns admin URL with callback params.
	 */
	public function test_get_redirect_uri_format() {
		$uri = $this->oauth->get_redirect_uri();

		$this->assertStringContainsString( 'wp-admin', $uri );
		$this->assertStringContainsString( 'affilync-settings', $uri );
		$this->assertStringContainsString( 'affilync_oauth=callback', $uri );
	}

	/**
	 * Test has_client_id returns false when not configured.
	 */
	public function test_has_client_id_returns_false_when_not_configured() {
		$this->assertFalse( $this->oauth->has_client_id() );
	}

	/**
	 * Test has_client_id returns true when license client ID is set.
	 */
	public function test_has_client_id_returns_true_with_license() {
		update_option( 'affilync_license_client_id', 'some_client_id' );

		$this->assertTrue( $this->oauth->has_client_id() );
	}

	/**
	 * Test is_connected delegates to encryption has_credentials.
	 */
	public function test_is_connected_delegates_to_encryption() {
		$this->mock_encryption
			->expects( $this->once() )
			->method( 'has_credentials' )
			->willReturn( false );

		$this->assertFalse( $this->oauth->is_connected() );
	}

	/**
	 * Test is_connected returns true when credentials exist.
	 */
	public function test_is_connected_returns_true_with_credentials() {
		$this->mock_encryption
			->method( 'has_credentials' )
			->willReturn( true );

		$this->assertTrue( $this->oauth->is_connected() );
	}

	/**
	 * Test get_status returns correct structure when disconnected.
	 */
	public function test_get_status_disconnected() {
		$this->mock_encryption
			->method( 'has_credentials' )
			->willReturn( false );

		$status = $this->oauth->get_status();

		$this->assertIsArray( $status );
		$this->assertFalse( $status['connected'] );
		$this->assertEquals( 'disconnected', $status['status'] );
		$this->assertArrayHasKey( 'brand_id', $status );
		$this->assertArrayHasKey( 'last_check', $status );
	}

	/**
	 * Test get_status returns correct structure when connected.
	 */
	public function test_get_status_connected() {
		update_option( 'affilync_brand_id', 'brand_789' );

		$this->mock_encryption
			->method( 'has_credentials' )
			->willReturn( true );

		$status = $this->oauth->get_status();

		$this->assertTrue( $status['connected'] );
		$this->assertEquals( 'connected', $status['status'] );
		$this->assertEquals( 'brand_789', $status['brand_id'] );
	}

	/**
	 * Test PKCE code verifier generation.
	 */
	public function test_pkce_code_verifier_generation() {
		$reflection = new ReflectionClass( $this->oauth );
		$method = $reflection->getMethod( 'generate_code_verifier' );
		$method->setAccessible( true );

		$verifier = $method->invoke( $this->oauth );

		// Code verifier should be 43-128 characters per RFC 7636.
		$this->assertGreaterThanOrEqual( 43, strlen( $verifier ) );
		$this->assertLessThanOrEqual( 128, strlen( $verifier ) );

		// Should only contain unreserved characters (base64url).
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-._~]+$/', $verifier );
	}

	/**
	 * Test PKCE code challenge generation.
	 */
	public function test_pkce_code_challenge_generation() {
		$reflection = new ReflectionClass( $this->oauth );
		$method = $reflection->getMethod( 'generate_code_challenge' );
		$method->setAccessible( true );

		$verifier  = 'test_code_verifier_1234567890';
		$challenge = $method->invoke( $this->oauth, $verifier );

		// Challenge should be base64url encoded SHA256 hash.
		$this->assertNotEmpty( $challenge );
		$this->assertNotEquals( $verifier, $challenge );

		// Should be URL-safe base64 (no +, /, or = characters).
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-_]+$/', $challenge );

		// Same verifier should produce same challenge (deterministic).
		$challenge2 = $method->invoke( $this->oauth, $verifier );
		$this->assertEquals( $challenge, $challenge2 );
	}

	/**
	 * Test code verifier uniqueness.
	 */
	public function test_pkce_code_verifier_uniqueness() {
		$reflection = new ReflectionClass( $this->oauth );
		$method = $reflection->getMethod( 'generate_code_verifier' );
		$method->setAccessible( true );

		$verifier1 = $method->invoke( $this->oauth );
		$verifier2 = $method->invoke( $this->oauth );

		$this->assertNotEquals( $verifier1, $verifier2 );
	}
}
