<?php
/**
 * Unit tests for Affilync_Verification_Brand class.
 *
 * @package Affilync_WooCommerce
 */

/**
 * Test class for Affilync_Verification_Brand.
 */
class Test_Brand_Verification extends WP_UnitTestCase {

    /**
     * Brand verification instance.
     *
     * @var Affilync_Verification_Brand
     */
    private $verification;

    /**
     * Mock API client.
     *
     * @var Affilync_API_Client
     */
    private $mock_api_client;

    /**
     * Mock audit logger.
     *
     * @var Affilync_Security_Audit_Logger
     */
    private $mock_audit_logger;

    /**
     * Set up test fixtures.
     */
    public function set_up() {
        parent::set_up();

        // Create mock API client.
        $this->mock_api_client = $this->createMock( Affilync_API_Client::class );

        // Create mock audit logger.
        $this->mock_audit_logger = $this->createMock( Affilync_Security_Audit_Logger::class );

        // Create verification instance.
        $this->verification = new Affilync_Verification_Brand(
            $this->mock_api_client,
            $this->mock_audit_logger
        );

        // Clear options before each test.
        delete_option( 'affilync_brand_verification' );
        delete_option( 'affilync_brand_id' );
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down() {
        parent::tear_down();

        delete_option( 'affilync_brand_verification' );
        delete_option( 'affilync_brand_id' );
    }

    /**
     * Test get_status returns unverified by default.
     */
    public function test_get_status_defaults_to_unverified() {
        $status = $this->verification->get_status();

        $this->assertIsArray( $status );
        $this->assertEquals( 'unverified', $status['status'] );
    }

    /**
     * Test is_verified returns false by default.
     */
    public function test_is_verified_returns_false_by_default() {
        $this->assertFalse( $this->verification->is_verified() );
    }

    /**
     * Test is_verified returns true when verified.
     */
    public function test_is_verified_returns_true_when_verified() {
        update_option( 'affilync_brand_verification', array(
            'status' => 'verified',
        ) );

        $this->assertTrue( $this->verification->is_verified() );
    }

    /**
     * Test is_pending returns correct status.
     */
    public function test_is_pending_returns_correct_status() {
        $this->assertFalse( $this->verification->is_pending() );

        update_option( 'affilync_brand_verification', array(
            'status' => 'pending',
        ) );

        $this->assertTrue( $this->verification->is_pending() );
    }

    /**
     * Test initiate_verification with invalid method.
     */
    public function test_initiate_verification_invalid_method() {
        $result = $this->verification->initiate_verification( 'invalid_method' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'invalid_method', $result->get_error_code() );
    }

    /**
     * Test initiate_verification with DNS method.
     */
    public function test_initiate_verification_dns_method() {
        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->with(
                '/api/woocommerce/verification/initiate',
                $this->callback( function( $data ) {
                    return $data['method'] === 'dns' &&
                           isset( $data['domain'] ) &&
                           isset( $data['site_url'] );
                })
            )
            ->willReturn( array(
                'verification_code' => 'affilync-verify-123456',
                'verification_id'   => 'ver_123',
                'expires_at'        => '2024-12-31T23:59:59Z',
                'instructions'      => 'Add TXT record...',
            ) );

        $this->mock_audit_logger
            ->expects( $this->once() )
            ->method( 'log' )
            ->with( 'verification_initiated', $this->anything(), 'info' );

        $result = $this->verification->initiate_verification( 'dns' );

        $this->assertIsArray( $result );
        $this->assertEquals( 'pending', $result['status'] );
        $this->assertEquals( 'dns', $result['method'] );
        $this->assertEquals( 'affilync-verify-123456', $result['verification_code'] );

        // Check option was saved.
        $saved = get_option( 'affilync_brand_verification' );
        $this->assertEquals( 'pending', $saved['status'] );
    }

    /**
     * Test initiate_verification with file method.
     */
    public function test_initiate_verification_file_method() {
        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->willReturn( array(
                'verification_code' => 'file-verify-789',
                'verification_id'   => 'ver_456',
            ) );

        $result = $this->verification->initiate_verification( 'file' );

        $this->assertIsArray( $result );
        $this->assertEquals( 'file', $result['method'] );
    }

    /**
     * Test initiate_verification handles API errors.
     */
    public function test_initiate_verification_handles_api_error() {
        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->willReturn( new WP_Error( 'api_error', 'API request failed' ) );

        $this->mock_audit_logger
            ->expects( $this->once() )
            ->method( 'log' )
            ->with( 'verification_initiate_failed', $this->anything(), 'warning' );

        $result = $this->verification->initiate_verification( 'dns' );

        $this->assertInstanceOf( WP_Error::class, $result );
    }

    /**
     * Test check_verification when not pending.
     */
    public function test_check_verification_when_not_pending() {
        $result = $this->verification->check_verification();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'invalid_status', $result->get_error_code() );
    }

