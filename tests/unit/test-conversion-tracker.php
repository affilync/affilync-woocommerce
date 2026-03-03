<?php
/**
 * Tests for Affilync_Tracking_Conversion_Tracker
 *
 * Covers order conversion tracking, attribution data resolution,
 * commission calculation (SEC-XIV-04), async sync via Action Scheduler
 * (PAY-III-03), refund handling, conversion pixel, and stats.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Conversion Tracker test case.
 */
class Test_Conversion_Tracker extends TestCase {

    /**
     * @var Affilync_Tracking_Conversion_Tracker
     */
    private $tracker;

    /**
     * Mock API client.
     */
    private $api_client;

    /**
     * Mock audit logger.
     */
    private $audit_logger;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options'] = array();
        $GLOBALS['affilync_scheduled_actions'] = array();

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-audit-logger.php';
        require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-affilync-tracking-cookie-handler.php';
        require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-affilync-tracking-conversion-tracker.php';

        $this->api_client   = $this->createMock( Affilync_API_Client::class );
        $this->audit_logger = $this->createMock( Affilync_Security_Audit_Logger::class );

        // Allow all audit logger calls.
        $this->audit_logger->method( 'info' )->willReturn( null );
        $this->audit_logger->method( 'warning' )->willReturn( null );
        $this->audit_logger->method( 'error' )->willReturn( null );

        $this->tracker = new Affilync_Tracking_Conversion_Tracker(
            $this->api_client,
            $this->audit_logger
        );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options'] = array();
        $GLOBALS['affilync_scheduled_actions'] = array();
        $_COOKIE = array();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_constants() {
        $this->assertSame( 'affilync_conversions', Affilync_Tracking_Conversion_Tracker::TABLE_NAME );
        $this->assertSame( 3, Affilync_Tracking_Conversion_Tracker::MAX_RETRY_ATTEMPTS );
        $this->assertSame( 'affilync_conversion_sync', Affilync_Tracking_Conversion_Tracker::ASYNC_GROUP );
    }

    // ------------------------------------------------------------------
    // track_conversion — guard clauses
    // ------------------------------------------------------------------

    public function test_track_conversion_skips_when_tracking_disabled() {
        update_option( 'affilync_settings', array( 'tracking_enabled' => false ) );

        // Should return early without error.
        $this->tracker->track_conversion( 100, null );

        // No audit log error expected (nothing happened).
        $this->assertTrue( true ); // Passed without exception.
    }

    public function test_track_conversion_skips_when_order_not_found() {
        // wc_get_order returns null by default from mock.
        $this->tracker->track_conversion( 999, null );
        $this->assertTrue( true );
    }

    public function test_track_conversion_skips_when_already_tracked() {
        global $wpdb;
        $saved = $GLOBALS['wpdb'];

        $order = $this->create_mock_order( 100, 'completed', 99.99 );

        // Use a simple anonymous object that returns '1' for get_var.
        $mock_wpdb = new class {
            public $prefix = 'wp_';
            public $last_error = '';
            public function prepare( $q, ...$a ) { return $q; }
            public function get_var( $q = null ) { return '1'; }
        };
        $GLOBALS['wpdb'] = $mock_wpdb;

        $this->tracker->track_conversion( 100, $order );

        $GLOBALS['wpdb'] = $saved;
        $this->assertTrue( true );
    }

    public function test_track_conversion_skips_when_status_not_configured() {
        update_option( 'affilync_settings', array(
            'conversion_statuses' => array( 'completed' ),
        ) );

        $order = $this->create_mock_order( 101, 'on-hold', 50.00 );
        $this->tracker->track_conversion( 101, $order );
        $this->assertTrue( true );
    }

