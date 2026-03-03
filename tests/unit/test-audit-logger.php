<?php
/**
 * Tests for Affilync_Security_Audit_Logger
 *
 * Covers event logging at all severity levels, IP detection,
 * log retrieval with filters, statistics, cleanup/retention,
 * CSV export, and error_log fallback for critical events.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Audit Logger test case.
 */
class Test_Audit_Logger extends TestCase {

    /**
     * @var Affilync_Security_Audit_Logger
     */
    private $logger;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options'] = array();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Agent';
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-audit-logger.php';

        $this->logger = new Affilync_Security_Audit_Logger();
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options'] = array();
        unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
        unset( $_SERVER['HTTP_X_REAL_IP'] );
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_severity_constants() {
        $this->assertSame( 'info', Affilync_Security_Audit_Logger::SEVERITY_INFO );
        $this->assertSame( 'warning', Affilync_Security_Audit_Logger::SEVERITY_WARNING );
        $this->assertSame( 'error', Affilync_Security_Audit_Logger::SEVERITY_ERROR );
        $this->assertSame( 'critical', Affilync_Security_Audit_Logger::SEVERITY_CRITICAL );
    }

    public function test_event_type_constants() {
        $this->assertSame( 'auth_success', Affilync_Security_Audit_Logger::EVENT_AUTH_SUCCESS );
        $this->assertSame( 'auth_failure', Affilync_Security_Audit_Logger::EVENT_AUTH_FAILURE );
        $this->assertSame( 'oauth_initiated', Affilync_Security_Audit_Logger::EVENT_OAUTH_INITIATED );
        $this->assertSame( 'conversion_tracked', Affilync_Security_Audit_Logger::EVENT_CONVERSION_TRACKED );
        $this->assertSame( 'conversion_failed', Affilync_Security_Audit_Logger::EVENT_CONVERSION_FAILED );
        $this->assertSame( 'api_error', Affilync_Security_Audit_Logger::EVENT_API_ERROR );
        $this->assertSame( 'settings_changed', Affilync_Security_Audit_Logger::EVENT_SETTINGS_CHANGED );
    }

    public function test_retention_days() {
        $this->assertSame( 90, Affilync_Security_Audit_Logger::RETENTION_DAYS );
    }

    public function test_table_name() {
        $this->assertSame( 'affilync_audit_log', Affilync_Security_Audit_Logger::TABLE_NAME );
    }

    // ------------------------------------------------------------------
    // Logging — severity helpers
    // ------------------------------------------------------------------

    public function test_info_returns_bool() {
        $result = $this->logger->info( 'test_event', array( 'key' => 'value' ) );
        $this->assertIsBool( $result );
    }

    public function test_warning_returns_bool() {
        $result = $this->logger->warning( 'test_warning', array( 'msg' => 'warn' ) );
        $this->assertIsBool( $result );
    }

    public function test_error_returns_bool() {
        $result = $this->logger->error( 'test_error', array( 'msg' => 'err' ) );
        $this->assertIsBool( $result );
    }

    public function test_critical_returns_bool() {
        $result = $this->logger->critical( 'test_critical', array( 'msg' => 'crit' ) );
        $this->assertIsBool( $result );
    }

    public function test_log_returns_true_on_insert() {
        // Default mock wpdb->insert returns 1, so log() should return true.
        $result = $this->logger->log( 'test_log', array(), Affilync_Security_Audit_Logger::SEVERITY_INFO );
        $this->assertTrue( $result );
    }

    // ------------------------------------------------------------------
    // IP detection
    // ------------------------------------------------------------------

    public function test_ip_from_cf_connecting_ip() {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.42';

        $method = new ReflectionMethod( Affilync_Security_Audit_Logger::class, 'get_client_ip' );
        $method->setAccessible( true );

        $ip = $method->invoke( $this->logger );
        $this->assertSame( '203.0.113.42', $ip );
    }

    public function test_ip_from_x_forwarded_for() {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10, 10.0.0.1';

        $method = new ReflectionMethod( Affilync_Security_Audit_Logger::class, 'get_client_ip' );
        $method->setAccessible( true );

        $ip = $method->invoke( $this->logger );
        $this->assertSame( '198.51.100.10', $ip );
    }