    /**
     * Test check_verification successful.
     */
    public function test_check_verification_successful() {
        update_option( 'affilync_brand_verification', array(
            'status'          => 'pending',
            'method'          => 'dns',
            'verification_id' => 'ver_123',
        ) );

        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->with(
                '/api/woocommerce/verification/verify',
                $this->anything()
            )
            ->willReturn( array(
                'verified'    => true,
                'brand_id'    => 'brand_456',
                'badge_url'   => 'https://affilync.com/badges/verified.svg',
                'trust_score' => 95,
            ) );

        $this->mock_audit_logger
            ->expects( $this->once() )
            ->method( 'log' )
            ->with( 'brand_verified', $this->anything(), 'info' );

        $result = $this->verification->check_verification();

        $this->assertIsArray( $result );
        $this->assertEquals( 'verified', $result['status'] );

        // Check brand ID was saved.
        $this->assertEquals( 'brand_456', get_option( 'affilync_brand_id' ) );
    }

    /**
     * Test check_verification rejected.
     */
    public function test_check_verification_rejected() {
        update_option( 'affilync_brand_verification', array(
            'status'          => 'pending',
            'method'          => 'dns',
            'verification_id' => 'ver_123',
        ) );

        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->willReturn( array(
                'rejected' => true,
                'reason'   => 'DNS record not found',
            ) );

        $this->mock_audit_logger
            ->expects( $this->once() )
            ->method( 'log' )
            ->with( 'brand_rejected', $this->anything(), 'warning' );

        $result = $this->verification->check_verification();

        $this->assertEquals( 'rejected', $result['status'] );
        $this->assertEquals( 'DNS record not found', $result['rejection_reason'] );
    }

    /**
     * Test cancel_verification when not pending.
     */
    public function test_cancel_verification_when_not_pending() {
        $result = $this->verification->cancel_verification();

        $this->assertFalse( $result );
    }

    /**
     * Test cancel_verification successful.
     */
    public function test_cancel_verification_successful() {
        update_option( 'affilync_brand_verification', array(
            'status'          => 'pending',
            'verification_id' => 'ver_123',
            'method'          => 'dns',
        ) );

        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->with(
                '/api/woocommerce/verification/cancel',
                $this->callback( function( $data ) {
                    return $data['verification_id'] === 'ver_123';
                })
            )
            ->willReturn( array( 'success' => true ) );

        $this->mock_audit_logger
            ->expects( $this->once() )
            ->method( 'log' )
            ->with( 'verification_cancelled', $this->anything(), 'info' );

        $result = $this->verification->cancel_verification();

        $this->assertTrue( $result );
        $this->assertFalse( get_option( 'affilync_brand_verification' ) );
    }

    /**
     * Test get_method_instructions returns correct format.
     */
    public function test_get_method_instructions_returns_correct_format() {
        update_option( 'affilync_brand_verification', array(
            'verification_code' => 'test-code-123',
            'brand_info'        => array(
                'domain' => 'example.com',
            ),
        ) );

        $instructions = $this->verification->get_method_instructions( 'dns' );

        $this->assertIsArray( $instructions );
        $this->assertArrayHasKey( 'title', $instructions );
        $this->assertArrayHasKey( 'description', $instructions );
        $this->assertArrayHasKey( 'steps', $instructions );
        $this->assertIsArray( $instructions['steps'] );
    }

