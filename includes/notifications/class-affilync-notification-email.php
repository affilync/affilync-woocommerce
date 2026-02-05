<?php
/**
 * Email Notification Class.
 *
 * Handles email notifications via Brevo (SendinBlue) transactional email service.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Email Notification.
 *
 * @since 1.0.0
 */
class Affilync_Notification_Email {

    /**
     * Brevo API endpoint.
     *
     * @var string
     */
    const BREVO_API_URL = 'https://api.brevo.com/v3';

    /**
     * Email template IDs.
     */
    const TEMPLATE_CONNECTED        = 1;
    const TEMPLATE_DISCONNECTED     = 2;
    const TEMPLATE_CONVERSION       = 3;
    const TEMPLATE_USAGE_WARNING    = 4;
    const TEMPLATE_USAGE_LIMIT      = 5;
    const TEMPLATE_VERIFICATION     = 6;
    const TEMPLATE_PAYOUT           = 7;
    const TEMPLATE_WEEKLY_REPORT    = 8;
    const TEMPLATE_SUBSCRIPTION     = 9;
    const TEMPLATE_TRIAL_ENDING     = 10;

    /**
     * API client instance.
     *
     * @var Affilync_API_Client
     */
    private $api_client;

    /**
     * Encryption handler.
     *
     * @var Affilync_Security_Encryption
     */
    private $encryption;

    /**
     * Brevo API key.
     *
     * @var string
     */
    private $brevo_api_key;

    /**
     * Email settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor.
     *
     * @param Affilync_API_Client          $api_client  API client instance.
     * @param Affilync_Security_Encryption $encryption  Encryption handler.
     */
    public function __construct( $api_client, $encryption ) {
        $this->api_client = $api_client;
        $this->encryption = $encryption;

        $this->load_settings();
        $this->init_hooks();
    }