    public function test_ip_from_x_real_ip() {
        $_SERVER['HTTP_X_REAL_IP'] = '192.0.2.5';

        $method = new ReflectionMethod( Affilync_Security_Audit_Logger::class, 'get_client_ip' );
        $method->setAccessible( true );

        $ip = $method->invoke( $this->logger );
        $this->assertSame( '192.0.2.5', $ip );
    }

    public function test_ip_from_remote_addr() {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $method = new ReflectionMethod( Affilync_Security_Audit_Logger::class, 'get_client_ip' );
        $method->setAccessible( true );

        $ip = $method->invoke( $this->logger );
        $this->assertSame( '10.0.0.1', $ip );
    }

    public function test_ip_returns_fallback_when_no_headers() {
        unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
        unset( $_SERVER['HTTP_X_REAL_IP'] );
        unset( $_SERVER['REMOTE_ADDR'] );

        $method = new ReflectionMethod( Affilync_Security_Audit_Logger::class, 'get_client_ip' );
        $method->setAccessible( true );

        $ip = $method->invoke( $this->logger );
        $this->assertSame( '0.0.0.0', $ip );
    }

    public function test_ip_skips_invalid_ip() {
        unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not_an_ip';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.2';

        $method = new ReflectionMethod( Affilync_Security_Audit_Logger::class, 'get_client_ip' );
        $method->setAccessible( true );

        $ip = $method->invoke( $this->logger );
        $this->assertSame( '127.0.0.2', $ip );
    }

    // ------------------------------------------------------------------
    // get_logs
    // ------------------------------------------------------------------

    public function test_get_logs_returns_array() {
        $logs = $this->logger->get_logs();
        $this->assertIsArray( $logs );
    }

    public function test_get_logs_with_filters() {
        $logs = $this->logger->get_logs( array(
            'event_type' => 'auth_success',
            'severity'   => 'info',
            'user_id'    => 1,
            'ip_address' => '127.0.0.1',
            'limit'      => 10,
            'offset'     => 0,
        ) );
        $this->assertIsArray( $logs );
    }

    // ------------------------------------------------------------------
    // get_count
    // ------------------------------------------------------------------

    public function test_get_count_returns_int() {
        $count = $this->logger->get_count();
        $this->assertIsInt( $count );
    }

    public function test_get_count_with_filters() {
        $count = $this->logger->get_count( 'auth_success', 'info' );
        $this->assertIsInt( $count );
    }

    // ------------------------------------------------------------------
    // get_stats
    // ------------------------------------------------------------------

    public function test_get_stats_returns_expected_keys() {
        $stats = $this->logger->get_stats( 7 );
        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'by_type', $stats );
        $this->assertArrayHasKey( 'by_severity', $stats );
        $this->assertArrayHasKey( 'period_days', $stats );
        $this->assertSame( 7, $stats['period_days'] );
    }

    // ------------------------------------------------------------------
    // cleanup
    // ------------------------------------------------------------------

    public function test_cleanup_returns_int() {
        $deleted = $this->logger->cleanup( 30 );
        $this->assertIsInt( $deleted );
    }

    public function test_cleanup_uses_default_retention() {
        $deleted = $this->logger->cleanup();
        $this->assertIsInt( $deleted );
    }

    // ------------------------------------------------------------------
    // clear_all
    // ------------------------------------------------------------------

    public function test_clear_all_returns_bool() {
        $result = $this->logger->clear_all();
        $this->assertIsBool( $result );
    }

    // ------------------------------------------------------------------
    // export_csv
    // ------------------------------------------------------------------

    public function test_export_csv_returns_string() {
        $csv = $this->logger->export_csv();
        $this->assertIsString( $csv );
    }

    public function test_export_csv_contains_header_row() {
        $csv = $this->logger->export_csv();
        $this->assertStringContainsString( 'Event Type', $csv );
        $this->assertStringContainsString( 'Severity', $csv );
        $this->assertStringContainsString( 'IP Address', $csv );
    }

    // ------------------------------------------------------------------
    // error_log fallback
    // ------------------------------------------------------------------

    public function test_log_to_error_log_for_error_severity() {
        // error() and critical() should trigger log_to_error_log internally.
        // Just verify the methods don't throw.
        $this->logger->error( 'test_error_log', array( 'test' => true ) );
        $this->logger->critical( 'test_critical_log', array( 'test' => true ) );
        $this->assertTrue( true );
    }
}
