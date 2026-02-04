<?php
/**
 * Tests for Affilync_Security_Encryption
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Encryption test case.
 */
class Test_Encryption extends TestCase {

    /**
     * Encryption instance.
     *
     * @var Affilync_Security_Encryption
     */
    private $encryption;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        parent::setUp();

        // Define encryption key for testing.
        if ( ! defined( 'AFFILYNC_ENCRYPTION_KEY' ) ) {
            define( 'AFFILYNC_ENCRYPTION_KEY', 'test_encryption_key_for_unit_tests_32chars!' );
        }

        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-encryption.php';

        $this->encryption = new Affilync_Security_Encryption();
    }

    /**
     * Test that encryption produces different output each time (due to random IV).
     */
    public function test_encryption_is_unique() {
        $plaintext = 'test_token_12345';

        $encrypted1 = $this->encryption->encrypt( $plaintext );
        $encrypted2 = $this->encryption->encrypt( $plaintext );

        $this->assertNotEquals( $encrypted1, $encrypted2 );
    }

    /**
     * Test encryption and decryption round-trip.
     */
    public function test_encrypt_decrypt_roundtrip() {
        $plaintext = 'my_secret_api_token';

        $encrypted = $this->encryption->encrypt( $plaintext );
        $decrypted = $this->encryption->decrypt( $encrypted );

        $this->assertEquals( $plaintext, $decrypted );
    }

    /**
     * Test encryption format.
     */
    public function test_encryption_format() {
        $encrypted = $this->encryption->encrypt( 'test' );

        // Should be version:iv:ciphertext format.
        $parts = explode( ':', $encrypted );
        $this->assertCount( 3, $parts );
        $this->assertEquals( 'v1', $parts[0] );
    }

    /**
     * Test decryption with invalid format.
     */
    public function test_decrypt_invalid_format() {
        $result = $this->encryption->decrypt( 'not_valid_encrypted_data' );
        $this->assertFalse( $result );
    }

    /**
     * Test decryption with tampered data.
     */
    public function test_decrypt_tampered_data() {
        $encrypted = $this->encryption->encrypt( 'original_data' );

        // Tamper with the ciphertext.
        $parts = explode( ':', $encrypted );
        $parts[2] = base64_encode( 'tampered' . base64_decode( $parts[2] ) );
        $tampered = implode( ':', $parts );

        $result = $this->encryption->decrypt( $tampered );
        $this->assertFalse( $result );
    }

    /**
     * Test decryption with wrong version.
     */
    public function test_decrypt_wrong_version() {
        $encrypted = $this->encryption->encrypt( 'test' );
        $parts = explode( ':', $encrypted );
        $parts[0] = 'v2'; // Wrong version.
        $wrong_version = implode( ':', $parts );

        $result = $this->encryption->decrypt( $wrong_version );
        $this->assertFalse( $result );
    }

    /**
     * Test encryption with empty string.
     */
    public function test_encrypt_empty_string() {
        $result = $this->encryption->encrypt( '' );
        $this->assertFalse( $result );
    }

    /**
     * Test decryption with empty string.
     */
    public function test_decrypt_empty_string() {
        $result = $this->encryption->decrypt( '' );
        $this->assertFalse( $result );
    }

    /**
     * Test encryption with special characters.
     */
    public function test_encrypt_special_characters() {
        $plaintext = "Special chars: !@#$%^&*()_+-=[]{}|;':\",./<>?\nNewline\tTab";

        $encrypted = $this->encryption->encrypt( $plaintext );
        $decrypted = $this->encryption->decrypt( $encrypted );

        $this->assertEquals( $plaintext, $decrypted );
    }

    /**
     * Test encryption with unicode characters.
     */
    public function test_encrypt_unicode() {
        $plaintext = "Unicode: 日本語 中文 한국어 😀🎉";

        $encrypted = $this->encryption->encrypt( $plaintext );
        $decrypted = $this->encryption->decrypt( $encrypted );

        $this->assertEquals( $plaintext, $decrypted );
    }

    /**
     * Test mask_token functionality.
     */
    public function test_mask_token() {
        $token = 'sk_live_abcdefghij123456';

        $masked = $this->encryption->mask_token( $token, 4 );
        $this->assertEquals( '***************3456', $masked );
    }

    /**
     * Test mask_token with short token.
     */
    public function test_mask_token_short() {
        $token = 'abc';

        $masked = $this->encryption->mask_token( $token, 4 );
        $this->assertEquals( '***', $masked );
    }

    /**
     * Test secure_compare.
     */
    public function test_secure_compare() {
        $this->assertTrue( $this->encryption->secure_compare( 'test', 'test' ) );
        $this->assertFalse( $this->encryption->secure_compare( 'test', 'Test' ) );
        $this->assertFalse( $this->encryption->secure_compare( 'test', 'test2' ) );
    }

    /**
     * Test encryption with long data.
     */
    public function test_encrypt_long_data() {
        $plaintext = str_repeat( 'a', 10000 );

        $encrypted = $this->encryption->encrypt( $plaintext );
        $decrypted = $this->encryption->decrypt( $encrypted );

        $this->assertEquals( $plaintext, $decrypted );
    }
}
