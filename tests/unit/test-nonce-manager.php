<?php
/**
 * Tests for Affilync_Security_Nonce_Manager
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Nonce Manager test case.
 *
 * Note: These are unit tests that mock database behavior.
 * Integration tests would use a real database.
 */
class Test_Nonce_Manager extends TestCase {

    /**
     * Nonce manager instance.
     *
     * @var Affilync_Security_Nonce_Manager
     */
    private $nonce_manager;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        parent::setUp();

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-nonce-manager.php';

        $this->nonce_manager = new Affilync_Security_Nonce_Manager();
    }

    /**
     * Test nonce generation produces valid format.
     */
    public function test_generate_produces_valid_format() {
        $nonce = $this->nonce_manager->generate();

        // Should be 64 hex characters (32 bytes = 64 hex chars).
        $this->assertEquals( 64, strlen( $nonce ) );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $nonce );
    }

    /**
     * Test nonce generation produces unique values.
     */
    public function test_generate_produces_unique() {
        $nonces = array();

        for ( $i = 0; $i < 100; $i++ ) {
            $nonces[] = $this->nonce_manager->generate();
        }

        // All nonces should be unique.
        $unique = array_unique( $nonces );
        $this->assertCount( 100, $unique );
    }

    /**
     * Test nonce length constant.
     */
    public function test_nonce_length_constant() {
        $reflection = new ReflectionClass( $this->nonce_manager );
        $constant = $reflection->getConstant( 'NONCE_LENGTH' );

        // Nonce length should be 32 bytes.
        $this->assertEquals( 32, $constant );
    }

    /**
     * Test default TTL constant.
     */
    public function test_default_ttl_constant() {
        $reflection = new ReflectionClass( $this->nonce_manager );
        $constant = $reflection->getConstant( 'DEFAULT_TTL' );

        // Default TTL should be 600 seconds (10 minutes).
        $this->assertEquals( 600, $constant );
    }

    /**
     * Test cleanup probability constant.
     */
    public function test_cleanup_probability_constant() {
        $reflection = new ReflectionClass( $this->nonce_manager );
        $constant = $reflection->getConstant( 'CLEANUP_PROBABILITY' );

        // Should be 1 in 100.
        $this->assertEquals( 100, $constant );
    }

    /**
     * Test that create method returns string on success pattern.
     * (Actual database behavior would be tested in integration tests.)
     */
    public function test_create_returns_string_type() {
        // We can verify the generate method works correctly.
        $nonce = $this->nonce_manager->generate();
        $this->assertIsString( $nonce );
    }

    /**
     * Test invalidate with empty nonce.
     */
    public function test_invalidate_empty_nonce() {
        $result = $this->nonce_manager->invalidate( '' );
        $this->assertFalse( $result );
    }

    /**
     * Test exists with empty nonce.
     */
    public function test_exists_empty_nonce() {
        $result = $this->nonce_manager->exists( '' );
        $this->assertFalse( $result );
    }

    /**
     * Test get_data with empty nonce.
     */
    public function test_get_data_empty_nonce() {
        $result = $this->nonce_manager->get_data( '' );
        $this->assertFalse( $result );
    }

    /**
     * Test verify_and_consume with empty nonce.
     */
    public function test_verify_and_consume_empty_nonce() {
        $result = $this->nonce_manager->verify_and_consume( '' );
        $this->assertFalse( $result );
    }

    /**
     * Test nonce entropy is sufficient.
     */
    public function test_nonce_entropy() {
        // Generate multiple nonces and check they have good distribution.
        $char_counts = array();
        $chars = '0123456789abcdef';

        for ( $i = 0; $i < 100; $i++ ) {
            $nonce = $this->nonce_manager->generate();
            for ( $j = 0; $j < strlen( $nonce ); $j++ ) {
                $char = $nonce[ $j ];
                if ( ! isset( $char_counts[ $char ] ) ) {
                    $char_counts[ $char ] = 0;
                }
                $char_counts[ $char ]++;
            }
        }

        // Each hex character should appear (100 nonces * 64 chars / 16 possibilities = ~400 times each).
        foreach ( str_split( $chars ) as $char ) {
            $this->assertArrayHasKey( $char, $char_counts );
            // Should appear at least 100 times (weak test, but catches obvious issues).
            $this->assertGreaterThan( 100, $char_counts[ $char ] );
        }
    }
}
