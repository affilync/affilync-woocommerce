<?php
/**
 * Tests for Affilync_Notification_Email
 *
 * Covers template constants, settings loading, Brevo configuration checks,
 * settings sanitization, event handler guard clauses, and email fallback.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Notification Email test case.
 */
class Test_Notification_Email extends TestCase {

    /**
     * @var Affilync_Notification_Email
     */
    private $email;

    /**
     * Mocks.
     */
    private $api_client;
    private $encryption;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options']       = array();
        $GLOBALS['affilync_sent_emails'] = array();

        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-api-client.php';
        require_once dirname( __DIR__, 2 ) . '/includes/security/class-affilync-security-encryption.php';
        require_once dirname( __DIR__, 2 ) . '/includes/notifications/class-affilync-notification-email.php';

        $this->api_client = $this->createMock( Affilync_API_Client::class );
        $this->encryption = $this->createMock( Affilync_Security_Encryption::class );
        $this->encryption->method( 'decrypt' )->willReturn( false );

        $this->email = new Affilync_Notification_Email( $this->api_client, $this->encryption );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options']       = array();
        $GLOBALS['affilync_sent_emails'] = array();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_template_constants() {
        $this->assertSame( 1, Affilync_Notification_Email::TEMPLATE_CONNECTED );
        $this->assertSame( 2, Affilync_Notification_Email::TEMPLATE_DISCONNECTED );
        $this->assertSame( 3, Affilync_Notification_Email::TEMPLATE_CONVERSION );
        $this->assertSame( 4, Affilync_Notification_Email::TEMPLATE_USAGE_WARNING );
        $this->assertSame( 5, Affilync_Notification_Email::TEMPLATE_USAGE_LIMIT );
        $this->assertSame( 6, Affilync_Notification_Email::TEMPLATE_VERIFICATION );
        $this->assertSame( 7, Affilync_Notification_Email::TEMPLATE_PAYOUT );
        $this->assertSame( 8, Affilync_Notification_Email::TEMPLATE_WEEKLY_REPORT );
        $this->assertSame( 9, Affilync_Notification_Email::TEMPLATE_SUBSCRIPTION );
        $this->assertSame( 10, Affilync_Notification_Email::TEMPLATE_TRIAL_ENDING );
    }

    public function test_brevo_api_url() {
        $this->assertSame( 'https://api.brevo.com/v3', Affilync_Notification_Email::BREVO_API_URL );
    }

    // ------------------------------------------------------------------
    // is_brevo_configured
    // ------------------------------------------------------------------

    public function test_brevo_not_configured_by_default() {
        $this->assertFalse( $this->email->is_brevo_configured() );
    }

    // ------------------------------------------------------------------
    // set_brevo_api_key
    // ------------------------------------------------------------------

    public function test_set_brevo_api_key_empty_clears() {
        $result = $this->email->set_brevo_api_key( '' );
        $this->assertTrue( $result );
        $this->assertFalse( $this->email->is_brevo_configured() );
    }

    public function test_set_brevo_api_key_stores_encrypted() {
        $this->encryption->method( 'encrypt' )->willReturn( 'encrypted_key' );

        // Need a fresh instance since encryption mock needs re-config.
        $encryption = $this->createMock( Affilync_Security_Encryption::class );
        $encryption->method( 'decrypt' )->willReturn( false );
        $encryption->method( 'encrypt' )->willReturn( 'encrypted_key' );

        $email = new Affilync_Notification_Email( $this->api_client, $encryption );
        $result = $email->set_brevo_api_key( 'xkeysib-test-key' );

        $this->assertTrue( $result );
        $this->assertTrue( $email->is_brevo_configured() );
    }

    // ------------------------------------------------------------------
    // send_brevo_email — falls back to wp_mail when not configured
    // ------------------------------------------------------------------

    public function test_send_brevo_falls_back_to_wp_mail() {
        $result = $this->email->send_brevo_email(
            'user@example.com',
            'Test User',
            Affilync_Notification_Email::TEMPLATE_CONNECTED,
            array(
                'site_name'    => 'Test Store',
                'site_url'     => 'https://example.com',
                'settings_url' => 'https://example.com/wp-admin/admin.php?page=affilync-settings',
            )
        );

        // wp_mail mock returns true.
        $this->assertTrue( $result );

        // Verify email was "sent".
        $this->assertNotEmpty( $GLOBALS['affilync_sent_emails'] );
        $this->assertSame( 'user@example.com', $GLOBALS['affilync_sent_emails'][0]['to'] );
    }

    // ------------------------------------------------------------------
    // send_via_api
    // ------------------------------------------------------------------

    public function test_send_via_api_returns_true_on_success() {
        $this->api_client->method( 'post' )->willReturn( array( 'success' => true ) );

        $result = $this->email->send_via_api( 'user@example.com', 'connected', array() );
        $this->assertTrue( $result );
    }

