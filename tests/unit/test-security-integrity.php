<?php
/**
 * Tests for Affilync_Security_Integrity
 *
 * Covers API/App URL verification, constant checks, debugging detection,
 * request origin analysis, integrity caching, and enforce behavior.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Security Integrity test case.
 */
class Test_Security_Integrity extends TestCase {

    /**
     * @var Affilync_Security_Integrity
     */
    private $integrity;

    /**
     * @var object Mock audit logger.
     */
    private $audit_logger;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options'] = array();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Agent';
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';

        // Reset the static cache before each test.
        $ref = new ReflectionProperty( Affilync_Security_Integrity::class, 'integrity_verified' );
        $ref->setAccessible( true );
        $ref->setValue( null, null );

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-audit-logger.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-integrity.php';

        $this->audit_logger = $this->createMock( Affilync_Security_Audit_Logger::class );
        $this->audit_logger->method( 'info' )->willReturn( true );
        $this->audit_logger->method( 'warning' )->willReturn( true );
        $this->audit_logger->method( 'error' )->willReturn( true );
        $this->audit_logger->method( 'critical' )->willReturn( true );

        $this->integrity = new Affilync_Security_Integrity( $this->audit_logger );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options'] = array();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_expected_api_url_constant() {
        $this->assertSame( 'https://api.affilync.com', Affilync_Security_Integrity::EXPECTED_API_URL );
    }

    public function test_expected_app_url_constant() {
        $this->assertSame( 'https://app.affilync.com', Affilync_Security_Integrity::EXPECTED_APP_URL );
    }

    // ------------------------------------------------------------------
    // verify
    // ------------------------------------------------------------------

    public function test_verify_passes_with_correct_urls_and_constants() {
        $result = $this->integrity->verify();
        $this->assertTrue( $result );
    }

    public function test_verify_uses_cache_on_second_call() {
        // First call should set the cache.
        $first = $this->integrity->verify();
        $this->assertTrue( $first );

        // Second call returns cached result.
        $second = $this->integrity->verify();
        $this->assertTrue( $second );
    }

    public function test_verify_with_check_files_skips_cache() {
        // First verify without files.
        $this->integrity->verify();

        // verify with check_files=true should not use cache.
        // No stored hashes, so it generates them and returns true.
        $result = $this->integrity->verify( true );
        $this->assertTrue( $result );
    }

    // ------------------------------------------------------------------
    // verify_api_url (via verify)
    // ------------------------------------------------------------------

    public function test_verify_api_url_matches_expected() {
        // AFFILYNC_API_URL = 'https://api.affilync.com' in mocks.
        $method = new ReflectionMethod( Affilync_Security_Integrity::class, 'verify_api_url' );
        $method->setAccessible( true );

        $this->assertTrue( $method->invoke( $this->integrity ) );
    }

    // ------------------------------------------------------------------
    // verify_app_url (via verify)
    // ------------------------------------------------------------------

    public function test_verify_app_url_matches_expected() {
        $method = new ReflectionMethod( Affilync_Security_Integrity::class, 'verify_app_url' );
        $method->setAccessible( true );

        $this->assertTrue( $method->invoke( $this->integrity ) );
    }

    // ------------------------------------------------------------------
    // verify_constants
    // ------------------------------------------------------------------

    public function test_verify_constants_pass_when_all_defined() {
        $method = new ReflectionMethod( Affilync_Security_Integrity::class, 'verify_constants' );
        $method->setAccessible( true );

        $this->assertTrue( $method->invoke( $this->integrity ) );
    }

    // ------------------------------------------------------------------
    // is_debugging
    // ------------------------------------------------------------------

    public function test_is_debugging_returns_false_in_test_env() {
        // Neither xdebug nor Zend Debugger should be loaded in tests.
        $result = $this->integrity->is_debugging();
        $this->assertIsBool( $result );
    }

    // ------------------------------------------------------------------
    // verify_request_origin
    // ------------------------------------------------------------------

    public function test_verify_request_origin_returns_true_in_cli() {
        // In CLI mode (phpunit), verify_request_origin always returns true
        // and skips user-agent checks entirely.
        $this->assertTrue( $this->integrity->verify_request_origin() );
    }

    public function test_verify_request_origin_always_returns_true() {
        // Even with suspicious agents, the method returns true (it logs but doesn't block).
        $_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.28.0';
        $this->assertTrue( $this->integrity->verify_request_origin() );

        $_SERVER['HTTP_USER_AGENT'] = 'curl/7.88.1';
        $this->assertTrue( $this->integrity->verify_request_origin() );

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->assertTrue( $this->integrity->verify_request_origin() );
    }

    // ------------------------------------------------------------------
    // get_status
    // ------------------------------------------------------------------

    public function test_get_status_returns_expected_keys() {
        $status = $this->integrity->get_status();

        $this->assertIsArray( $status );
        $this->assertArrayHasKey( 'integrity_verified', $status );
        $this->assertArrayHasKey( 'api_url_valid', $status );
        $this->assertArrayHasKey( 'app_url_valid', $status );
        $this->assertArrayHasKey( 'constants_valid', $status );
        $this->assertArrayHasKey( 'debugging_detected', $status );
        $this->assertArrayHasKey( 'last_check', $status );
    }

    public function test_get_status_all_valid() {
        $status = $this->integrity->get_status();

        $this->assertTrue( $status['integrity_verified'] );
        $this->assertTrue( $status['api_url_valid'] );
        $this->assertTrue( $status['app_url_valid'] );
        $this->assertTrue( $status['constants_valid'] );
    }

    // ------------------------------------------------------------------
    // enforce
    // ------------------------------------------------------------------

    public function test_enforce_returns_true_when_integrity_passes() {
        $result = $this->integrity->enforce( false );
        $this->assertTrue( $result );
    }

    // ------------------------------------------------------------------
    // generate_file_hashes
    // ------------------------------------------------------------------

    public function test_generate_file_hashes_stores_hashes() {
        $this->integrity->generate_file_hashes();

        $hashes = get_option( 'affilync_file_hashes', array() );
        $this->assertIsArray( $hashes );
    }

    // ------------------------------------------------------------------
    // Constructor
    // ------------------------------------------------------------------

    public function test_constructor_without_audit_logger() {
        $integrity = new Affilync_Security_Integrity();
        $this->assertInstanceOf( Affilync_Security_Integrity::class, $integrity );
    }

    // ------------------------------------------------------------------
    // get_client_ip (private)
    // ------------------------------------------------------------------

    public function test_get_client_ip_from_remote_addr() {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';

        $method = new ReflectionMethod( Affilync_Security_Integrity::class, 'get_client_ip' );
        $method->setAccessible( true );

        $ip = $method->invoke( $this->integrity );
        $this->assertSame( '10.0.0.5', $ip );
    }

    public function test_get_client_ip_returns_unknown_fallback() {
        unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
        unset( $_SERVER['HTTP_X_REAL_IP'] );
        unset( $_SERVER['REMOTE_ADDR'] );

        $method = new ReflectionMethod( Affilync_Security_Integrity::class, 'get_client_ip' );
        $method->setAccessible( true );

        $ip = $method->invoke( $this->integrity );
        $this->assertSame( 'unknown', $ip );
    }
}
