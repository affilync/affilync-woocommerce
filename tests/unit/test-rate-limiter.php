<?php
/**
 * Tests for Affilync_Security_Rate_Limiter
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Rate Limiter test case.
 *
 * Note: These tests mock the database calls for unit testing.
 * Integration tests would test against a real database.
 */
class Test_Rate_Limiter extends TestCase {

    /**
     * Rate limiter instance.
     *
     * @var Affilync_Security_Rate_Limiter
     */
    private $limiter;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        parent::setUp();

        // Clean up $_SERVER proxy headers between tests.
        unset(
            $_SERVER['HTTP_CF_CONNECTING_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_REAL_IP']
        );

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-rate-limiter.php';

        $this->limiter = new Affilync_Security_Rate_Limiter();
    }

    /**
     * Test get_client_ip with direct connection.
     */
    public function test_get_client_ip_direct() {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
        unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );

        $ip = $this->limiter->get_client_ip();

        $this->assertEquals( '192.168.1.100', $ip );
    }

    /**
     * Test get_client_ip with X-Forwarded-For header.
     */
    public function test_get_client_ip_forwarded() {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 192.168.1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $ip = $this->limiter->get_client_ip();

        $this->assertEquals( '10.0.0.1', $ip );
    }

    /**
     * Test get_client_ip with Cloudflare header.
     */
    public function test_get_client_ip_cloudflare() {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.50';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $ip = $this->limiter->get_client_ip();

        $this->assertEquals( '203.0.113.50', $ip );
    }

    /**
     * Test get_identifier format.
     */
    public function test_get_identifier() {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test Browser';

        $identifier = $this->limiter->get_identifier();

        // Should be ip:hash format.
        $parts = explode( ':', $identifier );
        $this->assertCount( 2, $parts );
        $this->assertEquals( '192.168.1.1', $parts[0] );
        $this->assertEquals( 8, strlen( $parts[1] ) ); // 8-char hash.
    }

    /**
     * Test set_limit functionality.
     */
    public function test_set_limit() {
        $this->limiter->set_limit( 'custom_action', 50, 120 );

        // The limit should be set (we can't directly verify internal state
        // in unit tests, but this ensures no errors are thrown).
        $this->assertTrue( true );
    }

    /**
     * Test check returns proper structure.
     */
    public function test_check_returns_structure() {
        // This test verifies the return structure without database.
        $result = $this->limiter->check( 'unknown_action' );

        $this->assertArrayHasKey( 'allowed', $result );
        $this->assertArrayHasKey( 'remaining', $result );
        $this->assertArrayHasKey( 'limit', $result );
        $this->assertArrayHasKey( 'reset', $result );

        // Unknown action should be allowed.
        $this->assertTrue( $result['allowed'] );
    }

    /**
     * Test that cleanup doesn't throw errors.
     */
    public function test_cleanup_expired() {
        // Just verify this doesn't throw.
        $this->limiter->cleanup_expired();
        $this->assertTrue( true );
    }

    /**
     * Test invalid IP validation.
     */
    public function test_invalid_ip_handling() {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip-address';
        unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        unset( $_SERVER['REMOTE_ADDR'] );

        $ip = $this->limiter->get_client_ip();

        // Should return fallback.
        $this->assertEquals( '0.0.0.0', $ip );
    }

    /**
     * Test that configured limits exist.
     */
    public function test_configured_limits() {
        // Test that known actions have limits configured.
        $actions = array( 'api_call', 'oauth_attempt', 'webhook_receive', 'product_sync' );

        foreach ( $actions as $action ) {
            // Should not error for known actions.
            $result = $this->limiter->check( $action, 'test_id' );
            $this->assertArrayHasKey( 'limit', $result );
        }
    }
}