    public function test_send_via_api_returns_wp_error_on_failure() {
        $this->api_client->method( 'post' )->willReturn(
            new WP_Error( 'api_error', 'Connection failed' )
        );

        $result = $this->email->send_via_api( 'user@example.com', 'connected', array() );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    // ------------------------------------------------------------------
    // get_settings / update_settings
    // ------------------------------------------------------------------

    public function test_get_settings_returns_defaults() {
        $settings = $this->email->get_settings();

        $this->assertIsArray( $settings );
        $this->assertTrue( $settings['enabled'] );
        $this->assertSame( 'brevo', $settings['send_via'] );
    }

    public function test_update_settings_sanitizes_input() {
        $result = $this->email->update_settings( array(
            'enabled'    => false,
            'send_via'   => 'wp_mail',
            'from_name'  => 'My Store',
            'from_email' => 'store@example.com',
        ) );

        $this->assertTrue( $result );

        $settings = $this->email->get_settings();
        $this->assertFalse( $settings['enabled'] );
        $this->assertSame( 'wp_mail', $settings['send_via'] );
    }

    // ------------------------------------------------------------------
    // sanitize_settings
    // ------------------------------------------------------------------

    public function test_sanitize_settings_sanitizes_email() {
        $sanitized = $this->email->sanitize_settings( array(
            'enabled'     => true,
            'send_via'    => 'brevo',
            'from_name'   => '<script>alert("xss")</script>',
            'from_email'  => 'valid@example.com',
            'admin_email' => 'admin@example.com',
        ) );

        $this->assertIsArray( $sanitized );
        $this->assertTrue( $sanitized['enabled'] );
        $this->assertStringNotContainsString( '<script>', $sanitized['from_name'] );
    }

    public function test_sanitize_settings_handles_notify_arrays() {
        $sanitized = $this->email->sanitize_settings( array(
            'enabled'      => true,
            'notify_admin' => array( 'new_conversion' => true, 'usage_warning' => false ),
            'notify_user'  => array( 'connected' => true ),
        ) );

        $this->assertTrue( $sanitized['notify_admin']['new_conversion'] );
        $this->assertFalse( $sanitized['notify_admin']['usage_warning'] );
    }

    // ------------------------------------------------------------------
    // Event handlers — guard clause tests
    // ------------------------------------------------------------------

    public function test_on_connection_changed_skips_when_disabled() {
        update_option( 'affilync_email_settings', array( 'enabled' => false ) );
        $email = new Affilync_Notification_Email( $this->api_client, $this->encryption );

        // Should not send any email.
        $GLOBALS['affilync_sent_emails'] = array();
        $email->on_connection_changed( 'connected' );
        $this->assertEmpty( $GLOBALS['affilync_sent_emails'] );
    }

    public function test_on_conversion_tracked_skips_when_disabled() {
        update_option( 'affilync_email_settings', array( 'enabled' => false ) );
        $email = new Affilync_Notification_Email( $this->api_client, $this->encryption );

        $GLOBALS['affilync_sent_emails'] = array();
        $email->on_conversion_tracked( 123, array( 'order_total' => 100 ) );
        $this->assertEmpty( $GLOBALS['affilync_sent_emails'] );
    }

    public function test_on_usage_warning_skips_when_disabled() {
        update_option( 'affilync_email_settings', array( 'enabled' => false ) );
        $email = new Affilync_Notification_Email( $this->api_client, $this->encryption );

        $GLOBALS['affilync_sent_emails'] = array();
        $email->on_usage_warning( 'conversions', 85 );
        $this->assertEmpty( $GLOBALS['affilync_sent_emails'] );
    }

    public function test_on_subscription_changed_skips_when_disabled() {
        update_option( 'affilync_email_settings', array( 'enabled' => false ) );
        $email = new Affilync_Notification_Email( $this->api_client, $this->encryption );

        $GLOBALS['affilync_sent_emails'] = array();
        $email->on_subscription_changed( 'pro', 'starter' );
        $this->assertEmpty( $GLOBALS['affilync_sent_emails'] );
    }

    // ------------------------------------------------------------------
    // send_test_email
    // ------------------------------------------------------------------

    public function test_send_test_email_falls_back_to_wp_mail() {
        $result = $this->email->send_test_email();
        $this->assertTrue( $result );
    }

    // ------------------------------------------------------------------
    // prepare_template_params (private)
    // ------------------------------------------------------------------

    public function test_prepare_template_params_adds_defaults() {
        $method = new ReflectionMethod( Affilync_Notification_Email::class, 'prepare_template_params' );
        $method->setAccessible( true );

        $params = $method->invoke( $this->email, array( 'custom' => 'value' ) );

        $this->assertArrayHasKey( 'site_name', $params );
        $this->assertArrayHasKey( 'site_url', $params );
        $this->assertArrayHasKey( 'admin_url', $params );
        $this->assertArrayHasKey( 'year', $params );
        $this->assertSame( 'value', $params['custom'] );
    }
}
