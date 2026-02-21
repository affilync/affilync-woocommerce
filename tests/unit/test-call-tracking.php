<?php
/**
 * Unit tests for Call Tracking feature.
 *
 * Tests the call tracker orchestrator, DNI injector output,
 * call log CRUD, stats queries, and webhook routing.
 *
 * @package Affilync_WooCommerce
 */

// Ensure mocks are loaded.
require_once dirname( __DIR__ ) . '/bootstrap.php';

// Load the call tracking classes.
require_once AFFILYNC_PLUGIN_DIR . 'includes/calltracking/class-affilync-calltracking-call-log.php';
require_once AFFILYNC_PLUGIN_DIR . 'includes/calltracking/class-affilync-calltracking-stats.php';
require_once AFFILYNC_PLUGIN_DIR . 'includes/calltracking/class-affilync-calltracking-dni-injector.php';
require_once AFFILYNC_PLUGIN_DIR . 'includes/calltracking/class-affilync-calltracking-tracker.php';

// Define the AFFILYNC_CALL_TRACKER_URL constant for tests.
if ( ! defined( 'AFFILYNC_CALL_TRACKER_URL' ) ) {
    define( 'AFFILYNC_CALL_TRACKER_URL', 'https://call.affilync.com' );
}

// Mock esc_url_raw if not available.
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url, $protocols = null ) {
        return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
    }
}

// Mock esc_js if not available.
if ( ! function_exists( 'esc_js' ) ) {
    function esc_js( $text ) {
        return addslashes( $text );
    }
}

// Mock wp_doing_ajax if not available.
if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax() {
        return false;
    }
}

// Mock untrailingslashit if not available.
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) {
        return rtrim( $value, '/\\' );
    }
}

// Mock affilync() for webhook handler tests.
// Returns an object with call_tracker = null (call tracking disabled).
if ( ! function_exists( 'affilync' ) ) {
    function affilync() {
        static $instance;
        if ( ! $instance ) {
            $instance = new stdClass();
            $instance->call_tracker = null;
            $instance->subscription = null;
            $instance->api_client   = null;
            $instance->audit_logger = null;
        }
        return $instance;
    }
}

/**
 * Test class for Call Tracking feature.
 */
class Test_Call_Tracking extends WP_UnitTestCase {

    /**
     * Call log instance.
     *
     * @var Affilync_CallTracking_Call_Log
     */
    private $call_log;

    /**
     * Stats instance.
     *
     * @var Affilync_CallTracking_Stats
     */
    private $stats;

    /**
     * Set up test fixtures.
     */
    public function set_up() {
        parent::set_up();

        $this->call_log = new Affilync_CallTracking_Call_Log();
        $this->stats    = new Affilync_CallTracking_Stats();
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down() {
        parent::tear_down();
    }

    // ========================================================================
    // Call Log Tests
    // ========================================================================

    /**
     * Test store_call inserts a record and returns an ID.
     */
    public function test_store_call_returns_insert_id() {
        $data = array(
            'call_id'          => 'test-call-001',
            'tracking_number'  => '+18005551234',
            'caller_number'    => '+14155559876',
            'affiliate_id'     => 'aff-uuid-123',
            'campaign_id'      => 'camp-uuid-456',
            'duration'         => 120,
            'billable_seconds' => 115,
            'status'           => 'completed',
        );

        $id = $this->call_log->store_call( $data );

        // The mock wpdb returns a random insert_id > 0.
        $this->assertNotFalse( $id );
        $this->assertGreaterThan( 0, $id );
    }

    /**
     * Test store_call encodes call_data arrays to JSON.
     */
    public function test_store_call_encodes_array_call_data() {
        $call_data = array(
            'hangup_cause' => 'NORMAL_CLEARING',
            'utm_source'   => 'google',
        );

        $data = array(
            'call_id'   => 'test-call-002',
            'call_data' => $call_data,
        );

        $id = $this->call_log->store_call( $data );
        $this->assertNotFalse( $id );
    }

    /**
     * Test store_call applies defaults for missing fields.
     */
    public function test_store_call_applies_defaults() {
        $data = array(
            'call_id' => 'test-call-003',
        );

        $id = $this->call_log->store_call( $data );
        $this->assertNotFalse( $id );
    }

    /**
     * Test is_duplicate returns false when no matching call_id exists.
     */
    public function test_is_duplicate_returns_false_for_new_call() {
        // Mock wpdb returns null for get_var, which becomes 0.
        $result = $this->call_log->is_duplicate( 'nonexistent-call-id' );
        $this->assertFalse( $result );
    }

    /**
     * Test update_call with string and integer fields.
     */
    public function test_update_call_with_valid_data() {
        $result = $this->call_log->update_call( 1, array(
            'status'        => 'qualified',
            'duration'      => 200,
            'recording_url' => 'https://s3.example.com/recording.wav',
        ) );

        // Mock wpdb update returns 1.
        $this->assertEquals( 1, $result );
    }

    /**
     * Test update_call returns false with empty data.
     */
    public function test_update_call_returns_false_for_empty_data() {
        $result = $this->call_log->update_call( 1, array() );
        $this->assertFalse( $result );
    }

    /**
     * Test mark_synced sets synced_to_api and api_call_id.
     */
    public function test_mark_synced_updates_fields() {
        $result = $this->call_log->mark_synced( 1, 'api-call-id-789' );

        // update_call delegates to wpdb->update which returns 1.
        $this->assertEquals( 1, $result );
    }

    /**
     * Test get_calls returns array with calls and total keys.
     */
    public function test_get_calls_returns_expected_structure() {
        $result = $this->call_log->get_calls();

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'calls', $result );
        $this->assertArrayHasKey( 'total', $result );
    }

