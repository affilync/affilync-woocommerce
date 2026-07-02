<?php
/**
 * Cookie Handler for affiliate tracking.
 *
 * Captures and manages affiliate tracking cookies.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Cookie Handler.
 *
 * @since 1.0.0
 */
class Affilync_Tracking_Cookie_Handler {

    /**
     * Cookie name for tracking data.
     *
     * @var string
     */
    const COOKIE_NAME = 'affilync_tracking';

    /**
     * Cookie name for first click (for attribution models).
     *
     * @var string
     */
    const FIRST_CLICK_COOKIE = 'affilync_first_click';

    /**
     * Default cookie duration in days.
     *
     * @var int
     */
    const DEFAULT_DURATION = 30;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Capture tracking params on page load.
        add_action( 'init', array( $this, 'capture_tracking_params' ), 1 );

        // Store in WC session on cart/checkout.
        add_action( 'woocommerce_add_to_cart', array( $this, 'store_in_session' ), 10 );
        add_action( 'woocommerce_checkout_init', array( $this, 'store_in_session' ), 10 );

        // Output tracking script in footer.
        add_action( 'wp_footer', array( $this, 'output_tracking_script' ) );
    }

    /**
     * Capture tracking parameters from URL.
     */
    public function capture_tracking_params() {
        // Don't run in admin or during AJAX.
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        // Check if tracking is enabled.
        $settings = get_option( 'affilync_settings', array() );
        if ( isset( $settings['tracking_enabled'] ) && ! $settings['tracking_enabled'] ) {
            return;
        }

        // Get configured URL parameters.
        $track_params = isset( $settings['track_url_params'] )
            ? $settings['track_url_params']
            : array( 'ref', 'aff', 'campaign', 'click_id', 'link_id' );

        // Check for any tracking parameters.
        $tracking_data = array();

        // Map URL params to tracking data keys.
        $param_map = array(
            'ref'          => 'affiliate_id',
            'aff'          => 'affiliate_id',
            'affiliate'    => 'affiliate_id',
            'affiliate_id' => 'affiliate_id',
            'campaign'     => 'campaign_id',
            'campaign_id'  => 'campaign_id',
            'link'         => 'link_id',
            'link_id'      => 'link_id',
            'click'        => 'click_id',
            'click_id'     => 'click_id',
            'source'       => 'source',
            'sub1'         => 'sub1',
            'sub2'         => 'sub2',
            'sub3'         => 'sub3',
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        foreach ( $_GET as $key => $value ) {
            $key = sanitize_key( $key );

            if ( isset( $param_map[ $key ] ) && in_array( $key, $track_params, true ) ) {
                $tracking_data[ $param_map[ $key ] ] = sanitize_text_field( $value );
            }
        }

        // No tracking params found.
        if ( empty( $tracking_data ) ) {
            return;
        }

        // Add metadata.
        $tracking_data['captured_at'] = time();
        $tracking_data['landing_url'] = $this->get_current_url();
        $tracking_data['referrer']    = isset( $_SERVER['HTTP_REFERER'] )
            ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
            : '';
        $tracking_data['user_agent']  = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';

        // Get attribution model setting.
        $attribution_model = isset( $settings['attribution_model'] )
            ? $settings['attribution_model']
            : 'last_click';

        // Handle attribution model.
        if ( $attribution_model === 'first_click' ) {
            // Only set if no existing first click.
            $existing_first = $this->get_cookie_data( self::FIRST_CLICK_COOKIE );
            if ( empty( $existing_first ) ) {
                $this->set_cookie( self::FIRST_CLICK_COOKIE, $tracking_data );
            }
        }

        // Always update last click cookie.
        $this->set_cookie( self::COOKIE_NAME, $tracking_data );

        // Store in WC session if available.
        $this->store_in_session();
    }

    /**
     * Set tracking cookie.
     *
     * @param string $name Cookie name.
     * @param array  $data Cookie data.
     */
    private function set_cookie( $name, $data ) {
        $settings = get_option( 'affilync_settings', array() );
        $duration = isset( $settings['cookie_duration'] )
            ? intval( $settings['cookie_duration'] )
            : self::DEFAULT_DURATION;

        $expiry = time() + ( $duration * DAY_IN_SECONDS );

        // Encode and sign data.
        $encoded = base64_encode( wp_json_encode( $data ) );
        $signature = $this->sign_cookie( $encoded );
        $cookie_value = $encoded . '.' . $signature;

        // Set cookie.
        if ( ! headers_sent() ) {
            setcookie(
                $name,
                $cookie_value,
                array(
                    'expires'  => $expiry,
                    'path'     => COOKIEPATH,
                    'domain'   => COOKIE_DOMAIN,
                    'secure'   => is_ssl(),
                    'httponly' => false, // Needs JS access for pixel.
                    'samesite' => 'Lax',
                )
            );

            // Also set in superglobal for immediate access.
            $_COOKIE[ $name ] = $cookie_value;
        }
    }

    /**
     * Get tracking data from cookies.
     *
     * @return array Tracking data.
     */
    public function get_tracking_data() {
        $settings = get_option( 'affilync_settings', array() );
        $attribution_model = isset( $settings['attribution_model'] )
            ? $settings['attribution_model']
            : 'last_click';

        // Get appropriate cookie based on attribution model.
        if ( $attribution_model === 'first_click' ) {
            $data = $this->get_cookie_data( self::FIRST_CLICK_COOKIE );
            if ( ! empty( $data ) ) {
                return $data;
            }
        }

        return $this->get_cookie_data( self::COOKIE_NAME );
    }

    /**
     * Get data from a cookie.
     *
     * @param string $name Cookie name.
     * @return array Cookie data or empty array.
     */
    private function get_cookie_data( $name ) {
        if ( ! isset( $_COOKIE[ $name ] ) ) {
            return array();
        }

        $cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );

        // Split value and signature.
        $parts = explode( '.', $cookie_value );
        if ( count( $parts ) !== 2 ) {
            return array();
        }

        list( $encoded, $signature ) = $parts;

        // Verify signature.
        if ( ! $this->verify_cookie_signature( $encoded, $signature ) ) {
            return array();
        }

        // Decode data.
        $decoded = base64_decode( $encoded );
        $data = json_decode( $decoded, true );

        return is_array( $data ) ? $data : array();
    }

    /**
     * Sign cookie data.
     *
     * @param string $data Cookie data.
     * @return string Signature.
     */
    private function sign_cookie( $data ) {
        $key = $this->get_signing_key();
        return hash_hmac( 'sha256', $data, $key );
    }

    /**
     * Verify cookie signature.
     *
     * @param string $data      Cookie data.
     * @param string $signature Signature to verify.
     * @return bool True if valid.
     */
    private function verify_cookie_signature( $data, $signature ) {
        $expected = $this->sign_cookie( $data );
        return hash_equals( $expected, $signature );
    }

    /**
     * Get signing key.
     *
     * @return string Signing key.
     */
    private function get_signing_key() {
        if ( defined( 'AFFILYNC_COOKIE_KEY' ) ) {
            return AFFILYNC_COOKIE_KEY;
        }

        if ( defined( 'AUTH_KEY' ) ) {
            return AUTH_KEY;
        }

        return 'affilync_default_key';
    }

    /**
     * Store tracking data in WC session.
     */
    public function store_in_session() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        $tracking_data = $this->get_tracking_data();
        if ( ! empty( $tracking_data ) ) {
            WC()->session->set( 'affilync_tracking', $tracking_data );
        }
    }

    /**
     * Clear tracking cookies.
     */
    public function clear_cookies() {
        $past = time() - HOUR_IN_SECONDS;

        setcookie( self::COOKIE_NAME, '', $past, COOKIEPATH, COOKIE_DOMAIN );
        setcookie( self::FIRST_CLICK_COOKIE, '', $past, COOKIEPATH, COOKIE_DOMAIN );

        unset( $_COOKIE[ self::COOKIE_NAME ] );
        unset( $_COOKIE[ self::FIRST_CLICK_COOKIE ] );
    }

    /**
     * Get current URL.
     *
     * @return string Current URL.
     */
    private function get_current_url() {
        $protocol = is_ssl() ? 'https://' : 'http://';
        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        return $protocol . $host . $uri;
    }

    /**
     * Output tracking script in footer.
     */
    public function output_tracking_script() {
        // Don't output in admin.
        if ( is_admin() ) {
            return;
        }

        // Check if tracking is enabled.
        $settings = get_option( 'affilync_settings', array() );
        if ( isset( $settings['tracking_enabled'] ) && ! $settings['tracking_enabled'] ) {
            return;
        }

        // NOTE: This script deliberately does NOT write the tracking cookie.
        // Affiliate params are captured and persisted server-side in
        // capture_tracking_params() (hooked on `init`, priority 1), which
        // writes a SIGNED cookie ("{base64(json)}.{hmac}"). The old JS wrote an
        // UNSIGNED btoa(JSON) value that the signed PHP reader
        // (get_cookie_data(): requires a 2-part "value.signature" cookie)
        // always rejected — silently clobbering the valid signed cookie and
        // breaking attribution. The script now only exposes a read helper for
        // any front-end consumers.
        ?>
        <script type="text/javascript">
        (function() {
            var affilyncTracking = {
                cookieName: '<?php echo esc_js( self::COOKIE_NAME ); ?>',

                // Read-only helper. Returns the decoded tracking payload from
                // the signed cookie (the JSON lives in the part before the
                // "." signature separator), or null if absent/malformed.
                getCookie: function() {
                    var cookies = document.cookie.split(';');
                    for (var i = 0; i < cookies.length; i++) {
                        var cookie = cookies[i].trim();
                        if (cookie.indexOf(this.cookieName + '=') === 0) {
                            try {
                                var parts = cookie.substring(this.cookieName.length + 1).split('.');
                                return JSON.parse(atob(parts[0]));
                            } catch (e) {
                                return null;
                            }
                        }
                    }
                    return null;
                }
            };

            window.affilyncTracking = affilyncTracking;
        })();
        </script>
        <?php
    }

    /**
     * Check if there is active tracking.
     *
     * @return bool True if tracking data exists.
     */
    public function has_tracking() {
        $data = $this->get_tracking_data();
        return ! empty( $data['affiliate_id'] ) || ! empty( $data['click_id'] );
    }

    /**
     * Get affiliate ID from tracking.
     *
     * @return string|null Affiliate ID.
     */
    public function get_affiliate_id() {
        $data = $this->get_tracking_data();
        return isset( $data['affiliate_id'] ) ? $data['affiliate_id'] : null;
    }

    /**
     * Get click ID from tracking.
     *
     * @return string|null Click ID.
     */
    public function get_click_id() {
        $data = $this->get_tracking_data();
        return isset( $data['click_id'] ) ? $data['click_id'] : null;
    }

    /**
     * Get campaign ID from tracking.
     *
     * @return string|null Campaign ID.
     */
    public function get_campaign_id() {
        $data = $this->get_tracking_data();
        return isset( $data['campaign_id'] ) ? $data['campaign_id'] : null;
    }
}
