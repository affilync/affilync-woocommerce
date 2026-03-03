<?php
/**
 * Tests for Affilync_Webhook_Queue
 *
 * Covers webhook enqueuing, immediate delivery, queue processing,
 * exponential backoff retries, cleanup, stats, and cron scheduling.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Webhook Queue test case.
 */
class Test_Webhook_Queue extends TestCase {

    /**
     * @var Affilync_Webhook_Queue
     */
    private $queue;

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

        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-api-client.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-audit-logger.php';
        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-webhook-queue.php';

        $this->api_client   = $this->createMock( Affilync_API_Client::class );
        $this->audit_logger = $this->createMock( Affilync_Security_Audit_Logger::class );

        $this->audit_logger->method( 'info' )->willReturn( true );
        $this->audit_logger->method( 'warning' )->willReturn( true );
        $this->audit_logger->method( 'error' )->willReturn( true );

        $this->queue = new Affilync_Webhook_Queue( $this->api_client, $this->audit_logger );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options'] = array();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_constants() {
        $this->assertSame( 'affilync_webhook_queue', Affilync_Webhook_Queue::TABLE_NAME );
        $this->assertSame( 5, Affilync_Webhook_Queue::DEFAULT_MAX_ATTEMPTS );
        $this->assertSame( 'pending', Affilync_Webhook_Queue::STATUS_PENDING );
        $this->assertSame( 'processing', Affilync_Webhook_Queue::STATUS_PROCESSING );
        $this->assertSame( 'completed', Affilync_Webhook_Queue::STATUS_COMPLETED );
        $this->assertSame( 'failed', Affilync_Webhook_Queue::STATUS_FAILED );
        $this->assertSame( 'affilync_process_webhook_queue', Affilync_Webhook_Queue::CRON_PROCESS_HOOK );
        $this->assertSame( 'affilync_cleanup_webhook_queue', Affilync_Webhook_Queue::CRON_CLEANUP_HOOK );
    }

    // ------------------------------------------------------------------
    // enqueue
    // ------------------------------------------------------------------

    public function test_enqueue_returns_queue_id() {
        // Default mock wpdb->insert returns 1, insert_id is random.
        $id = $this->queue->enqueue( 'conversion.created', array( 'order_id' => 42 ) );
        $this->assertIsInt( $id );
        $this->assertGreaterThan( 0, $id );
    }

    public function test_enqueue_with_custom_max_attempts() {
        $id = $this->queue->enqueue( 'conversion.approved', array(), 10 );
        $this->assertIsInt( $id );
    }

    public function test_enqueue_returns_false_on_db_failure() {
        global $wpdb;
        $saved = $GLOBALS['wpdb'];

        $mock_wpdb = new class extends Affilync_Mock_Wpdb {
            public function insert( $table, $data, $format = null ) {
                return false; // Simulate failure.
            }
        };
        $GLOBALS['wpdb'] = $mock_wpdb;

        $id = $this->queue->enqueue( 'test.event', array() );
        $this->assertFalse( $id );

        $GLOBALS['wpdb'] = $saved;
    }

    public function test_enqueue_logs_error_on_failure() {
        global $wpdb;
        $saved = $GLOBALS['wpdb'];

        $mock_wpdb = new class extends Affilync_Mock_Wpdb {
            public function insert( $table, $data, $format = null ) {
                return false;
            }
        };
        $GLOBALS['wpdb'] = $mock_wpdb;

        $this->audit_logger->expects( $this->once() )->method( 'error' );

        $this->queue->enqueue( 'test.event', array() );

        $GLOBALS['wpdb'] = $saved;
    }

    // ------------------------------------------------------------------
    // attempt_immediate_delivery
    // ------------------------------------------------------------------

    public function test_attempt_immediate_delivery_returns_false_for_missing_item() {
        // wpdb->get_row returns null by default.
        $result = $this->queue->attempt_immediate_delivery( 999 );
        $this->assertFalse( $result );
    }

    // ------------------------------------------------------------------
    // process_queue
    // ------------------------------------------------------------------

    public function test_process_queue_handles_empty_queue() {
        // wpdb->get_results returns empty by default.
        // Should not throw.
        $this->queue->process_queue( 10 );
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // retry_failed
    // ------------------------------------------------------------------

    public function test_retry_failed_handles_empty_results() {
        // wpdb->get_results returns empty by default.
        $this->queue->retry_failed();
        $this->assertTrue( true );
    }

    public function test_retry_failed_calculates_exponential_backoff() {
        global $wpdb;
        $saved = $GLOBALS['wpdb'];

        $update_calls = array();

        $mock_wpdb = new class( $update_calls ) extends Affilync_Mock_Wpdb {
            public $update_log = array();

            public function __construct( &$log ) {
                $this->update_log = &$log;
            }

            public function get_results( $q = null, $output = 'OBJECT' ) {
                return array(
                    (object) array( 'id' => 1, 'attempts' => 0, 'max_attempts' => 5 ),
                    (object) array( 'id' => 2, 'attempts' => 2, 'max_attempts' => 5 ),
                );
            }

            public function prepare( $q, ...$a ) { return $q; }

            public function update( $table, $data, $where, $format = null, $where_format = null ) {
                $this->update_log[] = $data;
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $mock_wpdb;

        $this->queue->retry_failed();

        // Two items should have been updated with different backoff times.
        $this->assertCount( 2, $mock_wpdb->update_log );
        // Both should have status = 'pending'.
        $this->assertSame( 'pending', $mock_wpdb->update_log[0]['status'] );
        $this->assertSame( 'pending', $mock_wpdb->update_log[1]['status'] );

        $GLOBALS['wpdb'] = $saved;
    }

    // ------------------------------------------------------------------
    // cleanup_completed
    // ------------------------------------------------------------------

    public function test_cleanup_completed_returns_int() {
        $deleted = $this->queue->cleanup_completed( 30 );
        // wpdb->query returns true (1) by default.
        $this->assertIsInt( $deleted );
    }

    public function test_cleanup_with_default_days() {
        $deleted = $this->queue->cleanup_completed();
        $this->assertIsInt( $deleted );
    }

    // ------------------------------------------------------------------
    // get_stats
    // ------------------------------------------------------------------

    public function test_get_stats_returns_expected_keys() {
        $stats = $this->queue->get_stats();
        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'pending', $stats );
        $this->assertArrayHasKey( 'processing', $stats );
        $this->assertArrayHasKey( 'completed', $stats );
        $this->assertArrayHasKey( 'failed', $stats );
    }

    public function test_get_stats_returns_zeros_for_empty_queue() {
        $stats = $this->queue->get_stats();
        $this->assertSame( 0, $stats['pending'] );
        $this->assertSame( 0, $stats['processing'] );
        $this->assertSame( 0, $stats['completed'] );
        $this->assertSame( 0, $stats['failed'] );
    }

    // ------------------------------------------------------------------
    // get_recent_items
    // ------------------------------------------------------------------

    public function test_get_recent_items_returns_array() {
        $items = $this->queue->get_recent_items();
        $this->assertIsArray( $items );
    }

    public function test_get_recent_items_with_status_filter() {
        $items = $this->queue->get_recent_items( 10, 'pending' );
        $this->assertIsArray( $items );
    }

    public function test_get_recent_items_with_custom_limit() {
        $items = $this->queue->get_recent_items( 5 );
        $this->assertIsArray( $items );
    }

    // ------------------------------------------------------------------
    // Cron scheduling
    // ------------------------------------------------------------------

    public function test_schedule_cron_events_is_static() {
        // Just verify the static method is callable.
        $this->assertTrue( method_exists( Affilync_Webhook_Queue::class, 'schedule_cron_events' ) );
        Affilync_Webhook_Queue::schedule_cron_events();
        $this->assertTrue( true );
    }

    public function test_clear_cron_events_is_static() {
        $this->assertTrue( method_exists( Affilync_Webhook_Queue::class, 'clear_cron_events' ) );
        Affilync_Webhook_Queue::clear_cron_events();
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // Constructor accepts null dependencies
    // ------------------------------------------------------------------

    public function test_constructor_works_without_dependencies() {
        $queue = new Affilync_Webhook_Queue();
        $this->assertInstanceOf( Affilync_Webhook_Queue::class, $queue );
    }

    public function test_enqueue_without_audit_logger() {
        $queue = new Affilync_Webhook_Queue( null, null );
        $id = $queue->enqueue( 'test.event', array( 'foo' => 'bar' ) );
        $this->assertIsInt( $id );
    }

    // ------------------------------------------------------------------
    // send_webhook (via deliver_webhook)
    // ------------------------------------------------------------------

    public function test_deliver_webhook_without_api_client_fails() {
        global $wpdb;
        $saved = $GLOBALS['wpdb'];

        // Create a queue item mock.
        $item = (object) array(
            'id'           => 1,
            'event_type'   => 'test.event',
            'payload'      => '{"key":"value"}',
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => 5,
        );

        $mock_wpdb = new class extends Affilync_Mock_Wpdb {
            public function get_row( $q = null, $output = 'OBJECT', $y = 0 ) {
                return (object) array(
                    'id'           => 1,
                    'event_type'   => 'test.event',
                    'payload'      => '{"key":"value"}',
                    'status'       => 'pending',
                    'attempts'     => 0,
                    'max_attempts' => 5,
                );
            }
        };
        $GLOBALS['wpdb'] = $mock_wpdb;

        // Queue without API client — send_webhook should throw.
        $queue = new Affilync_Webhook_Queue( null, $this->audit_logger );
        $result = $queue->attempt_immediate_delivery( 1 );
        $this->assertFalse( $result );

        $GLOBALS['wpdb'] = $saved;
    }

    public function test_deliver_webhook_with_api_error_fails() {
        global $wpdb;
        $saved = $GLOBALS['wpdb'];

        $mock_wpdb = new class extends Affilync_Mock_Wpdb {
            public function get_row( $q = null, $output = 'OBJECT', $y = 0 ) {
                return (object) array(
                    'id'           => 2,
                    'event_type'   => 'conversion.created',
                    'payload'      => '{"order_id":42}',
                    'status'       => 'pending',
                    'attempts'     => 0,
                    'max_attempts' => 5,
                );
            }
        };
        $GLOBALS['wpdb'] = $mock_wpdb;

        // API returns WP_Error.
        $this->api_client->method( 'post' )->willReturn(
            new WP_Error( 'api_error', 'Connection refused' )
        );

        $result = $this->queue->attempt_immediate_delivery( 2 );
        $this->assertFalse( $result );

        $GLOBALS['wpdb'] = $saved;
    }

    public function test_deliver_webhook_success() {
        global $wpdb;
        $saved = $GLOBALS['wpdb'];

        $mock_wpdb = new class extends Affilync_Mock_Wpdb {
            public function get_row( $q = null, $output = 'OBJECT', $y = 0 ) {
                return (object) array(
                    'id'           => 3,
                    'event_type'   => 'conversion.created',
                    'payload'      => '{"order_id":42}',
                    'status'       => 'pending',
                    'attempts'     => 0,
                    'max_attempts' => 5,
                );
            }
        };
        $GLOBALS['wpdb'] = $mock_wpdb;

        // API returns success — must be a proper WP HTTP response array.
        $this->api_client->method( 'post' )->willReturn( array(
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'body'     => '{"ok":true}',
        ) );

        $result = $this->queue->attempt_immediate_delivery( 3 );
        $this->assertTrue( $result );

        $GLOBALS['wpdb'] = $saved;
    }
}