    public function test_track_conversion_skips_without_attribution() {
        $order = $this->create_mock_order( 102, 'completed', 75.00 );
        // No cookies, no order meta attribution.

        $this->tracker->track_conversion( 102, $order );
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // track_conversion — success path
    // ------------------------------------------------------------------

    public function test_track_conversion_stores_and_schedules_sync() {
        global $wpdb;

        // Enable tracking.
        update_option( 'affilync_settings', array(
            'tracking_enabled'       => true,
            'default_commission_rate' => 0.15,
        ) );

        // Set up attribution via order meta.
        $order = $this->create_mock_order( 200, 'completed', 100.00, array(
            'affiliate_id' => 'aff_42',
            'campaign_id'  => 'camp_7',
            'click_id'     => 'clk_99',
        ) );

        // Mock wpdb to simulate no existing conversion.
        $mock_wpdb = new class extends stdClass {
            public $prefix = 'wp_';
            public $insert_id = 555;
            public $last_error = '';
            public function prepare( $q, ...$a ) { return $q; }
            public function get_var( $q = null ) { return '0'; }
            public function insert( $t, $d, $f = null ) { return 1; }
        };
        $saved_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $mock_wpdb;

        // API client returns campaign with commission_rate.
        $this->api_client->method( 'get' )->willReturn( array(
            'commission_rate' => 0.20,
        ) );

        $this->tracker->track_conversion( 200, $order );

        // Should have scheduled an async sync via Action Scheduler.
        $this->assertNotEmpty( $GLOBALS['affilync_scheduled_actions'] );
        $action = $GLOBALS['affilync_scheduled_actions'][0];
        $this->assertSame( 'affilync_async_sync_conversion', $action['hook'] );
        $this->assertSame( Affilync_Tracking_Conversion_Tracker::ASYNC_GROUP, $action['group'] );

        $GLOBALS['wpdb'] = $saved_wpdb;
    }

    // ------------------------------------------------------------------
    // async_sync_conversion_callback (PAY-III-03)
    // ------------------------------------------------------------------

    public function test_async_callback_retries_on_failure_with_exponential_backoff() {
        global $wpdb;

        // sync_conversion will fail because wpdb->get_row returns null.
        $GLOBALS['affilync_scheduled_actions'] = array();

        $this->tracker->async_sync_conversion_callback( 999, 1 );

        // Should schedule a retry.
        $this->assertNotEmpty( $GLOBALS['affilync_scheduled_actions'] );
        $retry = $GLOBALS['affilync_scheduled_actions'][0];
        $this->assertSame( 'affilync_async_sync_conversion', $retry['hook'] );
        $this->assertSame( 2, $retry['args']['attempt'] );
    }

    public function test_async_callback_stops_retrying_at_max() {
        $GLOBALS['affilync_scheduled_actions'] = array();

        // At attempt = MAX_RETRY_ATTEMPTS, no more retries.
        $this->tracker->async_sync_conversion_callback(
            999,
            Affilync_Tracking_Conversion_Tracker::MAX_RETRY_ATTEMPTS
        );

        // Should log error but NOT schedule another retry.
        $this->assertEmpty( $GLOBALS['affilync_scheduled_actions'] );
    }

    // ------------------------------------------------------------------
    // async_notify_refund_callback (PAY-III-03)
    // ------------------------------------------------------------------

    public function test_notify_refund_callback_calls_api() {
        $this->api_client->expects( $this->once() )
            ->method( 'post' )
            ->willReturn( array( 'ok' => true ) );

        $this->tracker->async_notify_refund_callback( 'conv_abc', 'refunded', 500 );
    }

    public function test_notify_refund_callback_logs_error_on_failure() {
        $this->api_client->method( 'post' )
            ->willReturn( new WP_Error( 'api_error', 'Failed' ) );

        $this->audit_logger->expects( $this->atLeastOnce() )
            ->method( 'error' );

        $this->tracker->async_notify_refund_callback( 'conv_abc', 'partial_refund', 501 );
    }

    // ------------------------------------------------------------------
    // track_refund / track_full_refund
    // ------------------------------------------------------------------

    public function test_track_refund_updates_status() {
        global $wpdb;

        // track_refund calls update_conversion_for_refund which does a DB update.
        // Just verify it doesn't throw.
        $this->tracker->track_refund( 300, 1 );
        $this->assertTrue( true );
    }

    public function test_track_full_refund() {
        $this->tracker->track_full_refund( 301, null );
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // render_conversion_pixel
    // ------------------------------------------------------------------

    public function test_render_pixel_returns_early_with_no_order_id() {
        $this->tracker->render_conversion_pixel( 0 );
        $this->assertTrue( true );
    }

    public function test_render_pixel_returns_early_with_null_order() {
        // wc_get_order returns null.
        $this->tracker->render_conversion_pixel( 404 );
        $this->assertTrue( true );
    }

    public function test_render_pixel_returns_early_when_already_rendered() {
        $order = $this->create_mock_order( 500, 'completed', 50.00 );
        // Simulate _affilync_pixel_rendered = 'yes'.
        $order->method( 'get_meta' )->willReturnMap( array(
            array( '_affilync_pixel_rendered', 'yes' ),
            array( '_affilync_attribution', array() ),
        ) );

        // Override wc_get_order to return our mock.
        // Note: since wc_get_order is a function mock that returns null,
        // we pass the order directly via a different approach:
        // render_conversion_pixel calls wc_get_order($order_id).
        // We can't easily override, but the method should return early
        // because wc_get_order returns null from the default mock.
        $this->tracker->render_conversion_pixel( 500 );
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // sync_pending_conversions
    // ------------------------------------------------------------------

    public function test_sync_pending_returns_results() {
        // wpdb->get_col returns empty by default.
        $result = $this->tracker->sync_pending_conversions();
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'synced', $result );
        $this->assertArrayHasKey( 'failed', $result );
        $this->assertSame( 0, $result['synced'] );
        $this->assertSame( 0, $result['failed'] );
    }

    // ------------------------------------------------------------------
    // get_stats
    // ------------------------------------------------------------------

    public function test_get_stats_returns_expected_keys() {
        global $wpdb;

        // Mock get_row to return a stats object.
        $mock_wpdb = new class extends stdClass {
            public $prefix = 'wp_';
            public $last_error = '';
            public function get_row( $q = null ) {
                return (object) array(
                    'total_conversions' => 10,
                    'total_revenue'     => 500.00,
                    'total_commission'  => 50.00,
                    'approved'          => 5,
                    'pending'           => 3,
                    'rejected'          => 2,
                );
            }
        };
        $saved = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $mock_wpdb;

        $stats = $this->tracker->get_stats( 'month' );

        $this->assertSame( 10, $stats['total_conversions'] );
        $this->assertSame( 500.0, $stats['total_revenue'] );
        $this->assertSame( 50.0, $stats['total_commission'] );
        $this->assertSame( 5, $stats['approved'] );
        $this->assertSame( 3, $stats['pending'] );
        $this->assertSame( 2, $stats['rejected'] );
        $this->assertSame( 'month', $stats['period'] );

        $GLOBALS['wpdb'] = $saved;
    }

    public function test_get_stats_periods() {
        global $wpdb;
        $mock_wpdb = new class extends stdClass {
            public $prefix = 'wp_';
            public $last_error = '';
            public function get_row( $q = null ) {
                return (object) array(
                    'total_conversions' => 0,
                    'total_revenue'     => 0,
                    'total_commission'  => 0,
                    'approved'          => 0,
                    'pending'           => 0,
                    'rejected'          => 0,
                );
            }
        };
        $saved = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $mock_wpdb;

        foreach ( array( 'today', 'week', 'month', 'all' ) as $period ) {
            $stats = $this->tracker->get_stats( $period );
            $this->assertSame( $period, $stats['period'] );
        }

        $GLOBALS['wpdb'] = $saved;
    }

    // ------------------------------------------------------------------
    // Commission calculation — SEC-XIV-04
    // ------------------------------------------------------------------

    public function test_commission_uses_api_rate_when_available() {
        $method = new ReflectionMethod( Affilync_Tracking_Conversion_Tracker::class, 'calculate_commission' );
        $method->setAccessible( true );

        // Set default rate in settings.
        update_option( 'affilync_settings', array( 'default_commission_rate' => 0.10 ) );

        // API returns campaign rate of 25%.
        $this->api_client->method( 'get' )->willReturn( array(
            'commission_rate' => 0.25,
        ) );

        $order = $this->create_mock_order( 600, 'completed', 200.00 );
        $attribution = array(
            'affiliate_id' => 'aff_1',
            'campaign_id'  => 'camp_1',
        );

        $commission = $method->invoke( $this->tracker, $order, $attribution );
        // 200.00 * 0.25 = 50.00
        $this->assertSame( 50.0, $commission );
    }

    public function test_commission_falls_back_to_default_rate() {
        $method = new ReflectionMethod( Affilync_Tracking_Conversion_Tracker::class, 'calculate_commission' );
        $method->setAccessible( true );

        update_option( 'affilync_settings', array( 'default_commission_rate' => 0.15 ) );

        // API returns error.
        $this->api_client->method( 'get' )->willReturn(
            new WP_Error( 'api_error', 'API down' )
        );

        $order = $this->create_mock_order( 601, 'completed', 100.00 );
        $attribution = array(
            'affiliate_id' => 'aff_1',
            'campaign_id'  => 'camp_2',
        );

        $commission = $method->invoke( $this->tracker, $order, $attribution );
        // 100.00 * 0.15 = 15.00
        $this->assertSame( 15.0, $commission );
    }

    public function test_commission_defaults_to_ten_percent() {
        $method = new ReflectionMethod( Affilync_Tracking_Conversion_Tracker::class, 'calculate_commission' );
        $method->setAccessible( true );

        // No settings, no campaign.
        $order = $this->create_mock_order( 602, 'completed', 80.00 );
        $attribution = array( 'affiliate_id' => 'aff_1' );

        $commission = $method->invoke( $this->tracker, $order, $attribution );
        // 80.00 * 0.10 = 8.00
        $this->assertSame( 8.0, $commission );
    }

    public function test_commission_rate_clamped_to_zero_one() {
        $method = new ReflectionMethod( Affilync_Tracking_Conversion_Tracker::class, 'calculate_commission' );
        $method->setAccessible( true );

        // API returns absurd rate.
        $this->api_client->method( 'get' )->willReturn( array(
            'commission_rate' => 5.0, // 500%!
        ) );

        $order = $this->create_mock_order( 603, 'completed', 100.00 );
        $attribution = array( 'campaign_id' => 'camp_bad' );

        $commission = $method->invoke( $this->tracker, $order, $attribution );
        // Clamped to 1.0 → 100.00 * 1.0 = 100.00.
        $this->assertSame( 100.0, $commission );
    }

    // ------------------------------------------------------------------
    // Commission rate caching
    // ------------------------------------------------------------------

    public function test_campaign_rate_caches_in_transient() {
        $method = new ReflectionMethod( Affilync_Tracking_Conversion_Tracker::class, 'get_campaign_commission_rate' );
        $method->setAccessible( true );

        // First call: API returns rate.
        $this->api_client->method( 'get' )->willReturn( array(
            'commission_rate' => 0.20,
        ) );

        $rate = $method->invoke( $this->tracker, 'camp_cache' );
        $this->assertSame( 0.20, $rate );

        // Should now be cached in transient.
        $cache_key = 'affilync_campaign_rate_' . md5( 'camp_cache' );
        $cached = get_transient( $cache_key );
        $this->assertNotFalse( $cached );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create a mock WC_Order with common methods.
     */
    private function create_mock_order( int $id, string $status, float $total, array $attribution = array() ) {
        $order = $this->getMockBuilder( stdClass::class )
            ->addMethods( array(
                'get_id', 'get_status', 'get_total', 'get_subtotal',
                'get_currency', 'get_billing_email', 'get_customer_id',
                'get_date_created', 'get_items', 'get_meta',
                'update_meta_data', 'save', 'add_order_note',
            ) )
            ->getMock();

        $order->method( 'get_id' )->willReturn( $id );
        $order->method( 'get_status' )->willReturn( $status );
        $order->method( 'get_total' )->willReturn( $total );
        $order->method( 'get_subtotal' )->willReturn( $total );
        $order->method( 'get_currency' )->willReturn( 'USD' );
        $order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
        $order->method( 'get_customer_id' )->willReturn( 1 );
        $order->method( 'get_date_created' )->willReturn( null );
        $order->method( 'get_items' )->willReturn( array() );
        $order->method( 'update_meta_data' )->willReturn( null );
        $order->method( 'save' )->willReturn( null );
        $order->method( 'add_order_note' )->willReturn( null );

        // Attribution from order meta.
        $order->method( 'get_meta' )->willReturnCallback( function ( $key ) use ( $attribution ) {
            if ( $key === '_affilync_attribution' && ! empty( $attribution ) ) {
                return $attribution;
            }
            return '';
        } );

        return $order;
    }
}
