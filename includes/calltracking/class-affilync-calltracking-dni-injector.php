<?php
/**
 * DNI (Dynamic Number Insertion) Injector.
 *
 * Injects JavaScript into the storefront footer to swap
 * the merchant's phone number with an affiliate tracking number.
 *
 * Two modes:
 * - Starter plan: Inline IIFE script that fetches from DNI API
 * - Pro+ plan: External widget.js with full caching and analytics
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Call Tracking DNI Injector.
 *
 * @since 1.5.0
 */
class Affilync_CallTracking_DNI_Injector {

    /**
     * Call tracker service URL.
     *
     * @var string
     */
    private $call_tracker_url;

    /**
     * CSS selector for phone elements to swap.
     *
     * @var string
     */
    private $phone_selector;

    /**
     * Whether the merchant's plan supports the full DNI widget.
     *
     * @var bool
     */
    private $has_widget;

    /**
     * Constructor.
     *
     * @param string $call_tracker_url Call tracker service URL.
     * @param string $phone_selector   CSS selector for phone elements.
     * @param bool   $has_widget       Whether to use the full widget (Pro+).
     */
    public function __construct( $call_tracker_url, $phone_selector, $has_widget = false ) {
        $this->call_tracker_url = untrailingslashit( $call_tracker_url );
        $this->phone_selector   = $phone_selector;
        $this->has_widget       = $has_widget;

        add_action( 'wp_footer', array( $this, 'inject_dni_script' ), 99 );
    }

    /**
     * Inject the DNI script into the page footer.
     *
     * Only outputs on the frontend (not admin), and only when
     * an affiliate tracking cookie is present.
     */
    public function inject_dni_script() {
        // Don't inject in admin.
        if ( is_admin() ) {
            return;
        }

        if ( $this->has_widget ) {
            $this->inject_widget_script();
        } else {
            $this->inject_inline_script();
        }
    }

    /**
     * Inject the full external widget.js (Pro+ plans).
     *
     * Loads the call tracker's widget.js with data attributes
     * for configuration. Handles caching, visitor tracking,
     * and analytics automatically.
     */
    private function inject_widget_script() {
        $selector = esc_attr( $this->phone_selector );
        $url      = esc_url( $this->call_tracker_url . '/api/v1/dni/widget.js' );
        ?>
        <script
            src="<?php echo $url; ?>"
            data-selector="<?php echo $selector; ?>"
            data-cookie-name="affilync_tracking"
            data-format="us"
            data-cache-duration="3600"
            data-loading-text=""
            defer>
        </script>
        <?php
    }

    /**
     * Inject a lightweight inline IIFE script (Starter plan).
     *
     * Reads the affilync_tracking cookie to extract affiliate_id
     * and campaign_id, then fetches the tracking number from the
     * DNI API and swaps matching DOM elements.
     */
    private function inject_inline_script() {
        $api_url  = esc_js( $this->call_tracker_url . '/api/v1/dni/dni' );
        $selector = esc_js( $this->phone_selector );
        ?>
        <script>
        (function(){
            try {
                var c = document.cookie.match(/(?:^|;\s*)affilync_tracking=([^;]*)/);
                if (!c) return;
                var d;
                try { d = JSON.parse(decodeURIComponent(c[1])); } catch(e) { return; }
                if (!d.affiliate_id) return;
                var p = '<?php echo $api_url; ?>'
                    + '?affiliate_id=' + encodeURIComponent(d.affiliate_id);
                if (d.campaign_id) p += '&campaign_id=' + encodeURIComponent(d.campaign_id);
                p += '&website_url=' + encodeURIComponent(window.location.href);
                var x = new XMLHttpRequest();
                x.open('GET', p, true);
                x.timeout = 5000;
                x.onload = function(){
                    if (x.status !== 200) return;
                    var r;
                    try { r = JSON.parse(x.responseText); } catch(e) { return; }
                    if (!r.formatted) return;
                    var els = document.querySelectorAll('<?php echo $selector; ?>');
                    for (var i = 0; i < els.length; i++) {
                        var el = els[i];
                        if (el.tagName === 'A' && el.href && el.href.indexOf('tel:') === 0) {
                            el.href = 'tel:' + r.phone_number;
                        }
                        if (el.textContent) el.textContent = r.formatted;
                    }
                };
                x.send();
            } catch(e) {}
        })();
        </script>
        <?php
    }
}
