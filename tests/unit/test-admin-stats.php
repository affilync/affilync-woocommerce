<?php
/**
 * Tests for Affilync_Admin_Stats
 *
 * Covers stats summary calculation, chart data generation,
 * percentage change calculation, CSV generation, and tab registration.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Admin Stats test case.
 */
class Test_Admin_Stats extends TestCase {

    /**
     * @var Affilync_Admin_Stats
     */
    private $stats;

    /**
     * Original wpdb.
     */
    private $original_wpdb;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options'] = array();

        // Override wpdb so get_row returns stdClass with zero values
        // (the real code accesses ->total_conversions etc.).
        $this->original_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class extends Affilync_Mock_Wpdb {
            public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
                $row = new \stdClass();
                $row->total_conversions = 0;
                $row->total_revenue     = 0;
                $row->total_commission  = 0;
                $row->average_order     = 0;
                return $row;
            }
        };

        require_once dirname( __DIR__, 2 ) . '/includes/admin/class-affilync-admin-stats.php';

        $this->stats = new Affilync_Admin_Stats();
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options'] = array();
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // add_stats_tab
    // ------------------------------------------------------------------

    public function test_add_stats_tab() {
        $tabs = $this->stats->add_stats_tab( array( 'general' => 'General' ) );
        $this->assertArrayHasKey( 'stats', $tabs );
        $this->assertSame( 'Analytics', $tabs['stats'] );
    }

    public function test_add_stats_tab_preserves_existing() {
        $tabs = $this->stats->add_stats_tab( array( 'general' => 'General', 'billing' => 'Billing' ) );
        $this->assertArrayHasKey( 'general', $tabs );
        $this->assertArrayHasKey( 'billing', $tabs );
        $this->assertArrayHasKey( 'stats', $tabs );
    }

    // ------------------------------------------------------------------
    // get_stats_summary
    // ------------------------------------------------------------------

    public function test_get_stats_summary_returns_expected_keys() {
        $summary = $this->stats->get_stats_summary( '30days' );

        $this->assertIsArray( $summary );
        $this->assertArrayHasKey( 'total_conversions', $summary );
        $this->assertArrayHasKey( 'total_revenue', $summary );
        $this->assertArrayHasKey( 'total_commission', $summary );
        $this->assertArrayHasKey( 'average_order', $summary );
        $this->assertArrayHasKey( 'conversion_change', $summary );
        $this->assertArrayHasKey( 'revenue_change', $summary );
        $this->assertArrayHasKey( 'commission_change', $summary );
        $this->assertArrayHasKey( 'top_affiliates', $summary );
        $this->assertArrayHasKey( 'period', $summary );
        $this->assertArrayHasKey( 'start_date', $summary );
        $this->assertArrayHasKey( 'end_date', $summary );
    }

    public function test_get_stats_summary_30days_period() {
        $summary = $this->stats->get_stats_summary( '30days' );
        $this->assertSame( '30days', $summary['period'] );
    }

    public function test_get_stats_summary_7days_period() {
        $summary = $this->stats->get_stats_summary( '7days' );
        $this->assertSame( '7days', $summary['period'] );
    }

    public function test_get_stats_summary_90days_period() {
        $summary = $this->stats->get_stats_summary( '90days' );
        $this->assertSame( '90days', $summary['period'] );
    }

    public function test_get_stats_summary_year_period() {
        $summary = $this->stats->get_stats_summary( 'year' );
        $this->assertSame( 'year', $summary['period'] );
    }

    public function test_get_stats_summary_defaults_to_zeros() {
        $summary = $this->stats->get_stats_summary();
        // Mock wpdb returns null for get_row, so values should be 0.
        $this->assertSame( 0, $summary['total_conversions'] );
        $this->assertSame( 0.0, $summary['total_revenue'] );
    }

    // ------------------------------------------------------------------
    // get_chart_data
    // ------------------------------------------------------------------

    public function test_get_chart_data_returns_expected_keys() {
        $data = $this->stats->get_chart_data( '30days', 'conversions' );

        $this->assertIsArray( $data );
        $this->assertArrayHasKey( 'labels', $data );
        $this->assertArrayHasKey( 'values', $data );
        $this->assertIsArray( $data['labels'] );
        $this->assertIsArray( $data['values'] );
    }

    public function test_get_chart_data_revenue_type() {
        $data = $this->stats->get_chart_data( '30days', 'revenue' );
        $this->assertIsArray( $data );
    }

    public function test_get_chart_data_commission_type() {
        $data = $this->stats->get_chart_data( '30days', 'commission' );
        $this->assertIsArray( $data );
    }

    public function test_get_chart_data_7days_period() {
        $data = $this->stats->get_chart_data( '7days', 'conversions' );
        $this->assertIsArray( $data );
    }

    public function test_get_chart_data_90days_period() {
        $data = $this->stats->get_chart_data( '90days', 'conversions' );
        $this->assertIsArray( $data );
    }

    public function test_get_chart_data_year_period() {
        $data = $this->stats->get_chart_data( 'year', 'conversions' );
        $this->assertIsArray( $data );
    }

    // ------------------------------------------------------------------
    // calculate_change (private)
    // ------------------------------------------------------------------

    public function test_calculate_change_positive() {
        $method = new ReflectionMethod( Affilync_Admin_Stats::class, 'calculate_change' );
        $method->setAccessible( true );

        $change = $method->invoke( $this->stats, 200, 100 );
        $this->assertEquals( 100, $change );
    }

    public function test_calculate_change_negative() {
        $method = new ReflectionMethod( Affilync_Admin_Stats::class, 'calculate_change' );
        $method->setAccessible( true );

        $change = $method->invoke( $this->stats, 50, 100 );
        $this->assertEquals( -50, $change );
    }

    public function test_calculate_change_zero_previous() {
        $method = new ReflectionMethod( Affilync_Admin_Stats::class, 'calculate_change' );
        $method->setAccessible( true );

        // With positive current, should return 100%.
        $change = $method->invoke( $this->stats, 10, 0 );
        $this->assertEquals( 100, $change );
    }

    public function test_calculate_change_zero_both() {
        $method = new ReflectionMethod( Affilync_Admin_Stats::class, 'calculate_change' );
        $method->setAccessible( true );

        $change = $method->invoke( $this->stats, 0, 0 );
        $this->assertEquals( 0, $change );
    }

    public function test_calculate_change_no_change() {
        $method = new ReflectionMethod( Affilync_Admin_Stats::class, 'calculate_change' );
        $method->setAccessible( true );

        $change = $method->invoke( $this->stats, 100, 100 );
        $this->assertEquals( 0, $change );
    }

    // ------------------------------------------------------------------
    // generate_csv (private)
    // ------------------------------------------------------------------

    public function test_generate_csv_empty_data() {
        $method = new ReflectionMethod( Affilync_Admin_Stats::class, 'generate_csv' );
        $method->setAccessible( true );

        $csv = $method->invoke( $this->stats, array() );
        $this->assertSame( '', $csv );
    }

    public function test_generate_csv_with_data() {
        $method = new ReflectionMethod( Affilync_Admin_Stats::class, 'generate_csv' );
        $method->setAccessible( true );

        $data = array(
            array( 'order_id' => 1, 'amount' => '99.99', 'status' => 'completed' ),
            array( 'order_id' => 2, 'amount' => '50.00', 'status' => 'pending' ),
        );

        $csv = $method->invoke( $this->stats, $data );

        $this->assertStringContainsString( 'order_id', $csv );
        $this->assertStringContainsString( 'amount', $csv );
        $this->assertStringContainsString( '99.99', $csv );
        $this->assertStringContainsString( '50.00', $csv );
    }
}
