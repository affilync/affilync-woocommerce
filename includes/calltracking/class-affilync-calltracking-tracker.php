<?php
/**
 * Call Tracking Tracker (Orchestrator).
 *
 * Main entry point for the call tracking feature.
 * Initializes sub-components and provides utility methods.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Call Tracking Tracker.
 *
 * @since 1.5.0
 */
class Affilync_CallTracking_Tracker {

    /**
     * Call log handler.
     *
     * @var Affilync_CallTracking_Call_Log
     */
    public $call_log;

    /**
     * Call stats handler.
     *
     * @var Affilync_CallTracking_Stats
     */
    public $stats;

    /**
     * DNI injector.
     *
     * @var Affilync_CallTracking_DNI_Injector|null
     */
    public $dni_injector;

    /**
     * Constructor.
     *
     * Initializes call tracking sub-components based on
     * the merchant's settings and billing plan.
     */
    public function __construct() {
        $this->call_log = new Affilync_CallTracking_Call_Log();
        $this->stats    = new Affilync_CallTracking_Stats();

        // Initialize DNI injector if the plan supports call tracking.
        $this->maybe_init_dni();
    }

    /**
     * Conditionally initialize the DNI injector.
     *
     * Only loads on the frontend when the merchant's plan
     * includes call tracking and the feature is enabled.
     */
    private function maybe_init_dni() {
        // Don't inject DNI in admin or during AJAX/REST requests.
        if ( is_admin() || wp_doing_ajax() || defined( 'REST_REQUEST' ) ) {
            return;
        }

        $subscription = affilync()->subscription;
        if ( ! $subscription || ! $subscription->has_call_tracking() ) {
            return;
        }

        $settings       = get_option( 'affilync_settings', array() );
        $phone_selector = ! empty( $settings['call_tracking_phone_selector'] )
            ? $settings['call_tracking_phone_selector']
            : '[data-affilync-phone], a[href^="tel:"]';

        $has_widget = $subscription->has_call_tracking_dni();

        $this->dni_injector = new Affilync_CallTracking_DNI_Injector(
            $this->get_call_tracker_url(),
            $phone_selector,
            $has_widget
        );
    }

    /**
     * Get the call tracker service URL.
     *
     * Checks settings first, then falls back to the constant.
     *
     * @return string Call tracker URL.
     */
    public function get_call_tracker_url() {
        $settings = get_option( 'affilync_settings', array() );

        if ( ! empty( $settings['call_tracking_url'] ) ) {
            return untrailingslashit( $settings['call_tracking_url'] );
        }

        return untrailingslashit( AFFILYNC_CALL_TRACKER_URL );
    }

    /**
     * Check if call tracking is active and the feature is enabled.
     *
     * @return bool True if call tracking is active.
     */
    public function is_active() {
        $settings = get_option( 'affilync_settings', array() );

        if ( empty( $settings['call_tracking_enabled'] ) ) {
            return false;
        }

        $subscription = affilync()->subscription;

        return $subscription && $subscription->has_call_tracking();
    }
}
