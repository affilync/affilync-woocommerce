<?php
/**
 * Tests for Affilync_Helper_Logger
 *
 * Covers log level constants, debug mode detection, sensitive data sanitization,
 * exception logging, API request/response logging, and WC logger fallback.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Helper Logger test case.
 */
class Test_Helper_Logger extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        require_once dirname( __DIR__, 2 ) . '/includes/helpers/class-affilync-helper-logger.php';
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_source_constant() {
        $this->assertSame( 'affilync', Affilync_Helper_Logger::SOURCE );
    }

    public function test_level_constants() {
        $this->assertSame( 'debug', Affilync_Helper_Logger::LEVEL_DEBUG );
        $this->assertSame( 'info', Affilync_Helper_Logger::LEVEL_INFO );
        $this->assertSame( 'notice', Affilync_Helper_Logger::LEVEL_NOTICE );
        $this->assertSame( 'warning', Affilync_Helper_Logger::LEVEL_WARNING );
        $this->assertSame( 'error', Affilync_Helper_Logger::LEVEL_ERROR );
        $this->assertSame( 'critical', Affilync_Helper_Logger::LEVEL_CRITICAL );
        $this->assertSame( 'alert', Affilync_Helper_Logger::LEVEL_ALERT );
        $this->assertSame( 'emergency', Affilync_Helper_Logger::LEVEL_EMERGENCY );
    }

    // ------------------------------------------------------------------
    // Logging helpers (smoke test — no WC_Logger available)
    // ------------------------------------------------------------------

    public function test_log_does_not_throw() {
        Affilync_Helper_Logger::log( 'test message', 'warning' );
        $this->assertTrue( true );
    }

    public function test_debug_does_not_throw() {
        Affilync_Helper_Logger::debug( 'debug message' );
        $this->assertTrue( true );
    }

    public function test_info_does_not_throw() {
        Affilync_Helper_Logger::info( 'info message' );
        $this->assertTrue( true );
    }

    public function test_notice_does_not_throw() {
        Affilync_Helper_Logger::notice( 'notice message' );
        $this->assertTrue( true );
    }

    public function test_warning_does_not_throw() {
        Affilync_Helper_Logger::warning( 'warning message' );
        $this->assertTrue( true );
    }

    public function test_error_does_not_throw() {
        Affilync_Helper_Logger::error( 'error message' );
        $this->assertTrue( true );
    }

    public function test_critical_does_not_throw() {
        Affilync_Helper_Logger::critical( 'critical message' );
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // exception logging
    // ------------------------------------------------------------------

    public function test_exception_logging() {
        $exception = new RuntimeException( 'Test error', 42 );
        Affilync_Helper_Logger::exception( $exception, 'During test' );
        $this->assertTrue( true );
    }

    public function test_exception_logging_without_context() {
        $exception = new InvalidArgumentException( 'Bad input' );
        Affilync_Helper_Logger::exception( $exception );
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // is_debug_enabled
    // ------------------------------------------------------------------

    public function test_is_debug_enabled_returns_bool() {
        $result = Affilync_Helper_Logger::is_debug_enabled();
        $this->assertIsBool( $result );
    }

    // ------------------------------------------------------------------
    // get_log_file
    // ------------------------------------------------------------------

    public function test_get_log_file_returns_null_without_wc() {
        $this->assertNull( Affilync_Helper_Logger::get_log_file() );
    }

    // ------------------------------------------------------------------
    // clear_log
    // ------------------------------------------------------------------

    public function test_clear_log_returns_false_without_wc() {
        $this->assertFalse( Affilync_Helper_Logger::clear_log() );
    }

    // ------------------------------------------------------------------
    // sanitize_log_data (private)
    // ------------------------------------------------------------------

    public function test_sanitize_redacts_sensitive_keys() {
        $method = new ReflectionMethod( Affilync_Helper_Logger::class, 'sanitize_log_data' );
        $method->setAccessible( true );

        $data = array(
            'username'     => 'john',
            'access_token' => 'secret123',
            'password'     => 'hunter2',
            'api_key'      => 'sk_live_xxx',
            'cookie'       => 'session=abc',
            'normal_field' => 'visible',
        );

        $sanitized = $method->invoke( null, $data );

        $this->assertSame( 'john', $sanitized['username'] );
        $this->assertSame( '***REDACTED***', $sanitized['access_token'] );
        $this->assertSame( '***REDACTED***', $sanitized['password'] );
        $this->assertSame( '***REDACTED***', $sanitized['api_key'] );
        $this->assertSame( '***REDACTED***', $sanitized['cookie'] );
        $this->assertSame( 'visible', $sanitized['normal_field'] );
    }

    public function test_sanitize_handles_nested_arrays() {
        $method = new ReflectionMethod( Affilync_Helper_Logger::class, 'sanitize_log_data' );
        $method->setAccessible( true );

        $data = array(
            'headers' => array(
                'authorization' => 'Bearer xxx',
                'content-type'  => 'application/json',
            ),
        );

        $sanitized = $method->invoke( null, $data );

        $this->assertSame( '***REDACTED***', $sanitized['headers']['authorization'] );
        $this->assertSame( 'application/json', $sanitized['headers']['content-type'] );
    }

    public function test_sanitize_non_array_passes_through() {
        $method = new ReflectionMethod( Affilync_Helper_Logger::class, 'sanitize_log_data' );
        $method->setAccessible( true );

        $this->assertSame( 'just a string', $method->invoke( null, 'just a string' ) );
        $this->assertSame( 42, $method->invoke( null, 42 ) );
    }

    // ------------------------------------------------------------------
    // log_api_request / log_api_response
    // ------------------------------------------------------------------

    public function test_log_api_request_does_not_throw() {
        Affilync_Helper_Logger::log_api_request( 'POST', '/api/test', array( 'key' => 'value' ) );
        $this->assertTrue( true );
    }

    public function test_log_api_response_does_not_throw() {
        Affilync_Helper_Logger::log_api_response( 'GET', '/api/test', 200, array( 'ok' => true ) );
        $this->assertTrue( true );
    }
}
