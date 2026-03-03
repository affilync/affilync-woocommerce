<?php
/**
 * Tests for Affilync_Admin_Settings
 *
 * Covers constants, settings sanitization, menu registration,
 * settings registration, activation redirect, and notice display.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Admin Settings test case.
 */
class Test_Admin_Settings extends TestCase {

    /**
     * @var Affilync_Admin_Settings
     */
    private $settings;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options']     = array();
        $GLOBALS['affilync_menu_pages'] = array();

        require_once dirname( __DIR__, 2 ) . '/includes/admin/class-affilync-admin-settings.php';

        $this->settings = new Affilync_Admin_Settings();
    }

    protected function tearDown(): void {
        $GLOBALS['wp_options']     = array();
        $GLOBALS['affilync_menu_pages'] = array();
        unset( $_GET['affilync_message'], $_GET['affilync_type'], $_GET['tab'] );
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_page_slug_constant() {
        $this->assertSame( 'affilync-settings', Affilync_Admin_Settings::PAGE_SLUG );
    }

    public function test_settings_group_constant() {
        $this->assertSame( 'affilync_settings_group', Affilync_Admin_Settings::SETTINGS_GROUP );
    }

    // ------------------------------------------------------------------
    // add_menu_pages
    // ------------------------------------------------------------------

    public function test_add_menu_pages_registers_submenu() {
        $GLOBALS['affilync_menu_pages'] = array();
        $this->settings->add_menu_pages();

        $this->assertNotEmpty( $GLOBALS['affilync_menu_pages'] );
        $slugs = array_column( $GLOBALS['affilync_menu_pages'], 'menu_slug' );
        $this->assertContains( 'affilync-settings', $slugs );
    }

    public function test_add_menu_pages_under_woocommerce() {
        $GLOBALS['affilync_menu_pages'] = array();
        $this->settings->add_menu_pages();

        $page = $GLOBALS['affilync_menu_pages'][0];
        $this->assertSame( 'woocommerce', $page['parent_slug'] );
    }

    // ------------------------------------------------------------------
    // register_settings (smoke test - no crash)
    // ------------------------------------------------------------------

    public function test_register_settings_does_not_throw() {
        $this->settings->register_settings();
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // sanitize_settings
    // ------------------------------------------------------------------

    public function test_sanitize_settings_sanitizes_tracking_enabled() {
        $result = $this->settings->sanitize_settings( array(
            'tracking_enabled' => 1,
        ) );
        $this->assertTrue( $result['tracking_enabled'] );
    }

    public function test_sanitize_settings_caps_cookie_duration() {
        $result = $this->settings->sanitize_settings( array(
            'cookie_duration' => 999,
        ) );
        $this->assertSame( 365, $result['cookie_duration'] );
    }

    public function test_sanitize_settings_accepts_valid_cookie_duration() {
        $result = $this->settings->sanitize_settings( array(
            'cookie_duration' => 60,
        ) );
        $this->assertSame( 60, $result['cookie_duration'] );
    }

    public function test_sanitize_settings_sanitizes_conversion_statuses() {
        $result = $this->settings->sanitize_settings( array(
            'conversion_statuses' => array( 'wc-completed', 'wc-processing' ),
        ) );
        $this->assertContains( 'wc-completed', $result['conversion_statuses'] );
        $this->assertContains( 'wc-processing', $result['conversion_statuses'] );
    }

    public function test_sanitize_settings_sanitizes_attribution_model() {
        $result = $this->settings->sanitize_settings( array(
            'attribution_model' => 'first_click',
        ) );
        $this->assertSame( 'first_click', $result['attribution_model'] );
    }

    public function test_sanitize_settings_sanitizes_product_sync() {
        $result = $this->settings->sanitize_settings( array(
            'product_sync_enabled'  => true,
            'product_sync_interval' => 'daily',
        ) );
        $this->assertTrue( $result['product_sync_enabled'] );
        $this->assertSame( 'daily', $result['product_sync_interval'] );
    }

    public function test_sanitize_settings_sanitizes_track_url_params() {
        $result = $this->settings->sanitize_settings( array(
            'track_url_params' => array( 'ref', 'aff', 'campaign' ),
        ) );
        $this->assertContains( 'ref', $result['track_url_params'] );
        $this->assertCount( 3, $result['track_url_params'] );
    }

    public function test_sanitize_settings_sanitizes_call_tracking() {
        $result = $this->settings->sanitize_settings( array(
            'call_tracking_enabled'        => true,
            'call_tracking_phone'          => '+1-555-123-4567',
            'call_tracking_phone_selector' => '.phone-number',
            'call_tracking_url'            => 'https://call.affilync.com',
        ) );
        $this->assertTrue( $result['call_tracking_enabled'] );
        $this->assertSame( '+1-555-123-4567', $result['call_tracking_phone'] );
        $this->assertSame( '.phone-number', $result['call_tracking_phone_selector'] );
        $this->assertStringContainsString( 'call.affilync.com', $result['call_tracking_url'] );
    }

    public function test_sanitize_settings_strips_xss_from_phone() {
        $result = $this->settings->sanitize_settings( array(
            'call_tracking_phone' => '<script>alert("xss")</script>555',
        ) );
        $this->assertStringNotContainsString( '<script>', $result['call_tracking_phone'] );
    }

    public function test_sanitize_settings_empty_input_returns_empty() {
        $result = $this->settings->sanitize_settings( array() );
        $this->assertIsArray( $result );
        $this->assertEmpty( $result );
    }

    public function test_sanitize_settings_ignores_unknown_keys() {
        $result = $this->settings->sanitize_settings( array(
            'unknown_field' => 'should_be_ignored',
        ) );
        $this->assertArrayNotHasKey( 'unknown_field', $result );
    }

    // ------------------------------------------------------------------
    // handle_activation_redirect (smoke test)
    // ------------------------------------------------------------------

    public function test_handle_activation_redirect_noop_without_transient() {
        $this->settings->handle_activation_redirect();
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // display_notices
    // ------------------------------------------------------------------

    public function test_display_notices_noop_without_message() {
        unset( $_GET['affilync_message'] );

        ob_start();
        $this->settings->display_notices();
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    // ------------------------------------------------------------------
    // render_tracking_section
    // ------------------------------------------------------------------

    public function test_render_tracking_section_outputs_html() {
        ob_start();
        $this->settings->render_tracking_section();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Configure how affiliate conversions', $output );
    }

    // ------------------------------------------------------------------
    // render_checkbox_field
    // ------------------------------------------------------------------

    public function test_render_checkbox_field_outputs_input() {
        ob_start();
        $this->settings->render_checkbox_field( array(
            'id'          => 'test_checkbox',
            'description' => 'A test checkbox',
        ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'type="checkbox"', $output );
        $this->assertStringContainsString( 'test_checkbox', $output );
        $this->assertStringContainsString( 'A test checkbox', $output );
    }

    // ------------------------------------------------------------------
    // render_text_field
    // ------------------------------------------------------------------

    public function test_render_text_field_outputs_input() {
        ob_start();
        $this->settings->render_text_field( array(
            'id'          => 'test_field',
            'description' => 'A test field',
            'placeholder' => 'Enter value',
        ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'type="text"', $output );
        $this->assertStringContainsString( 'test_field', $output );
        $this->assertStringContainsString( 'Enter value', $output );
    }
}
