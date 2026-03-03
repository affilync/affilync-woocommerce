<?php
/**
 * Tests for Affilync_License_Manager
 *
 * Covers license activation format validation, status logic, grace period,
 * domain hash generation, license key masking, feature checks, and caching.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * License Manager test case.
 */
class Test_License_Manager extends TestCase {

    /**
     * @var Affilync_License_Manager
     */
    private $license_mgr;

    /**
     * Mock encryption handler.
     */
    private $encryption;

    /**
     * Mock audit logger.
     */
    private $audit_logger;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options'] = array();

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-encryption.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-audit-logger.php';
        require_once dirname( __DIR__, 2 ) . '/includes/licensing/class-affilync-license-manager.php';

        $this->encryption   = $this->createMock( Affilync_Security_Encryption::class );
        $this->audit_logger = $this->createMock( Affilync_Security_Audit_Logger::class );

        $this->audit_logger->method( 'info' )->willReturn( true );
        $this->audit_logger->method( 'warning' )->willReturn( true );
        $this->audit_logger->method( 'error' )->willReturn( true );

        $this->license_mgr = new Affilync_License_Manager( $this->encryption, $this->audit_logger );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options'] = array();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_constants() {
        $this->assertSame( 3, Affilync_License_Manager::GRACE_PERIOD_DAYS );
        $this->assertSame( 24, Affilync_License_Manager::REVALIDATION_INTERVAL );
        $this->assertSame( 'valid', Affilync_License_Manager::STATUS_VALID );
        $this->assertSame( 'invalid', Affilync_License_Manager::STATUS_INVALID );
        $this->assertSame( 'expired', Affilync_License_Manager::STATUS_EXPIRED );
        $this->assertSame( 'suspended', Affilync_License_Manager::STATUS_SUSPENDED );
        $this->assertSame( 'grace_period', Affilync_License_Manager::STATUS_GRACE );
        $this->assertSame( 'unchecked', Affilync_License_Manager::STATUS_UNCHECKED );
    }

    // ------------------------------------------------------------------
    // get_status — no license stored
    // ------------------------------------------------------------------

    public function test_status_unchecked_without_license() {
        $this->assertSame( 'unchecked', $this->license_mgr->get_status() );
    }

    public function test_is_valid_false_without_license() {
        $this->assertFalse( $this->license_mgr->is_valid() );
    }

    // ------------------------------------------------------------------
    // activate — validation
    // ------------------------------------------------------------------

    public function test_activate_rejects_empty_key() {
        $result = $this->license_mgr->activate( '' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_license_key', $result->get_error_code() );
    }

    public function test_activate_rejects_invalid_format() {
        $result = $this->license_mgr->activate( 'BADKEY' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_license_format', $result->get_error_code() );
    }

    public function test_activate_rejects_lowercase_format() {
        $result = $this->license_mgr->activate( 'affilync-aaaa-bbbb-cccc-dddd' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_license_format', $result->get_error_code() );
    }

    public function test_activate_accepts_valid_format() {
        // Mock encryption to simulate storage.
        $this->encryption->method( 'encrypt' )->willReturn( 'encrypted_data' );
        $this->encryption->method( 'decrypt' )->willReturn( '{}' );

        // wp_remote_post returns 200 with valid license data.
        $result = $this->license_mgr->activate( 'AFFILYNC-ABCD-EF12-GH34-IJ56' );

        // The API call via wp_remote_post returns success by default.
        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
    }

    // ------------------------------------------------------------------
    // deactivate
    // ------------------------------------------------------------------

    public function test_deactivate_with_no_license() {
        $result = $this->license_mgr->deactivate();
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'no_license', $result->get_error_code() );
    }

    public function test_deactivate_with_stored_license() {
        // Store a license first.
        $license_data = wp_json_encode( array( 'license_key' => 'AFFILYNC-TEST-1234-5678-ABCD', 'status' => 'valid' ) );
        $this->encryption->method( 'encrypt' )->willReturn( 'enc' );
        $this->encryption->method( 'decrypt' )->willReturn( $license_data );
        update_option( 'affilync_license_data', 'enc' );

        $mgr = new Affilync_License_Manager( $this->encryption, $this->audit_logger );
        $result = $mgr->deactivate();

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
    }

    // ------------------------------------------------------------------
    // get_license_info
    // ------------------------------------------------------------------

    public function test_get_license_info_without_license() {
        $info = $this->license_mgr->get_license_info();

        $this->assertIsArray( $info );
        $this->assertSame( 'unchecked', $info['status'] );
        $this->assertSame( '', $info['license_key'] );
        $this->assertNull( $info['expires_at'] );
        $this->assertArrayHasKey( 'domain', $info );
        $this->assertArrayHasKey( 'domain_hash', $info );
    }

    public function test_get_license_info_with_license() {
        $license_data = wp_json_encode( array(
            'license_key'  => 'AFFILYNC-ABCD-EF12-GH34-IJ56',
            'status'       => 'valid',
            'plan'         => 'pro',
            'features'     => array( 'product_sync', 'webhooks' ),
            'last_check'   => gmdate( 'Y-m-d H:i:s' ),
        ) );
        $this->encryption->method( 'decrypt' )->willReturn( $license_data );
        update_option( 'affilync_license_data', 'enc' );

        $mgr = new Affilync_License_Manager( $this->encryption, $this->audit_logger );
        $info = $mgr->get_license_info();

        $this->assertIsArray( $info );
        $this->assertSame( 'pro', $info['plan'] );
        $this->assertContains( 'product_sync', $info['features'] );
    }

    // ------------------------------------------------------------------
    // mask_license_key (private)
    // ------------------------------------------------------------------

    public function test_mask_license_key_hides_middle() {
        $method = new ReflectionMethod( Affilync_License_Manager::class, 'mask_license_key' );
        $method->setAccessible( true );

        $masked = $method->invoke( $this->license_mgr, 'AFFILYNC-ABCD-EF12-GH34-IJ56' );

        // Should start with AFFILYNC and end with IJ56.
        $this->assertStringStartsWith( 'AFFILYNC', $masked );
        $this->assertStringEndsWith( 'IJ56', $masked );
        $this->assertStringContainsString( '*', $masked );
    }

    public function test_mask_short_key() {
        $method = new ReflectionMethod( Affilync_License_Manager::class, 'mask_license_key' );
        $method->setAccessible( true );

        $masked = $method->invoke( $this->license_mgr, 'SHORT' );
        $this->assertSame( '*****', $masked );
    }

    // ------------------------------------------------------------------
    // get_domain_hash (private)
    // ------------------------------------------------------------------

    public function test_domain_hash_is_stable() {
        $method = new ReflectionMethod( Affilync_License_Manager::class, 'get_domain_hash' );
        $method->setAccessible( true );

        $hash1 = $method->invoke( $this->license_mgr );
        $hash2 = $method->invoke( $this->license_mgr );

        $this->assertSame( $hash1, $hash2 );
        $this->assertSame( 64, strlen( $hash1 ) ); // SHA-256 hex.
    }

    // ------------------------------------------------------------------
    // has_feature
    // ------------------------------------------------------------------

    public function test_has_feature_false_without_valid_license() {
        $this->assertFalse( $this->license_mgr->has_feature( 'product_sync' ) );
    }

    // ------------------------------------------------------------------
    // get_plan
    // ------------------------------------------------------------------

    public function test_get_plan_null_without_license() {
        $this->assertNull( $this->license_mgr->get_plan() );
    }

    public function test_get_plan_returns_stored_plan() {
        $license_data = wp_json_encode( array(
            'license_key' => 'AFFILYNC-TEST-1234-5678-ABCD',
            'status'      => 'valid',
            'plan'        => 'enterprise',
            'last_check'  => gmdate( 'Y-m-d H:i:s' ),
        ) );
        $this->encryption->method( 'decrypt' )->willReturn( $license_data );
        update_option( 'affilync_license_data', 'enc' );

        $mgr = new Affilync_License_Manager( $this->encryption, $this->audit_logger );
        $this->assertSame( 'enterprise', $mgr->get_plan() );
    }

    // ------------------------------------------------------------------
    // is_grace_period / get_grace_days_remaining
    // ------------------------------------------------------------------

    public function test_grace_days_zero_without_grace() {
        $this->assertSame( 0, $this->license_mgr->get_grace_days_remaining() );
    }

    // ------------------------------------------------------------------
    // revalidate_license (cron handler)
    // ------------------------------------------------------------------

    public function test_revalidate_noop_without_license() {
        // Should not throw.
        $this->license_mgr->revalidate_license();
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // generate_request_signature (private)
    // ------------------------------------------------------------------

    public function test_request_signature_is_deterministic() {
        $method = new ReflectionMethod( Affilync_License_Manager::class, 'generate_request_signature' );
        $method->setAccessible( true );

        $data = array(
            'license_key' => 'AFFILYNC-TEST-1234-5678-ABCD',
            'domain_hash' => 'hash',
            'timestamp'   => 1000000,
        );

        $sig1 = $method->invoke( $this->license_mgr, $data );
        $sig2 = $method->invoke( $this->license_mgr, $data );

        $this->assertSame( $sig1, $sig2 );
        $this->assertSame( 64, strlen( $sig1 ) ); // HMAC-SHA256 hex.
    }
}
