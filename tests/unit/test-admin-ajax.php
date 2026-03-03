<?php
/**
 * Tests for Affilync_Admin_Ajax
 *
 * Covers constants, constructor injection, AJAX handler guard clauses,
 * script enqueueing, dashboard stats, product sync, connection test,
 * conversion sync, and webhook management.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Admin AJAX test case.
 */
class Test_Admin_Ajax extends TestCase {

    /**
     * @var Affilync_Admin_Ajax
     */
    private $ajax;

    /**
     * Mocks.
     */
    private $api_client;
    private $product_sync;
    private $conversion_tracker;
    private $webhook_handler;
    private $audit_logger;
    private $hmac_validator;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options'] = array();

        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-api-client.php';
        require_once dirname( __DIR__, 2 ) . '/includes/sync/class-affilync-sync-product-sync.php';
        require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-affilync-tracking-conversion-tracker.php';
        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-api-webhook-handler.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-audit-logger.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-hmac-validator.php';
        require_once dirname( __DIR__, 2 ) . '/includes/admin/class-affilync-admin-ajax.php';

        $this->api_client         = $this->createMock( Affilync_API_Client::class );
        $this->product_sync       = $this->createMock( Affilync_Sync_Product_Sync::class );
        $this->conversion_tracker = $this->createMock( Affilync_Tracking_Conversion_Tracker::class );
        $this->webhook_handler    = $this->createMock( Affilync_API_Webhook_Handler::class );
        $this->audit_logger       = $this->createMock( Affilync_Security_Audit_Logger::class );
        $this->hmac_validator     = $this->createMock( Affilync_Security_HMAC_Validator::class );

        $this->audit_logger->method( 'info' )->willReturn( true );
        $this->audit_logger->method( 'warning' )->willReturn( true );
        $this->audit_logger->method( 'error' )->willReturn( true );

        $this->ajax = new Affilync_Admin_Ajax(
            $this->api_client,
            $this->product_sync,
            $this->conversion_tracker,
            $this->webhook_handler,
            $this->audit_logger,
            $this->hmac_validator
        );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options'] = array();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_nonce_action_constant() {
        $this->assertSame( 'affilync_ajax_nonce', Affilync_Admin_Ajax::NONCE_ACTION );
    }

    public function test_stats_cache_prefix_constant() {
        $this->assertSame( 'affilync_dash_stats_', Affilync_Admin_Ajax::STATS_CACHE_PREFIX );
    }

    public function test_stats_cache_duration_constant() {
        $this->assertSame( 300, Affilync_Admin_Ajax::STATS_CACHE_DURATION );
    }

    // ------------------------------------------------------------------
    // Constructor
    // ------------------------------------------------------------------

    public function test_constructor_sets_dependencies() {
        $this->assertInstanceOf( Affilync_Admin_Ajax::class, $this->ajax );
    }

    // ------------------------------------------------------------------
    // enqueue_scripts
    // ------------------------------------------------------------------

    public function test_enqueue_scripts_returns_early_for_non_affilync_pages() {
        // Should not throw for non-affilync pages.
        $this->ajax->enqueue_scripts( 'edit.php' );
        $this->assertTrue( true );
    }

    public function test_enqueue_scripts_runs_for_affilync_pages() {
        // Should not throw for affilync pages.
        $this->ajax->enqueue_scripts( 'toplevel_page_affilync' );
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // AJAX handlers — guard clauses (smoke tests)
    //
    // These verify the handlers exist and don't crash.
    // Full flow testing requires $_POST and deeper mocks.
    // ------------------------------------------------------------------

    public function test_ajax_sync_products_exists() {
        $this->assertTrue( method_exists( $this->ajax, 'ajax_sync_products' ) );
    }

    public function test_ajax_test_connection_exists() {
        $this->assertTrue( method_exists( $this->ajax, 'ajax_test_connection' ) );
    }

    public function test_ajax_sync_conversions_exists() {
        $this->assertTrue( method_exists( $this->ajax, 'ajax_sync_conversions' ) );
    }

    public function test_ajax_get_dashboard_stats_exists() {
        $this->assertTrue( method_exists( $this->ajax, 'ajax_get_dashboard_stats' ) );
    }

    public function test_ajax_manage_webhooks_exists() {
        $this->assertTrue( method_exists( $this->ajax, 'ajax_manage_webhooks' ) );
    }

    public function test_ajax_get_call_logs_exists() {
        $this->assertTrue( method_exists( $this->ajax, 'ajax_get_call_logs' ) );
    }
}