    /**
     * Load email settings.
     */
    private function load_settings() {
        $defaults = array(
            'enabled'           => true,
            'send_via'          => 'brevo', // brevo, api, wp_mail
            'from_name'         => get_bloginfo( 'name' ),
            'from_email'        => get_option( 'admin_email' ),
            'admin_email'       => get_option( 'admin_email' ),
            'notify_admin'      => array(
                'new_conversion'    => true,
                'usage_warning'     => true,
                'usage_limit'       => true,
                'verification'      => true,
            ),
            'notify_user'       => array(
                'connected'         => true,
                'disconnected'      => true,
                'weekly_report'     => false,
                'trial_ending'      => true,
            ),
        );

        $this->settings = wp_parse_args(
            get_option( 'affilync_email_settings', array() ),
            $defaults
        );

        // Load Brevo API key.
        $encrypted_key = get_option( 'affilync_brevo_api_key', '' );
        if ( $encrypted_key ) {
            $this->brevo_api_key = $this->encryption->decrypt( $encrypted_key );
        }
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Connection events.
        add_action( 'affilync_connection_changed', array( $this, 'on_connection_changed' ), 10, 2 );

        // Conversion events.
        add_action( 'affilync_conversion_tracked', array( $this, 'on_conversion_tracked' ), 10, 2 );

        // Usage events.
        add_action( 'affilync_usage_warning', array( $this, 'on_usage_warning' ), 10, 2 );
        add_action( 'affilync_usage_limit_reached', array( $this, 'on_usage_limit_reached' ), 10, 2 );

        // Verification events.
        add_action( 'affilync_brand_verified', array( $this, 'on_brand_verified' ) );
        add_action( 'affilync_brand_rejected', array( $this, 'on_brand_rejected' ), 10, 2 );

        // Subscription events.
        add_action( 'affilync_subscription_changed', array( $this, 'on_subscription_changed' ), 10, 2 );
        add_action( 'affilync_trial_ending', array( $this, 'on_trial_ending' ), 10, 2 );

        // Cron for weekly reports.
        add_action( 'affilync_send_weekly_report', array( $this, 'send_weekly_report' ) );

        // Admin settings.
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Set Brevo API key.
     *
     * @param string $api_key Brevo API key.
     * @return bool
     */
    public function set_brevo_api_key( $api_key ) {
        if ( empty( $api_key ) ) {
            delete_option( 'affilync_brevo_api_key' );
            $this->brevo_api_key = '';
            return true;
        }

        $encrypted = $this->encryption->encrypt( $api_key );
        if ( ! $encrypted ) {
            return false;
        }

        update_option( 'affilync_brevo_api_key', $encrypted );
        $this->brevo_api_key = $api_key;

        return true;
    }

    /**
     * Check if Brevo is configured.
     *
     * @return bool
     */
    public function is_brevo_configured() {
        return ! empty( $this->brevo_api_key );
    }

    /**
     * Send email via Brevo transactional API.
     *
     * @param string $to_email    Recipient email.
     * @param string $to_name     Recipient name.
     * @param int    $template_id Brevo template ID.
     * @param array  $params      Template parameters.
     * @return bool|WP_Error
     */
    public function send_brevo_email( $to_email, $to_name, $template_id, $params = array() ) {
        if ( ! $this->is_brevo_configured() ) {
            return $this->fallback_to_wp_mail( $to_email, $to_name, $template_id, $params );
        }

        $body = array(
            'to'            => array(
                array(
                    'email' => $to_email,
                    'name'  => $to_name,
                ),
            ),
            'templateId'    => $template_id,
            'params'        => $this->prepare_template_params( $params ),
            'headers'       => array(
                'X-Mailin-Tag' => 'affilync-woocommerce',
            ),
        );

        // Add sender if custom from is set.
        if ( $this->settings['from_email'] ) {
            $body['sender'] = array(
                'email' => $this->settings['from_email'],
                'name'  => $this->settings['from_name'],
            );
        }

        $response = wp_remote_post(
            self::BREVO_API_URL . '/smtp/email',
            array(
                'headers' => array(
                    'accept'       => 'application/json',
                    'api-key'      => $this->brevo_api_key,
                    'content-type' => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->log_email_error( 'brevo_request_failed', $response->get_error_message(), $to_email );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code >= 400 ) {
            $error_message = $data['message'] ?? 'Unknown Brevo error';
            $this->log_email_error( 'brevo_api_error', $error_message, $to_email );
            return new WP_Error( 'brevo_error', $error_message );
        }

        $this->log_email_sent( $to_email, $template_id, $data['messageId'] ?? '' );

        return true;
    }

    /**
     * Send email via Affilync API (proxy through platform).
     *
     * @param string $to_email    Recipient email.
     * @param string $template    Template name.
     * @param array  $params      Template parameters.
     * @return bool|WP_Error
     */
    public function send_via_api( $to_email, $template, $params = array() ) {
        $response = $this->api_client->post(
            '/api/woocommerce/notifications/email',
            array(
                'to'       => $to_email,
                'template' => $template,
                'params'   => $params,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->log_email_error( 'api_send_failed', $response->get_error_message(), $to_email );
            return $response;
        }

        return true;
    }

    /**
     * Fallback to wp_mail when Brevo is not configured.
     *
     * @param string $to_email    Recipient email.
     * @param string $to_name     Recipient name.
     * @param int    $template_id Template ID for subject/body lookup.
     * @param array  $params      Template parameters.
     * @return bool
     */
    private function fallback_to_wp_mail( $to_email, $to_name, $template_id, $params ) {
        $template = $this->get_fallback_template( $template_id, $params );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $this->settings['from_name'], $this->settings['from_email'] ),
        );

        $sent = wp_mail( $to_email, $template['subject'], $template['body'], $headers );

        if ( $sent ) {
            $this->log_email_sent( $to_email, $template_id, 'wp_mail' );
        } else {
            $this->log_email_error( 'wp_mail_failed', 'wp_mail returned false', $to_email );
        }

        return $sent;
    }

    /**
     * Get fallback template content.
     *
     * @param int   $template_id Template ID.
     * @param array $params      Template parameters.
     * @return array
     */
    private function get_fallback_template( $template_id, $params ) {
        $site_name = get_bloginfo( 'name' );
        $templates = array(
            self::TEMPLATE_CONNECTED => array(
                'subject' => sprintf( '[%s] Connected to Affilync', $site_name ),
                'body'    => $this->render_template( 'connected', $params ),
            ),
            self::TEMPLATE_DISCONNECTED => array(
                'subject' => sprintf( '[%s] Disconnected from Affilync', $site_name ),
                'body'    => $this->render_template( 'disconnected', $params ),
            ),
            self::TEMPLATE_CONVERSION => array(
                'subject' => sprintf( '[%s] New Affiliate Conversion', $site_name ),
                'body'    => $this->render_template( 'conversion', $params ),
            ),
            self::TEMPLATE_USAGE_WARNING => array(
                'subject' => sprintf( '[%s] Affilync Usage Warning', $site_name ),
                'body'    => $this->render_template( 'usage-warning', $params ),
            ),
            self::TEMPLATE_USAGE_LIMIT => array(
                'subject' => sprintf( '[%s] Affilync Usage Limit Reached', $site_name ),
                'body'    => $this->render_template( 'usage-limit', $params ),
            ),
            self::TEMPLATE_VERIFICATION => array(
                'subject' => sprintf( '[%s] Brand Verification Update', $site_name ),
                'body'    => $this->render_template( 'verification', $params ),
            ),
            self::TEMPLATE_SUBSCRIPTION => array(
                'subject' => sprintf( '[%s] Subscription Updated', $site_name ),
                'body'    => $this->render_template( 'subscription', $params ),
            ),
            self::TEMPLATE_TRIAL_ENDING => array(
                'subject' => sprintf( '[%s] Your Affilync Trial is Ending Soon', $site_name ),
                'body'    => $this->render_template( 'trial-ending', $params ),
            ),
            self::TEMPLATE_WEEKLY_REPORT => array(
                'subject' => sprintf( '[%s] Weekly Affilync Report', $site_name ),
                'body'    => $this->render_template( 'weekly-report', $params ),
            ),
        );

        return $templates[ $template_id ] ?? array(
            'subject' => sprintf( '[%s] Affilync Notification', $site_name ),
            'body'    => '<p>You have a notification from Affilync.</p>',
        );
    }

    /**
     * Render email template.
     *
     * @param string $template Template name.
     * @param array  $params   Template parameters.
     * @return string
     */
    private function render_template( $template, $params ) {
        $template_file = AFFILYNC_PLUGIN_DIR . 'templates/emails/' . $template . '.php';

        if ( ! file_exists( $template_file ) ) {
            return $this->get_default_template( $template, $params );
        }

        ob_start();
        extract( $params, EXTR_SKIP );
        include $template_file;
        return ob_get_clean();
    }

    /**
     * Get default template HTML.
     *
     * @param string $template Template name.
     * @param array  $params   Template parameters.
     * @return string
     */
    private function get_default_template( $template, $params ) {
        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url();
        $logo_url  = AFFILYNC_PLUGIN_URL . 'assets/images/logo.png';

        $header = sprintf(
            '<div style="background:#0066e6;padding:20px;text-align:center;">
                <img src="%s" alt="Affilync" style="height:40px;">
            </div>',
            esc_url( $logo_url )
        );

        $footer = sprintf(
            '<div style="background:#f6f7f7;padding:20px;text-align:center;font-size:12px;color:#666;">
                <p>Sent by Affilync for WooCommerce</p>
                <p><a href="%s">%s</a></p>
            </div>',
            esc_url( $site_url ),
            esc_html( $site_name )
        );

        $content = '';
        switch ( $template ) {
            case 'connected':
                $content = sprintf(
                    '<h2>%s</h2><p>%s</p><p><a href="%s" style="background:#0066e6;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">%s</a></p>',
                    __( 'Successfully Connected!', 'affilync-woocommerce' ),
                    __( 'Your WooCommerce store is now connected to Affilync. You can start tracking affiliate conversions.', 'affilync-woocommerce' ),
                    esc_url( admin_url( 'admin.php?page=affilync-settings' ) ),
                    __( 'View Settings', 'affilync-woocommerce' )
                );
                break;

            case 'conversion':
                $content = sprintf(
                    '<h2>%s</h2><p>%s</p><table style="width:100%%;border-collapse:collapse;"><tr><td style="padding:10px;border:1px solid #ddd;"><strong>%s</strong></td><td style="padding:10px;border:1px solid #ddd;">%s</td></tr><tr><td style="padding:10px;border:1px solid #ddd;"><strong>%s</strong></td><td style="padding:10px;border:1px solid #ddd;">%s</td></tr><tr><td style="padding:10px;border:1px solid #ddd;"><strong>%s</strong></td><td style="padding:10px;border:1px solid #ddd;">%s</td></tr></table>',
                    __( 'New Affiliate Conversion', 'affilync-woocommerce' ),
                    __( 'A new affiliate conversion has been tracked:', 'affilync-woocommerce' ),
                    __( 'Order ID', 'affilync-woocommerce' ),
                    esc_html( $params['order_id'] ?? '-' ),
                    __( 'Total', 'affilync-woocommerce' ),
                    wc_price( $params['order_total'] ?? 0 ),
                    __( 'Commission', 'affilync-woocommerce' ),
                    wc_price( $params['commission'] ?? 0 )
                );
                break;

            case 'usage-warning':
                $content = sprintf(
                    '<h2>%s</h2><p>%s</p><p>%s: <strong>%d%%</strong></p><p><a href="%s" style="background:#0066e6;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">%s</a></p>',
                    __( 'Usage Warning', 'affilync-woocommerce' ),
                    __( 'You are approaching your plan limits. Consider upgrading to continue tracking conversions.', 'affilync-woocommerce' ),
                    __( 'Current Usage', 'affilync-woocommerce' ),
                    intval( $params['percentage'] ?? 0 ),
                    esc_url( admin_url( 'admin.php?page=affilync-settings&tab=billing' ) ),
                    __( 'Upgrade Plan', 'affilync-woocommerce' )
                );
                break;

            case 'usage-limit':
                $content = sprintf(
                    '<h2>%s</h2><p>%s</p><p><a href="%s" style="background:#d63638;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">%s</a></p>',
                    __( 'Usage Limit Reached', 'affilync-woocommerce' ),
                    __( 'You have reached your plan limits. Upgrade now to continue tracking affiliate conversions.', 'affilync-woocommerce' ),
                    esc_url( admin_url( 'admin.php?page=affilync-settings&tab=billing' ) ),
                    __( 'Upgrade Now', 'affilync-woocommerce' )
                );
                break;

            case 'verification':
                $status = $params['status'] ?? 'verified';
                if ( $status === 'verified' ) {
                    $content = sprintf(
                        '<h2>%s</h2><p>%s</p>',
                        __( 'Brand Verified!', 'affilync-woocommerce' ),
                        __( 'Congratulations! Your brand has been successfully verified on Affilync.', 'affilync-woocommerce' )
                    );
                } else {
                    $content = sprintf(
                        '<h2>%s</h2><p>%s</p><p><strong>%s:</strong> %s</p>',
                        __( 'Verification Update', 'affilync-woocommerce' ),
                        __( 'Your brand verification request has been reviewed.', 'affilync-woocommerce' ),
                        __( 'Status', 'affilync-woocommerce' ),
                        esc_html( ucfirst( $status ) )
                    );
                }
                break;

            default:
                $content = '<p>' . __( 'You have a notification from Affilync.', 'affilync-woocommerce' ) . '</p>';
        }

        return sprintf(
            '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">%s<div style="padding:30px;">%s</div>%s</div>',
            $header,
            $content,
            $footer
        );
    }

    /**
     * Prepare template parameters for Brevo.
     *
     * @param array $params Raw parameters.
     * @return array
     */
    private function prepare_template_params( $params ) {
        $defaults = array(
            'site_name'  => get_bloginfo( 'name' ),
            'site_url'   => home_url(),
            'admin_url'  => admin_url(),
            'settings_url' => admin_url( 'admin.php?page=affilync-settings' ),
            'year'       => gmdate( 'Y' ),
        );

        return array_merge( $defaults, $params );
    }

    /**
     * Log email sent.
     *
     * @param string $to_email    Recipient email.
     * @param int    $template_id Template ID.
     * @param string $message_id  Message ID from provider.
     */
    private function log_email_sent( $to_email, $template_id, $message_id ) {
        if ( defined( 'AFFILYNC_DEBUG' ) && AFFILYNC_DEBUG ) {
            error_log( sprintf(
                '[Affilync] Email sent: to=%s, template=%d, message_id=%s',
                $to_email,
                $template_id,
                $message_id
            ) );
        }
    }

    /**
     * Log email error.
     *
     * @param string $error_code Error code.
     * @param string $message    Error message.
     * @param string $to_email   Recipient email.
     */
    private function log_email_error( $error_code, $message, $to_email ) {
        error_log( sprintf(
            '[Affilync] Email error: code=%s, message=%s, to=%s',
            $error_code,
            $message,
            $to_email
        ) );
    }

    /**
     * Event handler: Connection changed.
     *
     * @param string $status     New connection status.
     * @param array  $connection Connection data.
     */
    public function on_connection_changed( $status, $connection = array() ) {
        if ( ! $this->settings['enabled'] ) {
            return;
        }

        if ( $status === 'connected' && $this->settings['notify_user']['connected'] ) {
            $this->send_brevo_email(
                $this->settings['admin_email'],
                get_bloginfo( 'name' ),
                self::TEMPLATE_CONNECTED,
                array( 'brand_id' => $connection['brand_id'] ?? '' )
            );
        }

        if ( $status === 'disconnected' && $this->settings['notify_user']['disconnected'] ) {
            $this->send_brevo_email(
                $this->settings['admin_email'],
                get_bloginfo( 'name' ),
                self::TEMPLATE_DISCONNECTED,
                array()
            );
        }
    }

    /**
     * Event handler: Conversion tracked.
     *
     * @param int   $order_id        Order ID.
     * @param array $conversion_data Conversion data.
     */
    public function on_conversion_tracked( $order_id, $conversion_data ) {
        if ( ! $this->settings['enabled'] || ! $this->settings['notify_admin']['new_conversion'] ) {
            return;
        }

        $this->send_brevo_email(
            $this->settings['admin_email'],
            get_bloginfo( 'name' ),
            self::TEMPLATE_CONVERSION,
            array(
                'order_id'    => $order_id,
                'order_total' => $conversion_data['order_total'] ?? 0,
                'commission'  => $conversion_data['commission'] ?? 0,
                'affiliate'   => $conversion_data['affiliate_id'] ?? '',
            )
        );
    }

    /**
     * Event handler: Usage warning.
     *
     * @param string $type       Resource type (conversions, products).
     * @param int    $percentage Current usage percentage.
     */
    public function on_usage_warning( $type, $percentage ) {
        if ( ! $this->settings['enabled'] || ! $this->settings['notify_admin']['usage_warning'] ) {
            return;
        }

        $this->send_brevo_email(
            $this->settings['admin_email'],
            get_bloginfo( 'name' ),
            self::TEMPLATE_USAGE_WARNING,
            array(
                'type'       => $type,
                'percentage' => $percentage,
            )
        );
    }

    /**
     * Event handler: Usage limit reached.
     *
     * @param string $type Resource type.
     * @param array  $usage Current usage data.
     */
    public function on_usage_limit_reached( $type, $usage ) {
        if ( ! $this->settings['enabled'] || ! $this->settings['notify_admin']['usage_limit'] ) {
            return;
        }

        $this->send_brevo_email(
            $this->settings['admin_email'],
            get_bloginfo( 'name' ),
            self::TEMPLATE_USAGE_LIMIT,
            array(
                'type'  => $type,
                'usage' => $usage,
            )
        );
    }

    /**
     * Event handler: Brand verified.
     *
     * @param array $verification_data Verification data.
     */
    public function on_brand_verified( $verification_data ) {
        if ( ! $this->settings['enabled'] || ! $this->settings['notify_admin']['verification'] ) {
            return;
        }

        $this->send_brevo_email(
            $this->settings['admin_email'],
            get_bloginfo( 'name' ),
            self::TEMPLATE_VERIFICATION,
            array(
                'status'   => 'verified',
                'brand_id' => $verification_data['brand_id'] ?? '',
            )
        );
    }

    /**
     * Event handler: Brand rejected.
     *
     * @param string $reason            Rejection reason.
     * @param array  $verification_data Verification data.
     */
    public function on_brand_rejected( $reason, $verification_data ) {
        if ( ! $this->settings['enabled'] || ! $this->settings['notify_admin']['verification'] ) {
            return;
        }

        $this->send_brevo_email(
            $this->settings['admin_email'],
            get_bloginfo( 'name' ),
            self::TEMPLATE_VERIFICATION,
            array(
                'status' => 'rejected',
                'reason' => $reason,
            )
        );
    }

    /**
     * Event handler: Subscription changed.
     *
     * @param string $new_plan Old plan.
     * @param string $old_plan New plan.
     */
    public function on_subscription_changed( $new_plan, $old_plan ) {
        if ( ! $this->settings['enabled'] ) {
            return;
        }

        $this->send_brevo_email(
            $this->settings['admin_email'],
            get_bloginfo( 'name' ),
            self::TEMPLATE_SUBSCRIPTION,
            array(
                'old_plan' => $old_plan,
                'new_plan' => $new_plan,
            )
        );
    }

    /**
     * Event handler: Trial ending.
     *
     * @param string $plan      Current plan.
     * @param int    $days_left Days remaining in trial.
     */
    public function on_trial_ending( $plan, $days_left ) {
        if ( ! $this->settings['enabled'] || ! $this->settings['notify_user']['trial_ending'] ) {
            return;
        }

        $this->send_brevo_email(
            $this->settings['admin_email'],
            get_bloginfo( 'name' ),
            self::TEMPLATE_TRIAL_ENDING,
            array(
                'plan'      => $plan,
                'days_left' => $days_left,
            )
        );
    }

    /**
     * Send weekly report.
     */
    public function send_weekly_report() {
        if ( ! $this->settings['enabled'] || ! $this->settings['notify_user']['weekly_report'] ) {
            return;
        }

        // Get weekly stats.
        $stats = affilync()->conversion_tracker->get_stats( 'week' );
        $usage = affilync()->subscription->get_usage();

        $this->send_brevo_email(
            $this->settings['admin_email'],
            get_bloginfo( 'name' ),
            self::TEMPLATE_WEEKLY_REPORT,
            array(
                'conversions'     => $stats['total_conversions'],
                'revenue'         => $stats['total_revenue'],
                'commission'      => $stats['total_commission'],
                'usage_percent'   => $usage['conversions']['percentage'],
                'top_affiliates'  => $stats['top_affiliates'] ?? array(),
            )
        );
    }

    /**
     * Register email settings.
     */
    public function register_settings() {
        register_setting(
            'affilync_settings_group',
            'affilync_email_settings',
            array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
        );
    }

    /**
     * Sanitize email settings.
     *
     * @param array $input Input values.
     * @return array
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        $sanitized['enabled']     = ! empty( $input['enabled'] );
        $sanitized['send_via']    = sanitize_key( $input['send_via'] ?? 'brevo' );
        $sanitized['from_name']   = sanitize_text_field( $input['from_name'] ?? '' );
        $sanitized['from_email']  = sanitize_email( $input['from_email'] ?? '' );
        $sanitized['admin_email'] = sanitize_email( $input['admin_email'] ?? '' );

        if ( isset( $input['notify_admin'] ) && is_array( $input['notify_admin'] ) ) {
            $sanitized['notify_admin'] = array_map( 'boolval', $input['notify_admin'] );
        }

        if ( isset( $input['notify_user'] ) && is_array( $input['notify_user'] ) ) {
            $sanitized['notify_user'] = array_map( 'boolval', $input['notify_user'] );
        }

        return $sanitized;
    }

    /**
     * Get current settings.
     *
     * @return array
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Update settings.
     *
     * @param array $new_settings New settings.
     * @return bool
     */
    public function update_settings( $new_settings ) {
        $sanitized = $this->sanitize_settings( $new_settings );
        $updated   = update_option( 'affilync_email_settings', $sanitized );

        if ( $updated ) {
            $this->settings = wp_parse_args( $sanitized, $this->settings );
        }

        return $updated;
    }

    /**
     * Test email configuration.
     *
     * @return bool|WP_Error
     */
    public function send_test_email() {
        return $this->send_brevo_email(
            $this->settings['admin_email'],
            get_bloginfo( 'name' ),
            self::TEMPLATE_CONNECTED,
            array(
                'test' => true,
                'message' => __( 'This is a test email from Affilync for WooCommerce.', 'affilync-woocommerce' ),
            )
        );
    }
}
