<?php
/**
 * Integration tests for Webhook Handler.
 *
 * @package Affilync_WooCommerce
 */

/**
 * Test class for Webhook Handler integration.
 */
class Test_Webhook_Handler extends WP_UnitTestCase {

    /**
     * Webhook handler instance.
     *
     * @var Affilync_API_Webhook_Handler
     */
    private $webhook_handler;

    /**
     * Mock HMAC validator.
     *
     * @var Affilync_Security_HMAC_Validator
     */
    private $mock_hmac_validator;

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

        global $wpdb;

        // Create mock objects.
        $this->mock_hmac_validator = $this->createMock( Affilync_Security_HMAC_Validator::class );
        $this->mock_audit_logger   = $this->createMock( Affilync_Security_Audit_Logger::class );

        // Create webhook handler instance.
        $this->webhook_handler = new Affilync_API_Webhook_Handler(
            $this->mock_hmac_validator,
            $this->mock_audit_logger
        );

        // Create webhooks table if it doesn't exist.
        $table = $wpdb->prefix . 'affilync_webhooks';
        $wpdb->query( "TRUNCATE TABLE IF EXISTS {$table}" );
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down() {
        parent::tear_down();

        global $wpdb;
        $table = $wpdb->prefix . 'affilync_webhooks';
        $wpdb->query( "TRUNCATE TABLE IF EXISTS {$table}" );
    }

    /**
     * Test webhook signature validation.
     */
    public function test_webhook_signature_validation() {
        $payload   = '{"event":"conversion.created","data":{"order_id":123}}';
        $signature = 'valid_signature';
        $timestamp = time();

        $this->mock_hmac_validator
            ->expects( $this->once() )
            ->method( 'verify' )
            ->with( $payload, $signature, $timestamp )
            ->willReturn( true );

        $result = $this->webhook_handler->validate_signature( $payload, $signature, $timestamp );

        $this->assertTrue( $result );
    }

    /**
     * Test webhook with invalid signature is rejected.
     */
    public function test_invalid_signature_rejected() {
        $payload   = '{"event":"test"}';
        $signature = 'invalid_signature';
        $timestamp = time();

        $this->mock_hmac_validator
            ->expects( $this->once() )
            ->method( 'verify' )
            ->willReturn( false );

        $this->mock_audit_logger
            ->expects( $this->once() )
            ->method( 'log' )
            ->with(
                'webhook_signature_invalid',
                $this->anything(),
                'warning'
            );

        $result = $this->webhook_handler->validate_signature( $payload, $signature, $timestamp );

        $this->assertFalse( $result );
    }

    /**
     * Test expired timestamp is rejected.
     */
    public function test_expired_timestamp_rejected() {
        $payload   = '{"event":"test"}';
        $signature = 'valid_signature';
        $timestamp = time() - 600; // 10 minutes ago.

        $this->mock_hmac_validator
            ->expects( $this->never() )
            ->method( 'verify' );

        $result = $this->webhook_handler->validate_signature( $payload, $signature, $timestamp );

        $this->assertFalse( $result );
    }

    /**
     * Test webhook processing for conversion.created event.
     */
    public function test_process_conversion_created_webhook() {
        $payload = array(
            'event'      => 'conversion.created',
            'webhook_id' => 'wh_123',
            'data'       => array(
                'conversion_id' => 'conv_456',
                'order_id'      => 789,
                'affiliate_id'  => 'aff_012',
                'amount'        => 100.00,
                'commission'    => 10.00,
            ),
        );

        // Set up action hook expectation.
        $action_called = false;
        add_action( 'affilync_webhook_conversion.created', function( $data ) use ( &$action_called ) {
            $action_called = true;
            $this->assertEquals( 789, $data['order_id'] );
        });

        $result = $this->webhook_handler->process_webhook( $payload );

        $this->assertTrue( $result['success'] );
        $this->assertTrue( $action_called );
    }

    /**
     * Test webhook processing for conversion.updated event.
     */
    public function test_process_conversion_updated_webhook() {
        $payload = array(
            'event'      => 'conversion.updated',
            'webhook_id' => 'wh_124',
            'data'       => array(
                'conversion_id' => 'conv_456',
                'status'        => 'approved',
            ),
        );

        $action_called = false;
        add_action( 'affilync_webhook_conversion.updated', function( $data ) use ( &$action_called ) {
            $action_called = true;
            $this->assertEquals( 'approved', $data['status'] );
        });

        $result = $this->webhook_handler->process_webhook( $payload );

        $this->assertTrue( $result['success'] );
        $this->assertTrue( $action_called );
    }

    /**
     * Test webhook processing for brand.verified event.
     */
    public function test_process_brand_verified_webhook() {
        $payload = array(
            'event'      => 'brand.verified',
            'webhook_id' => 'wh_125',
            'data'       => array(
                'brand_id'    => 'brand_789',
                'verified_at' => '2024-01-01T00:00:00Z',
            ),
        );

        $action_called = false;
        add_action( 'affilync_webhook_brand.verified', function( $data ) use ( &$action_called ) {
            $action_called = true;
            $this->assertEquals( 'brand_789', $data['brand_id'] );
        });

        $result = $this->webhook_handler->process_webhook( $payload );

        $this->assertTrue( $result['success'] );
        $this->assertTrue( $action_called );
    }

    /**
     * Test webhook processing for subscription.changed event.
     */
    public function test_process_subscription_changed_webhook() {
        $payload = array(
            'event'      => 'subscription.changed',
            'webhook_id' => 'wh_126',
            'data'       => array(
                'old_plan' => 'free',
                'new_plan' => 'pro',
            ),
        );

        $action_called = false;
        add_action( 'affilync_webhook_subscription.changed', function( $data ) use ( &$action_called ) {
            $action_called = true;
            $this->assertEquals( 'pro', $data['new_plan'] );
        });

        $result = $this->webhook_handler->process_webhook( $payload );

        $this->assertTrue( $result['success'] );
        $this->assertTrue( $action_called );
    }