    /**
     * Test get_calls respects pagination parameters.
     */
    public function test_get_calls_accepts_pagination() {
        $result = $this->call_log->get_calls( array(
            'per_page' => 10,
            'page'     => 2,
        ) );

        $this->assertIsArray( $result );
    }

    /**
     * Test get_calls respects filter parameters.
     */
    public function test_get_calls_accepts_filters() {
        $result = $this->call_log->get_calls( array(
            'affiliate_id' => 'aff-123',
            'status'       => 'completed',
            'date_from'    => '2026-01-01',
            'date_to'      => '2026-02-28',
        ) );

        $this->assertIsArray( $result );
    }

    /**
     * Test get_calls rejects invalid orderby values.
     */
    public function test_get_calls_sanitizes_orderby() {
        $result = $this->call_log->get_calls( array(
            'orderby' => 'DROP TABLE; --',
            'order'   => 'INVALID',
        ) );

        // Should not throw; falls back to defaults.
        $this->assertIsArray( $result );
    }

    // ========================================================================
    // Stats Tests
    // ========================================================================

    /**
     * Test get_stats returns expected keys.
     */
    public function test_get_stats_returns_expected_keys() {
        $stats = $this->stats->get_stats( 'month' );

        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'total_calls', $stats );
        $this->assertArrayHasKey( 'total_duration', $stats );
        $this->assertArrayHasKey( 'avg_duration', $stats );
        $this->assertArrayHasKey( 'qualified_calls', $stats );
        $this->assertArrayHasKey( 'unique_callers', $stats );
    }

    /**
     * Test get_stats returns zeros when table doesn't exist.
     */
    public function test_get_stats_returns_zeros_when_no_table() {
        $stats = $this->stats->get_stats( 'all' );

        $this->assertEquals( 0, $stats['total_calls'] );
        $this->assertEquals( 0, $stats['total_duration'] );
        $this->assertEquals( 0, $stats['avg_duration'] );
        $this->assertEquals( 0, $stats['qualified_calls'] );
        $this->assertEquals( 0, $stats['unique_callers'] );
    }

    /**
     * Test get_stats accepts all valid period values.
     */
    public function test_get_stats_accepts_all_periods() {
        foreach ( array( 'today', 'week', 'month', 'all' ) as $period ) {
            $stats = $this->stats->get_stats( $period );
            $this->assertIsArray( $stats, "Failed for period: {$period}" );
        }
    }

    // ========================================================================
    // DNI Injector Tests
    // ========================================================================

    /**
     * Test inline DNI script output contains expected elements.
     */
    public function test_inline_dni_script_output() {
        $injector = new Affilync_CallTracking_DNI_Injector(
            'https://call.affilync.com',
            '[data-affilync-phone]',
            false
        );

        ob_start();
        $injector->inject_dni_script();
        $output = ob_get_clean();

        // Should contain inline script with DNI API URL.
        $this->assertStringContainsString( 'affilync_tracking', $output );
        $this->assertStringContainsString( '/api/v1/dni/dni', $output );
        $this->assertStringContainsString( 'affiliate_id', $output );
        $this->assertStringContainsString( '[data-affilync-phone]', $output );
    }

    /**
     * Test widget DNI script output loads external script.
     */
    public function test_widget_dni_script_output() {
        $injector = new Affilync_CallTracking_DNI_Injector(
            'https://call.affilync.com',
            'a[href^="tel:"]',
            true
        );

        ob_start();
        $injector->inject_dni_script();
        $output = ob_get_clean();

        // Should contain external script tag with widget.js.
        $this->assertStringContainsString( 'widget.js', $output );
        $this->assertStringContainsString( 'data-selector', $output );
        $this->assertStringContainsString( 'data-cookie-name="affilync_tracking"', $output );
    }

    // ========================================================================
    // Billing Plan Tests
    // ========================================================================

    /**
     * Test free plan does not include call tracking.
     */
    public function test_free_plan_has_no_call_tracking() {
        $plans = Affilync_Billing_Subscription::PLANS;

        $this->assertFalse( $plans['free']['call_tracking'] );
        $this->assertFalse( $plans['free']['call_tracking_dni'] );
    }

    /**
     * Test starter plan includes basic call tracking.
     */
    public function test_starter_plan_has_basic_call_tracking() {
        $plans = Affilync_Billing_Subscription::PLANS;

        $this->assertTrue( $plans['starter']['call_tracking'] );
        $this->assertFalse( $plans['starter']['call_tracking_dni'] );
    }

    /**
     * Test pro plan includes full DNI widget.
     */
    public function test_pro_plan_has_dni_widget() {
        $plans = Affilync_Billing_Subscription::PLANS;

        $this->assertTrue( $plans['pro']['call_tracking'] );
        $this->assertTrue( $plans['pro']['call_tracking_dni'] );
    }

    /**
     * Test enterprise plan includes full DNI widget.
     */
    public function test_enterprise_plan_has_dni_widget() {
        $plans = Affilync_Billing_Subscription::PLANS;

        $this->assertTrue( $plans['enterprise']['call_tracking'] );
        $this->assertTrue( $plans['enterprise']['call_tracking_dni'] );
    }

    // ========================================================================
    // Webhook Routing Tests
    // ========================================================================

    /**
     * Test call.completed webhook handler routes correctly.
     *
     * We verify that the route_webhook method has call.completed in its handlers.
     */
    public function test_webhook_handler_has_call_completed_route() {
        // Load the webhook handler class.
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-hmac-validator.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-audit-logger.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-webhook-handler.php';

        $hmac_validator = $this->createMock( Affilync_Security_HMAC_Validator::class );
        $audit_logger   = $this->createMock( Affilync_Security_Audit_Logger::class );

        $handler = new Affilync_API_Webhook_Handler( $hmac_validator, $audit_logger );

        // Use reflection to access the private route_webhook method.
        $reflection = new ReflectionClass( $handler );
        $method     = $reflection->getMethod( 'route_webhook' );
        $method->setAccessible( true );

        // call.completed should not return 'unhandled' status.
        $result = $method->invoke( $handler, 'call.completed', array(
            'call_id' => 'test-call-wh-001',
        ) );

        // Without affilync()->call_tracker, it should return 'skipped'.
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'status', $result );
        $this->assertNotEquals( 'unhandled', $result['status'] );
    }

    /**
     * Test call.recording webhook handler routes correctly.
     */
    public function test_webhook_handler_has_call_recording_route() {
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-hmac-validator.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-audit-logger.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-webhook-handler.php';

        $hmac_validator = $this->createMock( Affilync_Security_HMAC_Validator::class );
        $audit_logger   = $this->createMock( Affilync_Security_Audit_Logger::class );

        $handler = new Affilync_API_Webhook_Handler( $hmac_validator, $audit_logger );

        $reflection = new ReflectionClass( $handler );
        $method     = $reflection->getMethod( 'route_webhook' );
        $method->setAccessible( true );

        $result = $method->invoke( $handler, 'call.recording', array(
            'call_id'       => 'test-call-wh-002',
            'recording_url' => 'https://s3.example.com/recording.wav',
        ) );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'status', $result );
        $this->assertNotEquals( 'unhandled', $result['status'] );
    }

    /**
     * Test unknown event type returns unhandled.
     */
    public function test_webhook_handler_returns_unhandled_for_unknown_event() {
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-hmac-validator.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/security/class-affilync-security-audit-logger.php';
        require_once AFFILYNC_PLUGIN_DIR . 'includes/api/class-affilync-api-webhook-handler.php';

        $hmac_validator = $this->createMock( Affilync_Security_HMAC_Validator::class );
        $audit_logger   = $this->createMock( Affilync_Security_Audit_Logger::class );

        $handler = new Affilync_API_Webhook_Handler( $hmac_validator, $audit_logger );

        $reflection = new ReflectionClass( $handler );
        $method     = $reflection->getMethod( 'route_webhook' );
        $method->setAccessible( true );

        $result = $method->invoke( $handler, 'totally.unknown.event', array() );

        $this->assertEquals( 'unhandled', $result['status'] );
    }

    // ========================================================================
    // Table & Constant Tests
    // ========================================================================

    /**
     * Test AFFILYNC_CALL_TRACKER_URL constant is defined.
     */
    public function test_call_tracker_url_constant_defined() {
        $this->assertTrue( defined( 'AFFILYNC_CALL_TRACKER_URL' ) );
        $this->assertEquals( 'https://call.affilync.com', AFFILYNC_CALL_TRACKER_URL );
    }

    /**
     * Test call log table name constant.
     */
    public function test_call_log_table_name() {
        $this->assertEquals( 'affilync_call_log', Affilync_CallTracking_Call_Log::TABLE_NAME );
    }

    /**
     * Test qualified duration threshold is 90 seconds.
     */
    public function test_qualified_duration_threshold() {
        $this->assertEquals( 90, Affilync_CallTracking_Stats::QUALIFIED_DURATION );
    }
}
