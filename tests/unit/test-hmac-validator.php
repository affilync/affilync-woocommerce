<?php
/**
 * Tests for Affilync_Security_HMAC_Validator
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * HMAC Validator test case.
 */
class Test_HMAC_Validator extends TestCase {

    /**
     * HMAC validator instance.
     *
     * @var Affilync_Security_HMAC_Validator
     */
    private $validator;

    /**
     * Test webhook secret.
     *
     * @var string
     */
    private $test_secret = 'whsec_test_secret_key_for_testing';

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        parent::setUp();

        // Define webhook secret for testing.
        if ( ! defined( 'AFFILYNC_WEBHOOK_SECRET' ) ) {
            define( 'AFFILYNC_WEBHOOK_SECRET', $this->test_secret );
        }

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-hmac-validator.php';

        $this->validator = new Affilync_Security_HMAC_Validator();
    }

    /**
     * Test signature calculation.
     */
    public function test_calculate_signature() {
        $payload = '{"event":"test","data":"value"}';

        $signature = $this->validator->calculate_signature( $payload, $this->test_secret );

        $this->assertNotEmpty( $signature );
        $this->assertEquals( 64, strlen( $signature ) ); // SHA256 hex is 64 chars.
    }

    /**
     * Test signature verification with valid signature.
     */
    public function test_verify_valid_signature() {
        $payload = '{"event":"conversion.approved","order_id":123}';

        // Calculate expected signature.
        $signature = hash_hmac( 'sha256', $payload, $this->test_secret );

        $result = $this->validator->verify( $payload, $signature );

        $this->assertTrue( $result['valid'] );
        $this->assertNull( $result['error'] );
    }

    /**
     * Test signature verification with invalid signature.
     */
    public function test_verify_invalid_signature() {
        $payload = '{"event":"test"}';
        $invalid_signature = 'invalid_signature_here';

        $result = $this->validator->verify( $payload, $invalid_signature );

        $this->assertFalse( $result['valid'] );
        $this->assertEquals( 'Invalid signature', $result['error'] );
    }

    /**
     * Test signature verification with missing signature.
     */
    public function test_verify_missing_signature() {
        $payload = '{"event":"test"}';

        $result = $this->validator->verify( $payload, '' );

        $this->assertFalse( $result['valid'] );
        $this->assertEquals( 'Missing signature', $result['error'] );
    }

    /**
     * Test timestamp validation with valid timestamp.
     */
    public function test_validate_timestamp_valid() {
        $timestamp = time();

        $result = $this->validator->validate_timestamp( $timestamp );

        $this->assertTrue( $result['valid'] );
    }

    /**
     * Test timestamp validation with old timestamp.
     */
    public function test_validate_timestamp_too_old() {
        $timestamp = time() - 600; // 10 minutes ago (max is 5 minutes).

        $result = $this->validator->validate_timestamp( $timestamp );

        $this->assertFalse( $result['valid'] );
        $this->assertEquals( 'Timestamp too old or in future', $result['error'] );
    }

    /**
     * Test timestamp validation with future timestamp.
     */
    public function test_validate_timestamp_future() {
        $timestamp = time() + 600; // 10 minutes in future.

        $result = $this->validator->validate_timestamp( $timestamp );

        $this->assertFalse( $result['valid'] );
    }

    /**
     * Test timestamp validation with invalid format.
     */
    public function test_validate_timestamp_invalid() {
        $result = $this->validator->validate_timestamp( 'invalid' );

        $this->assertFalse( $result['valid'] );
        $this->assertEquals( 'Invalid timestamp format', $result['error'] );
    }

    /**
     * Test verification with timestamp.
     */
    public function test_verify_with_timestamp() {
        $payload = '{"event":"test"}';
        $timestamp = time();
        $signed_payload = "{$timestamp}.{$payload}";

        $signature = hash_hmac( 'sha256', $signed_payload, $this->test_secret );

        $result = $this->validator->verify( $payload, $signature, $timestamp );

        $this->assertTrue( $result['valid'] );
    }

    /**
     * Test sign method.
     */
    public function test_sign() {
        $payload = '{"event":"outgoing_webhook"}';

        $result = $this->validator->sign( $payload );

        $this->assertNotEmpty( $result['signature'] );
        $this->assertGreaterThan( 0, $result['timestamp'] );

        // Verify the signature is correct.
        $signed_payload = $result['timestamp'] . '.' . $payload;
        $expected = hash_hmac( 'sha256', $signed_payload, $this->test_secret );

        $this->assertEquals( $expected, $result['signature'] );
    }

    /**
     * Test base64 verification.
     */
    public function test_verify_base64() {
        $payload = '{"event":"test"}';

        // Calculate base64-encoded signature.
        $signature = base64_encode(
            hash_hmac( 'sha256', $payload, $this->test_secret, true )
        );

        $result = $this->validator->verify_base64( $payload, $signature );

        $this->assertTrue( $result );
    }

    /**
     * Test base64 verification with invalid signature.
     */
    public function test_verify_base64_invalid() {
        $payload = '{"event":"test"}';
        $invalid_signature = base64_encode( 'invalid' );

        $result = $this->validator->verify_base64( $payload, $invalid_signature );

        $this->assertFalse( $result );
    }

    /**
     * Test that verification is constant-time.
     */
    public function test_timing_safe_comparison() {
        $payload = '{"event":"test"}';
        $correct_signature = hash_hmac( 'sha256', $payload, $this->test_secret );

        // Timing should be similar regardless of how wrong the signature is.
        $test_signatures = array(
            str_repeat( 'a', 64 ),
            $correct_signature,
            substr( $correct_signature, 0, 32 ) . str_repeat( 'b', 32 ),
        );

        $times = array();
        foreach ( $test_signatures as $sig ) {
            $start = microtime( true );
            for ( $i = 0; $i < 1000; $i++ ) {
                $this->validator->verify( $payload, $sig );
            }
            $times[] = microtime( true ) - $start;
        }

        // All times should be roughly similar (within 50% of each other).
        $max_time = max( $times );
        $min_time = min( $times );

        // Allow for some variance but not excessive timing differences.
        $this->assertLessThan( 3.0, $max_time / max( $min_time, 0.001 ) );
    }
}