    /**
     * Test duplicate webhook is rejected.
     */
    public function test_duplicate_webhook_rejected() {
        global $wpdb;
        $table = $wpdb->prefix . 'affilync_webhooks';

        // Insert existing webhook.
        $wpdb->insert(
            $table,
            array(
                'webhook_id'  => 'wh_duplicate',
                'event_type'  => 'test.event',
                'payload'     => '{}',
                'processed'   => 1,
                'created_at'  => current_time( 'mysql' ),
            )
        );

        $payload = array(
            'event'      => 'test.event',
            'webhook_id' => 'wh_duplicate',
            'data'       => array(),
        );

        $result = $this->webhook_handler->process_webhook( $payload );

        $this->assertFalse( $result['success'] );
        $this->assertEquals( 'duplicate', $result['error'] );
    }

    /**
     * Test webhook is logged in database.
     */
    public function test_webhook_logged_in_database() {
        global $wpdb;
        $table = $wpdb->prefix . 'affilync_webhooks';

        $payload = array(
            'event'      => 'test.event',
            'webhook_id' => 'wh_log_test',
            'data'       => array( 'test' => true ),
        );

        $this->webhook_handler->process_webhook( $payload );

        $logged = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE webhook_id = %s",
                'wh_log_test'
            )
        );

        $this->assertNotNull( $logged );
        $this->assertEquals( 'test.event', $logged->event_type );
        $this->assertEquals( 1, $logged->processed );
    }

    /**
     * Test webhook processing error is handled.
     */
    public function test_webhook_processing_error_handled() {
        $payload = array(
            'event'      => 'error.event',
            'webhook_id' => 'wh_error',
            'data'       => array(),
        );

        // Add action that throws exception.
        add_action( 'affilync_webhook_error.event', function() {
            throw new Exception( 'Processing error' );
        });

        $this->mock_audit_logger
            ->expects( $this->once() )
            ->method( 'log' )
            ->with(
                'webhook_processing_error',
                $this->callback( function( $data ) {
                    return isset( $data['error'] ) && strpos( $data['error'], 'Processing error' ) !== false;
                }),
                'error'
            );

        $result = $this->webhook_handler->process_webhook( $payload );

        $this->assertFalse( $result['success'] );
    }

    /**
     * Test webhook retry handling.
     */
    public function test_webhook_retry_handling() {
        global $wpdb;
        $table = $wpdb->prefix . 'affilync_webhooks';

        // Insert failed webhook with retry count.
        $wpdb->insert(
            $table,
            array(
                'webhook_id'        => 'wh_retry',
                'event_type'        => 'test.event',
                'payload'           => '{"data":"test"}',
                'processed'         => 0,
                'processing_result' => 'Failed: timeout',
                'created_at'        => current_time( 'mysql' ),
            )
        );

        $pending = $this->webhook_handler->get_pending_webhooks();

        $this->assertNotEmpty( $pending );
        $this->assertEquals( 'wh_retry', $pending[0]->webhook_id );
    }

    /**
     * Test supported webhook events.
     */
    public function test_supported_webhook_events() {
        $supported = $this->webhook_handler->get_supported_events();

        $expected_events = array(
            'conversion.created',
            'conversion.updated',
            'conversion.approved',
            'conversion.rejected',
            'product.synced',
            'product.sync_failed',
            'brand.verified',
            'brand.rejected',
            'subscription.changed',
            'subscription.cancelled',
        );

        foreach ( $expected_events as $event ) {
            $this->assertContains( $event, $supported );
        }
    }

    /**
     * Test webhook REST endpoint registration.
     */
    public function test_webhook_rest_endpoint_registered() {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey( '/affilync/v1/webhooks', $routes );
    }

    /**
     * Test webhook payload sanitization.
     */
    public function test_webhook_payload_sanitization() {
        $payload = array(
            'event'      => '<script>alert("xss")</script>conversion.created',
            'webhook_id' => 'wh_<script>',
            'data'       => array(
                'order_id'   => '123; DROP TABLE orders;',
                'amount'     => '<img src=x onerror=alert(1)>100.00',
                'affiliate'  => "Robert'); DROP TABLE affiliates;--",
            ),
        );

        $sanitized = $this->webhook_handler->sanitize_payload( $payload );

        // XSS should be removed.
        $this->assertStringNotContainsString( '<script>', $sanitized['event'] );
        $this->assertStringNotContainsString( '<script>', $sanitized['webhook_id'] );

        // SQL injection should be sanitized.
        $this->assertStringNotContainsString( 'DROP TABLE', $sanitized['data']['affiliate'] );
    }

    /**
     * Test webhook timestamp validation.
     */
    public function test_webhook_timestamp_validation() {
        // Future timestamp (more than 5 minutes ahead) should be rejected.
        $future_timestamp = time() + 400;
        $this->assertFalse(
            $this->webhook_handler->is_valid_timestamp( $future_timestamp )
        );

        // Current timestamp should be valid.
        $current_timestamp = time();
        $this->assertTrue(
            $this->webhook_handler->is_valid_timestamp( $current_timestamp )
        );

        // Recent timestamp (within 5 minutes) should be valid.
        $recent_timestamp = time() - 200;
        $this->assertTrue(
            $this->webhook_handler->is_valid_timestamp( $recent_timestamp )
        );

        // Old timestamp (more than 5 minutes ago) should be rejected.
        $old_timestamp = time() - 400;
        $this->assertFalse(
            $this->webhook_handler->is_valid_timestamp( $old_timestamp )
        );
    }
}