    /**
     * Test all verification methods have instructions.
     */
    public function test_all_methods_have_instructions() {
        $methods = array( 'dns', 'file', 'meta', 'email' );

        foreach ( $methods as $method ) {
            $instructions = $this->verification->get_method_instructions( $method );

            $this->assertNotEmpty( $instructions, "Instructions missing for method: {$method}" );
            $this->assertArrayHasKey( 'title', $instructions );
            $this->assertArrayHasKey( 'steps', $instructions );
        }
    }

    /**
     * Test render_badge returns empty when not verified.
     */
    public function test_render_badge_empty_when_not_verified() {
        $badge = $this->verification->render_badge();

        $this->assertEmpty( $badge );
    }

    /**
     * Test render_badge returns HTML when verified.
     */
    public function test_render_badge_returns_html_when_verified() {
        update_option( 'affilync_brand_verification', array(
            'status'     => 'verified',
            'badge_url'  => 'https://affilync.com/badges/verified.svg',
            'brand_info' => array(
                'site_name' => 'Test Store',
            ),
        ) );

        $badge = $this->verification->render_badge();

        $this->assertNotEmpty( $badge );
        $this->assertStringContainsString( 'affilync-verified-badge', $badge );
        $this->assertStringContainsString( 'img', $badge );
    }

    /**
     * Test render_badge with different sizes.
     */
    public function test_render_badge_sizes() {
        update_option( 'affilync_brand_verification', array(
            'status' => 'verified',
        ) );

        $sizes = array( 'small', 'medium', 'large' );

        foreach ( $sizes as $size ) {
            $badge = $this->verification->render_badge( array( 'size' => $size ) );
            $this->assertStringContainsString( "affilync-badge-{$size}", $badge );
        }
    }

    /**
     * Test verification status constants.
     */
    public function test_status_constants_defined() {
        $this->assertEquals( 'unverified', Affilync_Verification_Brand::STATUS_UNVERIFIED );
        $this->assertEquals( 'pending', Affilync_Verification_Brand::STATUS_PENDING );
        $this->assertEquals( 'verified', Affilync_Verification_Brand::STATUS_VERIFIED );
        $this->assertEquals( 'rejected', Affilync_Verification_Brand::STATUS_REJECTED );
    }

    /**
     * Test verification method constants.
     */
    public function test_method_constants_defined() {
        $this->assertEquals( 'dns', Affilync_Verification_Brand::METHOD_DNS );
        $this->assertEquals( 'file', Affilync_Verification_Brand::METHOD_FILE );
        $this->assertEquals( 'meta', Affilync_Verification_Brand::METHOD_META );
        $this->assertEquals( 'email', Affilync_Verification_Brand::METHOD_EMAIL );
    }

    /**
     * Test webhook handler for brand.verified event.
     */
    public function test_handle_verified_webhook() {
        update_option( 'affilync_brand_verification', array(
            'status' => 'pending',
            'method' => 'dns',
        ) );

        $this->mock_audit_logger
            ->method( 'log' );

        $this->verification->handle_verified_webhook( array(
            'brand_id'    => 'brand_webhook_test',
            'badge_url'   => 'https://affilync.com/badge.svg',
            'trust_score' => 100,
        ) );

        $status = $this->verification->get_status();

        $this->assertEquals( 'verified', $status['status'] );
        $this->assertEquals( 'brand_webhook_test', get_option( 'affilync_brand_id' ) );
    }

    /**
     * Test webhook handler for brand.rejected event.
     */
    public function test_handle_rejected_webhook() {
        update_option( 'affilync_brand_verification', array(
            'status' => 'pending',
            'method' => 'dns',
        ) );

        $this->mock_audit_logger
            ->method( 'log' );

        $this->verification->handle_rejected_webhook( array(
            'reason' => 'Verification failed: invalid domain',
        ) );

        $status = $this->verification->get_status();

        $this->assertEquals( 'rejected', $status['status'] );
        $this->assertEquals( 'Verification failed: invalid domain', $status['rejection_reason'] );
    }
}
